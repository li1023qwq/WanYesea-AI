<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

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
 * 需要 text_generation 能力的官方 AI Abilities（不含已在 09 中单独处理的出图/Alt）。
 *
 * @return list<string>
 */
function wanyesea_ai_text_generation_ability_names() {
    return apply_filters('wanyesea_ai_text_generation_ability_names', array(
        'ai/content-classification',
        'ai/editorial-notes',
        'ai/editorial-updates',
        'ai/title-generation',
        'ai/excerpt-generation',
        'ai/meta-description',
        'ai/summarization',
        'ai/content-resizing',
        'ai/comment-analysis',
    ));
}

function wanyesea_ai_is_text_generation_ability($ability_name) {
    return is_string($ability_name)
        && in_array($ability_name, wanyesea_ai_text_generation_ability_names(), true);
}

/**
 * Ability 名称（ai/content-classification）→ 实验功能 ID（content-classification）。
 */
function wanyesea_ai_ability_name_to_feature_id($ability_name) {
    if (!is_string($ability_name) || strpos($ability_name, 'ai/') !== 0) {
        return '';
    }

    return substr($ability_name, 3);
}

/**
 * 将单个 provider/model 写入 Registry（中转 OpenAI 用 bindModelDependencies）。
 */
function wanyesea_ai_registry_prime_model_pair($registry, $provider_id, $model_id) {
    if (!$registry instanceof \WordPress\AiClient\Providers\ProviderRegistry) {
        return;
    }

    $provider_id = sanitize_key((string) $provider_id);
    $model_id    = function_exists('wanyesea_ai_normalize_model_id')
        ? wanyesea_ai_normalize_model_id($model_id)
        : trim((string) $model_id);

    if ($provider_id === '' || $model_id === '') {
        return;
    }

    if ($provider_id === 'openai'
        && function_exists('wanyesea_ai_relay_is_provider_active')
        && wanyesea_ai_relay_is_provider_active('openai')
        && function_exists('wanyesea_ai_create_relay_openai_text_model_for_id')
        && wanyesea_ai_create_relay_openai_text_model_for_id($model_id) !== null) {
        return;
    }

    if (function_exists('wanyesea_ai_create_custom_text_model_for_id')
        && class_exists('Wanyesea_AI_Custom_Connectors')
        && Wanyesea_AI_Custom_Connectors::is_custom_provider($provider_id)
        && wanyesea_ai_create_custom_text_model_for_id($provider_id, $model_id) !== null) {
        return;
    }

    try {
        if (!$registry->hasProvider($provider_id) || !$registry->isProviderConfigured($provider_id)) {
            return;
        }
        $registry->getProviderModel($provider_id, $model_id);
    } catch (Throwable $e) {
        return;
    }
}

/**
 * 文本 Ability REST 是否已在本请求内完成 Registry 预热。
 */
function wanyesea_ai_text_ability_registry_prime_done() {
    return !empty($GLOBALS['wanyesea_ai_text_ability_registry_prime_done']);
}

/**
 * 标记文本 Ability REST 预热完成（每请求一次）。
 */
function wanyesea_ai_mark_text_ability_registry_prime_done() {
    $GLOBALS['wanyesea_ai_text_ability_registry_prime_done'] = true;
}

/**
 * 文本 Ability REST 执行前延长 PHP 时限，降低 Nginx/PHP-FPM 504 概率。
 *
 * @param string $ability_name
 */
function wanyesea_ai_prepare_text_ability_rest_request($ability_name) {
    $GLOBALS['wanyesea_ai_text_ability_running'] = sanitize_text_field((string) $ability_name);

    $limit = (int) apply_filters('wanyesea_ai_text_ability_php_time_limit', 300, $ability_name);
    $limit = max(120, min(600, $limit));

    if (function_exists('set_time_limit')) {
        @set_time_limit($limit);
    }

    if (function_exists('ini_set')) {
        @ini_set('max_execution_time', (string) $limit);
    }

    if (function_exists('ignore_user_abort')) {
        @ignore_user_abort(true);
    }
}

/**
 * 收集文本生成预热所需的 provider/model 对（含开发者指定模型与探测列表）。
 *
 * @param bool $lite 为 true 时不发起 /models 探测（避免编辑页 REST 504）。
 * @return list<array{0: string, 1: string}>
 */
