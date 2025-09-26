<?php
/**
 * Plugin Name: Silver Duck Comment Classifier
 * Description: Classifies WordPress comments as spam/ham using OpenRouter Llama models. Includes admin settings, logs, heuristics (links/blacklists), author field checks (name/email/url), optional blog-post context for relevance, bulk recheck, and rate-limit backoff.
 * Version: 1.2.17
 * Author: Matt Campos
 * License: GPL-2.0-or-later
 * Text Domain: silver-duck
 */

if (!defined('ABSPATH')) exit;

class Silver_Duck {
    const OPT_KEY = 'silver_duck_options';
    const NONCE   = 'silver_duck_nonce';
    const CRON_HOOK_PURGE = 'silver_duck_purge_logs';
    const TABLE   = 'silver_duck_logs';
    const CAP     = 'manage_options';
    const BLOCK_TRANSIENT = 'silver_duck_block_until'; // site-wide backoff when 429s occur
    const GROQ_BLOCK_TRANSIENT = 'silver_duck_groq_block_until';

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        // Admin UI
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        // Admin actions
        add_action('admin_post_silver_duck_test', [$this, 'handle_test_action']);
        add_action('admin_post_silver_duck_purge_logs', [$this, 'handle_purge_logs_action']);
        add_action('admin_post_silver_duck_recheck_pending', [$this, 'handle_recheck_pending_action']);

        // Comment pipeline
        add_filter('pre_comment_approved', [$this, 'filter_pre_comment_approved'], 9, 2);
        add_filter('preprocess_comment', [$this, 'maybe_preprocess_comment'], 9);

