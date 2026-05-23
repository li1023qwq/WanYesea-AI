<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

/**
 * WordPress 7.0 AI Client 第三方 API 中转适配。
 *
 * 官方 Provider 将请求发往各厂商默认域名；本模块在 HTTP 层将 URL 前缀
 * 替换为站点配置的中转 Base URL。API Key 由「AI 连接」页配置并同步至 Connectors。
 *
 * @see https://github.com/WordPress/ai
 */
final class Wanyesea_AI_Relay {

    /**
     * @return array<string, string> provider_id => base URL
     */
    public static function official_base_urls() {
        $defaults = array(
            'openai'    => 'https://api.openai.com/v1',
            'google'    => 'https://generativelanguage.googleapis.com/v1beta',
            'anthropic' => 'https://api.anthropic.com/v1',
        );

        return apply_filters('wanyesea_ai_official_base_urls', $defaults);
    }

    public static function is_enabled() {
        return wanyesea_ai_switcher_on('relay_enabled', false);
    }

    /**
     * 官方 + 自定义 Connector 的官方根地址（用于中转 URL 改写）。
     *
     * @return array<string, string>
     */
    public static function all_official_base_urls() {
        $urls = self::official_base_urls();

        if (class_exists('Wanyesea_AI_Custom_Connectors')) {
            foreach (Wanyesea_AI_Custom_Connectors::official_base_urls() as $provider_id => $base) {
                $urls[sanitize_key((string) $provider_id)] = $base;
            }
        }

        return apply_filters('wanyesea_ai_all_official_base_urls_for_relay', $urls);
    }

    public static function is_provider_active($provider_id) {
        if (!self::is_enabled()) {
            return false;
        }
        $provider_id = sanitize_key((string) $provider_id);
        $all         = self::all_official_base_urls();

        if (!isset($all[$provider_id]) && $provider_id !== '') {
            return false;
        }
        if (!wanyesea_ai_switcher_on('relay_' . $provider_id . '_enabled', false)) {
            return false;
        }
        return self::get_provider_base_url($provider_id) !== '';
    }

