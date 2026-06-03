<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\DTO\RequiredOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/**
 * 将 Registry 中的鉴权重新绑定到当前 OpenAI 中转 Provider 运行时实例（不清理静态缓存）。
 */
function wanyesea_ai_rebind_openai_provider_runtime_auth() {
    if (!function_exists('wanyesea_ai_relay_is_provider_active') || !wanyesea_ai_relay_is_provider_active('openai')) {
        return;
    }
    if (!class_exists(AiClient::class) || !class_exists('Wanyesea_AI_Relay_OpenAi_Provider')) {
        return;
    }

    try {
        $registry = AiClient::defaultRegistry();
        if (!$registry->hasProvider('openai')) {
            return;
        }

        $auth = $registry->getProviderRequestAuthentication('openai');
        if (!$auth instanceof \WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface) {
            return;
        }

        $directory = Wanyesea_AI_Relay_OpenAi_Provider::modelMetadataDirectory();
        if ($directory instanceof \WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface) {
            $directory->setRequestAuthentication($auth);
        }

        $availability = Wanyesea_AI_Relay_OpenAi_Provider::availability();
        if ($availability instanceof \WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface) {
            $availability->setRequestAuthentication($auth);
        }
    } catch (Throwable $e) {
        return;
    }
}

/**
 * 重新注入连接页/自定义/网关鉴权（不触发中转 Provider 静态缓存清理）。
 */
function wanyesea_ai_reinject_ai_client_auth() {
    if (function_exists('wanyesea_ai_inject_custom_provider_auth')) {
        wanyesea_ai_inject_custom_provider_auth();
    }
    if (class_exists('Wanyesea_AI_Connectors')) {
        Wanyesea_AI_Connectors::inject_ai_client_auth();
    }
    if (function_exists('wanyesea_ai_inject_gateway_provider_auth')) {
        wanyesea_ai_inject_gateway_provider_auth();
    }
    wanyesea_ai_rebind_openai_provider_runtime_auth();
}

/**
 * 注入本插件与 Connectors 的 AI Client 鉴权（幂等，供出图与就绪检测提前调用）。
 */
function wanyesea_ai_ensure_ai_client_auth() {
    static $bootstrapped = false;
    static $relay_registered = false;

    if (!$bootstrapped) {
        $bootstrapped = true;

        if (function_exists('wanyesea_ai_inject_custom_provider_auth')) {
            wanyesea_ai_inject_custom_provider_auth();
        }
        if (class_exists('Wanyesea_AI_Connectors')) {
            Wanyesea_AI_Connectors::inject_ai_client_auth();
        }
        if (function_exists('wanyesea_ai_inject_gateway_provider_auth')) {
            wanyesea_ai_inject_gateway_provider_auth();
        }
        if (function_exists('wanyesea_ai_wrap_relay_official_metadata_directories')) {
            wanyesea_ai_wrap_relay_official_metadata_directories();
        }
    } else {
        wanyesea_ai_reinject_ai_client_auth();
    }

    if (!$relay_registered && function_exists('wanyesea_ai_register_relay_openai_provider')) {
        $relay_registered = true;
        wanyesea_ai_register_relay_openai_provider();
        return;
    }

    wanyesea_ai_rebind_openai_provider_runtime_auth();
}

add_action('init', 'wanyesea_ai_ensure_ai_client_auth', 19);
add_action('rest_api_init', 'wanyesea_ai_ensure_ai_client_auth', 1);

/**
 * 判断指定厂商/模型是否支持 WordPress 出图（含 inline 输出）。
 */
