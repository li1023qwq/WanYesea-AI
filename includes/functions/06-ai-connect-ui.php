<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

/**
 * AI 连接设置页 UI 字段构建。
 */

/**
 * @return array<string, array{label: string, icon: string, tagline: string, color: string}>
 */
function wanyesea_ai_connect_provider_meta() {
    $meta = array(
        'openai' => array(
            'label'   => 'OpenAI',
            'icon'    => 'fa fa-comments',
            'tagline' => 'GPT · DALL·E',
            'color'   => 'openai',
        ),
        'google' => array(
            'label'   => 'Google Gemini',
            'icon'    => 'fa fa-google',
            'tagline' => 'Gemini · Imagen',
            'color'   => 'google',
        ),
        'anthropic' => array(
            'label'   => 'Anthropic',
            'icon'    => 'fa fa-bolt',
            'tagline' => 'Claude',
            'color'   => 'anthropic',
        ),
        'deepseek' => array(
            'label'   => 'DeepSeek',
            'icon'    => 'fa fa-code',
            'tagline' => '官方端点 · 可选中转',
            'color'   => 'deepseek',
        ),
        'moonshot' => array(
            'label'   => 'Moonshot',
            'icon'    => 'fa fa-moon-o',
            'tagline' => 'Kimi · 官方端点',
            'color'   => 'moonshot',
        ),
        'zhipu' => array(
            'label'   => '智谱 AI',
            'icon'    => 'fa fa-lightbulb-o',
            'tagline' => 'GLM · 官方端点',
            'color'   => 'zhipu',
        ),
        'xiaomi' => array(
            'label'   => '小米 MiMo',
            'icon'    => 'fa fa-mobile',
            'tagline' => 'MiMo-V2.5 · api.xiaomimimo.com',
            'color'   => 'xiaomi',
        ),
        'nvidia' => array(
            'label'   => 'NVIDIA',
            'icon'    => 'fa fa-microchip',
            'tagline' => 'NIM · 官方端点',
            'color'   => 'nvidia',
        ),
        'sensenova' => array(
            'label'   => 'SenseNova',
            'icon'    => 'fa fa-cloud',
            'tagline' => '6.7 对话 · U1 出图',
            'color'   => 'sensenova',
        ),
    );

    return apply_filters('wanyesea_ai_connect_provider_meta', $meta);
}

/**
 * @return list<string>
 */
function wanyesea_ai_connect_provider_ids() {
    return apply_filters(
        'wanyesea_ai_connect_provider_ids',
        Wanyesea_AI_Connectors::provider_ids()
    );
}

/**
 * 官方 Provider 插件 slug 列表 HTML（随 provider_ids 动态生成）。
 */
function wanyesea_ai_required_provider_plugins_html() {
    $tags = array('<code>ai</code>');
    foreach (wanyesea_ai_connect_provider_ids() as $provider_id) {
        $tags[] = '<code>ai-provider-for-' . esc_html($provider_id) . '</code>';
    }
    return implode(' / ', $tags);
}

/**
 * 环境检测：系统依赖项 HTML。
 */
function wanyesea_ai_connect_system_env_html() {
    $status = wanyesea_ai_wp_ai_status();
    $items  = array(
        array(
            'key'   => 'core',
            'label' => 'AI 核心',
            'hint'  => empty($status['core']) ? '未启用 ai 插件' : 'ai 已启用',
            'ok'    => !empty($status['core']),
        ),
        array(
            'key'   => 'client',
            'label' => 'AI Client',
            'hint'  => empty($status['client'])
                ? '未检测到 WP AI Client'
                : 'WP ' . WanYesea_AI_Config::get_wp_version_label(),
            'ok'    => !empty($status['client']),
        ),
    );

    $html = '<div class="wya-ai-env-grid wya-ai-env-grid--system">';
    foreach ($items as $item) {
        $class = $item['ok'] ? 'is-ok' : 'is-warn';
        $icon  = $item['ok'] ? 'fa-check-circle' : 'fa-exclamation-circle';
        $html .= '<div class="wya-ai-env-item ' . esc_attr($class) . '">';
        $html .= '<span class="wya-ai-env-item__icon"><i class="fa ' . esc_attr($icon) . '"></i></span>';
        $html .= '<span class="wya-ai-env-item__body">';
        $html .= '<span class="wya-ai-env-item__label">' . esc_html($item['label']) . '</span>';
        $html .= '<span class="wya-ai-env-item__hint">' . esc_html($item['hint']) . '</span>';
        $html .= '</span></div>';
    }
    $html .= '</div>';

    return $html;
}

