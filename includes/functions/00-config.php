<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

/**
 * 晚夜深秋·AI插件 本地配置
 */
class Wanyesea_AI_Config {

    /** @var array<string, string>|null */
    private static $plugin_data = null;

    /**
     * @return array<string, string>
     */
    public static function get_plugin_data() {
        if (self::$plugin_data !== null) {
            return self::$plugin_data;
        }

        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $data = get_plugin_data(WanYesea_AI_path . 'index.php', false, false);
        self::$plugin_data = is_array($data) ? $data : array();

        return self::$plugin_data;
    }

    /**
     * @param string $key get_plugin_data 返回的键名，如 Name、Version、Description
     */
    public static function get($key, $default = '') {
        $data = self::get_plugin_data();
        return isset($data[$key]) && $data[$key] !== '' ? (string) $data[$key] : $default;
    }

    public static function get_name() {
        return self::get('Name', 'WanYesea AI');
    }

    public static function get_version() {
        return self::get('Version', '0.0.0');
    }

    public static function get_description() {
        return self::get('Description', '');
    }

    public static function get_plugin_uri() {
        return self::get('PluginURI', '');
    }

    public static function get_author_uri() {
        return self::get('AuthorURI', '');
    }

    public static function get_requires_wp() {
        return self::get('RequiresWP', '');
    }

    public static function get_requires_php() {
        return self::get('RequiresPHP', '');
    }

    public static function get_version_label() {
        return 'v' . self::get_version();
    }

    public static function get_version_badge_html() {
        return '<span class="wya-version-badge">' . esc_html(self::get_version_label()) . '</span>';
    }

    /**
     * 后台资源版本号（用于 cache busting）。
     */
    public static function get_asset_version() {
        return self::get_version();
    }

    /**
     * 当前站点 WordPress 版本（用于说明文案）。
     */
    public static function get_wp_version_label() {
        return get_bloginfo('version');
    }

    /**
     * 环境要求一行说明。
     */
    public static function get_env_requirements_html() {
        $parts = array();

        $requires_wp = self::get_requires_wp();
        if ($requires_wp !== '') {
            $parts[] = 'WordPress ' . esc_html($requires_wp) . '+（当前 ' . esc_html(self::get_wp_version_label()) . '）';
        } else {
            $parts[] = 'WordPress（当前 ' . esc_html(self::get_wp_version_label()) . '）';
        }

        $requires_php = self::get_requires_php();
        if ($requires_php !== '') {
            $parts[] = 'PHP ' . esc_html($requires_php) . '+';
        }

        if (function_exists('wanyesea_ai_is_zibll_active') && wanyesea_ai_is_zibll_active()) {
            $parts[] = '子比主题 CSF 后台';
        } else {
            $parts[] = '子比主题（CSF）';
        }

        if (function_exists('wanyesea_ai_required_provider_plugins_html')) {
            $parts[] = '官方插件 ' . wanyesea_ai_required_provider_plugins_html();
        }

        return implode('、', $parts);
    }
}