        // Cron
        add_action(self::CRON_HOOK_PURGE, [$this, 'purge_old_logs']);
    }

    /** Default options */
    public static function defaults() {
        return [
                'enabled'               => 1,
                'api_key'               => '',
            // Correct default free model (updateable in Settings UI)
                'model'                 => 'meta-llama/llama-3.2-3b-instruct:free',
                'confidence'            => 0.85,
                'auto_action'           => 'spam', // spam|hold
                'timeout'               => 15,
                'force_spam_on_llm'     => 0,
                'auto_approve_valid'    => 0,
            'auto_approve_url_less' => 0,

            // Content heuristics
                'max_links'             => 2,
                'blacklist'             => "", // phrases/domains in comment content

            // Author field checks
                'check_author_fields'   => 1,
                'disposable_email_check'=> 1,
                'email_domain_blacklist'=> "",
                'url_domain_blacklist'  => "",
                'author_name_blacklist' => "",

            // Post context (relevance)
                'include_post_context'  => 1,
                'post_context_chars'    => 2000, // ~350–450 tokens

            // Ops
                'log_retention_days'    => 30,
                'run_for_logged_in'     => 0,

            // Groq fallback
                'groq_enabled'          => 0,
                'groq_api_key'          => '',
                'groq_model'            => 'llama-3.1-8b-instant',
        ];
    }

    /** Activation */
    public function activate() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE `{$table}` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            comment_id BIGINT UNSIGNED NULL,
            decision VARCHAR(10) NOT NULL,
            confidence DECIMAL(5,4) NULL,
            model VARCHAR(191) NULL,
            tokens INT NULL,
            latency_ms INT NULL,
            reasons TEXT NULL,
            raw_response TEXT NULL,
            error TEXT NULL,
            PRIMARY KEY (id),
            KEY comment_id (comment_id),
            KEY created_at (created_at)
        ) $charset;";
        dbDelta($sql);

        $existing = get_option(self::OPT_KEY, null);
        if ($existing === null) {
            add_option(self::OPT_KEY, self::defaults(), '', 'no');
        } else {
            update_option(self::OPT_KEY, wp_parse_args($existing, self::defaults()));
            // Ensure autoload for secret-containing options is disabled even for pre-existing installs
            $options_table = $wpdb->options;
            $row = $wpdb->get_row($wpdb->prepare("SELECT autoload FROM `{$options_table}` WHERE option_name = %s", self::OPT_KEY));
            if ($row && strtolower((string)$row->autoload) !== 'no') {
                $wpdb->update($options_table, ['autoload' => 'no'], ['option_name' => self::OPT_KEY]);
            }
        }

        if (!wp_next_scheduled(self::CRON_HOOK_PURGE)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK_PURGE);
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK_PURGE);
    }

    /** Admin menu */
    public function admin_menu() {
        // Top-level Silver Duck menu (main sidebar)
        add_menu_page(
                __('Silver Duck', 'silver-duck'),
                __('Silver Duck', 'silver-duck'),
                self::CAP,
                'silver-duck',
                [$this, 'render_settings_page'],
                'dashicons-admin-comments',
                26 // position near Comments
        );

        // Settings submenu (separate slug, renders the same settings page)
        add_submenu_page(
                'silver-duck',
                __('Silver Duck Settings', 'silver-duck'),
                __('Settings', 'silver-duck'),
                self::CAP,
                'silver-duck-settings',
                [$this, 'render_settings_page']
        );

        // Tools submenu
        add_submenu_page(
                'silver-duck',
                __('Silver Duck Tools', 'silver-duck'),
                __('Tools', 'silver-duck'),
                self::CAP,
                'silver-duck-tools',
                [$this, 'render_tools_page']
        );

        // Logs submenu under Silver Duck
        add_submenu_page(
                'silver-duck',
                __('Silver Duck Logs', 'silver-duck'),
                __('Logs', 'silver-duck'),
                self::CAP,
                'silver-duck-logs',
                [$this, 'render_logs_admin_page']
        );

        // Hide the auto-added duplicate submenu that repeats the top-level page label
        remove_submenu_page('silver-duck', 'silver-duck');
    }

    /** Register settings/sections/fields */
    public function register_settings() {
        register_setting(self::OPT_KEY, self::OPT_KEY, [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_options']
        ]);

        // Core
        add_settings_section('sd_core', __('Core Settings', 'silver-duck'), '__return_false', 'silver-duck');
        $core = [
                ['enabled', 'checkbox', __('Enable classifier', 'silver-duck')],
                ['api_key', 'password', __('OpenRouter API Key', 'silver-duck'), __('Leave blank to keep the existing key. Get an API key at openrouter.ai and keep it secret.', 'silver-duck')],
                ['model', 'text', __('Model', 'silver-duck')],
                ['confidence', 'number', __('Spam threshold (0–1)', 'silver-duck')],
                ['auto_action', 'select', __('When labeled spam', 'silver-duck')],
                ['timeout', 'number', __('API timeout (seconds)', 'silver-duck')],
                ['force_spam_on_llm', 'checkbox', __('Force "Spam" when LLM says spam', 'silver-duck')],
                ['auto_approve_valid', 'checkbox', __('Auto-approve when LLM says valid', 'silver-duck')],
                ['auto_approve_url_less', 'checkbox', __('Auto-approve valid comments with no URLs', 'silver-duck'), __('If enabled, comments that contain no links and receive a "valid" verdict will be auto-approved.', 'silver-duck')],
                ['run_for_logged_in', 'checkbox', __('Also classify comments from logged-in users', 'silver-duck')],
        ];
        foreach ($core as $f) {
            $args = ['key' => $f[0], 'type' => $f[1]];
            if (!empty($f[3])) $args['desc'] = $f[3];
            add_settings_field($f[0], $f[2], [$this, 'render_field'], 'silver-duck', 'sd_core', $args);
        }

        // Content heuristics
        add_settings_section('sd_rules', __('Content Heuristics', 'silver-duck'), function () {
            echo '<p class="description">'.esc_html__('Quick checks to reduce API calls & latency.', 'silver-duck').'</p>';
        }, 'silver-duck');
        add_settings_field('max_links', __('Auto-mark spam if links > N', 'silver-duck'), [$this, 'render_field'], 'silver-duck', 'sd_rules', ['key' => 'max_links', 'type' => 'number']);
        add_settings_field('blacklist', __('Content blacklist (one per line)', 'silver-duck'), [$this, 'render_field'], 'silver-duck', 'sd_rules', ['key' => 'blacklist', 'type' => 'textarea']);

        // Author field checks
        add_settings_section('sd_author', __('Author Field Checks', 'silver-duck'), function () {
            echo '<p class="description">'.esc_html__('Apply heuristics to name, email, and URL before calling the model.', 'silver-duck').'</p>';
        }, 'silver-duck');
        $author = [
                ['check_author_fields', 'checkbox', __('Enable author field checks', 'silver-duck')],
                ['disposable_email_check', 'checkbox', __('Flag disposable email domains', 'silver-duck')],
                ['email_domain_blacklist', 'textarea', __('Email domain blacklist (one domain per line)', 'silver-duck')],
                ['url_domain_blacklist', 'textarea', __('URL domain blacklist (one domain per line)', 'silver-duck')],
                ['author_name_blacklist', 'textarea', __('Author name blacklist (one phrase per line)', 'silver-duck')],
        ];
        foreach ($author as $f) add_settings_field($f[0], $f[2], [$this, 'render_field'], 'silver-duck', 'sd_author', ['key' => $f[0], 'type' => $f[1]]);

        // Post context (relevance)
        add_settings_section('sd_postctx', __('Post Context (Relevance)', 'silver-duck'), function () {
            echo '<p class="description">'.esc_html__('Include a compact summary of the blog post so the model can judge if the comment is on-topic.', 'silver-duck').'</p>';
        }, 'silver-duck');
        add_settings_field('include_post_context', __('Include post context', 'silver-duck'), [$this, 'render_field'], 'silver-duck', 'sd_postctx', ['key' => 'include_post_context', 'type' => 'checkbox']);
        add_settings_field('post_context_chars', __('Max context length (chars)', 'silver-duck'), [$this, 'render_field'], 'silver-duck', 'sd_postctx', ['key' => 'post_context_chars', 'type' => 'number']);

        // Maintenance
        add_settings_section('sd_maintenance', __('Maintenance', 'silver-duck'), '__return_false', 'silver-duck');
        add_settings_field('log_retention_days', __('Log retention days', 'silver-duck'), [$this, 'render_field'], 'silver-duck', 'sd_maintenance', ['key' => 'log_retention_days', 'type' => 'number']);

        // Groq fallback
        add_settings_section('sd_groq', __('Groq Fallback', 'silver-duck'), function () {
            echo '<p class="description">'.esc_html__('Configure Groq as a secondary provider when OpenRouter is unavailable or throttled.', 'silver-duck').'</p>';
        }, 'silver-duck');
        $groq_fields = [
                ['groq_enabled', 'checkbox', __('Enable Groq fallback', 'silver-duck')],
                ['groq_api_key', 'password', __('Groq API Key', 'silver-duck'), __('Leave blank to keep the existing key. Generate a key at console.groq.com and keep it secret.', 'silver-duck')],
                ['groq_model', 'text', __('Groq model', 'silver-duck'), __('Example: llama-3.1-8b-instant', 'silver-duck')],
        ];
        foreach ($groq_fields as $f) {
            $args = ['key' => $f[0], 'type' => $f[1]];
            if (!empty($f[3])) $args['desc'] = $f[3];
            add_settings_field($f[0], $f[2], [$this, 'render_field'], 'silver-duck', 'sd_groq', $args);
        }
    }

    /** Sanitize options */
    public function sanitize_options($input) {
        $d = self::defaults();
        $prev = wp_parse_args(get_option(self::OPT_KEY), $d);
        return [
                'enabled'               => empty($input['enabled']) ? 0 : 1,
                'api_key'               => isset($input['api_key']) && trim($input['api_key']) !== '' ? trim($input['api_key']) : (string)($prev['api_key'] ?? ''),
                'model'                 => isset($input['model']) ? sanitize_text_field($input['model']) : $d['model'],
                'confidence'            => isset($input['confidence']) ? min(1, max(0, floatval($input['confidence']))) : $d['confidence'],
                'auto_action'           => in_array($input['auto_action'] ?? 'spam', ['spam','hold'], true) ? $input['auto_action'] : 'spam',
                'timeout'               => max(3, intval($input['timeout'] ?? $d['timeout'])),
                'force_spam_on_llm'     => empty($input['force_spam_on_llm']) ? 0 : 1,
                'auto_approve_valid'    => empty($input['auto_approve_valid']) ? 0 : 1,
                'auto_approve_url_less' => empty($input['auto_approve_url_less']) ? 0 : 1,

                'max_links'             => max(0, intval($input['max_links'] ?? $d['max_links'])),
                'blacklist'             => isset($input['blacklist']) ? sanitize_textarea_field($input['blacklist']) : '',

                'check_author_fields'   => empty($input['check_author_fields']) ? 0 : 1,
                'disposable_email_check'=> empty($input['disposable_email_check']) ? 0 : 1,
                'email_domain_blacklist'=> isset($input['email_domain_blacklist']) ? sanitize_textarea_field($input['email_domain_blacklist']) : '',
                'url_domain_blacklist'  => isset($input['url_domain_blacklist']) ? sanitize_textarea_field($input['url_domain_blacklist']) : '',
                'author_name_blacklist' => isset($input['author_name_blacklist']) ? sanitize_textarea_field($input['author_name_blacklist']) : '',

                'include_post_context'  => empty($input['include_post_context']) ? 0 : 1,
                'post_context_chars'    => max(200, min(8000, intval($input['post_context_chars'] ?? 2000))),

                'log_retention_days'    => max(0, intval($input['log_retention_days'] ?? $d['log_retention_days'])),
                'run_for_logged_in'     => empty($input['run_for_logged_in']) ? 0 : 1,

                'groq_enabled'          => empty($input['groq_enabled']) ? 0 : 1,
                'groq_api_key'          => isset($input['groq_api_key']) && trim($input['groq_api_key']) !== '' ? trim($input['groq_api_key']) : (string)($prev['groq_api_key'] ?? ''),
                'groq_model'            => isset($input['groq_model']) ? sanitize_text_field($input['groq_model']) : $d['groq_model'],
        ];
    }

    /** Render fields */
    public function render_field($args) {
        $opts = wp_parse_args(get_option(self::OPT_KEY), self::defaults());
        $k = $args['key'];
        $type = $args['type'] ?? 'text';

        if ($type === 'checkbox') {
            printf('<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
                    esc_attr(self::OPT_KEY), esc_attr($k), checked(1, $opts[$k], false), ''
            );
            return;
        }
        if ($type === 'password') {
            printf('<input type="password" class="regular-text" name="%1$s[%2$s]" value="" autocomplete="new-password" placeholder="%3$s" />',
                    esc_attr(self::OPT_KEY), esc_attr($k), $opts[$k] ? esc_attr(str_repeat('•', 8)) : ''
            );
            if (!empty($args['desc'])) {
                echo '<p class="description">'.esc_html($args['desc']).'</p>';
            }
            return;
        }
        if ($type === 'number') {
            $step = ($k === 'confidence') ? '0.01' : '1';
            printf('<input type="number" class="small-text" step="%4$s" name="%1$s[%2$s]" value="%3$s" />',
                    esc_attr(self::OPT_KEY), esc_attr($k), esc_attr($opts[$k]), esc_attr($step)
            );
            return;
        }
        if ($type === 'textarea') {
            printf('<textarea class="large-text code" rows="6" name="%1$s[%2$s]">%3$s</textarea>',
                    esc_attr(self::OPT_KEY), esc_attr($k), esc_textarea($opts[$k])
            );
            return;
        }
        if ($type === 'select' && $k === 'auto_action') {
            $val = $opts[$k];
            echo '<select name="'.esc_attr(self::OPT_KEY).'['.esc_attr($k).']">';
            echo '<option value="spam" '.selected($val, 'spam', false).'>'.esc_html__('Mark as Spam', 'silver-duck').'</option>';
            echo '<option value="hold" '.selected($val, 'hold', false).'>'.esc_html__('Hold for Moderation', 'silver-duck').'</option>';
            echo '</select>';
            return;
        }
        printf('<input type="text" class="regular-text" name="%1$s[%2$s]" value="%3$s" />',
                esc_attr(self::OPT_KEY), esc_attr($k), esc_attr($opts[$k])
        );
        if (!empty($args['desc'])) {
            echo '<p class="description">'.esc_html($args['desc']).'</p>';
        }
    }

    /** Settings page */
    public function render_settings_page() {
        if (!current_user_can(self::CAP)) return;
        $opts = wp_parse_args(get_option(self::OPT_KEY), self::defaults());
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Silver Duck', 'silver-duck');?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPT_KEY);
                do_settings_sections('silver-duck');
                submit_button(__('Save Settings', 'silver-duck'));
                ?>
            </form>
        </div>
        <?php
    }

    /** Tools page */
    public function render_tools_page() {
        if (!current_user_can(self::CAP)) return;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Silver Duck Tools', 'silver-duck');?></h1>
            <?php
            // Admin notices for actions coming back to Tools
            $decision   = isset($_GET['decision'])   ? sanitize_text_field((string) $_GET['decision']) : '';
            $conf       = isset($_GET['conf'])       ? floatval($_GET['conf']) : null;
            $err        = isset($_GET['error'])      ? sanitize_text_field((string) $_GET['error']) : '';
            $spam_moved = isset($_GET['spam_moved']) ? max(0, intval($_GET['spam_moved'])) : 0;
            $hold_kept  = isset($_GET['hold_kept'])  ? max(0, intval($_GET['hold_kept'])) : 0;
            $approved_c = isset($_GET['approved'])   ? max(0, intval($_GET['approved'])) : 0;
            $rechecked  = isset($_GET['rechecked'])  ? intval($_GET['rechecked']) : 0;
            $purged     = isset($_GET['purged'])     ? intval($_GET['purged']) : 0;
            $remaining  = isset($_GET['remaining'])  ? max(0, intval($_GET['remaining'])) : 0;
            $next_off   = isset($_GET['next_offset'])? max(0, intval($_GET['next_offset'])) : 0;
            $batch_q    = isset($_GET['batch'])      ? max(1, min(200, intval($_GET['batch']))) : 25;

            if ($err !== '') {
                echo '<div class="notice notice-error is-dismissible"><p>'.esc_html__('Test error:', 'silver-duck').' '.esc_html($err).'</p></div>';
            }
            if ($decision !== '') {
                $msg = sprintf(__('Test result: %1$s (confidence %2$.2f)', 'silver-duck'), $decision, ($conf !== null ? $conf : 0));
                echo '<div class="notice notice-success is-dismissible"><p>'.esc_html($msg).'</p></div>';
            }
            if ($rechecked > 0) {
                $msg = sprintf(_n('Rechecked %s pending comment', 'Rechecked %s pending comments', $rechecked, 'silver-duck'), number_format_i18n($rechecked));
                echo '<div class="notice notice-success is-dismissible"><p>'.esc_html($msg).'</p></div>';
            }
            if ($spam_moved || $hold_kept || $approved_c) {
                $items = [];
                if ($spam_moved) {
                    $items[] = sprintf(__('%s moved to spam', 'silver-duck'), number_format_i18n($spam_moved));
                }
                if ($hold_kept) {
                    $items[] = sprintf(__('%s kept on hold', 'silver-duck'), number_format_i18n($hold_kept));
                }
                if ($approved_c) {
                    $items[] = sprintf(__('%s approved', 'silver-duck'), number_format_i18n($approved_c));
                }
                $summary = implode(' · ', $items);
                echo '<div class="notice notice-info is-dismissible"><p>'.esc_html__('Batch summary:', 'silver-duck').' '.esc_html($summary).'</p>';

                $link_parts = [];
                if ($spam_moved) {
                    $link_parts[] = '<a href="'.esc_url(admin_url('edit-comments.php?comment_status=spam')).'">'.esc_html__('View Spam Queue', 'silver-duck').'</a>';
                }
                if ($hold_kept) {
                    $link_parts[] = '<a href="'.esc_url(admin_url('edit-comments.php?comment_status=moderated')).'">'.esc_html__('View Pending Queue', 'silver-duck').'</a>';
                }
                if ($approved_c) {
                    $link_parts[] = '<a href="'.esc_url(admin_url('edit-comments.php?comment_status=approved')).'">'.esc_html__('View Approved Comments', 'silver-duck').'</a>';
                }
                if ($link_parts) {
                    $links_html = wp_kses(implode(' | ', $link_parts), [ 'a' => [ 'href' => [] ] ]);
                    echo '<p class="description">'.$links_html.'</p>';
                }
                echo '</div>';
            }
            if ($purged) {
                echo '<div class="notice notice-success is-dismissible"><p>'.esc_html__('Logs purged per retention setting.', 'silver-duck').'</p></div>';
            }
            if ($remaining > 0) {
                $msg = sprintf(_n('%s pending comment remaining', '%s pending comments remaining', $remaining, 'silver-duck'), number_format_i18n($remaining));
                echo '<div class="notice notice-info"><p>'.esc_html($msg).'</p>';
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin:8px 0;display:inline-block">';
                wp_nonce_field(self::NONCE);
                echo '<input type="hidden" name="action" value="silver_duck_recheck_pending" />';
                echo '<input type="hidden" name="offset" value="'.esc_attr($next_off).'" />';
                echo '<input type="hidden" name="batch" value="'.esc_attr($batch_q).'" />';
                echo '<button class="button button-primary">'.esc_html__('Run Next Batch', 'silver-duck').'</button> ';
                echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=silver-duck-tools')).'">'.esc_html__('Reset', 'silver-duck').'</a>';
                echo '</form></div>';
            }
            ?>
            <div style="display:flex;gap:24px;flex-wrap:wrap;">
                <div style="flex:1;min-width:380px;">
                    <h3><?php esc_html_e('Test a Comment', 'silver-duck');?></h3>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>">
                        <?php wp_nonce_field(self::NONCE); ?>
                        <input type="hidden" name="action" value="silver_duck_test" />
                        <textarea name="comment_body" class="large-text code" rows="6" placeholder="<?php esc_attr_e('Paste a sample comment…','silver-duck');?>"></textarea>
                        <p><button class="button button-primary"><?php esc_html_e('Classify', 'silver-duck');?></button></p>
                    </form>
                </div>

                <div style="flex:1;min-width:320px;">
                    <h3><?php esc_html_e('Recheck Pending Comments', 'silver-duck');?></h3>
                    <p class="description"><?php esc_html_e('Re-runs classification for comments awaiting moderation (conservative: does not auto-change status).', 'silver-duck');?></p>
                    <?php $batch_default = $batch_q; $offset_default = $next_off; ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>">
                        <?php wp_nonce_field(self::NONCE); ?>
                        <input type="hidden" name="action" value="silver_duck_recheck_pending" />
                        <p>
                            <label><?php esc_html_e('Batch size', 'silver-duck');?>
                                <input type="number" min="1" max="200" step="1" name="batch" value="<?php echo esc_attr($batch_default);?>" class="small-text" />
                            </label>
                            <input type="hidden" name="offset" value="<?php echo esc_attr($offset_default);?>" />
                            <button class="button">&nbsp;<?php esc_html_e('Run Now', 'silver-duck');?></button>
                        </p>
                    </form>

                    <h3 style="margin-top:24px;"><?php esc_html_e('Purge Logs', 'silver-duck');?></h3>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>">
                        <?php wp_nonce_field(self::NONCE); ?>
                        <input type="hidden" name="action" value="silver_duck_purge_logs" />
                        <button class="button"><?php esc_html_e('Purge Older Than Retention', 'silver-duck');?></button>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /** Admin: test action */
    public function handle_test_action() {
        if (!current_user_can(self::CAP)) wp_die('Unauthorized', 403);
        check_admin_referer(self::NONCE);
        $text = sanitize_textarea_field($_POST['comment_body'] ?? '');
        $res = $this->classify_text($text, null, true, [
                'author' => '(test)',
                'email'  => '(test@example.com)',
                'url'    => '(none)',
                'post'   => '' // no post context for the ad-hoc test box
        ]);
        $msg = $res['error']
                ? 'error=' . rawurlencode($res['error'])
                : 'decision=' . rawurlencode($res['decision']) . '&conf=' . rawurlencode($res['confidence']);
        wp_safe_redirect(admin_url('admin.php?page=silver-duck-tools&' . $msg));
        exit;
    }

    /** Admin: purge logs */
    public function handle_purge_logs_action() {
        if (!current_user_can(self::CAP)) wp_die('Unauthorized', 403);
        check_admin_referer(self::NONCE);
        $this->purge_old_logs();
        wp_safe_redirect(admin_url('admin.php?page=silver-duck-tools&purged=1'));
        exit;
    }

    /** Admin: recheck pending (conservative; only logs decisions) */
    public function handle_recheck_pending_action() {
        if (!current_user_can(self::CAP)) wp_die('Unauthorized', 403);
        check_admin_referer(self::NONCE);

        $opts = wp_parse_args(get_option(self::OPT_KEY), self::defaults());

        // Batch controls
        $batch  = isset($_POST['batch'])  ? max(1, min(200, intval($_POST['batch']))) : 25;
        $offset = isset($_POST['offset']) ? max(0, intval($_POST['offset'])) : 0;

        // Fetch this batch of pending comments
        $args = [
                'status'   => 'hold',
                'number'   => $batch,
                'offset'   => $offset,
                'orderby'  => 'comment_date_gmt',
                'order'    => 'ASC',
        ];
        $count = 0;
        $moved_spam = 0;
        $kept_hold = 0;
        $approved = 0;
        $pending = get_comments($args);
        foreach ($pending as $c) {
            $result = $this->evaluate_comment_for_action($c->comment_ID, (array)$c, false);

            if ($result === 'spam') {
                wp_spam_comment($c->comment_ID);
                $moved_spam++;
            } elseif ($result === 'hold') {
                wp_set_comment_status($c->comment_ID, 'hold');
                $kept_hold++;
            } elseif ($result === 'approve') {
                wp_set_comment_status($c->comment_ID, 'approve');
                $approved++;
            }

            $count++;
        }

        // Compute remaining and next offset
        $total_pending = intval(get_comments(['status' => 'hold', 'count' => true]));
        $next_offset   = $offset + $count;
        $remaining     = max(0, $total_pending - $next_offset);

        $qs = http_build_query([
                'page'        => 'silver-duck-tools',
                'rechecked'   => $count,
                'remaining'   => $remaining,
                'next_offset' => $next_offset,
                'batch'       => $batch,
                'spam_moved'  => $moved_spam,
                'hold_kept'   => $kept_hold,
                'approved'    => $approved,
        ]);
        wp_safe_redirect(admin_url('admin.php?'.$qs));
        exit;
    }

    /** preprocess (kept for parity) */
    public function maybe_preprocess_comment($commentdata) {
        return $commentdata;
    }

    /** Main approval decision hook */
    public function filter_pre_comment_approved($approved, $commentdata) {
        $opts = wp_parse_args(get_option(self::OPT_KEY), self::defaults());
        if (empty($opts['enabled'])) return $approved;

        if (!is_user_logged_in() || !empty($opts['run_for_logged_in'])) {
            $result = $this->evaluate_comment_for_action(null, $commentdata, false);
            if ($result === 'spam') return 'spam';
            if ($result === 'hold') return 0;
            if ($result === 'approve') return 1;
        }
        return $approved;
    }

    /** Evaluate (heuristics + LLM) and return 'spam' | 'hold' | 'none' */
    protected function evaluate_comment_for_action($comment_id, $commentdata, $bypass_heuristics_for_bulk=false) {
        $opts = wp_parse_args(get_option(self::OPT_KEY), self::defaults());

        $content = trim($commentdata['comment_content'] ?? '');
        $author  = trim($commentdata['comment_author'] ?? '');
        $email   = trim($commentdata['comment_author_email'] ?? '');
        $url     = trim($commentdata['comment_author_url'] ?? '');

        if ($content === '') return 'hold';

        $linkMatchesHttp = [];
        $linkCountHttp = preg_match_all('#https?://#i', $content, $linkMatchesHttp);
        $has_urls_in_content = $linkCountHttp > 0 || (bool) preg_match('#(?:https?://|www\.)#i', $content);

        // --- Heuristic: link count
        if (!$bypass_heuristics_for_bulk && $opts['max_links'] > 0) {
            if ($linkCountHttp > $opts['max_links']) {
                $this->log($comment_id, 'spam', 1.0000, $opts['model'], null, null, ['Too many links ('.$linkCount.')'], null, null);
                return $opts['auto_action'];
            }
        }

        // --- Heuristic: content blacklist
        if (!$bypass_heuristics_for_bulk && !empty($opts['blacklist'])) {
            $patterns = $this->lines_to_list($opts['blacklist']);
            foreach ($patterns as $p) {
                if ($p !== '' && stripos($content, $p) !== false) {
                    $this->log($comment_id, 'spam', 1.0000, $opts['model'], null, null, ['Content matched blacklist: '.$p], null, null);
                    return $opts['auto_action'];
                }
            }
        }

        // --- Heuristics: author field checks
        if (!$bypass_heuristics_for_bulk && !empty($opts['check_author_fields'])) {
            // Author name blacklist
            if (!empty($opts['author_name_blacklist']) && $author !== '') {
                foreach ($this->lines_to_list($opts['author_name_blacklist']) as $p) {
                    if ($p !== '' && stripos($author, $p) !== false) {
                        $this->log($comment_id, 'spam', 1.0000, $opts['model'], null, null, ['Author name matched blacklist: '.$p], null, null);
                        return $opts['auto_action'];
                    }
                }
            }

            // Email domain blacklist / disposable
            if ($email !== '' && is_email($email)) {
                $edomain = $this->normalize_domain($this->email_domain($email));
                if ($edomain) {
                    foreach ($this->lines_to_list($opts['email_domain_blacklist']) as $bd) {
                        if ($bd !== '' && $edomain === $this->normalize_domain($bd)) {
                            $this->log($comment_id, 'spam', 1.0000, $opts['model'], null, null, ['Email domain blacklisted: '.$edomain], null, null);
                            return $opts['auto_action'];
                        }
                    }
                    if (!empty($opts['disposable_email_check']) && $this->is_disposable_domain($edomain)) {
                        $this->log($comment_id, 'spam', 1.0000, $opts['model'], null, null, ['Disposable email domain: '.$edomain], null, null);
                        return $opts['auto_action'];
                    }
                }
            }

            // URL domain blacklist
            if ($url !== '') {
                $udomain = $this->normalize_domain($this->url_domain($url));
                if ($udomain) {
                    foreach ($this->lines_to_list($opts['url_domain_blacklist']) as $bd) {
                        if ($bd !== '' && $udomain === $this->normalize_domain($bd)) {
                            $this->log($comment_id, 'spam', 1.0000, $opts['model'], null, null, ['Author URL domain blacklisted: '.$udomain], null, null);
                            return $opts['auto_action'];
                        }
                    }
                }
            }
        }

        // --- Optional post context
        $postCtx = '';
        if (!empty($opts['include_post_context'])) {
            $post_id = intval($commentdata['comment_post_ID'] ?? 0);
            if ($post_id) {
                $post = get_post($post_id);
                if ($post && $post->post_status === 'publish') {
                    $postCtx = $this->make_post_context_for_llm($post, $content, intval($opts['post_context_chars']));
                }
            }
        }

        // --- LLM Classify (with author metadata + optional post context)
        $res = $this->classify_text($content, $comment_id, false, [
                'author' => $author,
                'email'  => $email,
                'url'    => $url,
                'post'   => $postCtx,
        ]);
        if (!empty($res['error'])) return 'hold';

        $decision = $res['decision'];
        $conf     = floatval($res['confidence']);
        $threshold = floatval($opts['confidence']);

        if ($decision === 'spam') {
            if (!empty($opts['force_spam_on_llm'])) return 'spam';
            if ($conf >= $threshold) return $opts['auto_action']; // spam|hold
            return 'hold'; // conservative action for low-confidence spam
        }
        if ($decision === 'valid') {
            if (!empty($opts['auto_approve_valid'])) return 'approve';
            if (!empty($opts['auto_approve_url_less']) && !$has_urls_in_content) return 'approve';
            return 'none';
        }
        return 'none';
    }

    /** LLM call with OpenRouter primary + Groq fallback. Includes metadata + post context in the prompt. */
    protected function classify_text($text, $comment_id=null, $is_test=false, $meta = []) {
        $opts    = wp_parse_args(get_option(self::OPT_KEY), self::defaults());
        $timeout = max(3, intval($opts['timeout']));
        $json_flags = (defined('JSON_UNESCAPED_SLASHES') ? JSON_UNESCAPED_SLASHES : 0) |
                      (defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE : 0);

        $author = trim($meta['author'] ?? '');
        $email  = trim($meta['email']  ?? '');
        $url    = trim($meta['url']    ?? '');
        $post   = trim($meta['post']   ?? '');

        $authorLine = $author !== '' ? $author : '(none)';
        $emailLine  = $email  !== '' ? $email  : '(none)';
        $urlLine    = $url    !== '' ? $url    : '(none)';

        $system = "You are a strict comment spam filter for a WordPress site.\n".
                "You also evaluate topical relevance to the blog post.\n".
                "Output a SINGLE LINE of compact JSON with keys exactly: label (\"spam\" or \"valid\"), confidence (0..1), reasons (array of short strings).\n".
                "If a comment is generic praise or unrelated to the post, prefer label \"spam\" with a reason like \"off-topic/generic\".\n".
                "Return ONLY JSON.";

        $postBlock = $post !== '' ? "Blog Post Context:\n---\n{$post}\n---\n\n" : "";

        $userPrompt =
                $postBlock .
                "Classify this comment as spam or valid. Consider: content quality, links, scams/phishing, SEO spam, duplicates, generic praise, and topical relevance to the post (if provided).\n".
                "Author fields (may be empty):\n".
                "- Author Name: {$authorLine}\n".
                "- Author Email: {$emailLine}\n".
                "- Author URL: {$urlLine}\n\n".
                "Comment:\n---\n".$text."\n---";

        $candidates = array_values(array_unique(array_filter([
                $opts['model'],
                'meta-llama/llama-4-maverick:free',
                'meta-llama/llama-3.2-3b-instruct:free',
        ])));

        $groqEnabled = !empty($opts['groq_enabled']) && !empty($opts['groq_api_key']);

        // Global backoff for OpenRouter
        $blockUntil = (int) get_transient(self::BLOCK_TRANSIENT);
        $openrouterBlocked = $blockUntil && $blockUntil > time();
        $last_error = null;
        $last_raw   = null;
        $last_tokens = null;

        if ($openrouterBlocked && !$groqEnabled) {
            $untilStr = gmdate('c', $blockUntil);
            $reasons = ['rate-limited until '.$untilStr];
            $raw_block = wp_json_encode([
                    'provider'   => 'openrouter',
                    'block_until'=> $untilStr,
            ], $json_flags);
            if (!$raw_block) $raw_block = 'openrouter rate_limited until '.$untilStr;
            $this->log($comment_id, 'valid', 0.50, $opts['model'], null, null, $reasons, $raw_block, 'rate_limited');
            return ['decision'=>'valid','confidence'=>0.5,'reasons'=>$reasons,'error'=>'rate_limited'];
        }

        if ($openrouterBlocked && $groqEnabled) {
            $untilStr = gmdate('c', $blockUntil);
            $last_error = 'openrouter_rate_limited';
            $last_raw = wp_json_encode([
                    'provider'   => 'openrouter',
                    'block_until'=> $untilStr,
            ], $json_flags);
            if (!$last_raw) $last_raw = 'openrouter rate_limited until '.$untilStr;
        }

        $start = microtime(true);
        $attempted = false;
        $skipped_models = [];

        if (!$openrouterBlocked) {
            foreach ($candidates as $model) {
                $result = $this->attempt_openrouter_model($model, $system, $userPrompt, $opts['api_key'], $timeout, $start, $comment_id, $json_flags);
                if ($result['status'] === 'skipped') {
                    $skipped_models[$model] = $result['until'];
                    continue;
                }
                if ($result['status'] === 'success') {
                    return $result['payload'];
                }
                $attempted = true;
                $last_error  = $result['message'];
                $last_raw    = $result['raw'];
                $last_tokens = $result['tokens'];
                if ($result['status'] === 'rate_limited') {
                    break; // no point trying more OpenRouter models
                }
            }
        }

        // Groq fallback
        if ($groqEnabled) {
            $groqBlock = (int) get_transient(self::GROQ_BLOCK_TRANSIENT);
            if ($groqBlock && $groqBlock > time()) {
                $last_error = 'groq_rate_limited';
                $last_raw = wp_json_encode([
                        'provider'   => 'groq',
                        'block_until'=> gmdate('c', $groqBlock),
                ], $json_flags);
            } else {
                $groqResult = $this->attempt_groq_model($system, $userPrompt, $opts['groq_api_key'], $opts['groq_model'], $timeout, $start, $comment_id, $json_flags);
                if ($groqResult['status'] === 'success') {
                    return $groqResult['payload'];
                }
                $last_error  = $groqResult['message'];
                $last_raw    = $groqResult['raw'];
                $last_tokens = $groqResult['tokens'];
            }
        }

        if (!$attempted && !empty($skipped_models)) {
            $soonest = min($skipped_models);
            $latency = intval((microtime(true) - $start) * 1000);
            $untilStr = gmdate('c', $soonest);
            $raw_backoff = wp_json_encode([
                    'provider'           => 'openrouter',
                    'error'              => 'all_models_backoff',
                    'models'             => $skipped_models,
                    'next_attempt_after' => $untilStr,
            ], $json_flags);
            if (!$raw_backoff) $raw_backoff = 'all models backoff until '.$untilStr;
            $this->log($comment_id, 'valid', 0.5, $opts['model'], $last_tokens, $latency, ['all models backoff until '.$untilStr], $raw_backoff, 'rate_limited');
            return ['decision'=>'valid','confidence'=>0.5,'reasons'=>['rate-limited'],'error'=>'rate_limited'];
        }

        $latency = intval((microtime(true) - $start) * 1000);
        $raw = $last_raw ?: wp_json_encode([
                'error'   => $last_error ?: 'unavailable',
                'context' => 'llm unavailable fallback',
        ], $json_flags);
        if (!$raw) $raw = (string) ($last_error ?: 'unavailable');
        $this->log($comment_id, 'valid', 0.5, $opts['model'], $last_tokens, $latency, ['llm unavailable: '.$last_error], $raw, $last_error ?: 'unavailable');
        return ['decision'=>'valid','confidence'=>0.5,'reasons'=>['llm unavailable'],'error'=>$last_error ?: 'unavailable'];
    }

    /** Attempt a single OpenRouter model. */
    protected function attempt_openrouter_model($model, $system, $userPrompt, $apiKey, $timeout, $start, $comment_id, $json_flags) {
        if (!$apiKey) {
            return ['status' => 'error', 'message' => 'Missing OpenRouter API key', 'raw' => null, 'tokens' => null];
        }

        $modelKey = $this->model_block_key($model);
        $blockUntil = (int) get_transient($modelKey);
        if ($blockUntil && $blockUntil > time()) {
            return ['status' => 'skipped', 'until' => $blockUntil, 'raw' => null, 'tokens' => null, 'message' => 'per-model backoff'];
        }

        $payload = [
                'model'       => $model,
                'temperature' => 0,
                'max_tokens'  => 80,
                'messages'    => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user',   'content' => $userPrompt],
                ],
        ];

        $resp = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
                'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode($payload),
                'timeout' => $timeout,
        ]);

        if (is_wp_error($resp)) {
            return ['status' => 'error', 'message' => $resp->get_error_message(), 'raw' => null, 'tokens' => null];
        }

        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        $raw  = $body;

        if ($code == 429) {
            $until = $this->extract_retry_until($resp);
            if ($until) {
                set_transient(self::BLOCK_TRANSIENT, $until, max(1, $until - time()));
            }
            return ['status' => 'rate_limited', 'message' => 'openrouter_rate_limited', 'raw' => $raw, 'tokens' => null];
        }

        if ($code >= 200 && $code < 300) {
            $json = json_decode($body, true);
            $tokens = intval($json['usage']['total_tokens'] ?? 0);
            $content = '';
            if (isset($json['choices'][0]['message']['content'])) {
                $content = (string)$json['choices'][0]['message']['content'];
            } elseif (isset($json['choices'][0]['text'])) {
                $content = (string)$json['choices'][0]['text'];
            }
            $parsed = $this->parse_model_output($content);
            $latency = intval((microtime(true) - $start) * 1000);
            $this->log($comment_id, $parsed['decision'], $parsed['confidence'], $model, $tokens, $latency, $parsed['reasons'], $raw, null);
            return ['status' => 'success', 'payload' => ['decision'=>$parsed['decision'],'confidence'=>$parsed['confidence'],'reasons'=>$parsed['reasons']]];
        }

        return ['status' => 'error', 'message' => 'openrouter_http_'.$code, 'raw' => $raw, 'tokens' => null];
    }

    /** Attempt Groq completion via OpenAI-compatible endpoint. */
    protected function attempt_groq_model($system, $userPrompt, $apiKey, $model, $timeout, $start, $comment_id, $json_flags) {
        if (!$apiKey) {
            return ['status' => 'error', 'message' => 'Missing Groq API key', 'raw' => null, 'tokens' => null];
        }

        $resp = wp_remote_post('https://api.groq.com/openai/v1/chat/completions', [
                'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode([
                        'model'       => $model,
                        'temperature' => 0,
                        'max_tokens'  => 80,
                        'messages'    => [
                                ['role' => 'system', 'content' => $system],
                                ['role' => 'user',   'content' => $userPrompt],
                        ],
                ]),
                'timeout' => $timeout,
        ]);

        if (is_wp_error($resp)) {
            return ['status' => 'error', 'message' => $resp->get_error_message(), 'raw' => null, 'tokens' => null];
        }

        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        $raw  = $body;

        if ($code == 429) {
            $until = $this->extract_retry_until($resp);
            if ($until) {
                set_transient(self::GROQ_BLOCK_TRANSIENT, $until, max(1, $until - time()));
            }
            return ['status' => 'error', 'message' => 'groq_rate_limited', 'raw' => $raw, 'tokens' => null];
        }

        if ($code >= 200 && $code < 300) {
            $json = json_decode($body, true);
            $tokens = intval($json['usage']['total_tokens'] ?? 0);
            $content = isset($json['choices'][0]['message']['content']) ? (string)$json['choices'][0]['message']['content'] : '';
            $parsed = $this->parse_model_output($content);
            $latency = intval((microtime(true) - $start) * 1000);
            $this->log($comment_id, $parsed['decision'], $parsed['confidence'], 'groq:'.$model, $tokens, $latency, $parsed['reasons'], $raw, null);
            return ['status' => 'success', 'payload' => ['decision'=>$parsed['decision'],'confidence'=>$parsed['confidence'],'reasons'=>$parsed['reasons']]];
        }

        return ['status' => 'error', 'message' => 'groq_http_'.$code, 'raw' => $raw, 'tokens' => null];
    }

    /** Parse JSON-ish model output, returning decision/confidence/reasons */
    protected function parse_model_output($content) {
        $content = trim((string)$content);
        if (preg_match('/\{.*\}/s', $content, $m)) {
            $content = $m[0];
        }
        $parsed = json_decode($content, true);
        if (is_array($parsed) && isset($parsed['label'])) {
            $decision = (strtolower(trim($parsed['label'])) === 'spam') ? 'spam' : 'valid';
            $confidence = isset($parsed['confidence']) ? floatval($parsed['confidence']) : 0.5;
            $reasons = is_array($parsed['reasons'] ?? null) ? array_slice(array_map('strval', $parsed['reasons']), 0, 6) : [];
            return compact('decision','confidence','reasons');
        }
        $low = $this->sd_mb_strtolower($content);
        if (strpos($low, 'spam') !== false && strpos($low, 'valid') === false) {
            return ['decision'=>'spam','confidence'=>0.85,'reasons'=>['fallback parse']];
        }
        return ['decision'=>'valid','confidence'=>0.55,'reasons'=>['fallback parse']];
    }

    /** Extract retry-until timestamp from HTTP headers */
    protected function extract_retry_until($resp) {
        $retryAfter = wp_remote_retrieve_header($resp, 'retry-after');
        $reset      = wp_remote_retrieve_header($resp, 'x-ratelimit-reset');
        $until = null;
        if ($retryAfter && is_numeric($retryAfter)) {
            $until = time() + (int)$retryAfter;
        } elseif ($reset && is_numeric($reset)) {
            $until = (strlen($reset) > 10) ? (int) round(((float)$reset)/1000) : (int)$reset;
        }
        return $until;
    }

    /** Logging helper */
    protected function log($comment_id, $decision, $confidence, $model, $tokens, $latency_ms, $reasons, $raw_response, $error) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $data = [];
        $formats = [];

        $data['created_at'] = current_time('mysql');
        $formats[] = '%s';

        if ($comment_id) {
            $data['comment_id'] = (int) $comment_id;
            $formats[] = '%d';
        }

        $data['decision'] = substr($decision, 0, 10);
        $formats[] = '%s';

        $data['confidence'] = (float) $confidence;
        $formats[] = '%f';

        $data['model'] = substr((string) $model, 0, 191);
        $formats[] = '%s';

        if ($tokens !== null) {
            $data['tokens'] = (int) $tokens;
            $formats[] = '%d';
        }

        if ($latency_ms !== null) {
            $data['latency_ms'] = (int) $latency_ms;
            $formats[] = '%d';
        }

        $data['reasons'] = $reasons ? maybe_serialize($reasons) : null;
        $formats[] = '%s';

        if ($raw_response === null || $raw_response === '') {
            $fallback_payload = [
                    'note'      => 'no raw response captured',
                    'decision'  => $decision,
                    'error'     => $error,
                    'model'     => $model,
                    'reasons'   => $reasons,
            ];
            $raw_response = wp_json_encode($fallback_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!$raw_response) {
                $raw_response = 'no raw response captured; error=' . (string) $error;
            }
        }

        $data['raw_response'] = $raw_response ? $this->sd_mb_substr($raw_response, 0, 64000) : null;
        $formats[] = '%s';

        $data['error'] = $error ? $this->sd_mb_substr($error, 0, 1000) : null;
        $formats[] = '%s';

        $wpdb->insert($table, $data, $formats);
    }

    /** Purge logs */
    public function purge_old_logs() {
        global $wpdb;
        $opts = wp_parse_args(get_option(self::OPT_KEY), self::defaults());
        $days = max(0, intval($opts['log_retention_days']));
        if ($days <= 0) return;
        $table = $wpdb->prefix . self::TABLE;
        $wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE created_at < (NOW() - INTERVAL %d DAY)", $days));
    }

    /** Logs table (latest 50) */
    public function render_logs_admin_page() {
        if (!current_user_can(self::CAP)) return;
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        // Filters
        $decision   = isset($_GET['decision']) ? sanitize_text_field((string) $_GET['decision']) : '';
        $has_error  = isset($_GET['has_error']) ? sanitize_text_field((string) $_GET['has_error']) : '';
        $model_q    = isset($_GET['model']) ? sanitize_text_field((string) $_GET['model']) : '';
        $comment_id = isset($_GET['comment_id']) ? intval($_GET['comment_id']) : 0;
        $date_start = isset($_GET['date_start']) ? sanitize_text_field((string) $_GET['date_start']) : '';
        $date_end   = isset($_GET['date_end']) ? sanitize_text_field((string) $_GET['date_end']) : '';

        // Validate dates (YYYY-MM-DD)
        if ($date_start && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_start)) $date_start = '';
        if ($date_end   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_end))   $date_end   = '';

        $per_page = isset($_GET['per_page']) ? max(10, min(200, intval($_GET['per_page']))) : 50;
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($paged - 1) * $per_page;

        $where = [];
        $params = [];
        if (in_array($decision, ['spam','valid'], true)) {
            $where[] = 'decision = %s';
            $params[] = $decision;
        }
        if ($has_error === '1') {
            $where[] = "(error IS NOT NULL AND error <> '')";
        } elseif ($has_error === '0') {
            $where[] = "(error IS NULL OR error = '')";
        }
        if ($model_q !== '') {
            $like = '%' . $wpdb->esc_like($model_q) . '%';
            $where[] = 'model LIKE %s';
            $params[] = $like;
        }
        if ($comment_id > 0) {
            $where[] = 'comment_id = %d';
            $params[] = $comment_id;
        }
        if ($date_start) {
            $where[] = 'created_at >= %s';
            $params[] = $date_start . ' 00:00:00';
        }
        if ($date_end) {
            $where[] = 'created_at <= %s';
            $params[] = $date_end . ' 23:59:59';
        }

        $base = "FROM `{$table}` WHERE 1=1" . ($where ? (' AND ' . implode(' AND ', $where)) : '');
        $count_sql = 'SELECT COUNT(*) ' . $base;
        $total = (int) ($params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql));

        $query_sql = 'SELECT * ' . $base . ' ORDER BY id DESC LIMIT %d OFFSET %d';
        $params2 = $params;
        $params2[] = $per_page;
        $params2[] = $offset;
        $rows = $wpdb->get_results($wpdb->prepare($query_sql, $params2), ARRAY_A);

        echo '<div class="wrap">';
        echo '<h1>'.esc_html__('Silver Duck Logs', 'silver-duck').'</h1>';
        add_thickbox();

        // Filter form
        echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'" class="sd-log-filters">';
        echo '<input type="hidden" name="page" value="silver-duck-logs" />';
        echo '<fieldset style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin:12px 0;">';
        // Decision
        echo '<label>'.esc_html__('Decision', 'silver-duck').'<br/>';
        echo '<select name="decision">';
        $opts_d = ['' => __('All','silver-duck'), 'spam' => 'spam', 'valid' => 'valid'];
        foreach ($opts_d as $val=>$lab) echo '<option value="'.esc_attr($val).'" '.selected($decision, $val, false).'>'.esc_html($lab).'</option>';
        echo '</select></label>';
        // Error
        echo '<label>'.esc_html__('Errors', 'silver-duck').'<br/>';
        echo '<select name="has_error">';
        $opts_e = ['' => __('All','silver-duck'), '1' => __('Errors only','silver-duck'), '0' => __('No errors','silver-duck')];
        foreach ($opts_e as $val=>$lab) echo '<option value="'.esc_attr($val).'" '.selected($has_error, $val, false).'>'.esc_html($lab).'</option>';
        echo '</select></label>';
        // Model
        echo '<label>'.esc_html__('Model contains', 'silver-duck').'<br/>';
        echo '<input type="text" name="model" value="'.esc_attr($model_q).'" class="regular-text" />';
        echo '</label>';
        // Comment ID
        echo '<label>'.esc_html__('Comment ID', 'silver-duck').'<br/>';
        echo '<input type="number" name="comment_id" value="'.esc_attr($comment_id).'" class="small-text" />';
        echo '</label>';
        // Date range
        echo '<label>'.esc_html__('From', 'silver-duck').'<br/>';
        echo '<input type="date" name="date_start" value="'.esc_attr($date_start).'" />';
        echo '</label>';
        echo '<label>'.esc_html__('To', 'silver-duck').'<br/>';
        echo '<input type="date" name="date_end" value="'.esc_attr($date_end).'" />';
        echo '</label>';
        echo '<label>'.esc_html__('Rows per page', 'silver-duck').'<br/>';
        echo '<input type="number" name="per_page" min="10" max="200" step="10" value="'.esc_attr($per_page).'" class="small-text" />';
        echo '</label>';
        echo '<button class="button button-primary" type="submit">'.esc_html__('Filter','silver-duck').'</button> ';
        echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=silver-duck-logs')).'">'.esc_html__('Reset','silver-duck').'</a>';
        echo '</fieldset>';
        echo '</form>';

        if (!$rows) {
            echo '<p>'.esc_html__('No logs found.', 'silver-duck').'</p>';
            echo '</div>';
            return;
        }

        echo '<p class="description">'.esc_html__('Browse classification logs.', 'silver-duck').'</p>';
        $modalResponses = [];
        echo '<table class="widefat striped"><thead><tr>';
        $cols = ['created_at'=>'Time','comment_id'=>'Comment','decision'=>'Decision','confidence'=>'Conf.','latency_ms'=>'Latency','model'=>'Model','tokens'=>'Tokens','error'=>'Error','response'=>'Response'];
        foreach ($cols as $k=>$label) echo '<th>'.esc_html__($label,'silver-duck').'</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr>';
            echo '<td>'.esc_html($r['created_at']).'</td>';
            echo '<td>'.($r['comment_id'] ? '<a href="'.esc_url(admin_url('comment.php?action=editcomment&c='.(int)$r['comment_id'])).'">'.(int)$r['comment_id'].'</a>' : '-').'</td>';
            echo '<td>'.esc_html($r['decision']).'</td>';
            echo '<td>'.esc_html(number_format_i18n((float)$r['confidence'],2)).'</td>';
            echo '<td>'.esc_html((int)$r['latency_ms']).' ms</td>';
            echo '<td>'.esc_html($r['model']).'</td>';
            echo '<td>'.esc_html((int)$r['tokens']).'</td>';
            echo '<td>'.($r['error'] ? '<code>'.esc_html($r['error']).'</code>' : '').'</td>';
            if (!empty($r['raw_response'])) {
                $modal_id = 'sd-log-response-' . (int)$r['id'];
                $decoded = json_decode($r['raw_response'], true);
                $formatted = $decoded !== null ? wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $r['raw_response'];
                $preview = $this->sd_mb_substr($formatted, 0, 80);
                $modalResponses[] = ['id' => $modal_id, 'content' => $formatted];
                $link = '#TB_inline?width=700&height=550&inlineId=' . $modal_id;
                $needs_ellipsis = $this->sd_mb_strlen($formatted) > 80;
                echo '<td><a class="thickbox" href="'.esc_url($link).'">'.esc_html__('View', 'silver-duck').'</a><br/><code>'.esc_html($preview).($needs_ellipsis ? '…' : '').'</code></td>';
            } else {
                echo '<td>-</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';

        if (!empty($modalResponses)) {
            foreach ($modalResponses as $modal) {
                echo '<div id="'.esc_attr($modal['id']).'" style="display:none;">';
                echo '<div style="max-height:70vh;overflow:auto;">';
                echo '<pre class="sd-json" style="white-space:pre-wrap;word-break:break-word;">'.esc_html($modal['content']).'</pre>';
                echo '</div></div>';
            }
            $style = <<<'STYLE'
<style>
    .sd-json .sd-json-key{color:#c7254e;font-weight:600;}
    .sd-json .sd-json-string{color:#008000;}
    .sd-json .sd-json-number{color:#1d6fdc;}
    .sd-json .sd-json-boolean{color:#aa0d91;}
    .sd-json .sd-json-null{color:#777;}
</style>
STYLE;
            $script = <<<'SCRIPT'
<script>
(function(){
    function syntaxHighlight(json){
        json = json.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        return json.replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:\s*)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, function(match){
            var cls = 'sd-json-number';
            if (/^"/.test(match)) {
                cls = /:$/.test(match) ? 'sd-json-key' : 'sd-json-string';
            } else if (/true|false/.test(match)) {
                cls = 'sd-json-boolean';
            } else if (/null/.test(match)) {
                cls = 'sd-json-null';
            }
            return '<span class="'+cls+'">'+match+'</span>';
        });
    }
    function formatElement(el){
        if (el.dataset.sdJsonProcessed) return;
        var text = el.textContent || '';
        if (!text.trim()) return;
        try {
            var parsed = JSON.parse(text);
            text = JSON.stringify(parsed, null, 2);
        } catch (e) {}
        el.innerHTML = syntaxHighlight(text);
        el.dataset.sdJsonProcessed = '1';
    }
    document.addEventListener('DOMContentLoaded', function(){
        document.querySelectorAll('.sd-json').forEach(formatElement);
        document.addEventListener('tb_show', function(){
            document.querySelectorAll('.sd-json').forEach(formatElement);
        });
    });
})();
</script>
SCRIPT;
            echo $style . $script;
        }

        $total_pages = max(1, (int) ceil($total / $per_page));
        if ($total_pages > 1) {
            $base_url = add_query_arg(array_filter([
                'page'        => 'silver-duck-logs',
                'decision'    => $decision ?: null,
                'has_error'   => ($has_error !== '') ? $has_error : null,
                'model'       => $model_q ?: null,
                'comment_id'  => $comment_id ?: null,
                'date_start'  => $date_start ?: null,
                'date_end'    => $date_end ?: null,
                'per_page'    => $per_page !== 50 ? $per_page : null,
            ], function($v){ return $v !== null; }), admin_url('admin.php'));

            $current  = $paged;
            $prev     = max(1, $current - 1);
            $next     = min($total_pages, $current + 1);
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo '<span class="displaying-num">'.esc_html(sprintf(_n('%s item', '%s items', $total, 'silver-duck'), number_format_i18n($total))).'</span> ';
            echo '<span class="pagination-links">';
            if ($current > 1) {
                echo '<a class="button" href="'.esc_url(add_query_arg('paged', 1, $base_url)).'">&laquo;</a> ';
                echo '<a class="button" href="'.esc_url(add_query_arg('paged', $prev, $base_url)).'">&lsaquo;</a> ';
            }
            echo '<span class="tablenav-paging-text">'.esc_html(sprintf(__('Page %1$s of %2$s','silver-duck'), number_format_i18n($current), number_format_i18n($total_pages))).'</span>';
            if ($current < $total_pages) {
                echo ' <a class="button" href="'.esc_url(add_query_arg('paged', $next, $base_url)).'">&rsaquo;</a>';
                echo ' <a class="button" href="'.esc_url(add_query_arg('paged', $total_pages, $base_url)).'">&raquo;</a>';
            }
            echo '</span>';
            echo '</div></div>';
        }

        echo '</div>';
    }

    /* ------------------------------- helpers ------------------------------- */
    /** mb_strtolower wrapper that falls back to strtolower if mbstring is unavailable */
    protected function sd_mb_strtolower($text) {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower((string)$text, 'UTF-8');
        }
        return strtolower((string)$text);
    }

    /** mb_substr wrapper with fallback */
    protected function sd_mb_substr($text, $start, $length = null) {
        if (function_exists('mb_substr')) {
            return $length === null ? mb_substr((string)$text, (int)$start) : mb_substr((string)$text, (int)$start, (int)$length);
        }
        return $length === null ? substr((string)$text, (int)$start) : substr((string)$text, (int)$start, (int)$length);
    }

    /** mb_strlen wrapper with fallback */
    protected function sd_mb_strlen($text) {
        if (function_exists('mb_strlen')) {
            return mb_strlen((string)$text, 'UTF-8');
        }
        return strlen((string)$text);
    }

    /** mb_stripos wrapper with fallback */
    protected function sd_mb_stripos($haystack, $needle) {
        if (function_exists('mb_stripos')) {
            return mb_stripos((string)$haystack, (string)$needle, 0, 'UTF-8');
        }
        return stripos((string)$haystack, (string)$needle);
    }

    /** Build a safe transient key for per-model backoff */
    protected function model_block_key($model) {
        return 'silver_duck_block_until_' . substr(md5((string)$model), 0, 12);
    }

    /** Minimal disposable email domain set (extend via settings with your own blacklist too) */
    protected function is_disposable_domain($domain) {
        static $set = null;
        if ($set === null) {
            $set = array_flip([
                    'mailinator.com','tempmail.com','guerrillamail.com','10minutemail.com','10minemail.com',
                    'fakeinbox.com','yopmail.com','trashmail.com','getnada.com','mohmal.com',
                    'sharklasers.com','dispostable.com','mail-temporaire.fr','mintemail.com','spambog.com',
                    'maildrop.cc','throwawaymail.com','linshi-email.com','temporary-mail.net','anonaddy.com',
            ]);
        }
        return isset($set[$domain]);
    }

    /** Create a compact representation of the post for the LLM */
    protected function make_post_context_for_llm($post, $commentText, $limitChars = 2000) {
        // Title
        $title = trim(get_the_title($post));
        if ($title === '') $title = '(untitled)';

        // Raw content → plain text
        $content = (string) $post->post_content;
        $content = wp_strip_all_tags($content);
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);

        if ($content === '') {
            return "Title: {$title}";
        }

        // Basic keyword set from comment (lowercase, no stopwords)
        $keywords = $this->keywords_from_comment($commentText);

        // Key parts: intro, a snippet around first matching keyword (if any), outro
        $intro = $this->first_words($content, 120);
        $spot  = $this->window_around_keywords($content, $keywords, 700); // ~700 chars
        $outro = $this->last_words($content, 60);

        $parts = [];
        $parts[] = "Title: {$title}";
        $parts[] = "Intro: {$intro}";
        if ($spot !== '') $parts[] = "Relevant snippet: {$spot}";
        $parts[] = "Conclusion: {$outro}";

        $ctx = implode("\n", $parts);
        if ($this->sd_mb_strlen($ctx) > $limitChars) {
            $ctx = $this->sd_mb_substr($ctx, 0, $limitChars);
        }
        return $ctx;
    }

    /** Extract simple keywords from a comment (lowercase, stopword-pruned) */
    protected function keywords_from_comment($text, $max = 12) {
        $t = function_exists('mb_strtolower') ? mb_strtolower((string)$text, 'UTF-8') : strtolower((string)$text);
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $t, -1, PREG_SPLIT_NO_EMPTY);
        $stop = array_flip([
                'the','a','an','and','or','but','if','then','else','when','at','by','for','in','of','on','to','with','from','is','it','this','that','these','those','i','you','he','she','we','they','me','him','her','us','them','are','was','were','be','been','am','as','my','our','your','their'
        ]);
        $freq = [];
        foreach ($tokens as $tk) {
            if (isset($stop[$tk]) || strlen($tk) < 3) continue;
            $freq[$tk] = isset($freq[$tk]) ? $freq[$tk] + 1 : 1;
        }
        arsort($freq);
        return array_slice(array_keys($freq), 0, $max);
    }

    /** First N words (approx) */
    protected function first_words($text, $n) {
        $parts = preg_split('/\s+/', (string)$text);
        return trim(implode(' ', array_slice($parts, 0, $n)));
    }

    /** Last N words (approx) */
    protected function last_words($text, $n) {
        $parts = preg_split('/\s+/', (string)$text);
        $len = count($parts);
        return trim(implode(' ', array_slice($parts, max(0, $len - $n))));
    }

    /** Take a ~window of content around the first matching keyword */
    protected function window_around_keywords($text, $keywords, $win = 700) {
        if (empty($keywords)) return '';
        $low = $this->sd_mb_strtolower((string)$text);
        $pos = -1;
        foreach ($keywords as $k) {
            $p = $this->sd_mb_stripos($low, $this->sd_mb_strtolower($k));
            if ($p !== false) { $pos = $p; break; }
        }
        if ($pos === -1) return '';
        $half = (int) floor($win / 2);
        $start = max(0, $pos - $half);
        $snippet = $this->sd_mb_substr($text, $start, $win);
        // Trim to word boundaries-ish
        $snippet = preg_replace('/^\S*\s/', '', $snippet); // drop partial first word
        $snippet = preg_replace('/\s\S*$/', '', $snippet); // drop partial last word
        return trim($snippet);
    }

    /** Convert multi-line textarea to a list of trimmed, non-empty lines */
    protected function lines_to_list($text) {
        $lines = preg_split("/[\r\n]+/", (string)$text);
        $out = [];
        foreach ($lines as $ln) {
            $ln = trim($ln);
            if ($ln !== '') $out[] = $ln;
        }
        return $out;
    }

    /** Extract domain from an email, lowercased */
    protected function email_domain($email) {
        $email = trim((string)$email);
        if ($email === '' || strpos($email, '@') === false) return '';
        $parts = explode('@', $email);
        return $this->sd_mb_strtolower(trim(end($parts)));
    }

    /** Extract host from a URL, tolerant to missing scheme */
    protected function url_domain($url) {
        $u = trim((string)$url);
        if ($u === '') return '';
        $host = parse_url($u, PHP_URL_HOST);
        if (!$host) {
            $host = parse_url('http://'.$u, PHP_URL_HOST);
        }
        return $host ? $this->sd_mb_strtolower($host) : '';
    }

    /** Normalize a domain for comparisons */
    protected function normalize_domain($domain) {
        $d = $this->sd_mb_strtolower(trim((string)$domain));
        if ($d === '') return '';
        // strip trailing dot and leading www.
        $d = rtrim($d, '.');
        if (strpos($d, 'www.') === 0) $d = substr($d, 4);
        return $d;
    }
}

new Silver_Duck();

// Parity hook (no-op)
add_action('comment_post', function($comment_id, $approved, $commentdata){}, 10, 3);
