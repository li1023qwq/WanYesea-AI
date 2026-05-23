<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * 解析厂商当前生效的 API 根地址（官方或中转）。
 *
 * @param array<string, mixed> $overrides 可选：relay_enabled(bool)、relay_base_url(string)
 * @return array{url: string, mode: string}
 */
function wanyesea_ai_get_provider_effective_endpoint($provider_id, array $overrides = array()) {
    $provider_id = sanitize_key((string) $provider_id);

    if (class_exists('Wanyesea_AI_Gateway_Settings', false)
        && Wanyesea_AI_Gateway_Settings::is_gateway_provider_id($provider_id)) {
        return wanyesea_ai_get_gateway_effective_endpoint($provider_id);
    }

    $urls        = Wanyesea_AI_Relay::official_base_urls();
    $official    = isset($urls[$provider_id]) ? rtrim((string) $urls[$provider_id], '/') : '';

    $relay_enabled = array_key_exists('relay_enabled', $overrides)
        ? (bool) $overrides['relay_enabled']
        : (Wanyesea_AI_Relay::is_enabled() && wanyesea_ai_switcher_on('relay_' . $provider_id . '_enabled', false));

    $relay_base = '';
    if (array_key_exists('relay_base_url', $overrides)) {
        $relay_base = trim((string) $overrides['relay_base_url']);
    } elseif ($relay_enabled) {
        $relay_base = Wanyesea_AI_Relay::get_provider_base_url($provider_id);
    }

    if ($relay_enabled && $relay_base !== '') {
        $raw = esc_url_raw($relay_base);
        if ($raw !== '' && wp_http_validate_url($raw)) {
            return array(
                'url'  => rtrim($raw, '/'),
                'mode' => 'relay',
            );
        }
    }

    if ($official !== '') {
        return array(
            'url'  => $official,
            'mode' => 'official',
        );
    }

    return array(
        'url'  => '',
        'mode' => '',
    );
}

/**
 * 探测用 API Key（支持 AJAX 传入未保存的密钥）。
 *
 * @param array<string, mixed> $overrides
 */
function wanyesea_ai_probe_resolve_api_key($provider_id, array $overrides = array()) {
    $provider_id = sanitize_key((string) $provider_id);

    if (!empty($overrides['api_key'])) {
        return trim((string) $overrides['api_key']);
    }

    if (function_exists('wanyesea_ai_get_connector_api_key_resolved')) {
        return wanyesea_ai_get_connector_api_key_resolved($provider_id);
    }

    return '';
}

/**
 * 厂商是否已安装对应 Provider 插件（自定义 Connector 视为本插件已集成）。
 */
function wanyesea_ai_probe_provider_plugin_ready($provider_id) {
    $provider_id = sanitize_key((string) $provider_id);
    $status      = wanyesea_ai_wp_ai_status();

    if (class_exists('Wanyesea_AI_Gateway_Settings', false)
        && Wanyesea_AI_Gateway_Settings::is_gateway_provider_id($provider_id)) {
        return class_exists(AiClient::class);
    }

    if (function_exists('wanyesea_ai_is_custom_connect_provider')
        && wanyesea_ai_is_custom_connect_provider($provider_id)) {
        return class_exists(AiClient::class);
    }

    return !empty($status[$provider_id]);
}

/**
 * 构建 GET /models（或厂商等价接口）请求参数。
 *
 * @return array{url: string, headers: array<string, string>, timeout: int}
 */
function wanyesea_ai_probe_build_models_http_request($provider_id, $base_url, $api_key) {
    $provider_id = sanitize_key((string) $provider_id);
    $base_url    = rtrim((string) $base_url, '/');
    $timeout     = (int) apply_filters(
        'wanyesea_ai_provider_probe_timeout',
        45,
        $provider_id
    );
    $timeout = max(15, min(180, $timeout));

    $headers = array(
        'Accept' => 'application/json',
    );

    $url = $base_url . '/models';

    if ($provider_id === 'google') {
        $url = $base_url . '/models?key=' . rawurlencode($api_key);
    } elseif (class_exists('Wanyesea_AI_Gateway_Settings', false)
        && Wanyesea_AI_Gateway_Settings::is_gateway_provider_id($provider_id)
        && Wanyesea_AI_Gateway_Settings::get_mode(
            Wanyesea_AI_Gateway_Settings::slot_id_for_provider_id($provider_id)
        ) === Wanyesea_AI_Gateway_Settings::MODE_ANTHROPIC) {
        $headers['x-api-key']         = $api_key;
        $headers['anthropic-version'] = '2023-06-01';
    } elseif ($provider_id === 'anthropic') {
        $headers['x-api-key']         = $api_key;
        $headers['anthropic-version'] = '2023-06-01';
    } elseif ($provider_id === 'xiaomi') {
        $headers['api-key'] = $api_key;
    } else {
        $headers['Authorization'] = 'Bearer ' . $api_key;
    }

    return array(
        'url'     => $url,
        'headers' => $headers,
        'timeout' => $timeout,
    );
}

