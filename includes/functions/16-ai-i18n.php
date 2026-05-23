<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

/**
 * WordPress 官方 AI 插件界面汉化（临时方案）。
 *
 * 在官方 ai 插件发布 zh_CN 语言包后，可关闭本插件中的「界面汉化」并删除
 * includes/options/03-ai-i18n.php、本文件及 includes/i18n/ai-zh-cn-strings.php。
 */

/**
 * 当前站点/用户是否使用中文界面语言。
 */
function wanyesea_ai_i18n_locale_is_chinese() {
    $locale = function_exists('get_user_locale') ? get_user_locale() : get_locale();
    $locale = strtolower(str_replace('_', '-', (string) $locale));

    return strpos($locale, 'zh') === 0;
}

/**
 * 是否应加载汉化逻辑。
 */
function wanyesea_ai_i18n_enabled() {
    if (!wanyesea_ai_i18n_locale_is_chinese()) {
        return false;
    }

    if (!function_exists('wanyesea_ai_switcher_on')) {
        return false;
    }

    /**
     * 在读取 CSF 选项前强制启用（默认 true，见 options/03）。
     *
     * @param bool $enabled
     */
    $enabled = wanyesea_ai_switcher_on('wp_ai_zh_i18n_enabled', true);
    return (bool) apply_filters('wanyesea_ai_i18n_enabled', $enabled);
}

/**
 * @return array<string, string>
 */
function wanyesea_ai_i18n_strings() {
    static $strings = null;

    if ($strings !== null) {
        return $strings;
    }

    $file = WanYesea_AI_path . 'includes/i18n/ai-zh-cn-strings.php';
    if (!is_readable($file)) {
        $strings = array();
        return $strings;
    }

    $loaded = require $file;
    $strings = is_array($loaded) ? $loaded : array();

    /**
     * @param array<string, string> $strings
     */
    $strings = apply_filters('wanyesea_ai_i18n_strings', $strings);

    return $strings;
}

/**
 * @param string $text 英文原文
 */
function wanyesea_ai_i18n_translate($text) {
    if ($text === '' || !wanyesea_ai_i18n_enabled()) {
        return $text;
    }

    $map = wanyesea_ai_i18n_strings();
    return isset($map[$text]) ? $map[$text] : $text;
}

/**
 * gettext 过滤器：覆盖 text domain 为 ai 的字符串。
 */
function wanyesea_ai_i18n_filter_gettext($translated, $text, $domain) {
    if ($domain !== 'ai' || !wanyesea_ai_i18n_enabled()) {
        return $translated;
    }

    $zh = wanyesea_ai_i18n_translate($text);
    return $zh !== $text ? $zh : $translated;
}

/**
 * 专用于 ai 域名的 gettext 快捷过滤器。
 */
function wanyesea_ai_i18n_filter_gettext_ai($translated, $text) {
    if (!wanyesea_ai_i18n_enabled()) {
        return $translated;
    }

    $zh = wanyesea_ai_i18n_translate($text);
    return $zh !== $text ? $zh : $translated;
}

/**
 * 向已注册的 wp-i18n 脚本注入 Jed 格式 locale data。
 */
function wanyesea_ai_i18n_enqueue_script_locale_data() {
    if (!wanyesea_ai_i18n_enabled()) {
        return;
    }

    $handle = 'wp-i18n';
    if (!wp_script_is($handle, 'registered')) {
        return;
    }

    $payload = wanyesea_ai_i18n_jed_payload();
    if (count($payload) <= 1) {
        return;
    }

    wp_enqueue_script($handle);
    wp_add_inline_script(
        $handle,
        "(function(wp){if(!wp||!wp.i18n||!wp.i18n.setLocaleData)return;wp.i18n.setLocaleData(" . wp_json_encode($payload) . ",'ai');})(window.wp);",
        'after'
    );
}

/**
 * @return array<string, array<int, string>|array<string, string>>
 */
function wanyesea_ai_i18n_jed_payload() {
    $map = wanyesea_ai_i18n_strings();
    $jed = array(
        '' => array(
            'domain'       => 'ai',
            'lang'         => 'zh-cn',
            'plural-forms' => 'nplurals=1; plural=0;',
        ),
    );

    foreach ($map as $en => $zh) {
        if ($en === '' || $zh === '') {
            continue;
        }
        $jed[$en] = array($zh);
    }

    return $jed;
}

function wanyesea_ai_i18n_bootstrap() {
    if (!wanyesea_ai_i18n_enabled()) {
        return;
    }

    add_filter('gettext', 'wanyesea_ai_i18n_filter_gettext', 20, 3);
    add_filter('gettext_ai', 'wanyesea_ai_i18n_filter_gettext_ai', 20, 2);

    add_action('admin_enqueue_scripts', 'wanyesea_ai_i18n_enqueue_script_locale_data', 200);
    add_action('enqueue_block_editor_assets', 'wanyesea_ai_i18n_enqueue_script_locale_data', 200);
}

add_action('plugins_loaded', 'wanyesea_ai_i18n_bootstrap', 30);