function wanyesea_ai_provider_supports_image_generation($provider_id, $model_id = '') {
    $provider_id = sanitize_key((string) $provider_id);
    $model_id    = sanitize_text_field((string) $model_id);

    if ($provider_id === '' || !class_exists(AiClient::class)) {
        return false;
    }

    if (!in_array($provider_id, wanyesea_ai_image_capable_provider_ids(), true)) {
        return false;
    }

    if (wanyesea_ai_get_connector_api_key_resolved($provider_id) === '') {
        return false;
    }

    wanyesea_ai_ensure_ai_client_auth();

    try {
        $registry = AiClient::defaultRegistry();
    } catch (Throwable $e) {
        return false;
    }

    if (!$registry->hasProvider($provider_id)) {
        return false;
    }

    try {
        if (!$registry->isProviderConfigured($provider_id)) {
            return false;
        }
    } catch (Throwable $e) {
        return false;
    }

    $required_options = array(
        new RequiredOption(OptionEnum::outputFileType(), FileTypeEnum::inline()),
    );

    try {
        if ($model_id !== '') {
            $metadata = $registry->getProviderModel($provider_id, $model_id)->metadata();
            $requirements = new ModelRequirements(
                array(CapabilityEnum::imageGeneration()),
                $required_options
            );

            return $requirements->areMetBy($metadata);
        }

        if (function_exists('wanyesea_ai_get_image_model_hint_for_provider')) {
            $hint = wanyesea_ai_get_image_model_hint_for_provider($provider_id);
            if ($hint !== '') {
                try {
                    $metadata = $registry->getProviderModel($provider_id, $hint)->metadata();
                    $requirements = new ModelRequirements(
                        array(CapabilityEnum::imageGeneration()),
                        $required_options
                    );

                    return $requirements->areMetBy($metadata);
                } catch (Throwable $inner) {
                    // 回退到完整发现。
                }
            }
        }

        $requirements  = new ModelRequirements(array(CapabilityEnum::imageGeneration()), $required_options);
        $metadata_list = $registry->findProviderModelsMetadataForSupport($provider_id, $requirements);

        return $metadata_list !== array();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * 仅支持文本、不支持出图的自定义 Connector ID（勿用于「图像生成」开发者选项）。
 *
 * @return list<string>
 */
function wanyesea_ai_text_only_custom_provider_ids() {
    $ids = array('deepseek', 'moonshot', 'zhipu', 'xiaomi', 'nvidia');

    return apply_filters('wanyesea_ai_text_only_custom_provider_ids', $ids);
}

/**
 * 清除「图像生成」开发者模式中误选的仅文本厂商（轻量校验，避免读取选项时触发大量 HTTP）。
 *
 * @param mixed $value
 * @return mixed
 */
function wanyesea_ai_sanitize_image_generation_developer_option($value) {
    if (!is_array($value)) {
        return $value;
    }

    $provider = isset($value['provider']) ? sanitize_key((string) $value['provider']) : '';
    $model    = isset($value['model']) ? sanitize_text_field((string) $value['model']) : '';

    if ($provider === '') {
        return $value;
    }

    if (in_array($provider, wanyesea_ai_text_only_custom_provider_ids(), true)) {
        return array(
            'provider' => '',
            'model'    => '',
        );
    }

    if ($model !== '' && function_exists('wanyesea_ai_provider_image_model_meets_inline_requirements')) {
        if (!wanyesea_ai_provider_image_model_meets_inline_requirements($provider, $model)) {
            $provider = '';
            $model    = '';
        }
    }

    $value['provider'] = $provider;
    $value['model']    = $model;

    return $value;
}

/**
 * 判断指定厂商/模型是否满足 WordPress 出图（含 inline）要求；优先读静态 fallback，避免开发者选项绑定无效模型。
 */
function wanyesea_ai_provider_image_model_meets_inline_requirements($provider_id, $model_id) {
    $provider_id = sanitize_key((string) $provider_id);
    $model_id    = sanitize_text_field((string) $model_id);

    if ($provider_id === '' || $model_id === '') {
        return false;
    }

    $required_options = array(
        new RequiredOption(OptionEnum::outputFileType(), FileTypeEnum::inline()),
    );
    $requirements = new ModelRequirements(
        array(CapabilityEnum::imageGeneration()),
        $required_options
    );

    if (function_exists('wanyesea_ai_custom_provider_models_fallback_map')) {
        $fallback = wanyesea_ai_custom_provider_models_fallback_map($provider_id);
        if (isset($fallback[$model_id]) && $fallback[$model_id] instanceof ModelMetadata) {
            return $requirements->areMetBy($fallback[$model_id]);
        }
    }

    if (!class_exists(AiClient::class)) {
        return false;
    }

    wanyesea_ai_ensure_ai_client_auth();

    try {
        $registry = AiClient::defaultRegistry();
        if (!$registry->hasProvider($provider_id) || !$registry->isProviderConfigured($provider_id)) {
            return false;
        }
        $metadata = $registry->getProviderModel($provider_id, $model_id)->metadata();

        return $requirements->areMetBy($metadata);
    } catch (Throwable $e) {
        return false;
    }
}

add_filter('option_wpai_feature_image-generation_field_developer', 'wanyesea_ai_sanitize_image_generation_developer_option');

/**
 * AI Ability REST 执行前延长 PHP 时限，避免多家模型探测导致超时并返回非 JSON 错误页。
 *
 * @param string $ability_name
 * @param mixed  $input
 */
function wanyesea_ai_extend_timeout_for_ai_abilities($ability_name, $input) {
    unset($input);

    if (!is_string($ability_name) || strpos($ability_name, 'ai/') !== 0) {
        return;
    }

    $limit = $ability_name === 'ai/image-generation' ? 600 : 300;

    if (function_exists('set_time_limit')) {
        @set_time_limit($limit);
    }

    if (function_exists('ini_set')) {
        @ini_set('max_execution_time', (string) $limit);
    }

    if ($ability_name === 'ai/image-generation') {
        $GLOBALS['wanyesea_ai_image_generation_ability_running'] = true;
    }

    if (function_exists('ignore_user_abort')) {
        @ignore_user_abort(true);
    }
}

/**
 * 出图 Ability 执行期间提高 AI Client 默认 HTTP 超时（核心 Generate_Image 写死 90s，模型层仍会再抬高）。
 *
 * @param float $timeout
 * @return float
 */
function wanyesea_ai_filter_default_timeout_during_image_ability($timeout) {
    if (empty($GLOBALS['wanyesea_ai_image_generation_ability_running'])) {
        return $timeout;
    }

    $minimum = (float) apply_filters('wanyesea_ai_image_generation_rest_client_timeout', 240.0);

    if (!is_numeric($timeout) || (float) $timeout < $minimum) {
        return $minimum;
    }

    return (float) $timeout;
}

add_filter('wp_ai_client_default_request_timeout', 'wanyesea_ai_filter_default_timeout_during_image_ability', 50);

/**
 * 块编辑器「生成特色图像」链路上的 REST（提示词 + 出图 + 导入等）是否处于加速模式。
 */
function wanyesea_ai_editor_ai_image_flow_is_active() {
    return !empty($GLOBALS['wanyesea_ai_editor_ai_image_flow_active']);
}

/**
 * 兼容旧名：出图 REST 加速模式。
 */
function wanyesea_ai_image_generation_rest_is_active() {
    return wanyesea_ai_editor_ai_image_flow_is_active();
}

/**
 * @param bool $active
 */
function wanyesea_ai_set_editor_ai_image_flow_active($active) {
    $GLOBALS['wanyesea_ai_editor_ai_image_flow_active'] = (bool) $active;
    $GLOBALS['wanyesea_ai_image_generation_rest_active']      = (bool) $active;
}

/**
 * @return list<string>
 */
function wanyesea_ai_get_configured_image_provider_ids_with_keys() {
    $ids = array();

    if (!function_exists('wanyesea_ai_image_capable_provider_ids')
        || !function_exists('wanyesea_ai_get_connector_api_key_resolved')) {
        return $ids;
    }

    foreach (wanyesea_ai_image_capable_provider_ids() as $provider_id) {
        if (wanyesea_ai_get_connector_api_key_resolved($provider_id) !== '') {
            $ids[] = $provider_id;
        }
    }

    return $ids;
}

/**
 * 编辑器出图流程优先使用的厂商（已配置 Key 中列表靠前者优先）。
 *
 * @return string
 */
function wanyesea_ai_get_primary_configured_image_provider_for_flow() {
    $ids = wanyesea_ai_get_configured_image_provider_ids_with_keys();

    return $ids !== array() ? $ids[0] : '';
}

/**
 * 已开启中转且填写 Base URL 的出图相关厂商 ID。
 *
 * @return list<string>
 */
function wanyesea_ai_get_relay_active_image_provider_ids() {
    $ids = array();

    if (!class_exists('Wanyesea_AI_Relay') || !Wanyesea_AI_Relay::is_enabled()) {
        return $ids;
    }

    foreach (wanyesea_ai_image_capable_provider_ids() as $provider_id) {
        if (!wanyesea_ai_switcher_on('relay_' . $provider_id . '_enabled', false)) {
            continue;
        }
        if (Wanyesea_AI_Relay::get_provider_base_url($provider_id) === '') {
            continue;
        }
        if (wanyesea_ai_get_connector_api_key_resolved($provider_id) === '') {
            continue;
        }
        $ids[] = $provider_id;
    }

    return $ids;
}

/**
 * @return list<string>
 */
function wanyesea_ai_provider_probe_host_allowlist($provider_id) {
    $provider_id = sanitize_key((string) $provider_id);
    $hosts       = array();

    if (function_exists('wanyesea_ai_is_custom_connect_provider')
        && wanyesea_ai_is_custom_connect_provider($provider_id)
        && class_exists('Wanyesea_AI_Custom_Connectors')) {
        $base = Wanyesea_AI_Custom_Connectors::get_official_base_url($provider_id);
        $host = is_string($base) ? wp_parse_url($base, PHP_URL_HOST) : '';
        if (is_string($host) && $host !== '') {
            $hosts[] = strtolower($host);
        }
    } elseif ($provider_id === 'google') {
        $hosts = array('generativelanguage.googleapis.com', 'googleapis.com', 'ai.google.dev');
    } elseif ($provider_id === 'openai') {
        $hosts = array('api.openai.com', 'openai.com');
    }

    if (Wanyesea_AI_Relay::is_enabled() && wanyesea_ai_switcher_on('relay_' . $provider_id . '_enabled', false)) {
        $relay_url  = Wanyesea_AI_Relay::get_provider_base_url($provider_id);
        $relay_host = is_string($relay_url) ? wp_parse_url($relay_url, PHP_URL_HOST) : '';
        if (is_string($relay_host) && $relay_host !== '') {
            $hosts[] = strtolower($relay_host);
        }
    }

    if (class_exists('Wanyesea_AI_Relay') && Wanyesea_AI_Relay::is_enabled()) {
        $hosts = array_merge($hosts, Wanyesea_AI_Relay::relay_hosts());
    }

    return apply_filters('wanyesea_ai_provider_probe_host_allowlist', array_values(array_unique($hosts)), $provider_id);
}

/**
 * @param string        $host
 * @param list<string>  $allowlist
 */
function wanyesea_ai_host_matches_probe_allowlist($host, array $allowlist) {
    $host = strtolower((string) $host);
    if ($host === '') {
        return false;
    }

    foreach ($allowlist as $allowed) {
        $allowed = strtolower((string) $allowed);
        if ($allowed === '' || $host === $allowed) {
            return true;
        }
        $suffix = '.' . $allowed;
        if (strlen($host) > strlen($suffix) && substr($host, -strlen($suffix)) === $suffix) {
            return true;
        }
    }

    return false;
}

/**
 * 特色图像链路 REST：注入鉴权并进入加速模式（不预热 /models，避免网关 504）。
 *
 * @param mixed           $result
 * @param WP_REST_Server  $server
 * @param WP_REST_Request $request
 * @return mixed
 */
function wanyesea_ai_rest_pre_dispatch_editor_image_flow($result, $server, $request) {
    unset($server);

    if (!($request instanceof WP_REST_Request)) {
        return $result;
    }

    $route = (string) $request->get_route();
    $paths = apply_filters(
        'wanyesea_ai_editor_image_flow_rest_routes',
        array(
            '/wp-abilities/v1/abilities/ai/image-prompt-generation/run',
            '/wp-abilities/v1/abilities/ai/image-generation/run',
            '/wp-abilities/v1/abilities/ai/image-import/run',
            '/wp-abilities/v1/abilities/ai/alt-text-generation/run',
        )
    );

    $matched = false;
    foreach ($paths as $path) {
        if ($route === $path || strpos($route, trim((string) $path, '/')) !== false) {
            $matched = true;
            break;
        }
    }

    if (!$matched) {
        foreach (array('ai/image-prompt-generation/run', 'ai/image-generation/run', 'ai/image-import/run', 'ai/alt-text-generation/run') as $needle) {
            if (strpos($route, $needle) !== false) {
                $matched = true;
                break;
            }
        }
    }

    if (!$matched) {
        return $result;
    }

    wanyesea_ai_set_editor_ai_image_flow_active(true);
    wanyesea_ai_ensure_ai_client_auth();

    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }
    if (function_exists('ini_set')) {
        @ini_set('max_execution_time', '300');
        @ini_set('memory_limit', (string) apply_filters('wanyesea_ai_editor_image_flow_memory_limit', '512M'));
    }

    return $result;
}

add_filter('rest_pre_dispatch', 'wanyesea_ai_rest_pre_dispatch_editor_image_flow', 5, 3);

/**
 * 特色图链路 REST 中应对 GET /models 探测的厂商 ID（已配置 Key）。
 *
 * @return list<string>
 */
function wanyesea_ai_get_editor_flow_models_probe_provider_ids() {
    $ids = array();

    if (function_exists('wanyesea_ai_get_configured_image_provider_ids_with_keys')) {
        $ids = array_merge($ids, wanyesea_ai_get_configured_image_provider_ids_with_keys());
    }

    if (function_exists('wanyesea_ai_text_only_custom_provider_ids')) {
        foreach (wanyesea_ai_text_only_custom_provider_ids() as $provider_id) {
            if (wanyesea_ai_get_connector_api_key_resolved($provider_id) !== '') {
                $ids[] = $provider_id;
            }
        }
    }

    foreach (apply_filters('wanyesea_ai_vision_capable_custom_provider_ids', array('sensenova')) as $provider_id) {
        $provider_id = sanitize_key((string) $provider_id);
        if ($provider_id !== '' && wanyesea_ai_get_connector_api_key_resolved($provider_id) !== '') {
            $ids[] = $provider_id;
        }
    }

    if (class_exists('Wanyesea_AI_Connectors')) {
        foreach (Wanyesea_AI_Connectors::provider_ids() as $provider_id) {
            if (wanyesea_ai_get_connector_api_key_resolved($provider_id) !== '') {
                $ids[] = $provider_id;
            }
        }
    }

    if (class_exists('Wanyesea_AI_Custom_Connectors')) {
        foreach (Wanyesea_AI_Custom_Connectors::provider_ids() as $provider_id) {
            if (wanyesea_ai_get_connector_api_key_resolved($provider_id) !== '') {
                $ids[] = $provider_id;
            }
        }
    }

    return array_values(array_unique(array_filter(array_map('sanitize_key', $ids))));
}

/**
 * 编辑器出图链路：拦截 GET /models——已配置厂商返回静态 fallback 或空列表，避免 Alt/就绪检测触发真实探测超时。
 *
 * @param false|array<string, mixed>|WP_Error $preempt
 * @param array<string, mixed>                $parsed_args
 * @param string                              $url
 * @return false|array<string, mixed>|WP_Error
 */
function wanyesea_ai_pre_http_request_limit_models_probe_during_editor_flow($preempt, $parsed_args, $url) {
    if ($preempt !== false || !wanyesea_ai_editor_ai_image_flow_is_active()) {
        return $preempt;
    }

    $url = (string) $url;
    if (strtoupper((string) ($parsed_args['method'] ?? 'GET')) !== 'GET' || strpos($url, '/models') === false) {
        return $preempt;
    }

    $host = wp_parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return $preempt;
    }

    foreach (wanyesea_ai_get_editor_flow_models_probe_provider_ids() as $provider_id) {
        if (!wanyesea_ai_host_matches_probe_allowlist($host, wanyesea_ai_provider_probe_host_allowlist($provider_id))) {
            continue;
        }

        $body = '';
        if (function_exists('wanyesea_ai_models_probe_json_body_for_provider')) {
            $body = wanyesea_ai_models_probe_json_body_for_provider($provider_id);
        }
        if ($body === '') {
            $body = wp_json_encode(array('data' => array(), 'models' => array()));
        }
        if (!is_string($body) || $body === '') {
            continue;
        }

        return array(
            'headers'  => array('content-type' => 'application/json'),
            'body'     => $body,
            'response' => array('code' => 200, 'message' => 'OK'),
            'cookies'  => array(),
            'filename' => null,
        );
    }

    return $preempt;
}