function wanyesea_ai_collect_text_model_pairs_for_priming($ability_name = '', $lite = false) {
    $pairs = array();
    $seen  = array();

    $add_pair = static function ($provider_id, $model_id) use (&$pairs, &$seen) {
        $provider_id = sanitize_key((string) $provider_id);
        $model_id    = function_exists('wanyesea_ai_normalize_model_id')
            ? wanyesea_ai_normalize_model_id($model_id)
            : trim((string) $model_id);

        if ($provider_id === '' || $model_id === '') {
            return;
        }

        $key = $provider_id . "\0" . $model_id;
        if (isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $pairs[]    = array($provider_id, $model_id);
    };

    if ($ability_name !== '') {
        $feature_id = wanyesea_ai_ability_name_to_feature_id($ability_name);
        if ($feature_id !== '' && function_exists('WordPress\AI\get_feature_developer_model_config')) {
            $config = \WordPress\AI\get_feature_developer_model_config($feature_id);
            if (is_array($config) && !empty($config['provider']) && !empty($config['model'])) {
                $add_pair($config['provider'], $config['model']);
            }
        }
    }

    if (function_exists('WordPress\AI\get_preferred_models_for_text_generation')) {
        $preferred = \WordPress\AI\get_preferred_models_for_text_generation();
    } else {
        $preferred = apply_filters('wpai_preferred_text_models', array());
    }

    if (is_array($preferred)) {
        foreach ($preferred as $pair) {
            if (!is_array($pair) || !isset($pair[0], $pair[1])) {
                continue;
            }
            $add_pair($pair[0], $pair[1]);
        }
    }

    if ($lite) {
        if (class_exists('Wanyesea_AI_Custom_Connectors')) {
            foreach (Wanyesea_AI_Custom_Connectors::provider_ids() as $provider_id) {
                $provider_id = sanitize_key((string) $provider_id);
                if ($provider_id === '') {
                    continue;
                }
                if (function_exists('wanyesea_ai_get_connector_api_key_resolved')
                    && wanyesea_ai_get_connector_api_key_resolved($provider_id) === '') {
                    continue;
                }
                if (function_exists('wanyesea_ai_custom_provider_text_models_fallback_map')) {
                    foreach (array_keys(wanyesea_ai_custom_provider_text_models_fallback_map($provider_id)) as $model_id) {
                        $add_pair($provider_id, $model_id);
                    }
                }
            }
        }

        if (function_exists('wanyesea_ai_gateway_text_provider_ids')) {
            foreach (wanyesea_ai_gateway_text_provider_ids() as $provider_id) {
                if (function_exists('wanyesea_ai_gateway_model_ids_for_capability')) {
                    foreach (wanyesea_ai_gateway_model_ids_for_capability($provider_id, 'text') as $model_id) {
                        $add_pair($provider_id, $model_id);
                    }
                }
            }
        }

        $default_max = 12;
        $max         = (int) apply_filters('wanyesea_ai_text_priming_model_pair_limit', $default_max, $lite);
        if ($max > 0 && count($pairs) > $max) {
            $pairs = array_slice($pairs, 0, $max);
        }

        return $pairs;
    }

    foreach (wanyesea_ai_text_capable_provider_ids() as $provider_id) {
        $provider_id = sanitize_key((string) $provider_id);
        if ($provider_id === '') {
            continue;
        }

        if (!function_exists('wanyesea_ai_is_provider_registry_configured')
            || !wanyesea_ai_is_provider_registry_configured($provider_id)) {
            if (function_exists('wanyesea_ai_get_connector_api_key_resolved')
                && wanyesea_ai_get_connector_api_key_resolved($provider_id) === '') {
                continue;
            }
        }

        if (function_exists('wanyesea_ai_get_custom_provider_text_model_preferences_cached')) {
            foreach (wanyesea_ai_get_custom_provider_text_model_preferences_cached($provider_id) as $pair) {
                if (is_array($pair) && isset($pair[0], $pair[1])) {
                    $add_pair($pair[0], $pair[1]);
                }
            }
        }

        if (!$lite && function_exists('wanyesea_ai_probe_model_ids_for_capability')) {
            foreach (wanyesea_ai_probe_model_ids_for_capability($provider_id, 'text', false) as $model_id) {
                $add_pair($provider_id, $model_id);
            }
        }

        if (function_exists('wanyesea_ai_gateway_model_ids_for_capability')) {
            foreach (wanyesea_ai_gateway_model_ids_for_capability($provider_id, 'text') as $model_id) {
                $add_pair($provider_id, $model_id);
            }
        }
    }

    $default_max = $lite ? 12 : 48;
    $max         = (int) apply_filters('wanyesea_ai_text_priming_model_pair_limit', $default_max, $lite);
    if ($max > 0 && count($pairs) > $max) {
        $pairs = array_slice($pairs, 0, $max);
    }

    return $pairs;
}

/**
 * 文本 Ability 执行前预热 Registry，避免 is_supported_for_text_generation 误报失败。
 *
 * @param string $ability_name 可选，用于预热该功能在开发者模式中指定的模型。
 * @param bool   $lite         轻量模式：不强制 /models、不遍历全站 Provider 元数据。
 */
function wanyesea_ai_prime_registry_for_text_generation($ability_name = '', $lite = false) {
    if (function_exists('wanyesea_ai_ensure_ai_client_auth')) {
        wanyesea_ai_ensure_ai_client_auth();
    }

    if (function_exists('wanyesea_ai_wrap_relay_official_metadata_directories')) {
        wanyesea_ai_wrap_relay_official_metadata_directories();
    }

    $relay_model = '';
    foreach (wanyesea_ai_collect_text_model_pairs_for_priming($ability_name, true) as $pair) {
        if (isset($pair[0], $pair[1]) && $pair[0] === 'openai' && $pair[1] !== '') {
            $relay_model = $pair[1];
            break;
        }
    }

    if (function_exists('wanyesea_ai_prime_relay_openai_for_text_generation')) {
        wanyesea_ai_prime_relay_openai_for_text_generation($relay_model, false);
    }

    if (!class_exists(AiClient::class)) {
        return;
    }

    try {
        $registry     = AiClient::defaultRegistry();
        $requirements = new ModelRequirements(array(CapabilityEnum::textGeneration()), array());
    } catch (Throwable $e) {
        return;
    }

    $provider_ids_for_metadata = wanyesea_ai_text_capable_provider_ids();

    if ($lite) {
        $provider_ids_for_metadata = array();
        foreach (wanyesea_ai_collect_text_model_pairs_for_priming($ability_name, true) as $pair) {
            if (isset($pair[0])) {
                $provider_ids_for_metadata[] = sanitize_key((string) $pair[0]);
            }
        }
        $provider_ids_for_metadata = array_values(array_unique(array_filter($provider_ids_for_metadata)));
    }

    if (!$lite) {
        foreach ($provider_ids_for_metadata as $provider_id) {
            $provider_id = sanitize_key((string) $provider_id);
            if ($provider_id === '') {
                continue;
            }

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

    foreach (wanyesea_ai_collect_text_model_pairs_for_priming($ability_name, $lite) as $pair) {
        wanyesea_ai_registry_prime_model_pair($registry, $pair[0], $pair[1]);
    }
}

/**
 * 文本实验功能 ID 列表（与 ai/* Ability 对应）。
 *
 * @return list<string>
 */
function wanyesea_ai_text_generation_feature_ids() {
    $ids = array();
    foreach (wanyesea_ai_text_generation_ability_names() as $ability_name) {
        $feature_id = wanyesea_ai_ability_name_to_feature_id($ability_name);
        if ($feature_id !== '') {
            $ids[] = $feature_id;
        }
    }

    return apply_filters('wanyesea_ai_text_generation_feature_ids', array_values(array_unique($ids)));
}

/**
 * 开发者模式中保存的 provider/model 是否可用于文本 Ability。
 */
function wanyesea_ai_text_developer_model_pair_is_allowed($provider_id, $model_id) {
    $provider_id = sanitize_key((string) $provider_id);
    $model_id    = function_exists('wanyesea_ai_normalize_model_id')
        ? wanyesea_ai_normalize_model_id($model_id)
        : trim((string) $model_id);

    if ($provider_id === '' || $model_id === '') {
        return false;
    }

    if (function_exists('wanyesea_ai_is_image_only_model_id_for_provider')
        && wanyesea_ai_is_image_only_model_id_for_provider($provider_id, $model_id)) {
        return false;
    }

    if (function_exists('wanyesea_ai_is_nvidia_nim_entity_extraction_model')
        && wanyesea_ai_is_nvidia_nim_entity_extraction_model($model_id)) {
        return false;
    }

    if (function_exists('wanyesea_ai_get_connector_api_key_resolved')
        && wanyesea_ai_get_connector_api_key_resolved($provider_id) === '') {
        return false;
    }

    if (class_exists('Wanyesea_AI_Custom_Connectors')
        && Wanyesea_AI_Custom_Connectors::is_custom_provider($provider_id)
        && function_exists('wanyesea_ai_custom_provider_text_models_fallback_map')) {
        return array_key_exists($model_id, wanyesea_ai_custom_provider_text_models_fallback_map($provider_id));
    }

    if (function_exists('wanyesea_ai_gateway_model_ids_for_capability')
        && class_exists('Wanyesea_AI_Gateway_Settings', false)
        && Wanyesea_AI_Gateway_Settings::is_gateway_provider_id($provider_id)) {
        return in_array($model_id, wanyesea_ai_gateway_model_ids_for_capability($provider_id, 'text'), true);
    }

    return true;
}

/**
 * 规范化「设置 → AI」各文本实验的开发者 provider/model（避免误选出图模型或未配置厂商）。
 *
 * @param mixed $value
 * @return array{provider: string, model: string}
 */
function wanyesea_ai_sanitize_text_feature_developer_option($value) {
    if (!is_array($value)) {
        return array(
            'provider' => '',
            'model'    => '',
        );
    }

    $provider_id = isset($value['provider']) ? sanitize_key((string) $value['provider']) : '';
    $model_id    = isset($value['model'])
        ? (function_exists('wanyesea_ai_normalize_model_id')
            ? wanyesea_ai_normalize_model_id((string) $value['model'])
            : trim((string) $value['model']))
        : '';

    if ($provider_id === '') {
        return array(
            'provider' => '',
            'model'    => '',
        );
    }

    if (function_exists('wanyesea_ai_get_connector_api_key_resolved')
        && wanyesea_ai_get_connector_api_key_resolved($provider_id) === '') {
        return array(
            'provider' => '',
            'model'    => '',
        );
    }

    if ($model_id === ''
        || (function_exists('wanyesea_ai_is_image_only_model_id_for_provider')
            && wanyesea_ai_is_image_only_model_id_for_provider($provider_id, $model_id))
        || !wanyesea_ai_text_developer_model_pair_is_allowed($provider_id, $model_id)) {
        $model_id = function_exists('wanyesea_ai_get_text_model_hint_for_provider')
            ? wanyesea_ai_get_text_model_hint_for_provider($provider_id)
            : '';
    }

    if ($model_id === '' || !wanyesea_ai_text_developer_model_pair_is_allowed($provider_id, $model_id)) {
        return array(
            'provider' => '',
            'model'    => '',
        );
    }

    return array(
        'provider' => $provider_id,
        'model'    => $model_id,
    );
}

/**
 * 为各文本实验注册开发者选项 sanitize（读取时即生效，无需重存设置）。
 */
function wanyesea_ai_register_text_feature_developer_option_filters() {
    static $registered = false;

    if ($registered) {
        return;
    }

    $registered = true;

    foreach (wanyesea_ai_text_generation_feature_ids() as $feature_id) {
        add_filter(
            'option_wpai_feature_' . $feature_id . '_field_developer',
            'wanyesea_ai_sanitize_text_feature_developer_option',
            15
        );
    }
}

add_action('init', 'wanyesea_ai_register_text_feature_developer_option_filters', 20);

/**
 * 将开发者指定模型写入 Registry（内容分类等走 using_model 时必需）。
 *
 * @param string $ability_name
 */
function wanyesea_ai_bind_text_developer_model_for_ability($ability_name) {
    $feature_id = wanyesea_ai_ability_name_to_feature_id($ability_name);
    if ($feature_id === '' || !function_exists('WordPress\AI\get_feature_developer_model_config')) {
        return;
    }

    $config = \WordPress\AI\get_feature_developer_model_config($feature_id);
    if (!is_array($config) || empty($config['provider']) || empty($config['model'])) {
        return;
    }

    $provider_id = sanitize_key((string) $config['provider']);
    $model_id    = function_exists('wanyesea_ai_normalize_model_id')
        ? wanyesea_ai_normalize_model_id((string) $config['model'])
        : trim((string) $config['model']);

    if (!wanyesea_ai_text_developer_model_pair_is_allowed($provider_id, $model_id)) {
        return;
    }

    if (function_exists('wanyesea_ai_create_custom_text_model_for_id')) {
        wanyesea_ai_create_custom_text_model_for_id($provider_id, $model_id);
    }
}

/**
 * 每请求仅预热一次（避免 rest_pre_dispatch + 回调重复探测导致 504）。
 *
 * @param string $ability_name
 * @param bool   $lite
 */
function wanyesea_ai_prime_registry_for_text_generation_once($ability_name = '', $lite = true) {
    if (wanyesea_ai_text_ability_registry_prime_done()) {
        return;
    }

    wanyesea_ai_prime_registry_for_text_generation($ability_name, $lite);
    wanyesea_ai_mark_text_ability_registry_prime_done();
}

/**
 * @param string $ability_name
 * @param mixed  $input
 */
function wanyesea_ai_bootstrap_text_generation_ability($ability_name, $input) {
    unset($input);

    if (!wanyesea_ai_is_text_generation_ability($ability_name)) {
        return;
    }

    wanyesea_ai_prepare_text_ability_rest_request($ability_name);
    wanyesea_ai_bind_text_developer_model_for_ability($ability_name);
    wanyesea_ai_prime_registry_for_text_generation_once($ability_name, true);
}

add_action('wp_before_execute_ability', 'wanyesea_ai_bootstrap_text_generation_ability', 0, 2);

/**
 * REST 调用 Abilities 时同样预热（区块编辑器走 wp-abilities API）。
 *
 * @param mixed           $response
 * @param array           $handler
 * @param WP_REST_Request $request
 * @return mixed
 */
function wanyesea_ai_rest_pre_dispatch_prime_text_abilities($response, $handler, $request) {
    unset($handler);

    if ($response !== null || !($request instanceof WP_REST_Request)) {
        return $response;
    }

    $route = (string) $request->get_route();
    if (strpos($route, '/wp-abilities/v1/abilities/ai/') === false || substr($route, -4) !== '/run') {
        return $response;
    }

    if (!preg_match('#/wp-abilities/v1/abilities/(ai/[^/]+)/run#', $route, $matches)) {
        return $response;
    }

    if (!wanyesea_ai_is_text_generation_ability($matches[1])) {
        return $response;
    }

    wanyesea_ai_prepare_text_ability_rest_request($matches[1]);
    wanyesea_ai_bind_text_developer_model_for_ability($matches[1]);
    wanyesea_ai_prime_registry_for_text_generation_once($matches[1], true);

    return $response;
}

add_filter('rest_pre_dispatch', 'wanyesea_ai_rest_pre_dispatch_prime_text_abilities', 5, 3);

/**
 * 文本 Ability 执行期间提高 AI Client 默认 HTTP 超时。
 *
 * @param float $timeout
 * @return float
 */
function wanyesea_ai_filter_default_timeout_during_text_ability($timeout) {
    if (empty($GLOBALS['wanyesea_ai_text_ability_running'])) {
        return $timeout;
    }

    $minimum = (float) apply_filters('wanyesea_ai_text_ability_rest_client_timeout', 120.0);

    if (!is_numeric($timeout) || (float) $timeout < $minimum) {
        return $minimum;
    }

    return (float) $timeout;
}

add_filter('wp_ai_client_default_request_timeout', 'wanyesea_ai_filter_default_timeout_during_text_ability', 50);

/**
 * 文本 Ability 执行期间延长 chat/completions 等出站 HTTP 超时。
 *
 * @param array<string, mixed> $args
 * @param string               $url
 * @return array<string, mixed>
 */
function wanyesea_ai_extend_text_generation_http_timeout_during_ability($args, $url) {
    if (empty($GLOBALS['wanyesea_ai_text_ability_running'])) {
        return $args;
    }

    $url = (string) $url;
    $needs_long = (strpos($url, '/chat/completions') !== false)
        || (strpos($url, '/messages') !== false)
        || (strpos($url, '/responses') !== false);

    if (!$needs_long) {
        return $args;
    }

    $timeout = (int) apply_filters('wanyesea_ai_text_ability_http_request_timeout', 120);
    $args['timeout'] = max((int) ($args['timeout'] ?? 5), $timeout);

    return $args;
}

add_filter('http_request_args', 'wanyesea_ai_extend_text_generation_http_timeout_during_ability', 12, 2);

/**
 * 区块编辑器：文本 Ability REST 强制 POST（须在 core-abilities / 官方 AI 脚本之前注册中间件）。
 */
function wanyesea_ai_enqueue_text_abilities_rest_middleware() {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || empty($screen->is_block_editor())) {
        return;
    }

    wp_enqueue_script('wp-api-fetch');

    $asset_ver = class_exists('Wanyesea_AI_Config')
        ? Wanyesea_AI_Config::get_asset_version()
        : '1.2.3';

    wp_enqueue_script(
        'wanyesea-ai-text-abilities-rest',
        WanYesea_AI_url . 'assets/wanyesea-ai-text-abilities-rest.js',
        array('wp-api-fetch'),
        $asset_ver,
        false
    );
}

add_action('enqueue_block_editor_assets', 'wanyesea_ai_enqueue_text_abilities_rest_middleware', 1);

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
