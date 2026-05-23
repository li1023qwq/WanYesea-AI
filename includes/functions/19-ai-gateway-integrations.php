<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

/**
 * 统一网关与测试实验室 / 环境检测 / 端点探测的集成。
 */

/**
 * @return list<string>
 */
function wanyesea_ai_gateway_text_provider_ids() {
    if (!class_exists('Wanyesea_AI_Gateway_Settings', false)) {
        return array();
    }

    $ids = array();
    foreach (Wanyesea_AI_Gateway_Settings::get_registerable_slots() as $slot_id => $slot) {
        unset($slot);
        $ids[] = Wanyesea_AI_Gateway_Settings::provider_id_for_slot($slot_id);
    }

    return $ids;
}

/**
 * @param string $capability text|image
 * @return list<string>
 */
function wanyesea_ai_gateway_model_ids_for_capability($provider_id, $capability = 'text') {
    if (!class_exists('Wanyesea_AI_Gateway_Settings', false)
        || !Wanyesea_AI_Gateway_Settings::is_gateway_provider_id($provider_id)) {
        return array();
    }

    $capability = $capability === 'image' ? 'image' : 'text';
    $slot_id    = Wanyesea_AI_Gateway_Settings::slot_id_for_provider_id($provider_id);
    $ids        = array();

    foreach (Wanyesea_AI_Gateway_Settings::get_models($slot_id) as $model) {
        $caps = isset($model['capabilities']) && is_array($model['capabilities'])
            ? $model['capabilities']
            : array();
        if ($capability === 'image') {
            if (in_array('image_generation', $caps, true)) {
                $ids[] = $model['id'];
            }
            continue;
        }
        if (in_array('text_generation', $caps, true) || in_array('vision', $caps, true)) {
            $ids[] = $model['id'];
        }
    }

    return array_values(array_unique($ids));
}

/**
 * @return array{url: string, mode: string}
 */
function wanyesea_ai_get_gateway_effective_endpoint($provider_id) {
    $provider_id = sanitize_key((string) $provider_id);
    if (!class_exists('Wanyesea_AI_Gateway_Settings', false)
        || !Wanyesea_AI_Gateway_Settings::is_gateway_provider_id($provider_id)) {
        return array('url' => '', 'mode' => '');
    }

    $slot_id = Wanyesea_AI_Gateway_Settings::slot_id_for_provider_id($provider_id);
    $base    = Wanyesea_AI_Gateway_Settings::api_base_url($slot_id);

    if ($base === '') {
        return array('url' => '', 'mode' => '');
    }

    return array(
        'url'  => rtrim($base, '/'),
        'mode' => 'gateway',
    );
}

add_filter('wanyesea_ai_text_capable_provider_ids', function ($ids) {
    if (!is_array($ids)) {
        $ids = array();
    }
    return array_values(array_unique(array_merge($ids, wanyesea_ai_gateway_text_provider_ids())));
});

add_filter('wanyesea_ai_image_capable_provider_ids', function ($ids) {
    if (!is_array($ids) || !class_exists('Wanyesea_AI_Gateway_Settings', false)) {
        return is_array($ids) ? $ids : array();
    }

    foreach (Wanyesea_AI_Gateway_Settings::get_registerable_slots() as $slot_id => $slot) {
        unset($slot);
        if (Wanyesea_AI_Gateway_Settings::get_mode($slot_id) === Wanyesea_AI_Gateway_Settings::MODE_ANTHROPIC) {
            continue;
        }
        if (Wanyesea_AI_Gateway_Settings::get_api_key($slot_id) === '') {
            continue;
        }
        $pid = Wanyesea_AI_Gateway_Settings::provider_id_for_slot($slot_id);
        if (!in_array($pid, $ids, true)) {
            $ids[] = $pid;
        }
    }

    return array_values(array_unique($ids));
}, 20);

add_filter('wanyesea_ai_connect_provider_meta', function ($meta) {
    if (!is_array($meta) || !class_exists('Wanyesea_AI_Gateway_Settings', false)) {
        return $meta;
    }

    foreach (Wanyesea_AI_Gateway_Settings::get_slots() as $slot) {
        if (empty($slot['enabled'])) {
            continue;
        }
        $pid = isset($slot['provider_id']) ? (string) $slot['provider_id'] : '';
        if ($pid === '') {
            continue;
        }
        $mode_label = (isset($slot['mode']) && $slot['mode'] === Wanyesea_AI_Gateway_Settings::MODE_ANTHROPIC)
            ? 'Anthropic Messages'
            : 'OpenAI Compatible';

        $meta[$pid] = array(
            'label'   => isset($slot['name']) ? (string) $slot['name'] : $pid,
            'icon'    => 'fa fa-cloud',
            'tagline' => '统一网关 · ' . $mode_label,
            'color'   => 'gateway',
        );
    }

    return $meta;
});

add_filter('wanyesea_ai_probe_model_ids_for_capability', function ($ids, $provider_id, $capability) {
    $local = wanyesea_ai_gateway_model_ids_for_capability($provider_id, $capability);
    return $local !== array() ? $local : $ids;
}, 8, 3);

