<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;

/**
 * 在本插件「AI 连接」页配置 API Key，并桥接到 WordPress Connectors / AI Client。
 */
final class Wanyesea_AI_Connectors {

    /**
     * @return array<string, string> provider_id => WP option name
     */
    public static function connector_option_names() {
        return array(
            'openai'    => 'connectors_ai_openai_api_key',
            'google'    => 'connectors_ai_google_api_key',
            'anthropic' => 'connectors_ai_anthropic_api_key',
        );
    }

    /**
     * @return list<string>
     */
    public static function provider_ids() {
        return array_keys(self::connector_option_names());
    }

    public static function option_field_id($provider_id) {
        return 'connector_' . sanitize_key((string) $provider_id) . '_api_key';
    }

    public static function get_api_key($provider_id) {
        $provider_id = sanitize_key((string) $provider_id);
        $key         = trim((string) WanYesea_AI(self::option_field_id($provider_id), ''));
        return apply_filters('wanyesea_ai_connector_api_key', $key, $provider_id);
    }

    /**
     * 本插件选项与 Connectors 选项合并后的 API Key（与核心 _wp_connectors_pass_default_keys_to_ai_client 一致）。
     */
    public static function get_api_key_resolved($provider_id) {
        if (function_exists('wanyesea_ai_get_connector_api_key_resolved')) {
            return wanyesea_ai_get_connector_api_key_resolved($provider_id);
        }

        return self::get_api_key($provider_id);
    }

    public static function is_configured($provider_id) {
        return self::get_api_key_resolved($provider_id) !== '';
    }

    public static function mask_api_key($key) {
        $key = (string) $key;
        if ($key === '') {
            return '';
        }
        if (strlen($key) <= 4) {
            return str_repeat('•', 4);
        }
        return str_repeat('•', min(12, strlen($key) - 4)) . substr($key, -4);
    }

    public static function boot() {
        foreach (self::connector_option_names() as $provider_id => $option_name) {
            add_filter(
                'pre_option_' . $option_name,
                static function ($pre) use ($provider_id) {
                    if (false !== $pre) {
                        return $pre;
                    }
                    $key = self::get_api_key($provider_id);
                    return $key !== '' ? $key : false;
                },
                5,
                1
            );
        }

        add_filter('csf_WanYesea_AI_save', array(__CLASS__, 'filter_csf_save'), 10, 2);
        add_action('csf_WanYesea_AI_saved', array(__CLASS__, 'on_csf_saved'), 10, 1);
        add_action('init', array(__CLASS__, 'inject_ai_client_auth'), 21);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function filter_csf_save($data, $instance) {
        unset($instance);
        $old = get_option('WanYesea_AI', array());
        if (!is_array($old)) {
            $old = array();
        }
        if (!is_array($data)) {
            return $data;
        }

        foreach (self::provider_ids() as $provider_id) {
            $field = self::option_field_id($provider_id);
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $raw = trim((string) $data[$field]);
            if ($raw === '') {
                $data[$field] = isset($old[$field]) ? (string) $old[$field] : '';
                continue;
            }
            if (strtoupper($raw) === 'REMOVE') {
                $data[$field] = '';
                continue;
            }
            $data[$field] = sanitize_text_field($raw);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function on_csf_saved($data) {
        if (!is_array($data)) {
            return;
        }
        foreach (self::connector_option_names() as $provider_id => $option_name) {
            $field = self::option_field_id($provider_id);
            $key   = isset($data[$field]) ? trim((string) $data[$field]) : '';
            update_option($option_name, $key, false);
        }
    }

    public static function inject_ai_client_auth() {
        if (!class_exists(AiClient::class)) {
            return;
        }

        try {
            $registry = AiClient::defaultRegistry();
        } catch (Throwable $e) {
            return;
        }

        foreach (self::connector_option_names() as $provider_id => $option_name) {
            unset($option_name);
            if (!$registry->hasProvider($provider_id)) {
                continue;
            }
            $key = self::get_api_key_resolved($provider_id);
            if ($key === '') {
                continue;
            }
            $registry->setProviderRequestAuthentication(
                $provider_id,
                new ApiKeyRequestAuthentication($key)
            );
        }
    }
}

add_action('plugins_loaded', array('Wanyesea_AI_Connectors', 'boot'), 15);

/**
 * 连接页加载前预热 Registry（核心 script_module 在 init 22 注册中转 Provider 之前可能已缓存判定）。
 */
function wanyesea_ai_connectors_script_module_preload(array $data) {
    if (function_exists('wanyesea_ai_ensure_ai_client_auth')) {
        wanyesea_ai_ensure_ai_client_auth();
    }

    return $data;
}

add_filter('script_module_data_options-connectors-wp-admin', 'wanyesea_ai_connectors_script_module_preload', 9);

/**
 * 修正「设置 → 连接」页的 isConnected（核心仅调用 isProviderConfigured，中转 OpenAI 需与测试页同源判定）。
 *
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function wanyesea_ai_connectors_script_module_patch_connected(array $data) {
    if (!is_array($data) || empty($data['connectors']) || !is_array($data['connectors'])) {
        return $data;
    }

    if (!class_exists(AiClient::class)) {
        return $data;
    }

    if (function_exists('wanyesea_ai_ensure_ai_client_auth')) {
        wanyesea_ai_ensure_ai_client_auth();
    }

    try {
        $registry = AiClient::defaultRegistry();
    } catch (Throwable $e) {
        return $data;
    }

    foreach ($data['connectors'] as $connector_id => &$connector_out) {
        $connector_id = sanitize_key((string) $connector_id);
        if (!is_array($connector_out)) {
            continue;
        }
        if (($connector_out['type'] ?? '') !== 'ai_provider') {
            continue;
        }
        if (!isset($connector_out['authentication']) || !is_array($connector_out['authentication'])) {
            continue;
        }
        if (($connector_out['authentication']['method'] ?? '') !== 'api_key') {
            continue;
        }
        if (!$registry->hasProvider($connector_id)) {
            continue;
        }

        $key = Wanyesea_AI_Connectors::get_api_key_resolved($connector_id);
        if ($key !== '') {
            $registry->setProviderRequestAuthentication(
                $connector_id,
                new ApiKeyRequestAuthentication($key)
            );
        }

        if (function_exists('wanyesea_ai_is_provider_registry_configured')) {
            $connector_out['authentication']['isConnected'] = wanyesea_ai_is_provider_registry_configured($connector_id);
        }
    }
    unset($connector_out);

    return $data;
}

add_filter('script_module_data_options-connectors-wp-admin', 'wanyesea_ai_connectors_script_module_patch_connected', 20);

/**
 * REST 保存 Connectors 密钥时，在核心校验 isProviderConfigured 之前预热中转 Provider。
 *
 * @param WP_REST_Response $response
 * @param WP_REST_Server   $server
 * @param WP_REST_Request  $request
 * @return WP_REST_Response
 */
function wanyesea_ai_connectors_rest_prepare_validation($response, $server, $request) {
    unset($server);

    if (!is_object($request) || !method_exists($request, 'get_route') || !method_exists($request, 'get_method')) {
        return $response;
    }

    if ('/wp/v2/settings' !== $request->get_route()) {
        return $response;
    }

    if (!in_array($request->get_method(), array('POST', 'PUT'), true)) {
        return $response;
    }

    if (function_exists('wanyesea_ai_ensure_ai_client_auth')) {
        wanyesea_ai_ensure_ai_client_auth();
    }

    return $response;
}

add_filter('rest_post_dispatch', 'wanyesea_ai_connectors_rest_prepare_validation', 5, 3);
