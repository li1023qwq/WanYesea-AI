<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

use WordPress\AiClient\AiClient;

/**
 * 支持 WordPress AI 文本生成（标题、摘要等）的 Provider ID。
 *
 * @return list<string>
 */
function wanyesea_ai_text_capable_provider_ids() {
    $ids = array('anthropic', 'google', 'openai');

    if (class_exists('Wanyesea_AI_Custom_Connectors')) {
        $ids = array_merge($ids, Wanyesea_AI_Custom_Connectors::provider_ids());
    }

    return apply_filters('wanyesea_ai_text_capable_provider_ids', array_values(array_unique($ids)));
}

/**
 * 当前站点是否具备文本生成能力（与 ensure_text_generation_supported 判定一致）。
 */
function wanyesea_ai_is_text_generation_available() {
    if (!function_exists('wp_ai_client_prompt')) {
        return false;
    }

    try {
        return (bool) wp_ai_client_prompt('ping')->is_supported_for_text_generation();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * 文本生成不可用时的人类可读原因（简体中文）。
 *
 * @return list<string>
 */
function wanyesea_ai_text_generation_blockers() {
    $blockers = array();
    $status   = wanyesea_ai_wp_ai_status();

    if (empty($status['core'])) {
        $blockers[] = '未启用 WordPress AI 核心插件（<code>ai</code>）';
    }
    if (empty($status['client'])) {
        $blockers[] = '未检测到 WP AI Client';
    }

    $has_official_plugin = false;
    $has_any_key         = false;
    $configured_ids      = array();

    foreach (wanyesea_ai_text_capable_provider_ids() as $provider_id) {
        $is_custom = function_exists('wanyesea_ai_is_custom_connect_provider')
            && wanyesea_ai_is_custom_connect_provider($provider_id);

        if (!$is_custom && !empty($status[$provider_id])) {
            $has_official_plugin = true;
        }

        if ($is_custom) {
            if (wanyesea_ai_get_custom_connector_api_key_resolved($provider_id) !== '') {
                $has_any_key = true;
            }
        } elseif (function_exists('wanyesea_ai_get_connector_api_key_resolved')
            && wanyesea_ai_get_connector_api_key_resolved($provider_id) !== '') {
            $has_any_key = true;
        }

        if (function_exists('wanyesea_ai_is_provider_registry_configured')
            && wanyesea_ai_is_provider_registry_configured($provider_id)) {
            $configured_ids[] = $provider_id;
        }
    }

    if (!$has_official_plugin && !$has_any_key) {
        $blockers[] = '未安装或未启用 <code>ai-provider-for-openai</code> / <code>google</code> / <code>anthropic</code>，且未配置 DeepSeek 等自定义 Connector 的 API Key';
    } elseif (!$has_any_key) {
        $blockers[] = '未配置任何文本厂商的 API Key（可在「设置 → 连接」或本插件「AI 连接」中填写）';
    } elseif (empty($configured_ids)) {
        $blockers[] = '已填写 API Key，但没有任何厂商通过 <strong>AI Client 可用性校验</strong>（与「设置 → 连接」页「已连接」标准相同：须成功拉取 <code>/models</code>）。本页「厂商端点 → 检测」仅测 HTTP 连通，二者可能不一致。';
        if (function_exists('wanyesea_ai_provider_registry_status_lines')) {
            $detail = wanyesea_ai_provider_registry_status_lines(wanyesea_ai_text_capable_provider_ids());
            if ($detail !== array()) {
                $blockers = array_merge($blockers, $detail);
            }
        }
    }

    if ($configured_ids !== array() && !wanyesea_ai_is_text_generation_available()) {
        $blockers[] = '至少有一个厂商已通过校验（' . esc_html(implode('、', $configured_ids)) . '），但 WordPress AI 仍不可用：请到 <a href="' . esc_url(admin_url('options-general.php?page=ai-wp-admin')) . '">设置 → AI</a> 开启「标题生成」等实验功能，并确认 Connector 审批已允许本插件。';
    }

    if (Wanyesea_AI_Relay::is_enabled()) {
        foreach (wanyesea_ai_text_capable_provider_ids() as $provider_id) {
            if (function_exists('wanyesea_ai_is_custom_connect_provider')
                && wanyesea_ai_is_custom_connect_provider($provider_id)) {
                continue;
            }
            if (!wanyesea_ai_switcher_on('relay_' . $provider_id . '_enabled', false)) {
                continue;
            }
            if (Wanyesea_AI_Relay::get_provider_base_url($provider_id) === '') {
                continue;
            }
            if (!in_array($provider_id, $configured_ids, true)) {
                $meta  = wanyesea_ai_connect_provider_meta();
                $label = isset($meta[$provider_id]['label']) ? $meta[$provider_id]['label'] : ucfirst($provider_id);
                $blockers[] = sprintf(
                    '%s 已启用中转但无法连通，请检查中转 Base URL 是否兼容该厂商的 <code>/v1/models</code> 与 <code>chat/completions</code> 接口',
                    $label
                );
                break;
            }
        }
    }

    if (empty($blockers) && !wanyesea_ai_is_text_generation_available()) {
        $blockers[] = '请在「设置 → AI」中确认已启用标题/摘要等实验功能，且未将文本模型强制指定为不可用模型';
    }

    return apply_filters('wanyesea_ai_text_generation_blockers', $blockers);
}

/**
 * 环境检测：文本生成就绪状态 HTML。
 */
function wanyesea_ai_connect_text_gen_env_html() {
    $ok       = wanyesea_ai_is_text_generation_available();
    $class    = $ok ? 'is-ok' : 'is-warn';
    $icon     = $ok ? 'fa-check-circle' : 'fa-exclamation-circle';
    $hint     = $ok ? '标题 / 摘要等' : '需配置密钥';
    $blockers = $ok ? array() : wanyesea_ai_text_generation_blockers();

    $html = '<div class="wya-ai-env-item wya-ai-env-item--text-gen ' . esc_attr($class) . '">';
    $html .= '<span class="wya-ai-env-item__icon"><i class="fa ' . esc_attr($icon) . '"></i></span>';
    $html .= '<span class="wya-ai-env-item__body">';
    $html .= '<span class="wya-ai-env-item__label">文本生成</span>';
    $html .= '<span class="wya-ai-env-item__hint">' . esc_html($hint) . '</span>';
    $html .= '</span></div>';

    if (!$ok && $blockers) {
        $html .= '<ul class="wya-ai-text-gen-blockers muted-3-color em09">';
        foreach ($blockers as $line) {
            $html .= '<li>' . wp_kses($line, array(
                'code'   => array(),
                'strong' => array(),
                'a'      => array('href' => array()),
            )) . '</li>';
        }
        $html .= '</ul>';
    }

    return $html;
}