/**
 * 参与「厂商端点」探测的厂商 ID（与 AI 连接页可见卡片一致：须已开启 API 中转总开关且该厂商「启用中转」）。
 *
 * @return list<string>
 */
function wanyesea_ai_connect_endpoint_probe_provider_ids() {
    if (!class_exists('Wanyesea_AI_Relay') || !Wanyesea_AI_Relay::is_enabled()) {
        return apply_filters('wanyesea_ai_connect_endpoint_probe_provider_ids', array());
    }

    $ids = array();
    foreach (wanyesea_ai_connect_provider_ids() as $provider_id) {
        if (!wanyesea_ai_switcher_on('relay_' . $provider_id . '_enabled', false)) {
            continue;
        }
        $ids[] = $provider_id;
    }

    return apply_filters('wanyesea_ai_connect_endpoint_probe_provider_ids', $ids);
}

/**
 * 环境检测：厂商端点探测区 HTML。
 */
function wanyesea_ai_connect_endpoint_probe_panel_html() {
    $probe_ids = function_exists('wanyesea_ai_connect_endpoint_probe_provider_ids')
        ? wanyesea_ai_connect_endpoint_probe_provider_ids()
        : array();

    $html  = '<div class="wya-env-probe-panel" data-wya-env-probe-panel>';
    $html .= '<div class="wya-env-probe-panel__head">';
    $html .= '<p class="wya-env-probe-panel__desc muted-3-color em09">';
    $html .= '仅列出已在下方厂商卡片中<strong>启用中转</strong>的厂商；对每个厂商请求 <code>GET /models</code>（Google 为等价接口），校验网关连通并列出文本 / 图像模型。';
    $html .= '未保存的 API Key 可在厂商卡片中填写后点「检测」。';
    $html .= '</p>';

    if ($probe_ids !== array()) {
        $html .= '<button type="button" class="button button-secondary wya-env-probe-all-btn" data-wya-probe-run-all>';
        $html .= '<i class="fa fa-refresh"></i> 全部检测</button>';
    }

    $html .= '</div>';
    $html .= '<div class="wya-env-probe-list">';

    if ($probe_ids === array()) {
        $html .= '<p class="muted-3-color em09" style="margin:0">请先开启「启用 API 中转」，并在对应厂商卡片中开启「启用中转」后，方可在此检测。</p>';
    } else {
        foreach ($probe_ids as $provider_id) {
            if (function_exists('wanyesea_ai_connect_provider_probe_row_html')) {
                $html .= wanyesea_ai_connect_provider_probe_row_html($provider_id);
            }
        }
    }

    $html .= '</div></div>';

    return $html;
}

/**
 * 环境检测完整 HTML。
 *
 * 须在 init 之后生成（自定义 Provider 于 init:5 注册）；勿在 CSF 注册阶段（after_setup_theme）直接拼接。
 */
function wanyesea_ai_connect_env_grid_html() {
    if (function_exists('wanyesea_ai_ensure_ai_client_auth')) {
        wanyesea_ai_ensure_ai_client_auth();
    }

    $html  = '<div class="wya-ai-env-section wya-ai-env-section--system">';
    $html .= '<p class="wya-ai-env-section__title">系统依赖</p>';
    $html .= wanyesea_ai_connect_system_env_html();
    $html .= '</div>';

    if (function_exists('wanyesea_ai_connect_endpoint_probe_panel_html')) {
        $html .= '<div class="wya-ai-env-section wya-ai-env-section--endpoints">';
        $html .= '<p class="wya-ai-env-section__title">厂商端点</p>';
        $html .= wanyesea_ai_connect_endpoint_probe_panel_html();
        $html .= '</div>';
    }

    if (function_exists('wanyesea_ai_connect_gateway_env_panel_html')) {
        $html .= '<div class="wya-ai-env-section wya-ai-env-section--gateway">';
        $html .= '<p class="wya-ai-env-section__title">统一网关</p>';
        $html .= wanyesea_ai_connect_gateway_env_panel_html();
        $html .= '</div>';
    }

    $html .= '<div class="wya-ai-env-section wya-ai-env-section--capabilities">';
    $html .= '<p class="wya-ai-env-section__title">AI 能力</p>';

    if (function_exists('wanyesea_ai_connect_text_gen_env_html')) {
        $html .= '<div class="wya-ai-env-text-gen">' . wanyesea_ai_connect_text_gen_env_html() . '</div>';
    }

    if (function_exists('wanyesea_ai_connect_image_gen_env_html')) {
        $html .= '<div class="wya-ai-env-image-gen">' . wanyesea_ai_connect_image_gen_env_html() . '</div>';
    }

    $html .= '</div>';

    return $html;
}