/**
 * 从 /models JSON 解析并分类模型 ID。
 *
 * @param array<string, mixed> $body
 * @return array{text: list<string>, image: list<string>, other: list<string>, total: int}
 */
function wanyesea_ai_probe_classify_models_from_payload($body, $provider_id = '') {
    $provider_id = sanitize_key((string) $provider_id);
    $items       = function_exists('wanyesea_ai_parse_openai_compatible_models_payload')
        ? wanyesea_ai_parse_openai_compatible_models_payload($body)
        : array();

    $text   = array();
    $image  = array();
    $other  = array();

    foreach ($items as $model_data) {
        if (!is_array($model_data)) {
            continue;
        }
        $model_id = '';
        if (!empty($model_data['id'])) {
            $model_id = (string) $model_data['id'];
        } elseif (!empty($model_data['name'])) {
            $model_id = (string) $model_data['name'];
        } elseif (!empty($model_data['model'])) {
            $model_id = (string) $model_data['model'];
        }
        if ($model_id === '') {
            continue;
        }
        if (function_exists('wanyesea_ai_should_skip_openai_compatible_model')
            && wanyesea_ai_should_skip_openai_compatible_model($model_id)) {
            continue;
        }

        $supports_text  = !function_exists('wanyesea_ai_openai_compatible_model_supports_text_output')
            || wanyesea_ai_openai_compatible_model_supports_text_output($model_data);
        $supports_image = function_exists('wanyesea_ai_openai_compatible_model_supports_image_output')
            && wanyesea_ai_openai_compatible_model_supports_image_output($model_data);

        if ($supports_text) {
            $text[] = $model_id;
        }
        if ($supports_image) {
            $image[] = $model_id;
        }
        if (!$supports_text && !$supports_image) {
            $other[] = $model_id;
        }
    }

    $text  = array_values(array_unique($text));
    $image = array_values(array_unique($image));
    $other = array_values(array_unique($other));

    return array(
        'text'  => $text,
        'image' => $image,
        'other' => $other,
        'total' => count($text) + count($image) + count($other),
    );
}

