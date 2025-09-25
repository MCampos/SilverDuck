<?php
/**
 * Plugin Name: Silver Duck (OpenRouter - Llama 3.2)
 * Description: Classifies WordPress comments as spam/ham using OpenRouter’s Llama 3.2 11B Free. Includes admin settings, logs, and bulk recheck.
 * Version: 1.0.7
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

    public function __construct() {
        // Install/Uninstall hooks
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        // Admin UI
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        // Admin actions (test, purge, recheck)
        add_action('admin_post_silver_duck_test', [$this, 'handle_test_action']);
        add_action('admin_post_silver_duck_purge_logs', [$this, 'handle_purge_logs_action']);
        add_action('admin_post_silver_duck_recheck_pending', [$this, 'handle_recheck_pending_action']);

        // Comment pipeline
        add_filter('pre_comment_approved', [$this, 'filter_pre_comment_approved'], 9, 2);
        add_filter('preprocess_comment', [$this, 'maybe_preprocess_comment'], 9);

        // Cron: purge logs
        add_action(self::CRON_HOOK_PURGE, [$this, 'purge_old_logs']);
    }

    /** Default options */
    public static function defaults() {
        return [
            'enabled'             => 1,
            'api_key'             => '',
            'model'      => 'meta-llama/llama-3.2-3b-instruct:free',
            'confidence'          => 0.85,
            'auto_action'         => 'spam', // spam|hold
            'timeout'             => 15,
            'max_links'           => 2,       // heuristic: auto spam if > N links
            'blacklist'           => "",      // one domain or phrase per line
            'log_retention_days'  => 30,
            'run_for_logged_in'   => 0,
        ];
    }

    /** Activation: create table, set defaults, schedule cron */
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

        // Initialize options
        $opts = get_option(self::OPT_KEY);
        if (!$opts) {
            add_option(self::OPT_KEY, self::defaults());
        } else {
            update_option(self::OPT_KEY, wp_parse_args($opts, self::defaults()));
        }

        // Daily purge
        if (!wp_next_scheduled(self::CRON_HOOK_PURGE)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK_PURGE);
        }
    }

    /** Deactivation: clear cron */
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

        add_settings_section('sd_core', __('Core Settings', 'silver-duck'), '__return_false', 'silver-duck');

        $fields = [
            ['enabled', 'checkbox', __('Enable classifier', 'silver-duck')],
            ['api_key', 'password', __('OpenRouter API Key', 'silver-duck')],
            ['model', 'text', __('Model', 'silver-duck')],
            ['confidence', 'number', __('Spam threshold (0–1)', 'silver-duck')],
            ['auto_action', 'select', __('When labeled spam', 'silver-duck')],
            ['timeout', 'number', __('API timeout (seconds)', 'silver-duck')],
            ['run_for_logged_in', 'checkbox', __('Also classify comments from logged-in users', 'silver-duck')],
        ];

        foreach ($fields as $f) {
            add_settings_field($f[0], $f[2], [$this, 'render_field'], 'silver-duck', 'sd_core', ['key' => $f[0], 'type' => $f[1]]);
        }

        add_settings_section('sd_rules', __('Light Heuristics', 'silver-duck'), function () {
            echo '<p class="description">'.esc_html__('Basic pre-checks to reduce API calls & latency.', 'silver-duck').'</p>';
        }, 'silver-duck');

        add_settings_field('max_links', __('Auto-mark spam if links > N', 'silver-duck'), [$this, 'render_field'], 'silver-duck', 'sd_rules', ['key' => 'max_links', 'type' => 'number']);
        add_settings_field('blacklist', __('Blacklist (one per line)', 'silver-duck'), [$this, 'render_field'], 'silver-duck', 'sd_rules', ['key' => 'blacklist', 'type' => 'textarea']);

        add_settings_section('sd_maintenance', __('Maintenance', 'silver-duck'), '__return_false', 'silver-duck');
        add_settings_field('log_retention_days', __('Log retention days', 'silver-duck'), [$this, 'render_field'], 'silver-duck', 'sd_maintenance', ['key' => 'log_retention_days', 'type' => 'number']);
    }

    /** Sanitize options */
    public function sanitize_options($input) {
        $defaults = self::defaults();
        $out = [
            'enabled'             => empty($input['enabled']) ? 0 : 1,
            'api_key'             => isset($input['api_key']) ? trim($input['api_key']) : '',
            'model'               => isset($input['model']) ? sanitize_text_field($input['model']) : $defaults['model'],
            'confidence'          => isset($input['confidence']) ? min(1, max(0, floatval($input['confidence']))) : $defaults['confidence'],
            'auto_action'         => in_array($input['auto_action'] ?? 'spam', ['spam','hold'], true) ? $input['auto_action'] : 'spam',
            'timeout'             => max(3, intval($input['timeout'] ?? $defaults['timeout'])),
            'max_links'           => max(0, intval($input['max_links'] ?? $defaults['max_links'])),
            'blacklist'           => isset($input['blacklist']) ? trim(wp_kses_post($input['blacklist'])) : '',
            'log_retention_days'  => max(0, intval($input['log_retention_days'] ?? $defaults['log_retention_days'])),
            'run_for_logged_in'   => empty($input['run_for_logged_in']) ? 0 : 1,
        ];
        return $out;
    }

    /** Render a single field */
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

        // default text input
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
                <!-- Test console -->
                <div style="flex:1;min-width:380px;">
                    <h3><?php esc_html_e('Test a Comment', 'silver-duck');?></h3>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>">
                        <?php wp_nonce_field(self::NONCE); ?>
                        <input type="hidden" name="action" value="silver_duck_test" />
                        <textarea name="comment_body" class="large-text code" rows="6" placeholder="<?php esc_attr_e('Paste a sample comment…','silver-duck');?>"></textarea>
                        <p><button class="button button-primary"><?php esc_html_e('Classify', 'silver-duck');?></button></p>
                    </form>
                </div>

                <!-- Bulk recheck -->
                <div style="flex:1;min-width:320px;">
                    <h3><?php esc_html_e('Recheck Pending Comments', 'silver-duck');?></h3>
                    <p class="description"><?php esc_html_e('Runs the classifier for all comments awaiting moderation.', 'silver-duck');?></p>
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
        $res = $this->classify_text($text, null, true);
        $msg = '';
        if ($res['error']) {
            $msg = 'error=' . rawurlencode($res['error']);
        } else {
            $msg = 'decision=' . rawurlencode($res['decision']) . '&conf=' . rawurlencode($res['confidence']);
        }
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

    /** Admin: recheck pending */
    public function handle_recheck_pending_action() {
        if (!current_user_can(self::CAP)) wp_die('Unauthorized', 403);
        check_admin_referer(self::NONCE);

        $count = 0;
        $pending = get_comments(['status' => 'hold', 'number' => 200, 'orderby' => 'comment_date_gmt', 'order' => 'ASC']);
        foreach ($pending as $c) {
            $this->evaluate_comment_for_action($c->comment_ID, (array)$c, true);
            $count++;
        }
        wp_safe_redirect(admin_url('options-general.php?page=silver-duck&rechecked=' . intval($count)));
        exit;
    }

    /** Preprocess hook (kept for parity/extension) */
    public function maybe_preprocess_comment($commentdata) {
        return $commentdata;
    }

    /** Decide approval: main hook */
    public function filter_pre_comment_approved($approved, $commentdata) {
        $opts = wp_parse_args(get_option(self::OPT_KEY), self::defaults());
        if (empty($opts['enabled'])) return $approved;

        // Skip logged-in users unless opted-in
        if (!is_user_logged_in() || !empty($opts['run_for_logged_in'])) {
            $result = $this->evaluate_comment_for_action(null, $commentdata, false);
            if ($result === 'spam') {
                return 'spam';
            } elseif ($result === 'hold') {
                return 0; // hold for moderation
            }
        }
        return $approved;
    }

    /** Full evaluation (heuristics + LLM) */
    protected function evaluate_comment_for_action($comment_id, $commentdata, $bypass_heuristics_for_bulk=false) {
        $opts = wp_parse_args(get_option(self::OPT_KEY), self::defaults());
        $content = trim($commentdata['comment_content'] ?? '');
        if ($content === '') return 'hold';

        // Heuristic: link count
        if (!$bypass_heuristics_for_bulk && $opts['max_links'] > 0) {
            $linkCount = preg_match_all('#https?://#i', $content, $m);
            if ($linkCount > $opts['max_links']) {
                $this->log($comment_id, 'spam', 1.0000, $opts['model'], null, null, ['Too many links ('.$linkCount.')'], null, null);
                return $opts['auto_action']; // spam or hold
            }
        }

        // Heuristic: blacklist
        if (!$bypass_heuristics_for_bulk && !empty($opts['blacklist'])) {
            $patterns = array_filter(array_map('trim', preg_split('/\R+/', $opts['blacklist'])));
            foreach ($patterns as $p) {
                if ($p !== '' && stripos($content, $p) !== false) {
                    $this->log($comment_id, 'spam', 1.0000, $opts['model'], null, null, ['Matched blacklist: '.$p], null, null);
                    return $opts['auto_action'];
                }
            }
        }

        // LLM Classify
        $res = $this->classify_text($content, $comment_id, false);
        if ($res['error']) {
            // On error, fail safe: hold for moderation
            return 'hold';
        }

        $decision = $res['decision'];
        $conf     = floatval($res['confidence']);
        $threshold = floatval($opts['confidence']);

        if ($decision === 'spam' && $conf >= $threshold) {
            return $opts['auto_action'];
        }
        return 'none'; // no change; WP continues its normal flow
    }

    /** Call OpenRouter to classify text */
    protected function classify_text($text, $comment_id=null, $is_test=false) {
        $opts = wp_parse_args(get_option(self::OPT_KEY), self::defaults());
        $apiKey  = $opts['api_key'];
        $model   = $opts['model'];
        $timeout = max(3, intval($opts['timeout']));

        $start = microtime(true);
        $error = null; $decision='valid'; $confidence=0.50; $reasons=[]; $raw=null; $tokens=null;

        if (!$apiKey) {
            $error = 'Missing OpenRouter API key.';
            $this->log($comment_id, 'valid', $confidence, $model, $tokens, null, $reasons, $raw, $error);
            return compact('decision','confidence','reasons','error');
        }

        $system = "You are a strict comment spam filter for a WordPress site.\n".
            "Output a SINGLE LINE of compact JSON with keys exactly: label (\"spam\" or \"valid\"), confidence (0..1), reasons (array of short strings).\n".
            "Return ONLY JSON. No prose.";

        $userPrompt = "Classify this comment as spam or valid. Consider content, links, promotion, scams, phishing, SEO spam, duplicate content, and low-effort generic praise.\n\n".
            "Comment:\n---\n".$text."\n---";

        $payload = [
            'model' => $model,
            'temperature' => 0,
            'max_tokens' => 48,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $userPrompt],
            ]
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
        } else {
            $code = wp_remote_retrieve_response_code($resp);
            $body = wp_remote_retrieve_body($resp);
            $raw  = $body;

            if ($code >= 200 && $code < 300) {
                $json = json_decode($body, true);
                $content = $json['choices'][0]['message']['content'] ?? '';
                $tokens = intval($json['usage']['total_tokens'] ?? 0);

                // normalize + extract JSON only
                $content = trim($content);
                if (preg_match('/\{.*\}/s', $content, $m)) {
                    $content = $m[0];
                }
                $parsed = json_decode($content, true);
                if (is_array($parsed) && isset($parsed['label'])) {
                    $decision   = (strtolower(trim($parsed['label'])) === 'spam') ? 'spam' : 'valid';
                    $confidence = isset($parsed['confidence']) ? floatval($parsed['confidence']) : 0.5;
                    $reasons    = is_array($parsed['reasons'] ?? null) ? array_slice(array_map('strval', $parsed['reasons']), 0, 6) : [];
                } else {
                    // Fallback parse
                    $low = strtolower($content);
                    if (strpos($low, 'spam') !== false && strpos($low, 'valid') === false) {
                        $decision = 'spam'; $confidence = 0.85; $reasons = ['fallback parse'];
                    } else {
                        $decision = 'valid'; $confidence = 0.55; $reasons = ['fallback parse'];
                    }
                }
            } else {
                $error = 'HTTP ' . $code . ' - ' . substr($body, 0, 300);
            }
        }

        $latency = intval((microtime(true) - $start) * 1000);
        $this->log($comment_id, $decision, $confidence, $model, $tokens, $latency, $reasons, $raw, $error);

        return compact('decision','confidence','reasons','error');
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

    /** Purge logs based on retention */
    public function purge_old_logs() {
        global $wpdb;
        $opts = wp_parse_args(get_option(self::OPT_KEY), self::defaults());
        $days = max(0, intval($opts['log_retention_days']));
        if ($days <= 0) return;
        $table = $wpdb->prefix . self::TABLE;
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE created_at < (NOW() - INTERVAL %d DAY)", $days));
    }

    /** Logs table rendering (latest 50) */
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
}

new Silver_Duck();

// Hook for comment_post kept for parity (logging already handled in classify_text)
add_action('comment_post', function($comment_id, $approved, $commentdata){
    // No-op
}, 10, 3);