add_filter('pre_http_request', 'wanyesea_ai_pre_http_request_limit_models_probe_during_editor_flow', 10, 3);

/**
 * 编辑器出图：拉长 SenseNova images/generations 与 CDN 下载的 HTTP 超时。
 *
 * @param array<string, mixed> $args
 * @param string               $url
 * @return array<string, mixed>
 */
function wanyesea_ai_extend_image_generation_http_timeout_during_editor_flow($args, $url) {
    if (!wanyesea_ai_editor_ai_image_flow_is_active()) {
        return $args;
    }

    $url = (string) $url;
    $needs_long_timeout = (strpos($url, '/images/generations') !== false)
        || strpos($url, '/chat/completions') !== false
        || strpos($url, '/v1/responses') !== false;

    if (!$needs_long_timeout) {
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $cdn_hosts = apply_filters(
                'wanyesea_ai_image_download_hosts',
                array('cdn.sensenova.dev', 'cdn.sensenova.cn')
            );
            foreach ((array) $cdn_hosts as $allowed_host) {
                if (strcasecmp($host, (string) $allowed_host) === 0) {
                    $needs_long_timeout = true;
                    break;
                }
            }
        }
    }

    if (!$needs_long_timeout) {
        return $args;
    }

    $timeout = (int) apply_filters('wanyesea_ai_editor_image_flow_image_request_timeout', 240);
    $args['timeout'] = max((int) ($args['timeout'] ?? 5), $timeout);

    return $args;
}

add_filter('http_request_args', 'wanyesea_ai_extend_image_generation_http_timeout_during_editor_flow', 12, 2);

/**
 * 中转场景下 /models 探测略延长，避免网关未超时但 WordPress HTTP 先失败。
 *
 * @param array<string, mixed> $args
 * @param string               $url
 * @return array<string, mixed>
 */
function wanyesea_ai_extend_models_probe_timeout_during_editor_flow($args, $url) {
    if (!wanyesea_ai_editor_ai_image_flow_is_active()) {
        return $args;
    }

    $url = (string) $url;
    if (strpos($url, '/models') === false) {
        return $args;
    }

    $timeout = 20;
    if (class_exists('Wanyesea_AI_Relay') && Wanyesea_AI_Relay::is_enabled()
        && wanyesea_ai_get_relay_active_image_provider_ids() !== array()) {
        $timeout = (int) apply_filters('wanyesea_ai_editor_image_flow_relay_models_probe_timeout', 28);
    } else {
        $timeout = (int) apply_filters('wanyesea_ai_editor_image_flow_models_probe_timeout', 20);
    }

    $args['timeout'] = max((int) ($args['timeout'] ?? 5), $timeout);

    return $args;
}

add_filter('http_request_args', 'wanyesea_ai_extend_models_probe_timeout_during_editor_flow', 11, 2);

/**
 * 出图 Ability 执行前确保加速模式与鉴权（不调用 /models 预热）。
 *
 * @param string $ability_name
 * @param mixed  $input
 */
function wanyesea_ai_bootstrap_image_generation_ability($ability_name, $input) {
    unset($input);

    if (!in_array($ability_name, array('ai/image-generation', 'ai/image-prompt-generation', 'ai/alt-text-generation', 'ai/image-import'), true)) {
        return;
    }

    wanyesea_ai_set_editor_ai_image_flow_active(true);
    wanyesea_ai_ensure_ai_client_auth();
}

add_action('wp_before_execute_ability', 'wanyesea_ai_bootstrap_image_generation_ability', 0, 2);
add_action('wp_before_execute_ability', 'wanyesea_ai_extend_timeout_for_ai_abilities', 1, 2);
add_action('wp_before_execute_ability', 'wanyesea_ai_prime_registry_for_image_prompt_ability', 2, 2);
add_action('wp_before_execute_ability', 'wanyesea_ai_prime_registry_for_image_generation_ability', 2, 2);
add_action('wp_before_execute_ability', 'wanyesea_ai_prime_registry_for_alt_text_ability', 2, 2);

/**
 * 将已配置且支持图像输入的自定义厂商加入视觉模型优先列表（Alt 文本等）。
 *
 * @param array<int, array{0: string, 1: string}> $preferred_models
 * @return array<int, array{0: string, 1: string}>
 */
function wanyesea_ai_prepend_custom_vision_models($preferred_models) {
    if (!is_array($preferred_models)) {
        $preferred_models = array();
    }

    $prepend = array();
    $vision_ids = apply_filters('wanyesea_ai_vision_capable_custom_provider_ids', array('sensenova'));

    foreach ($vision_ids as $provider_id) {
        $provider_id = sanitize_key((string) $provider_id);
        if ($provider_id === '' || wanyesea_ai_get_connector_api_key_resolved($provider_id) === '') {
            continue;
        }
        if (!function_exists('wanyesea_ai_get_vision_model_hint_for_provider')) {
            continue;
        }

        $hint = wanyesea_ai_get_vision_model_hint_for_provider($provider_id);
        if ($hint !== '') {
            $prepend[] = array($provider_id, $hint);
        }
    }

    if ($prepend === array()) {
        return $preferred_models;
    }

    return array_merge($prepend, $preferred_models);
}

add_filter('wpai_preferred_vision_models', 'wanyesea_ai_prepend_custom_vision_models', 15);

/**
 * 编辑器特色图链路：限制 Alt 文本使用的视觉模型，避免探测多家 /models。
 *
 * @param array<int, array{0: string, 1: string}> $preferred_models
 * @return array<int, array{0: string, 1: string}>
 */
function wanyesea_ai_limit_vision_models_during_editor_image_flow($preferred_models) {
    if (!wanyesea_ai_editor_ai_image_flow_is_active() || !is_array($preferred_models)) {
        return $preferred_models;
    }

    $vision_ids = apply_filters('wanyesea_ai_vision_capable_custom_provider_ids', array('sensenova'));
    $limited    = array();

    foreach ($preferred_models as $pair) {
        if (!is_array($pair) || !isset($pair[0], $pair[1])) {
            continue;
        }
        $provider_id = sanitize_key((string) $pair[0]);
        $model_id    = sanitize_text_field((string) $pair[1]);

        if ($provider_id === '' || $model_id === '' || !in_array($provider_id, $vision_ids, true)) {
            continue;
        }
        if (wanyesea_ai_get_connector_api_key_resolved($provider_id) === '') {
            continue;
        }
        if (function_exists('wanyesea_ai_is_image_only_model_id_for_provider')
            && wanyesea_ai_is_image_only_model_id_for_provider($provider_id, $model_id)) {
            continue;
        }

        $limited[] = array($provider_id, $model_id);
        break;
    }

    if ($limited === array()) {
        foreach ($vision_ids as $provider_id) {
            if (wanyesea_ai_get_connector_api_key_resolved($provider_id) === '') {
                continue;
            }
            if (!function_exists('wanyesea_ai_get_vision_model_hint_for_provider')) {
                continue;
            }
            $hint = wanyesea_ai_get_vision_model_hint_for_provider($provider_id);
            if ($hint !== '') {
                $limited[] = array($provider_id, $hint);
                break;
            }
        }
    }

    return $limited !== array() ? $limited : array_slice($preferred_models, 0, 4);
}

add_filter('wpai_preferred_vision_models', 'wanyesea_ai_limit_vision_models_during_editor_image_flow', 25);

/**
 * Alt 文本 Ability 执行前将支持图像输入的模型写入 Registry。
 *
 * @param string $ability_name
 * @param mixed  $input
 */
function wanyesea_ai_prime_registry_for_alt_text_ability($ability_name, $input) {
    unset($input);

    if ($ability_name !== 'ai/alt-text-generation') {
        return;
    }

    wanyesea_ai_ensure_ai_client_auth();

    if (!class_exists(AiClient::class) || !function_exists('wanyesea_ai_get_vision_model_hint_for_provider')) {
        return;
    }

    try {
        $registry = AiClient::defaultRegistry();
    } catch (Throwable $e) {
        return;
    }

    $vision_ids = apply_filters('wanyesea_ai_vision_capable_custom_provider_ids', array('sensenova'));

    foreach ($vision_ids as $provider_id) {
        $provider_id = sanitize_key((string) $provider_id);
        if ($provider_id === '' || wanyesea_ai_get_connector_api_key_resolved($provider_id) === '') {
            continue;
        }

        $hint = wanyesea_ai_get_vision_model_hint_for_provider($provider_id);
        if ($hint === '') {
            continue;
        }

        try {
            if (!$registry->hasProvider($provider_id) || !$registry->isProviderConfigured($provider_id)) {
                continue;
            }
            $registry->getProviderModel($provider_id, $hint);
        } catch (Throwable $e) {
            continue;
        }
    }
}