/**
 * HTTP 探测 GET /models（或 Google 等价接口）。
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function wanyesea_ai_probe_provider_http($provider_id, array $overrides = array()) {
    $provider_id = sanitize_key((string) $provider_id);
    $endpoint    = wanyesea_ai_get_provider_effective_endpoint($provider_id, $overrides);
    $api_key     = wanyesea_ai_probe_resolve_api_key($provider_id, $overrides);
    $started     = microtime(true);

    $base = array(
        'provider_id'   => $provider_id,
        'reachable'     => false,
        'configured'    => $api_key !== '',
        'plugin_ready'  => wanyesea_ai_probe_provider_plugin_ready($provider_id),
        'endpoint_url'  => $endpoint['url'],
        'endpoint_mode' => $endpoint['mode'],
        'http_code'     => 0,
        'latency_ms'    => 0,
        'message'       => '',
        'models'        => array(
            'text'  => array(),
            'image' => array(),
            'other' => array(),
            'total' => 0,
        ),
        'source'        => 'http',
    );

    if ($api_key === '') {
        $base['message'] = '未配置 API Key，无法探测连通性';
        return apply_filters('wanyesea_ai_provider_probe_result', $base, $provider_id, $overrides);
    }

    if ($endpoint['url'] === '') {
        $base['message'] = '未配置该厂商的官方或中转 API 根地址';
        return apply_filters('wanyesea_ai_provider_probe_result', $base, $provider_id, $overrides);
    }

    $req      = wanyesea_ai_probe_build_models_http_request($provider_id, $endpoint['url'], $api_key);
    $response = wp_safe_remote_get(
        $req['url'],
        array(
            'timeout'     => $req['timeout'],
            'redirection' => 3,
            'headers'     => $req['headers'],
        )
    );

    $base['latency_ms'] = (int) round((microtime(true) - $started) * 1000);

    if (is_wp_error($response)) {
        $base['message'] = $response->get_error_message();
        return apply_filters('wanyesea_ai_provider_probe_result', $base, $provider_id, $overrides);
    }

    $base['http_code'] = (int) wp_remote_retrieve_response_code($response);
    $raw_body          = wp_remote_retrieve_body($response);
    $body              = json_decode($raw_body, true);

    if ($base['http_code'] < 200 || $base['http_code'] >= 300) {
        $snippet = is_string($raw_body) ? wp_strip_all_tags(substr($raw_body, 0, 200)) : '';
        $base['message'] = sprintf(
            'HTTP %d：%s',
            $base['http_code'],
            $snippet !== '' ? $snippet : '请求失败'
        );
        return apply_filters('wanyesea_ai_provider_probe_result', $base, $provider_id, $overrides);
    }

    if (!is_array($body)) {
        $base['message'] = '响应不是有效的 JSON';
        return apply_filters('wanyesea_ai_provider_probe_result', $base, $provider_id, $overrides);
    }

    $classified = wanyesea_ai_probe_classify_models_from_payload($body, $provider_id);
    $base['models']    = $classified;
    $base['reachable'] = $classified['total'] > 0 || !empty($body['data']) || !empty($body['models']);

    if ($classified['total'] > 0) {
        $base['message'] = sprintf(
            '连通正常，共 %d 个可用模型（文本 %d · 图像 %d）',
            $classified['total'],
            count($classified['text']),
            count($classified['image'])
        );
    } elseif ($base['reachable']) {
        $base['message'] = '端点可访问，但未解析到可用模型（响应格式可能不兼容 OpenAI /models）';
    } else {
        $base['message'] = '端点返回成功，但模型列表为空';
    }

    return apply_filters('wanyesea_ai_provider_probe_result', $base, $provider_id, $overrides);
}

/**
 * 通过 WP AI Client Registry 拉取模型（适用于已注册 Provider）。
 *
 * @return array<string, mixed>|null 无法使用时返回 null
 */
function wanyesea_ai_probe_provider_via_registry($provider_id) {
    $provider_id = sanitize_key((string) $provider_id);

    if (!class_exists(AiClient::class)) {
        return null;
    }

    $api_key = wanyesea_ai_probe_resolve_api_key($provider_id, array());
    if ($api_key === '') {
        return null;
    }

    if (function_exists('wanyesea_ai_inject_custom_provider_auth')) {
        wanyesea_ai_inject_custom_provider_auth();
    }
    if (class_exists('Wanyesea_AI_Connectors')) {
        Wanyesea_AI_Connectors::inject_ai_client_auth();
    }

    try {
        $registry = AiClient::defaultRegistry();
    } catch (Throwable $e) {
        return null;
    }

    if (!$registry->hasProvider($provider_id)) {
        return null;
    }

    $started = microtime(true);
    $result  = array(
        'provider_id'   => $provider_id,
        'reachable'     => false,
        'configured'    => true,
        'plugin_ready'  => wanyesea_ai_probe_provider_plugin_ready($provider_id),
        'endpoint_url'  => wanyesea_ai_get_provider_effective_endpoint($provider_id)['url'],
        'endpoint_mode' => wanyesea_ai_get_provider_effective_endpoint($provider_id)['mode'],
        'http_code'     => 0,
        'latency_ms'    => 0,
        'message'       => '',
        'models'        => array(
            'text'  => array(),
            'image' => array(),
            'other' => array(),
            'total' => 0,
        ),
        'source'        => 'registry',
    );

    try {
        $configured = $registry->isProviderConfigured($provider_id);
    } catch (Throwable $e) {
        $configured = false;
    }

    if (!$configured) {
        $result['message'] = 'Provider 未通过可用性校验（密钥无效或无法拉取模型列表）';
        $result['latency_ms'] = (int) round((microtime(true) - $started) * 1000);
        return $result;
    }

    $text_ids  = array();
    $image_ids = array();

    try {
        $text_req = new ModelRequirements(array(CapabilityEnum::textGeneration()), array());
        foreach ($registry->findProviderModelsMetadataForSupport($provider_id, $text_req) as $metadata) {
            if ($metadata instanceof ModelMetadata) {
                $text_ids[] = $metadata->getId();
            }
        }
    } catch (Throwable $e) {
        $text_ids = array();
    }

    try {
        $image_req = new ModelRequirements(array(CapabilityEnum::imageGeneration()), array());
        foreach ($registry->findProviderModelsMetadataForSupport($provider_id, $image_req) as $metadata) {
            if ($metadata instanceof ModelMetadata) {
                $image_ids[] = $metadata->getId();
            }
        }
    } catch (Throwable $e) {
        $image_ids = array();
    }

    $text_ids  = array_values(array_unique($text_ids));
    $image_ids = array_values(array_unique($image_ids));
    $total     = count(array_unique(array_merge($text_ids, $image_ids)));

    $result['latency_ms'] = (int) round((microtime(true) - $started) * 1000);
    $result['models']     = array(
        'text'  => $text_ids,
        'image' => $image_ids,
        'other' => array(),
        'total' => $total,
    );
    $result['reachable']  = $total > 0;

    if ($total > 0) {
        $result['message'] = sprintf(
            'AI Client 校验通过，共 %d 个模型（文本 %d · 图像 %d）',
            $total,
            count($text_ids),
            count($image_ids)
        );
    } else {
        $result['message'] = 'Provider 已配置，但未发现可用模型';
    }

    return apply_filters('wanyesea_ai_provider_probe_result', $result, $provider_id, array());
}

