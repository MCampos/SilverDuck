<?php
/**
 * Plugin Name: Silver Duck (OpenRouter - Llama 3.2)
 * Description: Classifies WordPress comments as spam/ham using OpenRouter Llama models. Includes admin settings, logs, heuristics (links/blacklists), author field checks (name/email/url), optional blog-post context for relevance, bulk recheck, and rate-limit backoff.
 * Version: 1.2.0
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
        ];
    }

    /** Activation */
    public function activate() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE $table (
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

        $opts = get_option(self::OPT_KEY);
        if (!$opts) add_option(self::OPT_KEY, self::defaults());
        else update_option(self::OPT_KEY, wp_parse_args($opts, self::defaults()));

        if (!wp_next_scheduled(self::CRON_HOOK_PURGE)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK_PURGE);
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK_PURGE);
    }

    /** Admin menu */
    public function admin_menu() {
        add_options_page(
                __('Silver Duck', 'silver-duck'),
                __('Silver Duck', 'silver-duck'),
                self::CAP,
                'silver-duck',
                [$this, 'render_settings_page']
        );
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
                ['api_key', 'password', __('OpenRouter API Key', 'silver-duck')],
                ['model', 'text', __('Model', 'silver-duck')],
                ['confidence', 'number', __('Spam threshold (0–1)', 'silver-duck')],
                ['auto_action', 'select', __('When labeled spam', 'silver-duck')],
                ['timeout', 'number', __('API timeout (seconds)', 'silver-duck')],
                ['run_for_logged_in', 'checkbox', __('Also classify comments from logged-in users', 'silver-duck')],
        ];
        foreach ($core as $f) add_settings_field($f[0], $f[2], [$this, 'render_field'], 'silver-duck', 'sd_core', ['key' => $f[0], 'type' => $f[1]]);

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
    }

    /** Sanitize options */
    public function sanitize_options($input) {
        $d = self::defaults();
        return [
                'enabled'               => empty($input['enabled']) ? 0 : 1,
                'api_key'               => isset($input['api_key']) ? trim($input['api_key']) : '',
                'model'                 => isset($input['model']) ? sanitize_text_field($input['model']) : $d['model'],
                'confidence'            => isset($input['confidence']) ? min(1, max(0, floatval($input['confidence']))) : $d['confidence'],
                'auto_action'           => in_array($input['auto_action'] ?? 'spam', ['spam','hold'], true) ? $input['auto_action'] : 'spam',
                'timeout'               => max(3, intval($input['timeout'] ?? $d['timeout'])),

                'max_links'             => max(0, intval($input['max_links'] ?? $d['max_links'])),
                'blacklist'             => isset($input['blacklist']) ? trim(wp_kses_post($input['blacklist'])) : '',

                'check_author_fields'   => empty($input['check_author_fields']) ? 0 : 1,
                'disposable_email_check'=> empty($input['disposable_email_check']) ? 0 : 1,
                'email_domain_blacklist'=> isset($input['email_domain_blacklist']) ? trim(wp_kses_post($input['email_domain_blacklist'])) : '',
                'url_domain_blacklist'  => isset($input['url_domain_blacklist']) ? trim(wp_kses_post($input['url_domain_blacklist'])) : '',
                'author_name_blacklist' => isset($input['author_name_blacklist']) ? trim(wp_kses_post($input['author_name_blacklist'])) : '',

                'include_post_context'  => empty($input['include_post_context']) ? 0 : 1,
                'post_context_chars'    => max(200, min(8000, intval($input['post_context_chars'] ?? 2000))),

                'log_retention_days'    => max(0, intval($input['log_retention_days'] ?? $d['log_retention_days'])),
                'run_for_logged_in'     => empty($input['run_for_logged_in']) ? 0 : 1,
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
            printf('<input type="password" class="regular-text" name="%1$s[%2$s]" value="%3$s" autocomplete="off" />',
                    esc_attr(self::OPT_KEY), esc_attr($k), esc_attr($opts[$k])
            );
            echo '<p class="description">'.esc_html__('Get an API key at openrouter.ai and keep it secret.', 'silver-duck').'</p>';
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

            <hr />

            <h2><?php esc_html_e('Tools', 'silver-duck');?></h2>
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
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>">
                        <?php wp_nonce_field(self::NONCE); ?>
                        <input type="hidden" name="action" value="silver_duck_recheck_pending" />
                        <button class="button"><?php esc_html_e('Run Now', 'silver-duck');?></button>
                    </form>

                    <h3 style="margin-top:24px;"><?php esc_html_e('Purge Logs', 'silver-duck');?></h3>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>">
                        <?php wp_nonce_field(self::NONCE); ?>
                        <input type="hidden" name="action" value="silver_duck_purge_logs" />
                        <button class="button"><?php esc_html_e('Purge Older Than Retention', 'silver-duck');?></button>
                    </form>
                </div>
            </div>

            <hr />
            <h2><?php esc_html_e('Recent Classifications', 'silver-duck');?></h2>
            <?php $this->render_logs_table(); ?>
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
        wp_safe_redirect(admin_url('options-general.php?page=silver-duck&' . $msg));
        exit;
    }

    /** Admin: purge logs */
    public function handle_purge_logs_action() {
        if (!current_user_can(self::CAP)) wp_die('Unauthorized', 403);
        check_admin_referer(self::NONCE);
        $this->purge_old_logs();
        wp_safe_redirect(admin_url('options-general.php?page=silver-duck&purged=1'));
        exit;
    }

    /** Admin: recheck pending (conservative; only logs decisions) */
    public function handle_recheck_pending_action() {
        if (!current_user_can(self::CAP)) wp_die('Unauthorized', 403);
        check_admin_referer(self::NONCE);

        $count = 0;
        $pending = get_comments(['status' => 'hold', 'number' => 200, 'orderby' => 'comment_date_gmt', 'order' => 'ASC']);
        foreach ($pending as $c) {
            $this->evaluate_comment_for_action($c->comment_ID, (array)$c, false);
            $count++;
        }
        wp_safe_redirect(admin_url('options-general.php?page=silver-duck&rechecked=' . intval($count)));
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

        // --- Heuristic: link count
        if (!$bypass_heuristics_for_bulk && $opts['max_links'] > 0) {
            $linkCount = preg_match_all('#https?://#i', $content, $m);
            if ($linkCount > $opts['max_links']) {
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

        if ($decision === 'spam' && $conf >= $threshold) return $opts['auto_action'];
        return 'none';
    }

    /** LLM call with 429 handling + fallback; includes metadata + post context in the prompt */
    protected function classify_text($text, $comment_id=null, $is_test=false, $meta = []) {
        $opts    = wp_parse_args(get_option(self::OPT_KEY), self::defaults());
        $apiKey  = $opts['api_key'];
        $timeout = max(3, intval($opts['timeout']));

        // Rate-limit backoff
        $blockUntil = (int) get_transient(self::BLOCK_TRANSIENT);
        if ($blockUntil && $blockUntil > time()) {
            $untilStr = gmdate('c', $blockUntil);
            $decision='valid'; $confidence=0.50; $reasons=['rate-limited until '.$untilStr]; $error='rate_limited';
            $this->log($comment_id, $decision, $confidence, $opts['model'], null, null, $reasons, null, $error);
            return compact('decision','confidence','reasons','error');
        }

        $start = microtime(true);
        $error = null; $decision='valid'; $confidence=0.50; $reasons=[]; $raw=null; $tokens=null;

        if (!$apiKey) {
            $error = 'Missing OpenRouter API key.';
            $this->log($comment_id, 'valid', $confidence, $opts['model'], $tokens, null, $reasons, $raw, $error);
            return compact('decision','confidence','reasons','error');
        }

        // Model candidates (saved model first)
        $candidates = array_values(array_unique(array_filter([
                $opts['model'],
                'meta-llama/llama-4-maverick:free',
                'meta-llama/llama-3.2-3b-instruct:free',
        ])));

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

        foreach ($candidates as $model) {
            $payload = [
                    'model'       => $model,
                    'temperature' => 0,
                    'max_tokens'  => 48,
                    'messages'    => [
                            ['role' => 'system', 'content' => $system],
                            ['role' => 'user',   'content' => $userPrompt],
                    ],
            ];

            $args = [
                    'headers' => [
                            'Authorization' => 'Bearer ' . $apiKey,
                            'Content-Type'  => 'application/json',
                    ],
                    'body'    => wp_json_encode($payload),
                    'timeout' => $timeout,
            ];

            $resp = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', $args);
            if (is_wp_error($resp)) {
                $error = $resp->get_error_message();
                continue;
            }

            $code = wp_remote_retrieve_response_code($resp);
            $body = wp_remote_retrieve_body($resp);
            $raw  = $body;

            if ($code == 429) {
                $retryAfter = wp_remote_retrieve_header($resp, 'retry-after');
                $reset      = wp_remote_retrieve_header($resp, 'x-ratelimit-reset');
                $until = null;
                if ($retryAfter && is_numeric($retryAfter)) {
                    $until = time() + (int)$retryAfter;
                } elseif ($reset && is_numeric($reset)) {
                    $until = (strlen($reset) > 10) ? (int) round(((float)$reset)/1000) : (int)$reset;
                }
                if ($until && $until > time()) {
                    set_transient(self::BLOCK_TRANSIENT, $until, max(1, $until - time()));
                }
                $error = 'rate_limited';
                continue; // try next model
            }

            if ($code >= 200 && $code < 300) {
                $json = json_decode($body, true);
                $content = $json['choices'][0]['message']['content'] ?? '';
                $tokens  = intval($json['usage']['total_tokens'] ?? 0);

                $content = trim($content);
                if (preg_match('/\{.*\}/s', $content, $m)) $content = $m[0];
                $parsed = json_decode($content, true);

                if (is_array($parsed) && isset($parsed['label'])) {
                    $decision   = (strtolower(trim($parsed['label'])) === 'spam') ? 'spam' : 'valid';
                    $confidence = isset($parsed['confidence']) ? floatval($parsed['confidence']) : 0.5;
                    $reasons    = is_array($parsed['reasons'] ?? null) ? array_slice(array_map('strval', $parsed['reasons']), 0, 6) : [];
                } else {
                    // fallback parse
                    $low = strtolower($content);
                    if (strpos($low, 'spam') !== false && strpos($low, 'valid') === false) {
                        $decision = 'spam'; $confidence = 0.85; $reasons = ['fallback parse'];
                    } else {
                        $decision = 'valid'; $confidence = 0.55; $reasons = ['fallback parse'];
                    }
                }

                $latency = intval((microtime(true) - $start) * 1000);
                $this->log($comment_id, $decision, $confidence, $model, $tokens, $latency, $reasons, $raw, null);
                return compact('decision','confidence','reasons','error');
            }

            // Other non-2xx: try next
            $error = 'HTTP '.$code.' - '.substr($body, 0, 300);
        }

        // Exhausted candidates → fail-safe
        $latency = intval((microtime(true) - $start) * 1000);
        $this->log($comment_id, 'valid', 0.5, $opts['model'], $tokens, $latency, ['llm unavailable: '.$error], $raw, $error ?: 'unavailable');
        return ['decision'=>'valid','confidence'=>0.5,'reasons'=>['llm unavailable'],'error'=>$error ?: 'unavailable'];
    }

    /** Logging helper */
    protected function log($comment_id, $decision, $confidence, $model, $tokens, $latency_ms, $reasons, $raw_response, $error) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $wpdb->insert($table, [
                'created_at'   => current_time('mysql'),
                'comment_id'   => $comment_id ? intval($comment_id) : null,
                'decision'     => substr($decision, 0, 10),
                'confidence'   => $confidence,
                'model'        => substr((string)$model, 0, 191),
                'tokens'       => $tokens,
                'latency_ms'   => $latency_ms,
                'reasons'      => $reasons ? maybe_serialize($reasons) : null,
                'raw_response' => $raw_response ? wp_json_encode(mb_substr($raw_response, 0, 5000)) : null,
                'error'        => $error ? mb_substr($error, 0, 1000) : null,
        ], [
                '%s','%d','%s','%f','%s','%d','%d','%s','%s','%s'
        ]);
    }

    /** Purge logs */
    public function purge_old_logs() {
        global $wpdb;
        $opts = wp_parse_args(get_option(self::OPT_KEY), self::defaults());
        $days = max(0, intval($opts['log_retention_days']));
        if ($days <= 0) return;
        $table = $wpdb->prefix . self::TABLE;
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE created_at < (NOW() - INTERVAL %d DAY)", $days));
    }

    /** Logs table (latest 50) */
    protected function render_logs_table() {
        if (!current_user_can(self::CAP)) return;
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT 50", ARRAY_A);
        if (!$rows) {
            echo '<p>'.esc_html__('No logs yet.', 'silver-duck').'</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr>';
        $cols = ['created_at'=>'Time','comment_id'=>'Comment','decision'=>'Decision','confidence'=>'Conf.','latency_ms'=>'Latency','model'=>'Model','tokens'=>'Tokens','error'=>'Error'];
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
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /* ------------------------------- helpers ------------------------------- */

    /** Split multi-line text into trimmed array (skip empties) */
    protected function lines_to_list($text) {
        $arr = array_map('trim', preg_split('/\R+/', (string)$text));
        return array_values(array_filter($arr, function($x){ return $x !== ''; }));
    }

    /** Extract domain from email */
    protected function email_domain($email) {
        $at = strrpos($email, '@');
        if ($at === false) return '';
        return substr($email, $at+1);
    }

    /** Extract domain from URL */
    protected function url_domain($url) {
        $p = wp_parse_url($url);
        return isset($p['host']) ? $p['host'] : '';
    }

    /** Normalize domain (lowercase, strip leading www.) */
    protected function normalize_domain($domain) {
        $d = strtolower(trim($domain));
        if (strpos($d, 'www.') === 0) $d = substr($d, 4);
        return $d;
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
        if (mb_strlen($ctx) > $limitChars) {
            $ctx = mb_substr($ctx, 0, $limitChars);
        }
        return $ctx;
    }

    /** Extract simple keywords from a comment (lowercase, stopword-pruned) */
    protected function keywords_from_comment($text, $max = 12) {
        $t = strtolower((string)$text);
        $tokens = preg_split('/[^a-z0-9]+/i', $t, -1, PREG_SPLIT_NO_EMPTY);
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
        $low = strtolower((string)$text);
        $pos = -1;
        foreach ($keywords as $k) {
            $p = mb_stripos($low, strtolower($k));
            if ($p !== false) { $pos = $p; break; }
        }
        if ($pos === -1) return '';
        $half = (int) floor($win / 2);
        $start = max(0, $pos - $half);
        $snippet = mb_substr($text, $start, $win);
        // Trim to word boundaries-ish
        $snippet = preg_replace('/^\S*\s/', '', $snippet); // drop partial first word
        $snippet = preg_replace('/\s\S*$/', '', $snippet); // drop partial last word
        return trim($snippet);
    }
}

new Silver_Duck();

// Parity hook (no-op)
add_action('comment_post', function($comment_id, $approved, $commentdata){}, 10, 3);
