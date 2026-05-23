<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

/**
 * 子比主题是否处于启用状态（用于依赖主题后台样式表）。
 */
function wanyesea_ai_is_zibll_active() {
    $theme = function_exists('wp_get_theme') ? wp_get_theme() : null;
    if (!$theme || !$theme->exists()) {
        return false;
    }
    $slug     = $theme->get_stylesheet();
    $template = $theme->get_template();
    return ($slug === 'zibll' || $template === 'zibll');
}

/**
 * 判断 CSF switcher 选项是否为开启状态（兼容 1 / true / on 等存值）。
 */
function wanyesea_ai_switcher_on($option_key, $default = false) {
    $value = WanYesea_AI($option_key, $default);
    if ($value === true || $value === 1) {
        return true;
    }
    $value = strtolower(trim((string) $value));
    return in_array($value, array('1', 'true', 'on', 'yes'), true);
}

/**
 * 从环境变量或 wp-config 常量读取 Connector API Key（优先级高于选项）。
 *
 * 命名：WANYESEA_AI_{PROVIDER}_API_KEY，如 WANYESEA_AI_OPENAI_API_KEY、WANYESEA_AI_WANYESEA_GATEWAY_API_KEY
 */
function wanyesea_ai_resolve_env_api_key($provider_id) {
    $provider_id = sanitize_key((string) $provider_id);
    if ($provider_id === '') {
        return '';
    }

    if (class_exists('Wanyesea_AI_Gateway_Settings', false)
        && Wanyesea_AI_Gateway_Settings::is_gateway_provider_id($provider_id)) {
        $constant = Wanyesea_AI_Gateway_Settings::env_constant_name(
            Wanyesea_AI_Gateway_Settings::slot_id_for_provider_id($provider_id)
        );
    } else {
        $constant = 'WANYESEA_AI_' . strtoupper(str_replace('-', '_', $provider_id)) . '_API_KEY';
    }

    $env = getenv($constant);
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }

    if (defined($constant)) {
        $val = constant($constant);
        if (is_scalar($val) && trim((string) $val) !== '') {
            return trim((string) $val);
        }
    }

    return (string) apply_filters('wanyesea_ai_env_api_key', '', $provider_id, $constant);
}
