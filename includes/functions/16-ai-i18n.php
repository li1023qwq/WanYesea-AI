<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

/**
 * WordPress 官方 AI 插件界面汉化（运行时翻译）。
 *
 * 参考 gettext + wp.i18n.setLocaleData + DOM 动态文案替换。
 * 官方 ai 插件发布完整 zh_CN 语言包后，可关闭本功能并移除相关模块。
 */

function wanyesea_ai_i18n_locale_is_chinese() {
    $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
    $locale = strtolower(str_replace('_', '-', (string) $locale));

    return strpos($locale, 'zh') === 0;
}

function wanyesea_ai_i18n_enabled() {
    if (!wanyesea_ai_i18n_locale_is_chinese()) {
        return false;
    }

    if (!function_exists('wanyesea_ai_switcher_on')) {
        return false;
    }

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

    return (array) apply_filters('wanyesea_ai_i18n_strings', $strings);
}

function wanyesea_ai_i18n_normalize_space($text) {
    return trim((string) preg_replace('/\s+/', ' ', (string) $text));
}

/**
 * @param string $text
 * @param array<string, string> $map
 * @param string $fallback
 */
function wanyesea_ai_i18n_translate_from_map($text, array $map, $fallback) {
    if (isset($map[$text])) {
        return $map[$text];
    }

    $normalized = wanyesea_ai_i18n_normalize_space($text);
    if (isset($map[$normalized])) {
        return $map[$normalized];
    }

    $trimmed_colon = rtrim($normalized, ':');
    if (isset($map[$trimmed_colon])) {
        return $map[$trimmed_colon] . ':';
    }
    if (isset($map[$trimmed_colon . ':'])) {
        return $map[$trimmed_colon . ':'];
    }

    return $fallback;
}

function wanyesea_ai_i18n_filter_gettext($translated, $text, $domain) {
    if ($domain !== 'ai' || !wanyesea_ai_i18n_enabled()) {
        return $translated;
    }

    return wanyesea_ai_i18n_translate_from_map($text, wanyesea_ai_i18n_strings(), $translated);
}

function wanyesea_ai_i18n_filter_gettext_with_context($translated, $text, $context, $domain) {
    unset($context);
    return wanyesea_ai_i18n_filter_gettext($translated, $text, $domain);
}

/**
 * @param array<string, string> $map
 */
function wanyesea_ai_i18n_build_set_locale_data_script($domain, array $map) {
    $messages = array(
        '' => array(
            'domain'       => $domain,
            'lang'         => 'zh_CN',
            'plural-forms' => 'nplurals=1; plural=0;',
        ),
    );

    foreach ($map as $source => $translated) {
        if ($source === '' || $translated === '') {
            continue;
        }
        $messages[$source] = array($translated);
    }

    return 'window.wp&&wp.i18n&&wp.i18n.setLocaleData('
        . wp_json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . ','
        . wp_json_encode($domain)
        . ');';
}

/**
 * @param array<string, string> $map
 */
function wanyesea_ai_i18n_build_dom_translation_script(array $map, $root_id) {
    return '(function(){var map=' . wp_json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . ';var rootId=' . wp_json_encode($root_id)
        . ';function norm(v){return String(v||"").replace(/&amp;/g,"&").replace(/\u00a0/g," ").replace(/\s+/g," ").trim()}function t(v){var n=norm(v);if(Object.prototype.hasOwnProperty.call(map,n))return map[n];if(Object.prototype.hasOwnProperty.call(map,n.replace(/:$/,"")))return map[n.replace(/:$/,"")]+":";if(Object.prototype.hasOwnProperty.call(map,n+":"))return map[n+":"];return v}function walk(root){if(!root)return;var tw=document.createTreeWalker(root,NodeFilter.SHOW_TEXT,{acceptNode:function(node){if(!norm(node.nodeValue))return NodeFilter.FILTER_REJECT;var p=node.parentNode;if(!p||/^(SCRIPT|STYLE|TEXTAREA|INPUT)$/i.test(p.nodeName))return NodeFilter.FILTER_REJECT;return NodeFilter.FILTER_ACCEPT}});var nodes=[];while(tw.nextNode())nodes.push(tw.currentNode);nodes.forEach(function(node){var next=t(node.nodeValue);if(next!==node.nodeValue)node.nodeValue=next});Array.prototype.forEach.call(root.querySelectorAll("input[placeholder],textarea[placeholder],[aria-label],option"),function(el){if(el.placeholder)el.placeholder=t(el.placeholder);if(el.getAttribute&&el.getAttribute("aria-label"))el.setAttribute("aria-label",t(el.getAttribute("aria-label")));if(el.tagName==="OPTION")el.textContent=t(el.textContent)})}function run(){walk(document.getElementById(rootId)||document.body)}if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",run);else run();var mo=new MutationObserver(function(){window.clearTimeout(mo._t);mo._t=window.setTimeout(run,40)});mo.observe(document.body,{childList:true,subtree:true})})();';
}

function wanyesea_ai_i18n_enqueue_runtime_translations() {
    if (!wanyesea_ai_i18n_enabled()) {
        return;
    }

    $map = wanyesea_ai_i18n_strings();
    if ($map === array()) {
        return;
    }

    if (wp_script_is('wp-i18n', 'registered')) {
        wp_enqueue_script('wp-i18n');
        wp_add_inline_script('wp-i18n', wanyesea_ai_i18n_build_set_locale_data_script('ai', $map), 'after');
        wp_add_inline_script('wp-i18n', wanyesea_ai_i18n_build_dom_translation_script($map, 'wpbody-content'), 'after');
    }
}

function wanyesea_ai_i18n_print_admin_footer_translations() {
    if (!wanyesea_ai_i18n_enabled()) {
        return;
    }

    $map = wanyesea_ai_i18n_strings();
    if ($map === array()) {
        return;
    }

    echo '<script>' . wanyesea_ai_i18n_build_dom_translation_script($map, 'wpbody-content') . '</script>';
}

function wanyesea_ai_i18n_bootstrap() {
    if (!wanyesea_ai_i18n_enabled()) {
        return;
    }

    add_filter('gettext', 'wanyesea_ai_i18n_filter_gettext', 20, 3);
    add_filter('gettext_with_context', 'wanyesea_ai_i18n_filter_gettext_with_context', 20, 4);
    add_action('admin_enqueue_scripts', 'wanyesea_ai_i18n_enqueue_runtime_translations', 100);
    add_action('enqueue_block_editor_assets', 'wanyesea_ai_i18n_enqueue_runtime_translations', 100);
    add_action('admin_footer', 'wanyesea_ai_i18n_print_admin_footer_translations', 100);
}

add_action('plugins_loaded', 'wanyesea_ai_i18n_bootstrap', 30);