/**
 * 判断模型 ID 是否应作为「仅出图」模型排除在特色图提示词阶段之外。
 */
function wanyesea_ai_is_image_only_model_id_for_provider($provider_id, $model_id) {
    $provider_id = sanitize_key((string) $provider_id);
    $model_id    = strtolower(sanitize_text_field((string) $model_id));

    if ($provider_id === '' || $model_id === '') {
        return false;
    }

    if (function_exists('wanyesea_ai_get_image_model_hint_for_provider')) {
        $image_hint = strtolower((string) wanyesea_ai_get_image_model_hint_for_provider($provider_id));
        if ($image_hint !== '' && $model_id === $image_hint) {
            return true;
        }
    }

    if (class_exists('Wanyesea_AI_Custom_Connectors')) {
        $defs = Wanyesea_AI_Custom_Connectors::definitions();
        $def  = isset($defs[$provider_id]) && is_array($defs[$provider_id]) ? $defs[$provider_id] : array();
        if (!empty($def['preferred_image_model_hint'])
            && strtolower(trim((string) $def['preferred_image_model_hint'])) === $model_id) {
            return true;
        }
    }

    if (function_exists('wanyesea_ai_openai_compatible_model_likely_image_by_id')
        && wanyesea_ai_openai_compatible_model_likely_image_by_id($model_id)) {
        return true;
    }

    return false;
}

/**
 * 将 [[provider, model], ...] 合并进目标列表（去重，可选每厂商上限）。
 *
 * @param list<array{0: string, 1: string}> $target
 * @param list<array{0: string, 1: string}> $source
 * @param array<string, int>                $counts
 * @param int                               $per_provider_limit
 * @return list<array{0: string, 1: string}>
 */
function wanyesea_ai_merge_text_model_preference_pairs($target, $source, &$counts, $per_provider_limit = 1) {
    $seen = array();
    foreach ($target as $pair) {
        if (!is_array($pair) || !isset($pair[0], $pair[1])) {
            continue;
        }
        $seen[sanitize_key((string) $pair[0]) . "\0" . sanitize_text_field((string) $pair[1])] = true;
    }

    foreach ($source as $pair) {
        if (!is_array($pair) || !isset($pair[0], $pair[1])) {
            continue;
        }
        $provider_id = sanitize_key((string) $pair[0]);
        $model_id    = sanitize_text_field((string) $pair[1]);
        if ($provider_id === '' || $model_id === '') {
            continue;
        }
        if (wanyesea_ai_is_image_only_model_id_for_provider($provider_id, $model_id)) {
            continue;
        }

        $key = $provider_id . "\0" . $model_id;
        if (isset($seen[$key])) {
            continue;
        }

        if ($per_provider_limit > 0) {
            if (!isset($counts[$provider_id])) {
                $counts[$provider_id] = 0;
            }
            if ($counts[$provider_id] >= $per_provider_limit) {
                continue;
            }
            $counts[$provider_id]++;
        }

        $seen[$key]  = true;
        $target[]    = array($provider_id, $model_id);
    }

    return $target;
}

/**
 * 使用与特色图提示词 Ability 相同的条件检测文本生成是否可用。
 *
 * @param list<array{0: string, 1: string}> $preferred_models
 */
