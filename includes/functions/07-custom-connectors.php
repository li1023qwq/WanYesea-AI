<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

/**
 * 自定义 Connector（方案 B）：注册到 WordPress 官方「设置 → 连接」页，并与本插件选项双向同步 API Key。
 */
final class Wanyesea_AI_Custom_Connectors {

    const PLUGIN_FILE = 'WanYesea-AI/index.php';

    /**
     * @return array<string, array{
     *     name: string,
     *     description: string,
     *     logo: string,
     *     credentials_url: string,
     *     official_base_url: string,
     *     preferred_model_hint?: string,
     *     type?: string
     * }>
     */
    public static function definitions() {
        $defaults = array(
            'deepseek' => array(
                'name'                  => 'DeepSeek',
                'description'           => 'DeepSeek 大模型 API（OpenAI 兼容）。默认走官方端点，可按需启用中转。',
                'logo'                  => 'assets/images/deepseek.svg',
                'credentials_url'       => 'https://platform.deepseek.com/api_keys',
                'official_base_url'     => 'https://api.deepseek.com/v1',
                'preferred_model_hint'  => 'deepseek-chat',
                'type'                  => 'ai_provider',
            ),
            'moonshot' => array(
                'name'                  => 'Moonshot',
                'description'           => '月之暗面 Kimi 大模型 API（OpenAI 兼容）。默认走官方端点，可按需启用中转。',
                'logo'                  => 'assets/images/moonshot.svg',
                'credentials_url'       => 'https://platform.moonshot.cn/console/api-keys',
                'official_base_url'     => 'https://api.moonshot.cn/v1',
                'preferred_model_hint'  => 'moonshot-v1-8k',
                'type'                  => 'ai_provider',
            ),
            'zhipu' => array(
                'name'                  => '智谱 AI',
                'description'           => '智谱 GLM 系列大模型 API。默认走官方端点，可按需启用中转。',
                'logo'                  => 'assets/images/zhipu.svg',
                'credentials_url'       => 'https://open.bigmodel.cn/usercenter/apikeys',
                'official_base_url'     => 'https://open.bigmodel.cn/api/paas/v4',
                'preferred_model_hint'  => 'glm-4-flash',
                'type'                  => 'ai_provider',
            ),
            'xiaomi' => array(
                'name'                  => '小米 MiMo',
                'description'           => '小米 MiMo 开放平台（OpenAI 兼容 https://api.xiaomimimo.com/v1，请求头 api-key）。默认 mimo-v2.5-pro，可按需启用中转。',
                'logo'                  => 'assets/images/xiaomi.svg',
                'credentials_url'       => 'https://platform.xiaomimimo.com/docs/zh-CN/quick-start/first-api-call',
                'official_base_url'     => 'https://api.xiaomimimo.com/v1',
                'preferred_model_hint'  => 'mimo-v2.5-pro',
                'type'                  => 'ai_provider',
            ),
            'nvidia' => array(
                'name'                  => 'NVIDIA',
                'description'           => 'NVIDIA NIM / Build API。默认走官方端点，可按需启用中转。',
                'logo'                  => 'assets/images/nvidia.svg',
                'credentials_url'       => 'https://build.nvidia.com/settings/api-keys',
                'official_base_url'     => 'https://integrate.api.nvidia.com/v1',
                'preferred_model_hint'  => 'meta/llama-3.1-8b-instruct',
                'type'                  => 'ai_provider',
            ),
            'sensenova' => array(
                'name'                  => 'SenseNova',
                'description'           => '商汤 SenseNova API（OpenAI 兼容，https://token.sensenova.cn/v1）。文本：sensenova-6.7-flash-lite、deepseek-v4-flash；图像：sensenova-u1-fast（POST /v1/images/generations 信息图）。默认走官方端点，可按需启用中转。',
                'logo'                  => 'assets/images/sensenova.svg',
                'credentials_url'       => 'https://platform.sensenova.cn/docs',
                'official_base_url'     => 'https://token.sensenova.cn/v1',
                'preferred_model_hint'  => 'sensenova-6.7-flash-lite',
                'preferred_image_model_hint' => 'sensenova-u1-fast',
                'type'                  => 'ai_provider',
            ),
        );

        return apply_filters('wanyesea_ai_custom_connectors', $defaults);
    }