    /**
     * 规范化中转 Base URL：去除路径片段、合并重复斜杠，保留完整 API 根（含 /v1 等）。
     */
    public static function normalize_relay_base_url($url) {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        $url = preg_replace('/\s+/', '', $url);
        if (!preg_match('#^https?://#i', $url)) {
            return '';
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $base   = $scheme . '://' . strtolower((string) $parts['host']);
        if (!empty($parts['port'])) {
            $base .= ':' . (int) $parts['port'];
        }
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        $path = preg_replace('#/+#', '/', $path);
        if ($path !== '' && $path !== '/') {
            $base .= rtrim($path, '/');
        }
        $raw = esc_url_raw($base);
        return ($raw !== '' && wp_http_validate_url($raw)) ? rtrim($raw, '/') : '';
    }

    public static function get_provider_base_url($provider_id) {
        $provider_id = sanitize_key((string) $provider_id);
        $raw         = trim((string) WanYesea_AI('relay_' . $provider_id . '_base_url', ''));
        if ($raw === '') {
            return '';
        }
        $normalized = self::normalize_relay_base_url($raw);
        return (string) apply_filters('wanyesea_ai_relay_base_url', $normalized, $provider_id);
    }

    /**
     * @return list<string>
     */
    public static function relay_hosts() {
        $hosts = array();

        if (!self::is_enabled()) {
            return $hosts;
        }

        foreach (array_keys(self::all_official_base_urls()) as $provider_id) {
            if (!self::is_provider_active($provider_id)) {
                continue;
            }
            $parsed = wp_parse_url(self::get_provider_base_url($provider_id));
            if (!empty($parsed['host'])) {
                $hosts[] = strtolower((string) $parsed['host']);
            }
        }

        return array_values(array_unique($hosts));
    }

    public static function rewrite_url($url) {
        $url = (string) $url;
        if ($url === '' || !self::is_enabled()) {
            return $url;
        }

        foreach (self::all_official_base_urls() as $provider_id => $official_base) {
            if (!self::is_provider_active($provider_id)) {
                continue;
            }
            $relay_base    = self::get_provider_base_url($provider_id);
            $official_base = rtrim((string) $official_base, '/');
            if ($official_base === '' || strpos($url, $official_base) !== 0) {
                continue;
            }
            $suffix  = substr($url, strlen($official_base));
            $new_url = $relay_base . $suffix;
            return (string) apply_filters('wanyesea_ai_rewritten_request_url', $new_url, $url, $provider_id);
        }

        return $url;
    }

    public static function boot() {
        add_filter('pre_http_request', array(__CLASS__, 'filter_pre_http_request'), 9, 3);
        add_filter('http_request_host_is_external', array(__CLASS__, 'allow_relay_hosts'), 10, 3);
        add_filter('http_request_args', 'wanyesea_ai_extend_relay_models_validation_timeout', 12, 2);
    }

    /**
     * @param false|array|\WP_Error $preempt
     * @param array                 $args
     * @param string                $url
     * @return false|array|\WP_Error
     */
    public static function filter_pre_http_request($preempt, $args, $url) {
        $new_url = self::rewrite_url($url);
        if ($new_url === $url) {
            return $preempt;
        }
        return wp_safe_remote_request($new_url, $args);
    }

    public static function allow_relay_hosts($allow, $host, $url) {
        unset($url);
        if ($allow) {
            return $allow;
        }
        $host = strtolower((string) $host);
        if (in_array($host, self::relay_hosts(), true)) {
            return true;
        }
        return $allow;
    }
}

add_action('plugins_loaded', array('Wanyesea_AI_Relay', 'boot'), 20);

/**
 * 当前 HTTP 请求是否为「已启用中转」厂商的 GET /models（含 Google ?key= 形式）。
 *
 * 用于 AI Client 可用性校验（设置 → 连接「已连接」、REST 保存 API Key 等），
 * 与「AI 连接」页厂商端点探测使用相近超时，避免 WordPress 默认 5 秒先失败。
 *
 * @param string $url 请求 URL（pre_http_request 改写前多为官方域名）。
 */
function wanyesea_ai_relay_is_models_validation_request($url) {
    if (!class_exists('Wanyesea_AI_Relay') || !Wanyesea_AI_Relay::is_enabled()) {
        return false;
    }

    $url = (string) $url;
    if ($url === '' || strpos($url, '/models') === false) {
        return false;
    }

    foreach (Wanyesea_AI_Relay::all_official_base_urls() as $provider_id => $official_base) {
        if (!Wanyesea_AI_Relay::is_provider_active($provider_id)) {
            continue;
        }

        $official_base = rtrim((string) $official_base, '/');
        if ($official_base !== '' && strpos($url, $official_base) !== false) {
            return true;
        }

        $relay_base = Wanyesea_AI_Relay::get_provider_base_url($provider_id);
        if ($relay_base !== '' && strpos($url, rtrim($relay_base, '/')) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * 中转场景下延长 GET /models 超时，使 AI Client isProviderConfigured 与连接页「已连接」判定更可靠。
 *
 * @param array<string, mixed> $args
 * @param string               $url
 * @return array<string, mixed>
 */
function wanyesea_ai_extend_relay_models_validation_timeout($args, $url) {
    if (!wanyesea_ai_relay_is_models_validation_request($url)) {
        return $args;
    }

    $timeout = (int) apply_filters('wanyesea_ai_relay_models_validation_timeout', 45);
    $timeout = max(15, min(180, $timeout));
    $args['timeout'] = max((int) ($args['timeout'] ?? 5), $timeout);

    return $args;
}

/**
 * @return array{core: bool, openai: bool, google: bool, anthropic: bool, client: bool}
 */
function wanyesea_ai_wp_ai_status() {
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $check = static function ($file) {
        return is_plugin_active($file);
    };

    return array(
        'client'    => class_exists('WordPress\AiClient\AiClient'),
        'core'      => $check('ai/ai.php'),
        'openai'    => $check('ai-provider-for-openai/plugin.php'),
        'google'    => $check('ai-provider-for-google/plugin.php'),
        'anthropic' => $check('ai-provider-for-anthropic/plugin.php'),
    );
}