function wanyesea_ai_image_prompt_text_generation_is_supported(array $preferred_models) {
    if (!function_exists('wp_ai_client_prompt')) {
        return false;
    }

    wanyesea_ai_ensure_ai_client_auth();

    try {
        $builder = wp_ai_client_prompt('ping')->using_system_instruction('ping');
        if ($preferred_models !== array()) {
            $builder->using_model_preference(...$preferred_models);
        }

        return (bool) $builder->is_supported_for_text_generation();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * 特色图提示词执行前预热 Registry（静态 hint + 已配置厂商），避免 unsupported_model。
 *
 * @param string $ability_name
 * @param mixed  $input
 */
function wanyesea_ai_prime_registry_for_image_prompt_ability($ability_name, $input) {
    unset($input);

    if ($ability_name !== 'ai/image-prompt-generation') {
        return;
    }

    wanyesea_ai_ensure_ai_client_auth();

    if (!class_exists(AiClient::class)) {
        return;
    }

    $provider_ids = wanyesea_ai_get_configured_image_provider_ids_with_keys();

    if (class_exists('Wanyesea_AI_Custom_Connectors')) {
        foreach (Wanyesea_AI_Custom_Connectors::provider_ids() as $provider_id) {
            if (wanyesea_ai_get_connector_api_key_resolved($provider_id) === '') {
                continue;
            }
            $provider_ids[] = $provider_id;
        }
    }

    $provider_ids = array_values(array_unique(array_filter(array_map('sanitize_key', $provider_ids))));

    try {
        $registry     = AiClient::defaultRegistry();
        $requirements = new ModelRequirements(array(CapabilityEnum::textGeneration()), array());
    } catch (Throwable $e) {
        return;
    }

    foreach ($provider_ids as $provider_id) {
        try {
            if (!$registry->hasProvider($provider_id) || !$registry->isProviderConfigured($provider_id)) {
                continue;
            }
            $registry->findProviderModelsMetadataForSupport($provider_id, $requirements);
        } catch (Throwable $e) {
            continue;
        }
    }
}

/**
 * 出图 Ability 执行前将 sensenova-u1-fast 等写入 Registry（避免 preferred 列表有 ID 但 getProviderModel 找不到）。
 *
 * @param string $ability_name
 * @param mixed  $input
 */
function wanyesea_ai_prime_registry_for_image_generation_ability($ability_name, $input) {
    unset($input);

    if ($ability_name !== 'ai/image-generation') {
        return;
    }

    wanyesea_ai_ensure_ai_client_auth();

    if (!class_exists(AiClient::class)) {
        return;
    }

    try {
        $registry = AiClient::defaultRegistry();
    } catch (Throwable $e) {
        return;
    }

    if (!function_exists('wanyesea_ai_get_image_model_hint_for_provider')) {
        return;
    }

    foreach (wanyesea_ai_get_configured_image_provider_ids_with_keys() as $provider_id) {
        $hint = wanyesea_ai_get_image_model_hint_for_provider($provider_id);
        if ($hint === '') {
            continue;
        }

        try {
            if (!$registry->hasProvider($provider_id) || !$registry->isProviderConfigured($provider_id)) {
                continue;
            }
            $registry->getProviderModel($provider_id, $hint);
        } catch (Throwable $e) {
            continue;
        }
    }
}

/**
 * 已配置密钥的仅文本 Connector：用 hint 补全提示词阶段的模型优先列表（不拉 /models）。
 *
 * @param list<array{0: string, 1: string}> $limited
 * @param array<string, int>                $counts
 * @param int                               $max_pairs
 * @return list<array{0: string, 1: string}>
 */
function wanyesea_ai_append_text_only_fallback_models_for_image_prompt($limited, $counts, $max_pairs = 2) {
    $text_only = function_exists('wanyesea_ai_text_only_custom_provider_ids')
        ? wanyesea_ai_text_only_custom_provider_ids()
        : array();

    $fallback_pairs = array();
    foreach ($text_only as $provider_id) {
        if (wanyesea_ai_get_connector_api_key_resolved($provider_id) === '') {
            continue;
        }
        if (function_exists('wanyesea_ai_get_custom_provider_text_model_preferences_cached')) {
            $pairs = wanyesea_ai_get_custom_provider_text_model_preferences_cached($provider_id);
            if ($pairs !== array()) {
                $fallback_pairs[] = array($provider_id, $pairs[0][1]);
                continue;
            }
        }
        if (function_exists('wanyesea_ai_custom_provider_text_models_fallback_map')) {
            $map = wanyesea_ai_custom_provider_text_models_fallback_map($provider_id);
            if ($map !== array()) {
                $fallback_pairs[] = array($provider_id, (string) array_key_first($map));
            }
        }
    }

    if ($fallback_pairs === array()) {
        return $limited;
    }

    return wanyesea_ai_merge_text_model_preference_pairs($limited, $fallback_pairs, $counts, 1);
}

/**
 * 特色图像提示词阶段：优先已配置出图厂商的文本模型；必要时回退仅文本 Connector 的 hint。
 *
 * @param array<int, array{0: string, 1: string}> $preferred_models
 * @return array<int, array{0: string, 1: string}>
 */
function wanyesea_ai_limit_text_models_during_editor_image_flow($preferred_models) {
    if (!wanyesea_ai_editor_ai_image_flow_is_active() || !is_array($preferred_models)) {
        return $preferred_models;
    }

    $image_providers = wanyesea_ai_get_configured_image_provider_ids_with_keys();

    if ($image_providers === array()) {
        $slice = array_slice($preferred_models, 0, (int) apply_filters('wanyesea_ai_editor_image_flow_text_model_limit', 4));
        if (wanyesea_ai_image_prompt_text_generation_is_supported($slice)) {
            return $slice;
        }

        return wanyesea_ai_append_text_only_fallback_models_for_image_prompt($slice, array(), 2);
    }

    $limited = array();
    $counts  = array();
    $per_provider = (int) apply_filters('wanyesea_ai_editor_image_flow_text_models_per_provider', 1);
    if ($per_provider < 1) {
        $per_provider = 1;
    }

    foreach ($image_providers as $provider_id) {
        if (function_exists('wanyesea_ai_get_custom_provider_text_model_preferences_cached')) {
            $pairs = wanyesea_ai_get_custom_provider_text_model_preferences_cached($provider_id);
            if ($pairs !== array()) {
                $limited = wanyesea_ai_merge_text_model_preference_pairs($limited, $pairs, $counts, $per_provider);
            }
        }
    }

    $limited = wanyesea_ai_merge_text_model_preference_pairs($limited, $preferred_models, $counts, $per_provider);

    foreach ($image_providers as $provider_id) {
        if (isset($counts[$provider_id]) && $counts[$provider_id] > 0) {
            continue;
        }
        $hint_pairs = array();
        if (class_exists('Wanyesea_AI_Custom_Connectors')) {
            $defs = Wanyesea_AI_Custom_Connectors::definitions();
            $def  = isset($defs[$provider_id]) && is_array($defs[$provider_id]) ? $defs[$provider_id] : array();
            $hint = !empty($def['preferred_model_hint']) ? trim((string) $def['preferred_model_hint']) : '';
            if ($hint !== '' && !wanyesea_ai_is_image_only_model_id_for_provider($provider_id, $hint)) {
                $hint_pairs[] = array($provider_id, $hint);
            }
        }
        $limited = wanyesea_ai_merge_text_model_preference_pairs($limited, $hint_pairs, $counts, $per_provider);
    }

    $max = (int) apply_filters('wanyesea_ai_editor_image_flow_text_model_limit', min(6, count($image_providers) * 2));
    if ($max > 0 && count($limited) > $max) {
        $limited = array_slice($limited, 0, $max);
    }

    if ($limited === array()) {
        $limited = array_slice($preferred_models, 0, 4);
    }

    if (!wanyesea_ai_image_prompt_text_generation_is_supported($limited)) {
        $limited = wanyesea_ai_append_text_only_fallback_models_for_image_prompt($limited, array(), 2);
        if ($max > 0 && count($limited) > $max + 2) {
            $limited = array_slice($limited, 0, $max + 2);
        }
    }

    return $limited;
}

add_filter('wpai_preferred_text_models', 'wanyesea_ai_limit_text_models_during_editor_image_flow', 25);

/**
 * 子比主题在 admin_footer 输出 console.log，可能触发古腾堡 Quirks Mode 警告；块编辑器下移除。
 */
function wanyesea_ai_block_editor_remove_zib_console() {
    if (!function_exists('get_current_screen')) {
        return;
    }

    $screen = get_current_screen();
    if (!$screen || empty($screen->is_block_editor())) {
        return;
    }

    remove_action('admin_footer', 'zib_win_console', 99);
}

add_action('admin_enqueue_scripts', 'wanyesea_ai_block_editor_remove_zib_console', 1);

/**
 * 提高 WP AI Client 默认 HTTP 超时，避免图像提示词/文本生成在默认 30 秒内被截断。
 */
function wanyesea_ai_filter_default_ai_client_timeout($timeout) {
    $minimum = 90.0;

    if (!is_numeric($timeout) || (float) $timeout < $minimum) {
        return $minimum;
    }

    return (float) $timeout;
}

add_filter('wp_ai_client_default_request_timeout', 'wanyesea_ai_filter_default_ai_client_timeout');

/**
 * 支持 WordPress AI 图像生成的 Provider ID（与 get_preferred_image_models 一致）。
 *
 * @return list<string>
 */
function wanyesea_ai_image_capable_provider_ids() {
    $ids = array('sensenova', 'google', 'openai');

    return apply_filters('wanyesea_ai_image_capable_provider_ids', $ids);
}

/**
 * 自定义 Connector 是否已配置且 Provider 在 AI Client 中可用。
 */
function wanyesea_ai_is_custom_image_provider_ready($provider_id) {
    $provider_id = sanitize_key((string) $provider_id);

    if (!function_exists('wanyesea_ai_is_custom_connect_provider')
        || !wanyesea_ai_is_custom_connect_provider($provider_id)) {
        return false;
    }
    if (wanyesea_ai_get_connector_api_key_resolved($provider_id) === '') {
        return false;
    }
    if (!class_exists(AiClient::class)) {
        return false;
    }

    if (function_exists('wanyesea_ai_is_provider_registry_configured')) {
        return wanyesea_ai_is_provider_registry_configured($provider_id);
    }

    try {
        $registry = AiClient::defaultRegistry();
        return $registry->hasProvider($provider_id) && $registry->isProviderConfigured($provider_id);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * 读取已保存的 API Key（本插件选项 → Connectors 选项）。
 */
function wanyesea_ai_get_connector_api_key_resolved($provider_id) {
    $provider_id = sanitize_key((string) $provider_id);

    if (function_exists('wanyesea_ai_resolve_env_api_key')) {
        $from_env = wanyesea_ai_resolve_env_api_key($provider_id);
        if ($from_env !== '') {
            return $from_env;
        }
    }

    if (function_exists('wanyesea_ai_get_gateway_api_key_resolved')) {
        $gateway_key = wanyesea_ai_get_gateway_api_key_resolved($provider_id);
        if ($gateway_key !== '') {
            return $gateway_key;
        }
    }

    if (function_exists('wanyesea_ai_get_custom_connector_api_key_resolved')
        && function_exists('wanyesea_ai_is_custom_connect_provider')
        && wanyesea_ai_is_custom_connect_provider($provider_id)) {
        $custom_key = wanyesea_ai_get_custom_connector_api_key_resolved($provider_id);
        if ($custom_key !== '') {
            return $custom_key;
        }
    }

    if (class_exists('Wanyesea_AI_Connectors') && in_array($provider_id, Wanyesea_AI_Connectors::provider_ids(), true)) {
        $key = Wanyesea_AI_Connectors::get_api_key($provider_id);
        if ($key !== '') {
            return $key;
        }
        $names = Wanyesea_AI_Connectors::connector_option_names();
        if (isset($names[$provider_id])) {
            $stored = get_option($names[$provider_id], '');
            if (is_string($stored) && trim($stored) !== '') {
                return trim($stored);
            }
        }
    }

    return '';
}

/**
 * 当前站点是否具备图像生成能力（与官方 ai 插件 ensure_image_generation_supported 判定一致）。
 */
function wanyesea_ai_is_image_generation_available() {
    if (!function_exists('wp_ai_client_prompt')) {
        return false;
    }

    wanyesea_ai_ensure_ai_client_auth();

    try {
        $builder = wp_ai_client_prompt('ping');
        $builder->as_output_file_type(FileTypeEnum::inline());

        return (bool) $builder->is_supported_for_image_generation();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * 设置页「AI 能力 → 图像生成」就绪判定（与 Generate Image 实际可用性对齐）。
 *
 * 官方 is_supported_for_image_generation 在自定义 Provider（如 SenseNova）未写入 Registry 模型前常为 false，
 * 但出图 Ability 仍会成功；此处将已配置且就绪的自定义出图厂商视为可用。
 */
function wanyesea_ai_is_image_generation_available_for_env() {
    if (wanyesea_ai_is_image_generation_available()) {
        return true;
    }

    foreach (wanyesea_ai_image_capable_provider_ids() as $provider_id) {
        if (function_exists('wanyesea_ai_is_custom_image_provider_ready')
            && wanyesea_ai_is_custom_image_provider_ready($provider_id)) {
            return true;
        }
    }

    return (bool) apply_filters('wanyesea_ai_is_image_generation_available_for_env', false);
}

/**
 * 图像生成不可用时的人类可读原因（简体中文）。
 *
 * @return list<string>
 */
function wanyesea_ai_image_generation_blockers() {
    $blockers = array();
    $status   = wanyesea_ai_wp_ai_status();

    if (empty($status['core'])) {
        $blockers[] = '未启用 WordPress AI 核心插件（<code>ai</code>）';
    }
    if (empty($status['client'])) {
        $blockers[] = '未检测到 WP AI Client';
    }

    $capable = wanyesea_ai_image_capable_provider_ids();
    $has_capable_plugin = false;
    $has_any_key        = false;
    $configured_ids     = array();

    foreach ($capable as $provider_id) {
        $is_custom_image = function_exists('wanyesea_ai_is_custom_connect_provider')
            && wanyesea_ai_is_custom_connect_provider($provider_id);

        if ($is_custom_image) {
            if (wanyesea_ai_is_custom_image_provider_ready($provider_id)) {
                $has_capable_plugin = true;
                $has_any_key        = true;
                $configured_ids[]   = $provider_id;
            } elseif (wanyesea_ai_get_connector_api_key_resolved($provider_id) !== '') {
                $has_any_key = true;
            }
            continue;
        }

        if (!empty($status[$provider_id])) {
            $has_capable_plugin = true;
        }
        if (wanyesea_ai_get_connector_api_key_resolved($provider_id) !== '') {
            $has_any_key = true;
        }
        if (function_exists('wanyesea_ai_is_provider_registry_configured')
            && wanyesea_ai_is_provider_registry_configured($provider_id)) {
            $configured_ids[] = $provider_id;
        }
    }

    if (!$has_capable_plugin) {
        $blockers[] = '未安装或未启用 <code>ai-provider-for-google</code> / <code>ai-provider-for-openai</code>，或未配置 SenseNova API Key（出图模型 <code>sensenova-u1-fast</code>）';
    }
    if (!$has_any_key) {
        $blockers[] = '未配置 Google、OpenAI 或 SenseNova 的 API Key（可在「设置 → 连接」或本插件「AI 连接」中填写）';
    } elseif (empty($configured_ids) && $has_capable_plugin) {
        $blockers[] = '已填写 API Key，但没有任何出图厂商通过 <strong>AI Client 可用性校验</strong>（与连接页「已连接」相同）。「厂商端点 → 检测」成功不代表此项通过。';
        if (function_exists('wanyesea_ai_provider_registry_status_lines')) {
            $detail = wanyesea_ai_provider_registry_status_lines(wanyesea_ai_image_capable_provider_ids());
            if ($detail !== array()) {
                $blockers = array_merge($blockers, $detail);
            }
        }
    }

    if ($configured_ids !== array() && !wanyesea_ai_is_image_generation_available_for_env()) {
        $blockers[] = '已有厂商通过校验（' . esc_html(implode('、', $configured_ids)) . '），但 WordPress AI 尚未识别出图能力：请到 <a href="' . esc_url(admin_url('options-general.php?page=ai-wp-admin')) . '">设置 → AI</a> 开启图像生成实验，并在开发者模式中选取出图模型（如 <code>sensenova-u1-fast</code>）。若 Generate Image 已可用，可忽略本提示。';
    }

    if (Wanyesea_AI_Relay::is_enabled()) {
        foreach ($capable as $provider_id) {
            if ($provider_id === 'sensenova') {
                continue;
            }
            if (!wanyesea_ai_switcher_on('relay_' . $provider_id . '_enabled', false)) {
                continue;
            }
            if (Wanyesea_AI_Relay::get_provider_base_url($provider_id) === '') {
                continue;
            }
            if (!in_array($provider_id, $configured_ids, true)) {
                $meta = wanyesea_ai_connect_provider_meta();
                $label = isset($meta[$provider_id]['label']) ? $meta[$provider_id]['label'] : ucfirst($provider_id);
                $blockers[] = sprintf(
                    '%s 已启用中转但无法连通，请检查中转 Base URL 是否兼容该厂商的图像/模型列表接口',
                    $label
                );
                break;
            }
        }
    }

    if (empty($blockers) && !wanyesea_ai_is_image_generation_available_for_env()) {
        $blockers[] = '请在「设置 → AI」中确认已启用图像生成实验功能；若开发者模式指定了仅文本厂商（如 DeepSeek），请清空「图像生成」的厂商/模型或改选 Google / OpenAI / SenseNova 出图模型';
    }

    return apply_filters('wanyesea_ai_image_generation_blockers', $blockers);
}

/**
 * 环境检测：图像生成就绪状态 HTML。
 */
function wanyesea_ai_connect_image_gen_env_html() {
    $ok        = wanyesea_ai_is_image_generation_available_for_env();
    $class     = $ok ? 'is-ok' : 'is-warn';
    $icon      = $ok ? 'fa-check-circle' : 'fa-exclamation-circle';
    $hint      = $ok ? 'Google / OpenAI / SenseNova' : '需配置密钥';
    $blockers  = $ok ? array() : wanyesea_ai_image_generation_blockers();

    $html = '<div class="wya-ai-env-item wya-ai-env-item--image-gen ' . esc_attr($class) . '">';
    $html .= '<span class="wya-ai-env-item__icon"><i class="fa ' . esc_attr($icon) . '"></i></span>';
    $html .= '<span class="wya-ai-env-item__body">';
    $html .= '<span class="wya-ai-env-item__label">图像生成</span>';
    $html .= '<span class="wya-ai-env-item__hint">' . esc_html($hint) . '</span>';
    $html .= '</span></div>';

    if (!$ok && $blockers) {
        $html .= '<ul class="wya-ai-image-gen-blockers muted-3-color em09">';
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

/**
 * 允许从 SenseNova 等 CDN 拉取出图结果（Generate_Image 需将 url 转为 base64）。
 *
 * @param bool   $allow
 * @param string $host
 * @param string $url
 * @return bool
 */
function wanyesea_ai_allow_image_cdn_hosts($allow, $host, $url) {
    unset($url);
    if ($allow) {
        return $allow;
    }

    $host = strtolower((string) $host);
    $hosts = apply_filters(
        'wanyesea_ai_image_download_hosts',
        array('cdn.sensenova.dev', 'cdn.sensenova.cn')
    );

    foreach ($hosts as $allowed) {
        $allowed = strtolower((string) $allowed);
        if ($allowed === '' || $host === $allowed) {
            return true;
        }
        $suffix = '.' . $allowed;
        if (strlen($host) > strlen($suffix) && substr($host, -strlen($suffix)) === $suffix) {
            return true;
        }
    }

    return $allow;
}

add_filter('http_request_host_is_external', 'wanyesea_ai_allow_image_cdn_hosts', 10, 3);

/**
 * 是否为 AI 设置页拉取出图 Provider 列表的 REST 请求。
 */
function wanyesea_ai_is_ai_providers_image_generation_rest_request($request) {
    if (!($request instanceof WP_REST_Request)) {
        return false;
    }
    if (strtoupper((string) $request->get_method()) !== 'GET') {
        return false;
    }
    if ((string) $request->get_param('capability') !== 'image_generation') {
        return false;
    }

    $route = (string) $request->get_route();

    return $route === '/ai/v1/providers' || strpos($route, '/ai/v1/providers') !== false;
}

/**
 * 在官方 Models_Controller 执行前注入鉴权，便于 /models 与 Registry 发现出图模型。
 *
 * @param mixed           $result
 * @param WP_REST_Server  $server
 * @param WP_REST_Request $request
 * @return mixed
 */
function wanyesea_ai_rest_pre_dispatch_ai_providers_image($result, $server, $request) {
    unset($server);

    if (!wanyesea_ai_is_ai_providers_image_generation_rest_request($request)) {
        return $result;
    }

    wanyesea_ai_ensure_ai_client_auth();

    return $result;
}

add_filter('rest_pre_dispatch', 'wanyesea_ai_rest_pre_dispatch_ai_providers_image', 4, 3);

/**
 * 收集某厂商可供「图像生成」开发者设置下拉使用的模型（id + name）。
 *
 * @return list<array{id: string, name: string}>
 */
function wanyesea_ai_collect_image_model_choices_for_provider($provider_id) {
    $provider_id = sanitize_key((string) $provider_id);

    if ($provider_id === '' || wanyesea_ai_get_connector_api_key_resolved($provider_id) === '') {
        return array();
    }

    $seen    = array();
    $choices = array();

    $add = static function ($model_id, $model_name = '') use (&$seen, &$choices) {
        $model_id = sanitize_text_field((string) $model_id);
        if ($model_id === '' || isset($seen[$model_id])) {
            return;
        }
        $seen[$model_id] = true;
        $choices[]       = array(
            'id'   => $model_id,
            'name' => $model_name !== '' ? (string) $model_name : $model_id,
        );
    };

    wanyesea_ai_ensure_ai_client_auth();

    if (class_exists(AiClient::class)) {
        try {
            $registry = AiClient::defaultRegistry();
            if ($registry->hasProvider($provider_id) && $registry->isProviderConfigured($provider_id)) {
                $requirements = new ModelRequirements(array(CapabilityEnum::imageGeneration()), array());
                foreach ($registry->findProviderModelsMetadataForSupport($provider_id, $requirements) as $metadata) {
                    if ($metadata instanceof ModelMetadata) {
                        $add($metadata->getId(), $metadata->getName());
                    }
                }
            }
        } catch (Throwable $e) {
            // 继续用 hint / 优先列表补全。
        }
    }

    if (function_exists('WordPress\\AI\\get_preferred_image_models')) {
        foreach (\WordPress\AI\get_preferred_image_models() as $pair) {
            if (!is_array($pair) || !isset($pair[0], $pair[1])) {
                continue;
            }
            if (sanitize_key((string) $pair[0]) === $provider_id) {
                $add($pair[1]);
            }
        }
    }

    if (function_exists('wanyesea_ai_get_image_model_hint_for_provider')) {
        $hint = wanyesea_ai_get_image_model_hint_for_provider($provider_id);
        if ($hint !== '') {
            $add($hint);
        }
    }

    if (function_exists('wanyesea_ai_discover_provider_image_model_ids')) {
        foreach (wanyesea_ai_discover_provider_image_model_ids($provider_id, true) as $model_id) {
            $add($model_id);
        }
    }

    if (function_exists('wanyesea_ai_custom_provider_models_fallback_map')) {
        foreach (wanyesea_ai_custom_provider_models_fallback_map($provider_id) as $model_id => $metadata) {
            if (!$metadata instanceof ModelMetadata) {
                continue;
            }
            foreach ($metadata->getSupportedCapabilities() as $capability) {
                if ($capability->isImageGeneration()) {
                    $add($model_id, $metadata->getName());
                    break;
                }
            }
        }
    }

    if (class_exists(AiClient::class)) {
        try {
            $registry = AiClient::defaultRegistry();
            if ($registry->hasProvider($provider_id) && $registry->isProviderConfigured($provider_id)) {
                foreach ($choices as $index => $choice) {
                    try {
                        $metadata = $registry->getProviderModel($provider_id, $choice['id'])->metadata();
                        $choices[$index]['name'] = $metadata->getName();
                    } catch (Throwable $inner) {
                        // 保留已有显示名。
                    }
                }
            }
        } catch (Throwable $e) {
            // 忽略。
        }
    }

    return apply_filters('wanyesea_ai_image_model_choices_for_provider', $choices, $provider_id);
}

/**
 * 补全官方 AI 设置页 GET /ai/v1/providers?capability=image_generation 的出图模型列表。
 *
 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response
 * @param WP_REST_Server                                 $server
 * @param WP_REST_Request                                $request
 * @return mixed
 */
function wanyesea_ai_augment_ai_providers_rest_image_models($response, $server, $request) {
    unset($server);

    if (!($response instanceof WP_HTTP_Response) || !wanyesea_ai_is_ai_providers_image_generation_rest_request($request)) {
        return $response;
    }
    if ((int) $response->get_status() !== 200) {
        return $response;
    }

    $data = $response->get_data();
    if (!is_array($data)) {
        $data = array();
    }

    wanyesea_ai_ensure_ai_client_auth();

    $by_id = array();
    foreach ($data as $row) {
        if (!is_array($row) || empty($row['id'])) {
            continue;
        }
        $pid = sanitize_key((string) $row['id']);
        if ($pid === '') {
            continue;
        }
        if (!isset($row['models']) || !is_array($row['models'])) {
            $row['models'] = array();
        }
        $by_id[$pid] = $row;
    }

    $target_provider_ids = array_unique(array_merge(
        array_keys($by_id),
        wanyesea_ai_get_configured_image_provider_ids_with_keys()
    ));

    foreach ($target_provider_ids as $provider_id) {
        $provider_id = sanitize_key((string) $provider_id);
        if ($provider_id === '' || !in_array($provider_id, wanyesea_ai_image_capable_provider_ids(), true)) {
            continue;
        }
        if (wanyesea_ai_get_connector_api_key_resolved($provider_id) === '') {
            continue;
        }

        $choices = wanyesea_ai_collect_image_model_choices_for_provider($provider_id);
        if ($choices === array()) {
            continue;
        }

        if (!isset($by_id[$provider_id])) {
            $provider_name = $provider_id;
            if (class_exists(AiClient::class)) {
                try {
                    $registry = AiClient::defaultRegistry();
                    if ($registry->hasProvider($provider_id)) {
                        $provider_class = $registry->getProviderClassName($provider_id);
                        if (is_string($provider_class) && method_exists($provider_class, 'metadata')) {
                            $provider_name = $provider_class::metadata()->getName();
                        }
                    }
                } catch (Throwable $e) {
                    // 使用 provider_id 作为显示名。
                }
            }

            $by_id[$provider_id] = array(
                'id'     => $provider_id,
                'name'   => (string) $provider_name,
                'models' => array(),
            );
        }

        $existing = array();
        foreach ($by_id[$provider_id]['models'] as $model_row) {
            if (is_array($model_row) && !empty($model_row['id'])) {
                $existing[sanitize_text_field((string) $model_row['id'])] = true;
            }
        }

        foreach ($choices as $choice) {
            if (!isset($existing[$choice['id']])) {
                $by_id[$provider_id]['models'][] = array(
                    'id'   => $choice['id'],
                    'name' => $choice['name'],
                );
                $existing[$choice['id']] = true;
            }
        }
    }

    $merged = array();
    foreach ($by_id as $row) {
        if (!empty($row['models'])) {
            $merged[] = $row;
        }
    }

    $response->set_data($merged);

    return $response;
}

add_filter('rest_post_dispatch', 'wanyesea_ai_augment_ai_providers_rest_image_models', 20, 3);

/**
 * 轻量校验 base64 出图数据（避免 sanitize_text_field 处理数 MB 字符串导致超时与非 JSON 响应）。
 *
 * @param mixed $value
 * @return string
 */
function wanyesea_ai_sanitize_base64_image_payload($value) {
    if (!is_string($value)) {
        return '';
    }

    $value = trim($value);
    if ($value === 'wanyesea_cache' || $value === 'cached') {
        $cached = wanyesea_ai_get_editor_flow_generated_image_cache();
        if (is_array($cached) && !empty($cached['data'])) {
            return (string) $cached['data'];
        }

        return '';
    }

    if ($value === '') {
        return '';
    }

    if (preg_match('#^data:image/[^;]+;base64,#i', $value)) {
        $comma = strpos($value, ',');
        if ($comma !== false) {
            $value = substr($value, $comma + 1);
        }
    }

    $value = preg_replace('/\s+/', '', $value);
    if ($value === '' || !preg_match('/^[A-Za-z0-9+\/]*={0,2}$/', $value)) {
        return '';
    }

    return $value;
}

/**
 * 将 ai/image-import 的 data 字段改为轻量 sanitizer（官方默认 sanitize_text_field 不适合大段 base64）。
 */
function wanyesea_ai_patch_image_import_base64_sanitizer() {
    if (!function_exists('wp_get_ability')) {
        return;
    }

    $ability = wp_get_ability('ai/image-import');
    if (!$ability instanceof WP_Ability) {
        return;
    }

    try {
        $reflection = new ReflectionClass($ability);
        $property   = $reflection->getProperty('input_schema');
        $property->setAccessible(true);
        $schema = $property->getValue($ability);
        if (!is_array($schema) || empty($schema['properties']['data']) || !is_array($schema['properties']['data'])) {
            return;
        }
        $schema['properties']['data']['sanitize_callback'] = 'wanyesea_ai_sanitize_base64_image_payload';
        $property->setValue($ability, $schema);
    } catch (Throwable $e) {
        return;
    }
}

add_action('wp_abilities_api_init', 'wanyesea_ai_patch_image_import_base64_sanitizer', 9999);
add_action('rest_api_init', 'wanyesea_ai_patch_image_import_base64_sanitizer', 100);

/**
 * 特色图链路：按当前用户缓存最近一次出图 base64（供 Alt / 导入复用）。
 *
 * @return string
 */
function wanyesea_ai_editor_flow_image_cache_transient_key() {
    return 'wanyesea_ai_ef_img_' . (int) get_current_user_id();
}

/**
 * @return array{data: string, mime: string}|null
 */
function wanyesea_ai_get_editor_flow_generated_image_cache() {
    $user_id = get_current_user_id();
    if ($user_id <= 0) {
        return null;
    }

    $cached = get_transient(wanyesea_ai_editor_flow_image_cache_transient_key());
    if (!is_array($cached) || empty($cached['data']) || !is_string($cached['data'])) {
        return null;
    }

    if (isset($cached['user']) && (int) $cached['user'] !== $user_id) {
        return null;
    }

    return array(
        'data' => $cached['data'],
        'mime' => !empty($cached['mime']) ? (string) $cached['mime'] : 'image/png',
    );
}

/**
 * @param array{data: string, mime: string} $cached
 */
function wanyesea_ai_save_editor_flow_generated_image_cache(array $cached) {
    $user_id = get_current_user_id();
    if ($user_id <= 0 || $cached['data'] === '') {
        return;
    }

    set_transient(
        wanyesea_ai_editor_flow_image_cache_transient_key(),
        array(
            'data' => $cached['data'],
            'mime' => $cached['mime'],
            'user' => $user_id,
        ),
        (int) apply_filters('wanyesea_ai_editor_flow_image_cache_ttl', 600)
    );

    delete_transient(wanyesea_ai_editor_flow_image_cache_transient_key() . '_att');
}

/**
 * @param string $ability_name
 * @param mixed  $input
 * @param mixed  $result
 */
function wanyesea_ai_cache_image_after_generation_ability($ability_name, $input, $result) {
    unset($input);

    if ($ability_name !== 'ai/image-generation') {
        return;
    }

    if (!wanyesea_ai_editor_ai_image_flow_is_active()) {
        return;
    }

    if (!is_array($result) || empty($result['image']) || !is_array($result['image'])) {
        return;
    }

    $image = $result['image'];
    $data  = !empty($image['data']) && is_string($image['data']) ? $image['data'] : '';
    $mime  = !empty($image['mime_type']) ? (string) $image['mime_type'] : 'image/png';

    if ($data === '' && !empty($image['url']) && is_string($image['url'])
        && function_exists('wanyesea_ai_fetch_image_url_as_base64')) {
        $data = wanyesea_ai_fetch_image_url_as_base64($image['url'], $mime);
    }

    if ($data === '') {
        return;
    }

    wanyesea_ai_save_editor_flow_generated_image_cache(
        array(
            'data' => $data,
            'mime' => $mime,
        )
    );
}

add_action('wp_after_execute_ability', 'wanyesea_ai_cache_image_after_generation_ability', 10, 3);

/**
 * @return int Attachment ID or 0.
 */
function wanyesea_ai_get_editor_flow_cached_attachment_id() {
    $att_key = wanyesea_ai_editor_flow_image_cache_transient_key() . '_att';
    $cached  = get_transient($att_key);
    if (is_numeric($cached) && (int) $cached > 0) {
        $post = get_post((int) $cached);
        if ($post && $post->post_type === 'attachment') {
            return (int) $cached;
        }
    }

    $image = wanyesea_ai_get_editor_flow_generated_image_cache();
    if ($image === null) {
        return 0;
    }

    $decoded = base64_decode($image['data'], true);
    if ($decoded === false || $decoded === '') {
        return 0;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $temp = wp_tempnam('wanyesea-ai-alt');
    if (!is_string($temp) || $temp === '') {
        return 0;
    }

    if (file_put_contents($temp, $decoded) === false) {
        wp_delete_file($temp);
        return 0;
    }

    $mime = $image['mime'] !== '' ? $image['mime'] : 'image/png';
    $ext  = wp_get_default_extension_for_mime_type($mime);
    if (!is_string($ext) || $ext === '') {
        $ext = 'png';
    }

    $attachment_id = media_handle_sideload(
        array(
            'name'     => 'wanyesea-ai-temp-' . time() . '.' . $ext,
            'type'     => $mime,
            'tmp_name' => $temp,
        ),
        0
    );

    if (file_exists($temp)) {
        wp_delete_file($temp);
    }

    if (is_wp_error($attachment_id)) {
        return 0;
    }

    set_transient($att_key, (int) $attachment_id, (int) apply_filters('wanyesea_ai_editor_flow_image_cache_ttl', 600));

    return (int) $attachment_id;
}

/**
 * set_body() 之后强制 REST 请求按新 body 重新解析 JSON（不可将 params 置 null，否则会触发 PHP 致命错误）。
 *
 * @param WP_REST_Request $request
 */
function wanyesea_ai_rest_request_reset_json_params($request) {
    if (!($request instanceof WP_REST_Request)) {
        return;
    }

    try {
        $reflection = new ReflectionClass($request);

        if ($reflection->hasProperty('parsed_json')) {
            $parsed_json = $reflection->getProperty('parsed_json');
            $parsed_json->setAccessible(true);
            $parsed_json->setValue($request, false);
        }

        $body = $request->get_body();
        if ($body === '' || !$reflection->hasProperty('params')) {
            return;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return;
        }

        $params_property = $reflection->getProperty('params');
        $params_property->setAccessible(true);
        $params = $params_property->getValue($request);

        if (!is_array($params)) {
            $params = array(
                'URL'      => array(),
                'GET'      => array(),
                'POST'     => array(),
                'FILES'    => array(),
                'JSON'     => null,
                'defaults' => array(),
            );
        }

        $params['JSON'] = $decoded;
        $params_property->setValue($request, $params);

        if (isset($parsed_json)) {
            $parsed_json->setValue($request, true);
        }
    } catch (Throwable $e) {
        return;
    }
}

/**
 * @param WP_REST_Request $request
 */
function wanyesea_ai_is_editor_image_ability_rest_request($request) {
    if (!($request instanceof WP_REST_Request)) {
        return false;
    }

    $route = (string) $request->get_route();

    return strpos($route, 'ai/alt-text-generation/run') !== false
        || strpos($route, 'ai/image-import/run') !== false
        || strpos($route, 'ai/image-generation/run') !== false;
}

/**
 * 编辑器出图缓存不可用时的 REST 错误（保证返回合法 JSON，避免 invalid_json）。
 *
 * @return WP_Error
 */
function wanyesea_ai_editor_flow_cache_miss_error() {
    return new WP_Error(
        'wanyesea_editor_flow_cache_miss',
        '出图结果缓存已失效或未写入。请重新点击「生成特色图」，完成后再导入媒体库。',
        array('status' => 400)
    );
}

/**
 * 子比主题：块编辑器会为普通文章请求 shop_cat / plate_cat 等 REST 分类法，因文章类型不匹配返回 403。
 * 对「post 参数存在且该分类法未绑定当前文章类型」的 GET 请求返回空列表，消除控制台噪音。
 *
 * @param mixed           $result
 * @param WP_REST_Server  $server
 * @param WP_REST_Request $request
 * @return mixed
 */
function wanyesea_ai_rest_pre_dispatch_zibll_unrelated_taxonomy_terms($result, $server, $request) {
    unset($server);

    if ($result !== null || !($request instanceof WP_REST_Request)) {
        return $result;
    }

    if (strtoupper((string) $request->get_method()) !== 'GET') {
        return $result;
    }

    $route = (string) $request->get_route();
    if (!preg_match('#^/wp/v2/([a-z0-9_-]+)$#', $route, $matches)) {
        return $result;
    }

    $taxonomy = $matches[1];
    $allowed  = apply_filters(
        'wanyesea_ai_zibll_unrelated_taxonomy_rest_slugs',
        array('shop_cat', 'shop_tag', 'shop_discount', 'plate_cat', 'forum_topic', 'forum_tag')
    );

    if (!in_array($taxonomy, $allowed, true)) {
        return $result;
    }

    $post_id = (int) $request->get_param('post');
    if ($post_id <= 0) {
        return $result;
    }

    $post = get_post($post_id);
    if (!$post || is_object_in_taxonomy($post->post_type, $taxonomy)) {
        return $result;
    }

    $response = new WP_REST_Response(array(), 200);
    $response->header('X-WP-Total', '0');
    $response->header('X-WP-TotalPages', '0');

    return $response;
}

add_filter('rest_pre_dispatch', 'wanyesea_ai_rest_pre_dispatch_zibll_unrelated_taxonomy_terms', 3, 3);

/**
 * 在权限/校验前把 Alt、导入改为使用服务端缓存（小 JSON 请求体）。
 *
 * @param WP_HTTP_Response|WP_Error|null $response
 * @param array                            $handler
 * @param WP_REST_Request                  $request
 * @return WP_HTTP_Response|WP_Error|null
 */
function wanyesea_ai_prepare_editor_image_flow_rest_input($response, $handler, $request) {
    unset($handler);

    if ($response !== null || !wanyesea_ai_is_editor_image_ability_rest_request($request)) {
        return $response;
    }

    wanyesea_ai_set_editor_ai_image_flow_active(true);
    wanyesea_ai_ensure_ai_client_auth();

    $json = $request->get_json_params();
    if (!is_array($json) || !isset($json['input']) || !is_array($json['input'])) {
        return $response;
    }

    $route = (string) $request->get_route();
    $input = $json['input'];
    $dirty = false;

    if (strpos($route, 'alt-text-generation') !== false) {
        $use_cache = !empty($input['wanyesea_use_editor_flow_cache']);
        $image_url = isset($input['image_url']) ? (string) $input['image_url'] : '';
        if (!$use_cache && $image_url !== '' && strpos($image_url, 'data:') === 0 && strlen($image_url) > 200000) {
            $use_cache = true;
        }

        if ($use_cache) {
            $attachment_id = wanyesea_ai_get_editor_flow_cached_attachment_id();
            if ($attachment_id > 0) {
                $input = array(
                    'attachment_id' => $attachment_id,
                );
                if (!empty($json['input']['context'])) {
                    $input['context'] = sanitize_textarea_field((string) $json['input']['context']);
                }
                if (!empty($json['input']['image_meta'])) {
                    $input['image_meta'] = sanitize_textarea_field((string) $json['input']['image_meta']);
                }
                $dirty = true;
            } else {
                return wanyesea_ai_editor_flow_cache_miss_error();
            }
        }
    }

    if (strpos($route, 'image-import') !== false) {
        $use_cache = !empty($input['wanyesea_use_editor_flow_cache']);
        $data      = isset($input['data']) ? (string) $input['data'] : '';
        if (!$use_cache && ($data === 'wanyesea_cache' || $data === 'cached' || strlen($data) > 200000)) {
            $use_cache = true;
        }

        if ($use_cache) {
            $cached = wanyesea_ai_get_editor_flow_generated_image_cache();
            if ($cached !== null) {
                $input['data'] = $cached['data'];
                if (empty($input['mime_type'])) {
                    $input['mime_type'] = $cached['mime'];
                }
                unset($input['wanyesea_use_editor_flow_cache']);
                $dirty = true;
            } else {
                return wanyesea_ai_editor_flow_cache_miss_error();
            }
        }
    }

    if (!$dirty) {
        return $response;
    }

    $json['input'] = $input;
    $encoded       = wp_json_encode($json);
    if (!is_string($encoded)) {
        return $response;
    }

    $request->set_body($encoded);
    wanyesea_ai_rest_request_reset_json_params($request);

    return $response;
}

add_filter('rest_request_before_callbacks', 'wanyesea_ai_prepare_editor_image_flow_rest_input', 4, 3);

/**
 * 块编辑器 apiFetch 中间件（内联，尽早注册，避免出图脚本先于本插件执行时 POST 数 MB base64）。
 */
function wanyesea_ai_get_editor_image_flow_inline_script() {
    return <<<'JS'
(function ( wp ) {
	if ( ! wp?.apiFetch?.use ) {
		return;
	}
	var CACHE_FLAG = 'wanyesea_use_editor_flow_cache';
	wp.apiFetch.use( function ( options, next ) {
		var path = options.path || '';
		if ( typeof path !== 'string' || path.indexOf( '/wp-abilities/v1/abilities/' ) === -1 ) {
			return next( options );
		}
		if ( path.indexOf( 'ai/alt-text-generation/run' ) !== -1 ) {
			var altInput = options.data && options.data.input ? options.data.input : {};
			options.data = {
				input: {
					wanyesea_use_editor_flow_cache: true,
					context: altInput.context || '',
					image_meta: altInput.image_meta || '',
				},
			};
			return next( options );
		}
		if ( path.indexOf( 'ai/image-import/run' ) !== -1 ) {
			var importInput = options.data && options.data.input ? options.data.input : {};
			options.data = {
				input: Object.assign( {}, importInput, {
					wanyesea_use_editor_flow_cache: true,
					data: 'wanyesea_cache',
				} ),
			};
			return next( options );
		}
		return next( options );
	} );
})( window.wp );
JS;
}

/**
 * 块编辑器：为官方 image-generation 脚本注入 apiFetch 中间件，缩小 Alt / 导入请求体。
 */
function wanyesea_ai_enqueue_editor_image_flow_script() {
    if (!function_exists('get_current_screen')) {
        return;
    }

    $screen = get_current_screen();
    if (!$screen || empty($screen->is_block_editor())) {
        return;
    }

    if (!post_type_supports($screen->post_type, 'thumbnail')) {
        return;
    }

    wp_enqueue_script('wp-api-fetch');
    wp_add_inline_script('wp-api-fetch', wanyesea_ai_get_editor_image_flow_inline_script(), 'before');

    wp_enqueue_script(
        'wanyesea-ai-editor-image-flow',
        WanYesea_AI_url . '/assets/wanyesea-ai-editor-image-flow.js',
        array('wp-api-fetch'),
        '1.1.2',
        true
    );
}

add_action('enqueue_block_editor_assets', 'wanyesea_ai_enqueue_editor_image_flow_script', 5);