    /**
     * 自定义 Connector 官方 API 根地址（未启用中转或 Base URL 留空时使用）。
     *
     * @return array<string, string>
     */
    public static function official_base_urls() {
        $urls = array();
        foreach (self::definitions() as $id => $def) {
            if (empty($def['official_base_url'])) {
                continue;
            }
            $urls[$id] = rtrim((string) $def['official_base_url'], '/');
        }

        return apply_filters('wanyesea_ai_custom_official_base_urls', $urls);
    }

    public static function get_official_base_url($provider_id) {
        $urls = self::official_base_urls();
        $provider_id = sanitize_key((string) $provider_id);
        return isset($urls[$provider_id]) ? $urls[$provider_id] : '';
    }

    /**
     * @return list<string>
     */
    public static function provider_ids() {
        return array_keys(self::definitions());
    }

    public static function is_custom_provider($provider_id) {
        $provider_id = sanitize_key((string) $provider_id);
        return isset(self::definitions()[$provider_id]);
    }

    public static function option_field_id($provider_id) {
        return 'connector_' . sanitize_key((string) $provider_id) . '_api_key';
    }

    /**
     * WordPress Connectors 选项名（沿用 connectors_custom_{id}_api_key，与既有数据兼容）。
     */
    public static function wp_option_name($provider_id) {
        $provider_id = sanitize_key((string) $provider_id);
        if (!isset(self::definitions()[$provider_id])) {
            return '';
        }

        return 'connectors_custom_' . $provider_id . '_api_key';
    }

    public static function get_api_key($provider_id) {
        $provider_id = sanitize_key((string) $provider_id);
        $key         = trim((string) WanYesea_AI(self::option_field_id($provider_id), ''));
        return apply_filters('wanyesea_ai_custom_connector_api_key', $key, $provider_id);
    }

    public static function is_configured($provider_id) {
        return self::get_api_key($provider_id) !== '';
    }

    public static function logo_url($provider_id) {
        $provider_id = sanitize_key((string) $provider_id);
        $def         = self::definitions()[$provider_id] ?? null;
        if (!$def || empty($def['logo'])) {
            return '';
        }
        return WanYesea_AI_url . '/' . ltrim($def['logo'], '/');
    }