add_filter('wanyesea_ai_provider_registry_status', function ($status, $provider_id) {
    if (!is_array($status)
        || !class_exists('Wanyesea_AI_Gateway_Settings', false)
        || !Wanyesea_AI_Gateway_Settings::is_gateway_provider_id($provider_id)) {
        return $status;
    }

    $slot_id = Wanyesea_AI_Gateway_Settings::slot_id_for_provider_id($provider_id);
    $slot    = Wanyesea_AI_Gateway_Settings::get_slot($slot_id);

    if (empty($slot['enabled']) || Wanyesea_AI_Gateway_Settings::api_base_url($slot_id) === '') {
        return $status;
    }

    $status['has_provider'] = true;

    if (!empty($status['registry_ok'])) {
        return $status;
    }

    if (!empty($slot['status']['ok']) || wanyesea_ai_gateway_model_ids_for_capability($provider_id, 'text') !== array()) {
        $status['registry_ok'] = !empty($status['has_key']);
    }

    return $status;
}, 10, 2);

/**
 * 环境检测：统一网关状态区 HTML。
 */
function wanyesea_ai_connect_gateway_env_panel_html() {
    if (!class_exists('Wanyesea_AI_Gateway_Settings', false)) {
        return '';
    }

    $slots                = Wanyesea_AI_Gateway_Settings::get_slots();
    $gateway_settings_url = admin_url('admin.php?page=WanYesea_AI');

    $html  = '<div class="wya-env-gateway-panel" data-wya-env-gateway-panel>';
    $html .= '<p class="wya-env-gateway-panel__desc muted-3-color em09">';
    $html .= '在 <a href="' . esc_url($gateway_settings_url) . '">AI 统一网关</a> 中配置的 One API / New API 站点；';
    $html .= '启用且填写根地址与 API Key 后，会注册为 <code>wanyesea-gateway</code> Provider，并出现在「晚夜深秋-AI测试」实验室。';
    $html .= '</p>';

    $enabled = 0;
    foreach ($slots as $slot) {
        if (!empty($slot['enabled']) && !empty($slot['site_url'])) {
            $enabled++;
        }
    }

    if ($enabled === 0) {
        $html .= '<p class="muted-3-color em09" style="margin:0">暂无已启用的网关，请先在「AI 统一网关」中添加并启用。</p>';
        $html .= '</div>';
        return $html;
    }

    $html .= '<div class="wya-env-gateway-list">';
    foreach ($slots as $slot) {
        if (empty($slot['enabled'])) {
            continue;
        }
        $html .= wanyesea_ai_connect_gateway_probe_row_html($slot);
    }
    $html .= '</div></div>';

    return $html;
}

/**
 * @param array<string, mixed> $slot
 */
function wanyesea_ai_connect_gateway_probe_row_html(array $slot) {
    $provider_id = isset($slot['provider_id']) ? sanitize_key((string) $slot['provider_id']) : '';
    if ($provider_id === '') {
        return '';
    }

    $meta_all = function_exists('wanyesea_ai_connect_provider_meta') ? wanyesea_ai_connect_provider_meta() : array();
    $meta_row = isset($meta_all[$provider_id]) ? $meta_all[$provider_id] : array(
        'label' => isset($slot['name']) ? (string) $slot['name'] : $provider_id,
        'icon'  => 'fa fa-cloud',
    );

    $endpoint = wanyesea_ai_get_gateway_effective_endpoint($provider_id);
    $has_key  = function_exists('wanyesea_ai_get_connector_api_key_resolved')
        && wanyesea_ai_get_connector_api_key_resolved($provider_id) !== '';
    $status   = isset($slot['status']) && is_array($slot['status']) ? $slot['status'] : array();

    if ($endpoint['url'] === '') {
        $state_class = 'is-warn';
        $state_hint  = '未填根地址';
    } elseif (!$has_key) {
        $state_class = 'is-warn';
        $state_hint  = '未配置密钥';
    } elseif (!empty($status['ok'])) {
        $state_class = 'is-ok';
        $state_hint  = isset($status['message']) ? (string) $status['message'] : '已测速';
    } elseif (wanyesea_ai_gateway_model_ids_for_capability($provider_id, 'text') !== array()) {
        $state_class = 'is-ok';
        $state_hint  = '模型池已配置';
    } else {
        $state_class = 'is-idle';
        $state_hint  = '统一网关 · 待检测';
    }

    $endpoint_label = $endpoint['url'] !== '' ? $endpoint['url'] : '—';

    $html  = '<div class="wya-env-probe-row wya-env-probe-row--gateway ' . esc_attr($state_class) . '" data-wya-probe-provider="' . esc_attr($provider_id) . '">';
    $html .= '<div class="wya-env-probe-row__main">';
    $html .= '<span class="wya-env-probe-row__icon"><i class="' . esc_attr($meta_row['icon'] ?? 'fa fa-cloud') . '"></i></span>';
    $html .= '<span class="wya-env-probe-row__body">';
    $html .= '<span class="wya-env-probe-row__label">' . esc_html($meta_row['label'] ?? $provider_id) . '</span>';
    $html .= '<span class="wya-env-probe-row__endpoint" title="' . esc_attr($endpoint_label) . '">';
    $html .= '<code>' . esc_html($endpoint_label) . '</code></span>';
    $html .= '<span class="wya-env-probe-row__status" data-wya-probe-status>' . esc_html($state_hint) . '</span>';
    $html .= '</span>';
    $html .= '<button type="button" class="button button-small wya-env-probe-btn" data-wya-probe-run="' . esc_attr($provider_id) . '">';
    $html .= '<i class="fa fa-refresh"></i> 检测</button>';
    $html .= '</div>';
    $html .= '<div class="wya-env-probe-row__detail" data-wya-probe-detail hidden></div>';
    $html .= '</div>';

    return $html;
}
