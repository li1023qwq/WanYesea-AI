<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

/**
 * 站点是否为中文区域设置（用于 CJK 词数等效换算）。
 */
function wanyesea_ai_is_zh_site_locale() {
    $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
    $locale = strtolower(str_replace('_', '-', (string) $locale));

    return strpos($locale, 'zh') === 0;
}

/**
 * 内容分类实验：是否应在当前文章编辑页加载词数修正脚本。
 *
 * @param string $hook_suffix admin_enqueue_scripts 的 hook。
 */
function wanyesea_ai_should_fix_content_classification_wordcount($hook_suffix) {
    if ($hook_suffix !== 'post.php' && $hook_suffix !== 'post-new.php') {
        return false;
    }

    if (!current_user_can('manage_categories')) {
        return false;
    }

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->post_type === 'attachment') {
        return false;
    }

    return is_object_in_taxonomy($screen->post_type, 'category')
        || is_object_in_taxonomy($screen->post_type, 'post_tag');
}

/**
 * 在官方 content-classification 脚本之前预热 wp-wordcount 并注入中文词数修正。
 *
 * @param string $hook_suffix Current admin page hook suffix.
 */
function wanyesea_ai_enqueue_content_classification_wordcount_fix($hook_suffix) {
    if (!wanyesea_ai_should_fix_content_classification_wordcount($hook_suffix)) {
        return;
    }

    wp_enqueue_script('wp-wordcount');

    $asset_ver = class_exists('Wanyesea_AI_Config')
        ? Wanyesea_AI_Config::get_asset_version()
        : '1.0.0';

    wp_enqueue_script(
        'wanyesea-ai-content-classification',
        WanYesea_AI_url . 'assets/wanyesea-ai-content-classification.js',
        array('wp-wordcount'),
        $asset_ver,
        false
    );

    wp_localize_script(
        'wanyesea-ai-content-classification',
        'wanyeseaAiContentClassification',
        array(
            'minWords'          => (int) apply_filters('wanyesea_ai_content_classification_min_words', 150),
            'cjkCharsPerWord'   => (int) apply_filters('wanyesea_ai_content_classification_cjk_chars_per_word', 2),
            'isZhLocale'        => wanyesea_ai_is_zh_site_locale(),
            'useCjkAdjustment'  => (bool) apply_filters('wanyesea_ai_content_classification_use_cjk_adjustment', true),
        )
    );
}
add_action('admin_enqueue_scripts', 'wanyesea_ai_enqueue_content_classification_wordcount_fix', 5);