    public static function boot() {
        if (!function_exists('wp_get_connector')) {
            return;
        }

        add_action('wp_connectors_init', array(__CLASS__, 'register_on_wp_registry'), 20);

        foreach (self::definitions() as $provider_id => $def) {
            unset($def);
            $wp_option = self::wp_option_name($provider_id);
            if ($wp_option === '') {
                continue;
            }

            add_filter(
                'pre_option_' . $wp_option,
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

            add_filter(
                'option_' . $wp_option,
                static function ($value) use ($provider_id) {
                    if (is_string($value) && trim($value) !== '') {
                        return $value;
                    }
                    $key = self::get_api_key($provider_id);
                    return $key !== '' ? $key : $value;
                },
                5,
                1
            );

            add_action(
                'update_option_' . $wp_option,
                static function ($old_value, $value) use ($provider_id) {
                    unset($old_value);
                    self::sync_wp_option_to_plugin($provider_id, (string) $value);
                },
                10,
                2
            );
        }

        add_filter('csf_WanYesea_AI_save', array(__CLASS__, 'filter_csf_save'), 10, 2);
        add_action('csf_WanYesea_AI_saved', array(__CLASS__, 'on_csf_saved'), 10, 1);
    }

    /**
     * @param WP_Connector_Registry $registry
     */
    public static function register_on_wp_registry($registry) {
        if (!is_object($registry) || !method_exists($registry, 'register')) {
            return;
        }

        foreach (self::definitions() as $id => $def) {
            $type = isset($def['type']) && $def['type'] !== '' ? sanitize_key($def['type']) : 'ai_provider';

            $connector = array(
                'name'           => $def['name'],
                'description'    => $def['description'],
                'logo_url'       => self::logo_url($id),
                'type'           => $type,
                'plugin'         => array(
                    'file'      => self::PLUGIN_FILE,
                    'is_active' => static function () {
                        return defined('WanYesea_AI_path');
                    },
                ),
                'authentication' => array(
                    'method'          => 'api_key',
                    'credentials_url' => $def['credentials_url'],
                    'setting_name'    => self::wp_option_name($id),
                ),
            );

            if ($registry->is_registered($id)) {
                $registry->unregister($id);
            }

            $registry->register($id, $connector);
        }
    }

    /**
     * 官方连接页保存后写回本插件选项。
     */
    public static function sync_wp_option_to_plugin($provider_id, $value) {
        $provider_id = sanitize_key((string) $provider_id);
        if (!self::is_custom_provider($provider_id)) {
            return;
        }

        $field   = self::option_field_id($provider_id);
        $options = get_option('WanYesea_AI', array());
        if (!is_array($options)) {
            $options = array();
        }

        $incoming = trim(sanitize_text_field($value));
        $current  = isset($options[$field]) ? trim((string) $options[$field]) : '';

        if ($incoming === $current) {
            return;
        }

        $options[$field] = $incoming;
        update_option('WanYesea_AI', $options, false);
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
        foreach (self::provider_ids() as $provider_id) {
            $wp_option = self::wp_option_name($provider_id);
            $field     = self::option_field_id($provider_id);
            if ($wp_option === '') {
                continue;
            }
            $key = isset($data[$field]) ? trim((string) $data[$field]) : '';
            update_option($wp_option, $key, false);
        }
    }
}

add_action('plugins_loaded', array('Wanyesea_AI_Custom_Connectors', 'boot'), 15);

/**
 * 将本插件选项中的自定义 Connector 密钥同步到 WordPress Connectors 选项（供连接页「已连接」判定）。
 */
function wanyesea_ai_sync_custom_connector_keys_to_wp_options() {
    if (!class_exists('Wanyesea_AI_Custom_Connectors')) {
        return;
    }

    foreach (Wanyesea_AI_Custom_Connectors::provider_ids() as $provider_id) {
        $wp_option = Wanyesea_AI_Custom_Connectors::wp_option_name($provider_id);
        if ($wp_option === '') {
            continue;
        }
        $key = Wanyesea_AI_Custom_Connectors::get_api_key($provider_id);
        if ($key === '') {
            continue;
        }
        $stored = get_option($wp_option, '');
        if (!is_string($stored) || trim($stored) === '') {
            update_option($wp_option, $key, false);
        }
    }
}

add_action('plugins_loaded', 'wanyesea_ai_sync_custom_connector_keys_to_wp_options', 16);

/**
 * @return list<string>
 */
function wanyesea_ai_custom_connect_provider_ids() {
    return Wanyesea_AI_Custom_Connectors::provider_ids();
}

function wanyesea_ai_is_custom_connect_provider($provider_id) {
    return Wanyesea_AI_Custom_Connectors::is_custom_provider($provider_id);
}

add_filter('wanyesea_ai_connect_provider_ids', function ($ids) {
    if (!is_array($ids)) {
        $ids = array();
    }
    return array_values(array_unique(array_merge($ids, wanyesea_ai_custom_connect_provider_ids())));
});

add_filter('wanyesea_ai_official_base_urls', function ($urls) {
    if (!is_array($urls)) {
        $urls = array();
    }
    return array_merge($urls, Wanyesea_AI_Custom_Connectors::official_base_urls());
});