/**
 * 探测单个厂商端点（优先 HTTP 以便支持未保存密钥；否则回退 Registry）。
 *
 * @param array<string, mixed> $overrides api_key, relay_enabled, relay_base_url
 * @return array<string, mixed>
 */
function wanyesea_ai_probe_provider_endpoint($provider_id, array $overrides = array()) {
    $provider_id = sanitize_key((string) $provider_id);
    $has_override_key = !empty($overrides['api_key']);

    if ($has_override_key) {
        return wanyesea_ai_probe_provider_http($provider_id, $overrides);
    }

    $registry_result = wanyesea_ai_probe_provider_via_registry($provider_id);
    if ($registry_result !== null && !empty($registry_result['reachable'])) {
        return $registry_result;
    }

    $http_result = wanyesea_ai_probe_provider_http($provider_id, $overrides);

    if ($registry_result !== null && empty($http_result['reachable']) && !empty($registry_result['configured'])) {
        if (!empty($registry_result['message'])) {
            $http_result['registry_hint'] = $registry_result['message'];
        }
    }

    return $http_result;
}

/**
 * 环境检测：单厂商端点行 HTML（含探测按钮与结果容器）。
 */
function wanyesea_ai_connect_provider_probe_row_html($provider_id) {
    $provider_id = sanitize_key((string) $provider_id);
    $meta_all    = wanyesea_ai_connect_provider_meta();
    $meta        = isset($meta_all[$provider_id]) ? $meta_all[$provider_id] : array(
        'label' => ucfirst($provider_id),
        'icon'  => 'fa fa-plug',
        'color' => $provider_id,
    );

    $endpoint   = wanyesea_ai_get_provider_effective_endpoint($provider_id);
    $has_key    = wanyesea_ai_probe_resolve_api_key($provider_id) !== '';
    $plugin_ok  = wanyesea_ai_probe_provider_plugin_ready($provider_id);

    if (!$plugin_ok) {
        $state_class = 'is-warn';
        $state_hint  = '未安装 Provider';
    } elseif (!$has_key) {
        $state_class = 'is-warn';
        $state_hint  = '未配置密钥';
    } elseif ($endpoint['url'] === '') {
        $state_class = 'is-warn';
        $state_hint  = '无端点地址';
    } else {
        $state_class = 'is-idle';
        $state_hint  = $endpoint['mode'] === 'gateway' ? '统一网关 · 待检测' : ($endpoint['mode'] === 'relay' ? '中转 · 待检测' : '官方 · 待检测');
    }

    $endpoint_label = $endpoint['url'] !== '' ? $endpoint['url'] : '—';

    $html  = '<div class="wya-env-probe-row ' . esc_attr($state_class) . '" data-wya-probe-provider="' . esc_attr($provider_id) . '">';
    $html .= '<div class="wya-env-probe-row__main">';
    $html .= '<span class="wya-env-probe-row__icon"><i class="' . esc_attr($meta['icon'] ?? 'fa fa-plug') . '"></i></span>';
    $html .= '<span class="wya-env-probe-row__body">';
    $html .= '<span class="wya-env-probe-row__label">' . esc_html($meta['label'] ?? $provider_id) . '</span>';
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

/**
 * 中转厂商是否已通过 HTTP /models 探测（与测试页「检测端点 / 加载模型」同源）。
 */
function wanyesea_ai_is_provider_relay_endpoint_verified($provider_id) {
    $provider_id = sanitize_key((string) $provider_id);

    if (!function_exists('wanyesea_ai_relay_is_provider_active')
        || !wanyesea_ai_relay_is_provider_active($provider_id)) {
        return false;
    }

    if (!function_exists('wanyesea_ai_get_connector_api_key_resolved')
        || wanyesea_ai_get_connector_api_key_resolved($provider_id) === '') {
        return false;
    }

    if (!function_exists('wanyesea_ai_probe_model_ids_for_capability')) {
        return true;
    }

    foreach (array('text', 'image') as $cap) {
        if (wanyesea_ai_probe_model_ids_for_capability($provider_id, $cap, false) !== array()) {
            return true;
        }
    }

    // 与测试页直连 chat/completions 一致：有密钥且启用中转即视为可用（不依赖 Registry ListModels）。
    return true;
}

/**
 * 厂商是否已在 AI Client Registry 中注册且通过 isProviderConfigured（与「设置 → 连接」一致）。
 * 启用 API 中转且密钥有效时，若 Registry 仍走官方 ListModels 失败，则回退为 HTTP 探测可用（与测试页一致）。
 */
function wanyesea_ai_is_provider_registry_configured($provider_id) {
    $provider_id = sanitize_key((string) $provider_id);

    if (!class_exists(AiClient::class)) {
        return wanyesea_ai_is_provider_relay_endpoint_verified($provider_id);
    }

    if (function_exists('wanyesea_ai_ensure_ai_client_auth')) {
        wanyesea_ai_ensure_ai_client_auth();
    }

    try {
        $registry = AiClient::defaultRegistry();

        if ($registry->hasProvider($provider_id) && $registry->isProviderConfigured($provider_id)) {
            return true;
        }
    } catch (Throwable $e) {
        unset($e);
    }

    return wanyesea_ai_is_provider_relay_endpoint_verified($provider_id);
}

/**
 * 单厂商在 AI Client Registry 中的就绪状态（与「设置 → 连接」页「已连接」相同标准）。
 *
 * @return array{has_key: bool, registry_ok: bool, has_provider: bool}
 */
function wanyesea_ai_provider_registry_status($provider_id) {
    $provider_id = sanitize_key((string) $provider_id);
    $has_key     = function_exists('wanyesea_ai_get_connector_api_key_resolved')
        ? wanyesea_ai_get_connector_api_key_resolved($provider_id) !== ''
        : false;

    $status = array(
        'has_key'      => $has_key,
        'registry_ok'  => false,
        'has_provider' => false,
    );

    if (!class_exists(AiClient::class) || !$has_key) {
        return apply_filters('wanyesea_ai_provider_registry_status', $status, $provider_id);
    }

    if (function_exists('wanyesea_ai_ensure_ai_client_auth')) {
        wanyesea_ai_ensure_ai_client_auth();
    }

    try {
        $registry = AiClient::defaultRegistry();
        $status['has_provider'] = $registry->hasProvider($provider_id);
        $status['registry_ok']  = function_exists('wanyesea_ai_is_provider_registry_configured')
            ? wanyesea_ai_is_provider_registry_configured($provider_id)
            : ($status['has_provider'] && $registry->isProviderConfigured($provider_id));
    } catch (Throwable $e) {
        if (function_exists('wanyesea_ai_is_provider_registry_configured')) {
            $status['registry_ok'] = wanyesea_ai_is_provider_registry_configured($provider_id);
        }
        unset($e);
    }

    return apply_filters('wanyesea_ai_provider_registry_status', $status, $provider_id);
}

/**
 * 收集各厂商 Registry 状态说明（用于 AI 能力区块）。
 *
 * @param list<string> $provider_ids
 * @return list<string>
 */
function wanyesea_ai_provider_registry_status_lines(array $provider_ids) {
    $meta  = function_exists('wanyesea_ai_connect_provider_meta') ? wanyesea_ai_connect_provider_meta() : array();
    $lines = array();

    $approval_url = function_exists('wanyesea_ai_connector_approval_admin_url')
        ? wanyesea_ai_connector_approval_admin_url()
        : admin_url('tools.php?page=ai-connector-approval');

    foreach ($provider_ids as $provider_id) {
        $provider_id = sanitize_key((string) $provider_id);
        $label       = isset($meta[$provider_id]['label']) ? $meta[$provider_id]['label'] : ucfirst($provider_id);
        $row         = wanyesea_ai_provider_registry_status($provider_id);

        if (!$row['has_key']) {
            continue;
        }

        if ($row['registry_ok']) {
            $lines[] = sprintf('%s：AI Client 已校验（连接页应显示「已连接」）', $label);
            continue;
        }

        if (!$row['has_provider']) {
            $lines[] = sprintf(
                '%s：有密钥，但 Provider 未注册到 AI Client（请刷新本页；若仍失败请确认已启用 WordPress AI 核心插件）',
                $label
            );
            continue;
        }

        $lines[] = sprintf(
            '%s：有密钥，但 AI Client 未校验（连接页不显示「已连接」；「检测」成功不代表此项通过，请保存设置后刷新或到 <a href="%s">Connector 审批</a> 批准本插件）',
            $label,
            esc_url($approval_url)
        );
    }

    return $lines;
}

/**
 * AJAX：探测单个厂商端点。
 */
function wanyesea_ai_ajax_probe_provider_endpoint() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => '权限不足'), 403);
    }

    check_ajax_referer('wanyesea_ai_probe', 'nonce');

    $provider_id = isset($_POST['provider_id']) ? sanitize_key((string) $_POST['provider_id']) : '';
    $allowed_ids = function_exists('wanyesea_ai_connect_endpoint_probe_provider_ids')
        ? wanyesea_ai_connect_endpoint_probe_provider_ids()
        : wanyesea_ai_connect_provider_ids();

    if ($provider_id === '' || !in_array($provider_id, $allowed_ids, true)) {
        wp_send_json_error(array('message' => '无效的厂商 ID，或未为该厂商启用中转'), 400);
    }

    $overrides = array();
    if (isset($_POST['api_key'])) {
        $overrides['api_key'] = sanitize_text_field(wp_unslash((string) $_POST['api_key']));
    }
    if (isset($_POST['relay_base_url'])) {
        $overrides['relay_base_url'] = esc_url_raw(wp_unslash((string) $_POST['relay_base_url']));
    }
    if (isset($_POST['relay_enabled'])) {
        $overrides['relay_enabled'] = filter_var(wp_unslash($_POST['relay_enabled']), FILTER_VALIDATE_BOOLEAN);
    }

    $result = wanyesea_ai_probe_provider_endpoint($provider_id, $overrides);

    wp_send_json_success($result);
}

add_action('wp_ajax_wanyesea_ai_probe_provider', 'wanyesea_ai_ajax_probe_provider_endpoint');

/**
 * AJAX：批量探测全部厂商端点。
 */
function wanyesea_ai_ajax_probe_all_provider_endpoints() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => '权限不足'), 403);
    }

    check_ajax_referer('wanyesea_ai_probe', 'nonce');

    $results = array();
    $probe_ids = function_exists('wanyesea_ai_connect_endpoint_probe_provider_ids')
        ? wanyesea_ai_connect_endpoint_probe_provider_ids()
        : wanyesea_ai_connect_provider_ids();

    foreach ($probe_ids as $provider_id) {
        $results[$provider_id] = wanyesea_ai_probe_provider_endpoint($provider_id);
    }

    wp_send_json_success(array(
        'providers' => $results,
    ));
}

add_action('wp_ajax_wanyesea_ai_probe_all_providers', 'wanyesea_ai_ajax_probe_all_provider_endpoints');
