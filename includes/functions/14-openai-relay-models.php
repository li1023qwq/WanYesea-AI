<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithHttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

/**
 * 启用 OpenAI 中转时替换官方 Provider：文本走 chat/completions（New API 不支持 /v1/responses）。
 */
if (class_exists('WordPress\OpenAiAiProvider\Provider\OpenAiProvider')
    && class_exists('WordPress\OpenAiAiProvider\Metadata\OpenAiModelMetadataDirectory')
    && class_exists('Wanyesea_AI_OpenAi_Compatible_Text_Generation_Model')) {

    final class Wanyesea_AI_Relay_OpenAi_Provider extends WordPress\OpenAiAiProvider\Provider\OpenAiProvider {

        protected static function createModel(
            ModelMetadata $modelMetadata,
            ProviderMetadata $providerMetadata
        ): ModelInterface {
            foreach ($modelMetadata->getSupportedCapabilities() as $capability) {
                if ($capability->isTextGeneration()) {
                    return new Wanyesea_AI_OpenAi_Compatible_Text_Generation_Model(
                        $modelMetadata,
                        $providerMetadata,
                        WordPress\OpenAiAiProvider\Provider\OpenAiProvider::class
                    );
                }
                if ($capability->isImageGeneration()) {
                    return new WordPress\OpenAiAiProvider\Models\OpenAiImageGenerationModel(
                        $modelMetadata,
                        $providerMetadata
                    );
                }
            }

            throw new RuntimeException(
                'Unsupported model capabilities for relay OpenAI: ' . $modelMetadata->getId()
            );
        }

        protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
            return new Wanyesea_AI_Relay_Official_Model_Metadata_Directory(
                new WordPress\OpenAiAiProvider\Metadata\OpenAiModelMetadataDirectory(),
                'openai'
            );
        }

        /**
         * 有 API Key 即视为已配置，避免「未校验」导致 Registry 拒绝列出中转模型。
         */
        protected static function createProviderAvailability(): ProviderAvailabilityInterface {
            return new Wanyesea_AI_Api_Key_Or_List_Models_Provider_Availability(
                static::modelMetadataDirectory(),
                'openai'
            );
        }
    }
}

/**
 * 官方 OpenAI Provider 在 API 中转（New API / One API）下的模型元数据装饰器。
 *
 * 官方 OpenAiModelMetadataDirectory 仅对 gpt-* / o1 / o3 / o4 等 ID 声明 text_generation；
 * 中转网关常返回 openai/gpt-4o、deepseek-chat 等 ID，导致 Registry 无法匹配文本能力。
 * 本类在保留官方元数据的前提下，合并 HTTP /models 探测到的对话模型。
 */

/**
 * 从 AI Client Registry（或连接页密钥）解析厂商鉴权，供中转元数据目录/模型在实例未绑定时回退。
 *
 * @throws RuntimeException
 */
function wanyesea_ai_get_registry_provider_request_authentication($provider_id) {
    $provider_id = sanitize_key((string) $provider_id);
    if ($provider_id === '' || !class_exists('WordPress\AiClient\AiClient')) {
        throw new RuntimeException(
            'RequestAuthenticationInterface instance not set. Make sure you use the AiClient class for all requests.'
        );
    }

    if (function_exists('wanyesea_ai_ensure_ai_client_auth')) {
        wanyesea_ai_ensure_ai_client_auth();
    }

    try {
        $registry = WordPress\AiClient\AiClient::defaultRegistry();
        if ($registry->hasProvider($provider_id)) {
            $auth = $registry->getProviderRequestAuthentication($provider_id);
            if ($auth instanceof RequestAuthenticationInterface) {
                return $auth;
            }
        }
    } catch (Throwable $e) {
        // fall through
    }

    if (function_exists('wanyesea_ai_get_connector_api_key_resolved')) {
        $key = wanyesea_ai_get_connector_api_key_resolved($provider_id);
        if ($key !== '') {
            return new WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication($key);
        }
    }

    throw new RuntimeException(
        'RequestAuthenticationInterface instance not set. Make sure you use the AiClient class for all requests.'
    );
}

final class Wanyesea_AI_Relay_Official_Model_Metadata_Directory implements
    ModelMetadataDirectoryInterface,
    WithHttpTransporterInterface,
    WithRequestAuthenticationInterface {

    /** @var ModelMetadataDirectoryInterface&WithHttpTransporterInterface&WithRequestAuthenticationInterface */
    private $inner;

    /** @var string */
    private $provider_id;

    /** @var RequestAuthenticationInterface|null */
    private $request_authentication = null;

    /** @var array<string, array<string, ModelMetadata>> */
    private static $merged_map_cache = array();

    public static function clearMergedMapCache($provider_id = '') {
        $provider_id = sanitize_key((string) $provider_id);
        if ($provider_id === '') {
            self::$merged_map_cache = array();
            return;
        }
        unset(self::$merged_map_cache[$provider_id]);
    }

    /**
     * @param ModelMetadataDirectoryInterface $inner
     * @param string                          $provider_id
     */
    public function __construct(ModelMetadataDirectoryInterface $inner, $provider_id) {
        $this->inner        = $inner;
        $this->provider_id  = sanitize_key((string) $provider_id);
    }

    public function listModelMetadata(): array {
        return array_values($this->getMergedMap());
    }

    public function hasModelMetadata(string $modelId): bool {
        $map = $this->getMergedMap();
        return isset($map[$modelId]);
    }

    public function getModelMetadata(string $modelId): ModelMetadata {
        $map = $this->getMergedMap();
        if (!isset($map[$modelId])) {
            throw new WordPress\AiClient\Common\Exception\InvalidArgumentException(
                sprintf('No model with ID %s was found in the provider', $modelId)
            );
        }
        return $map[$modelId];
    }

    public function setHttpTransporter(HttpTransporterInterface $httpTransporter): void {
        if ($this->inner instanceof WithHttpTransporterInterface) {
            $this->inner->setHttpTransporter($httpTransporter);
        }
    }

    public function getHttpTransporter(): HttpTransporterInterface {
        if ($this->inner instanceof WithHttpTransporterInterface) {
            return $this->inner->getHttpTransporter();
        }
        throw new RuntimeException(
            'HttpTransporterInterface instance not set. Make sure you use the AiClient class for all requests.'
        );
    }

    public function setRequestAuthentication(RequestAuthenticationInterface $requestAuthentication): void {
        $this->request_authentication = $requestAuthentication;
        if ($this->inner instanceof WithRequestAuthenticationInterface) {
            $this->inner->setRequestAuthentication($requestAuthentication);
        }
    }

    public function getRequestAuthentication(): RequestAuthenticationInterface {
        if ($this->request_authentication instanceof RequestAuthenticationInterface) {
            return $this->request_authentication;
        }

        if ($this->inner instanceof WithRequestAuthenticationInterface) {
            try {
                return $this->inner->getRequestAuthentication();
            } catch (RuntimeException $e) {
                // Provider 静态缓存刷新后 inner 可能尚未注入鉴权，回退 Registry。
            }
        }

        $auth = wanyesea_ai_get_registry_provider_request_authentication($this->provider_id);
        $this->setRequestAuthentication($auth);

        return $auth;
    }

    /**
     * @return array<string, ModelMetadata>
     */
    private function getMergedMap(): array {
        $provider_id = $this->provider_id;
        if (isset(self::$merged_map_cache[$provider_id])) {
            return self::$merged_map_cache[$provider_id];
        }

        $map = array();
        try {
            foreach ($this->inner->listModelMetadata() as $metadata) {
                if (!$metadata instanceof ModelMetadata) {
                    continue;
                }
                $map[$metadata->getId()] = $metadata;
            }
        } catch (Throwable $e) {
            $map = array();
        }

        foreach (wanyesea_ai_relay_official_probe_text_model_ids($provider_id) as $model_id) {
            if ($model_id === '') {
                continue;
            }
            if (isset($map[$model_id]) && wanyesea_ai_model_metadata_has_text_generation($map[$model_id])) {
                continue;
            }
            $map[$model_id] = wanyesea_ai_build_relay_chat_text_model_metadata($model_id);
        }

        if (function_exists('wanyesea_ai_build_relay_image_model_metadata')) {
            foreach (wanyesea_ai_relay_official_probe_image_model_ids($provider_id) as $model_id) {
                if ($model_id === '' || isset($map[$model_id])) {
                    continue;
                }
                $map[$model_id] = wanyesea_ai_build_relay_image_model_metadata($model_id, $provider_id);
            }
        }

        if ($map !== array()) {
            self::$merged_map_cache[$provider_id] = $map;
        }

        return $map;
    }
}

/**
 * 启用中转的官方 Provider ID => Provider 类名。
 *
 * @return array<string, class-string>
 */
function wanyesea_ai_relay_official_provider_class_map() {
    $map = array();

    if (class_exists('Wanyesea_AI_Relay_OpenAi_Provider')) {
        $map['openai'] = 'Wanyesea_AI_Relay_OpenAi_Provider';
    } elseif (class_exists('WordPress\OpenAiAiProvider\Provider\OpenAiProvider')) {
        $map['openai'] = 'WordPress\OpenAiAiProvider\Provider\OpenAiProvider';
    }

    return apply_filters('wanyesea_ai_relay_official_provider_class_map', $map);
}

/**
 * 向 AI Client 注册中转版 OpenAI Provider（覆盖官方实现，同 ID openai）。
 */
/**
 * 清除 Provider 静态缓存（availability / modelMetadataDirectory）。
 *
 * @param list<string> $class_names
 */
function wanyesea_ai_clear_provider_static_caches(array $class_names) {
    if (!class_exists('WordPress\AiClient\Providers\AbstractProvider')) {
        return;
    }

    try {
        $ref = new ReflectionClass('WordPress\AiClient\Providers\AbstractProvider');

        foreach (array('availabilityCache', 'modelMetadataDirectoryCache') as $prop_name) {
            $prop = $ref->getProperty($prop_name);
            $prop->setAccessible(true);
            $cache = $prop->getValue();
            if (!is_array($cache)) {
                continue;
            }
            foreach ($class_names as $class_name) {
                unset($cache[$class_name]);
            }
            $prop->setValue(null, $cache);
        }
    } catch (Throwable $e) {
        return;
    }
}

function wanyesea_ai_register_relay_openai_provider() {
    if (!wanyesea_ai_relay_is_provider_active('openai')) {
        return;
    }
    if (!class_exists('Wanyesea_AI_Relay_OpenAi_Provider') || !class_exists('WordPress\AiClient\AiClient')) {
        return;
    }

    try {
        $registry = WordPress\AiClient\AiClient::defaultRegistry();
        $registry->registerProvider('Wanyesea_AI_Relay_OpenAi_Provider');
    } catch (Throwable $e) {
        return;
    }

    wanyesea_ai_clear_provider_static_caches(array(
        'Wanyesea_AI_Relay_OpenAi_Provider',
        'WordPress\OpenAiAiProvider\Provider\OpenAiProvider',
    ));

    if (class_exists('Wanyesea_AI_Relay_Official_Model_Metadata_Directory')) {
        Wanyesea_AI_Relay_Official_Model_Metadata_Directory::clearMergedMapCache('openai');
    }

    if (class_exists('Wanyesea_AI_Connectors')) {
        Wanyesea_AI_Connectors::inject_ai_client_auth();
    }
}

add_action('init', 'wanyesea_ai_register_relay_openai_provider', 22);

/**
 * 文本生成前预热：注册中转 Provider、写入 Registry。
 *
 * @param string $model_id       可选，额外预热指定模型。
 * @param bool   $force_refresh  为 true 时强制 HTTP 刷新 /models（仅测试页等场景，编辑页勿用）。
 */
function wanyesea_ai_prime_relay_openai_for_text_generation($model_id = '', $force_refresh = false) {
    if (!wanyesea_ai_relay_is_provider_active('openai')) {
        return;
    }

    wanyesea_ai_register_relay_openai_provider();

    if ($force_refresh) {
        wanyesea_ai_probe_models_classified_reset('openai');
        wanyesea_ai_probe_model_ids_for_capability('openai', 'text', true);
    }

    if (!class_exists('WordPress\AiClient\AiClient')) {
        return;
    }

    try {
        $registry = WordPress\AiClient\AiClient::defaultRegistry();
        if (!$registry->hasProvider('openai')) {
            return;
        }

        if ($force_refresh) {
            $registry->findProviderModelsMetadataForSupport(
                'openai',
                new WordPress\AiClient\Providers\Models\DTO\ModelRequirements(
                    array(CapabilityEnum::textGeneration()),
                    array()
                )
            );
        }

        $model_id = wanyesea_ai_normalize_model_id($model_id);
        if ($model_id !== '') {
            if (function_exists('wanyesea_ai_create_relay_openai_text_model_for_id')
                && wanyesea_ai_create_relay_openai_text_model_for_id($model_id) !== null) {
                return;
            }
            $registry->getProviderModel('openai', $model_id);
        }
    } catch (Throwable $e) {
        return;
    }
}

/**
 * 中转 OpenAI：直连 chat/completions（测试页 Registry 失败时的回退）。
 *
 * @return string|\WP_Error 成功返回文本，失败返回 WP_Error
 */
function wanyesea_ai_relay_openai_direct_chat_completions($model_id, $prompt, $max_tokens = 256) {
    $model_id = wanyesea_ai_normalize_model_id($model_id);
    $prompt   = (string) $prompt;

    if ($model_id === '' || $prompt === '') {
        return new WP_Error('wya_invalid_args', '模型 ID 或提示词为空');
    }
    if (!wanyesea_ai_relay_is_provider_active('openai')) {
        return new WP_Error('wya_relay_off', '未启用 OpenAI 中转');
    }

    $api_key = function_exists('wanyesea_ai_get_connector_api_key_resolved')
        ? wanyesea_ai_get_connector_api_key_resolved('openai')
        : '';
    if ($api_key === '') {
        return new WP_Error('wya_no_key', '未配置 OpenAI API Key');
    }

    $endpoint = function_exists('wanyesea_ai_get_provider_effective_endpoint')
        ? wanyesea_ai_get_provider_effective_endpoint('openai')
        : array('url' => '');
    $base_url = isset($endpoint['url']) ? rtrim((string) $endpoint['url'], '/') : '';
    if ($base_url === '') {
        return new WP_Error('wya_no_endpoint', '未配置中转 Base URL');
    }

    $timeout = (int) apply_filters('wanyesea_ai_text_generation_timeout', 120.0, 'openai');
    $timeout = max(30, min(300, $timeout));

    $payload = wp_json_encode(array(
        'model'       => $model_id,
        'messages'    => array(
            array(
                'role'    => 'user',
                'content' => $prompt,
            ),
        ),
        'max_tokens'  => max(1, (int) $max_tokens),
        'temperature' => 0.5,
    ));

    if (!is_string($payload)) {
        return new WP_Error('wya_payload', '无法构建请求 JSON');
    }

    $response = wp_safe_remote_post(
        $base_url . '/chat/completions',
        array(
            'timeout' => $timeout,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => $payload,
        )
    );

    if (is_wp_error($response)) {
        return $response;
    }

    $code     = (int) wp_remote_retrieve_response_code($response);
    $raw_body = (string) wp_remote_retrieve_body($response);
    $body     = json_decode($raw_body, true);

    if ($code < 200 || $code >= 300) {
        $snippet = wp_strip_all_tags(substr($raw_body, 0, 400));
        return new WP_Error(
            'wya_api_http',
            sprintf('HTTP %d：%s', $code, $snippet !== '' ? $snippet : '请求被拒绝')
        );
    }

    if (!is_array($body)) {
        return new WP_Error('wya_api_json', '中转返回不是有效 JSON');
    }

    if (!empty($body['choices'][0]['message']['content'])) {
        return (string) $body['choices'][0]['message']['content'];
    }

    if (!empty($body['choices'][0]['text'])) {
        return (string) $body['choices'][0]['text'];
    }

    if (!empty($body['error']['message'])) {
        return new WP_Error('wya_api_error', (string) $body['error']['message']);
    }

    return new WP_Error('wya_api_empty', 'API 返回成功但未包含文本内容');
}

/**
 * 用所选模型 ID 构建中转 OpenAI 文本模型实例（绕过 Registry 候选列表，与「加载模型」一致）。
 *
 * @return \WordPress\AiClient\Providers\Models\Contracts\ModelInterface|null
 */
function wanyesea_ai_create_relay_openai_text_model_for_id($model_id) {
    $model_id = wanyesea_ai_normalize_model_id($model_id);
    if ($model_id === '' || !class_exists('Wanyesea_AI_Relay_OpenAi_Provider')) {
        return null;
    }

    if (!function_exists('wanyesea_ai_build_relay_chat_text_model_metadata')) {
        return null;
    }

    try {
        $metadata = wanyesea_ai_build_relay_chat_text_model_metadata($model_id);
        $model    = new Wanyesea_AI_OpenAi_Compatible_Text_Generation_Model(
            $metadata,
            Wanyesea_AI_Relay_OpenAi_Provider::metadata(),
            WordPress\OpenAiAiProvider\Provider\OpenAiProvider::class
        );

        if (class_exists('WordPress\AiClient\AiClient')) {
            WordPress\AiClient\AiClient::defaultRegistry()->bindModelDependencies($model);
        }

        return $model;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * 测试页 / 与「加载模型」同源：中转 OpenAI 优先直连 chat/completions，不依赖 Registry 二次筛选。
 *
 * @return string|\WP_Error
 */
function wanyesea_ai_test_lab_generate_text($provider_id, $model_id, $prompt) {
    $provider_id = sanitize_key((string) $provider_id);
    $model_id    = wanyesea_ai_normalize_model_id($model_id);
    $prompt      = (string) $prompt;

    if ($provider_id === '' || $model_id === '') {
        return new WP_Error('wya_invalid_args', '请选择厂商与模型');
    }

    if ($provider_id === 'openai' && wanyesea_ai_relay_is_provider_active('openai')) {
        $listed = wanyesea_ai_probe_model_ids_for_capability('openai', 'text', true);
        if ($listed !== array() && !in_array($model_id, $listed, true)) {
            return new WP_Error(
                'wya_model_not_in_list',
                '所选模型不在当前中转列表中，请重新点击「加载模型」后再试。'
            );
        }

        $direct = wanyesea_ai_relay_openai_direct_chat_completions($model_id, $prompt, 256);
        if (is_string($direct) && $direct !== '') {
            return $direct;
        }

        if (!function_exists('wp_ai_client_prompt')) {
            return is_wp_error($direct) ? $direct : new WP_Error('wya_no_client', '未检测到 WP AI Client');
        }

        $model = wanyesea_ai_create_relay_openai_text_model_for_id($model_id);
        if ($model !== null) {
            try {
                $options = RequestOptions::fromArray(array('timeout' => 120.0));
                $text    = wp_ai_client_prompt($prompt)
                    ->usingModel($model)
                    ->using_max_tokens(256)
                    ->using_temperature(0.5)
                    ->using_request_options($options)
                    ->generate_text();

                if (is_string($text) && $text !== '' && !is_wp_error($text)) {
                    return $text;
                }
            } catch (Throwable $e) {
                if (is_wp_error($direct)) {
                    return new WP_Error(
                        'wya_relay_failed',
                        $direct->get_error_message() . '（Registry 回退：' . $e->getMessage() . '）'
                    );
                }
                return new WP_Error('wya_relay_failed', $e->getMessage());
            }
        }

        return is_wp_error($direct)
            ? $direct
            : new WP_Error('wya_relay_failed', '中转文本生成失败，请检查模型 ID 与网关渠道');
    }

    if (!function_exists('wp_ai_client_prompt')) {
        return new WP_Error('wya_no_client', '未检测到 WP AI Client');
    }

    if (function_exists('wanyesea_ai_prime_relay_openai_for_text_generation')) {
        wanyesea_ai_prime_relay_openai_for_text_generation($model_id, true);
    }

    try {
        $options = RequestOptions::fromArray(array('timeout' => 120.0));
        $builder = wp_ai_client_prompt($prompt)
            ->using_provider($provider_id);

        $explicit = function_exists('wanyesea_ai_create_relay_openai_text_model_for_id')
            ? wanyesea_ai_create_relay_openai_text_model_for_id($model_id)
            : null;
        if ($explicit !== null) {
            $builder = $builder->usingModel($explicit);
        } else {
            $builder = $builder->using_model_preference(array($provider_id, $model_id));
        }

        $text = $builder
            ->using_max_tokens(256)
            ->using_temperature(0.5)
            ->using_request_options($options)
            ->generate_text();

        if (is_wp_error($text)) {
            return $text;
        }
        if (is_string($text) && $text !== '') {
            return $text;
        }

        return new WP_Error('wya_empty', '未返回文本内容');
    } catch (Throwable $e) {
        return new WP_Error('wya_generate_failed', $e->getMessage());
    }
}

/**
 * 模型元数据是否声明 text_generation。
 */
function wanyesea_ai_model_metadata_has_text_generation($metadata) {
    if (!$metadata instanceof ModelMetadata) {
        return false;
    }
    foreach ($metadata->getSupportedCapabilities() as $capability) {
        if ($capability->isTextGeneration()) {
            return true;
        }
    }
    return false;
}

/**
 * 为中转网关上的对话模型构建 OpenAI 兼容文本元数据。
 */
function wanyesea_ai_build_relay_chat_text_model_metadata($model_id) {
    $model_id = wanyesea_ai_normalize_model_id($model_id);

    return new ModelMetadata(
        $model_id,
        $model_id,
        array(CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory()),
        wanyesea_ai_openai_compatible_chat_text_model_options(false)
    );
}

/**
 * 保留网关模型 ID 原样（含 64/gpt-5.2、claude-sonnet-4 等，勿用 sanitize_text_field）。
 */
function wanyesea_ai_normalize_model_id($model_id) {
    $model_id = trim(wp_unslash((string) $model_id));
    if ($model_id === '') {
        return '';
    }

    return apply_filters('wanyesea_ai_normalize_model_id', $model_id);
}

/**
 * 是否对该厂商启用 API 中转（官方 openai 等）。
 */
function wanyesea_ai_relay_is_provider_active($provider_id) {
    return class_exists('Wanyesea_AI_Relay')
        && Wanyesea_AI_Relay::is_provider_active(sanitize_key((string) $provider_id));
}

/**
 * 清除 /models 探测的请求内缓存（供加载模型等场景强制刷新）。
 */
function wanyesea_ai_probe_models_classified_reset($provider_id = '') {
    $GLOBALS['wanyesea_ai_probe_models_classified_cache'] = array();
    if ($provider_id !== '' && class_exists('Wanyesea_AI_Relay_Official_Model_Metadata_Directory')) {
        Wanyesea_AI_Relay_Official_Model_Metadata_Directory::clearMergedMapCache($provider_id);
    }
}

/**
 * HTTP 探测 /models 的分类结果（与「检测端点」一致）。
 *
 * @param string $provider_id
 * @param bool   $force_refresh 为 true 时忽略请求内缓存（加载模型按钮应传 true）
 * @return array{text: list<string>, image: list<string>, other: list<string>}
 */
function wanyesea_ai_probe_models_classified($provider_id, $force_refresh = false) {
    $provider_id = sanitize_key((string) $provider_id);
    $empty       = array('text' => array(), 'image' => array(), 'other' => array());

    if (!isset($GLOBALS['wanyesea_ai_probe_models_classified_cache'])
        || !is_array($GLOBALS['wanyesea_ai_probe_models_classified_cache'])) {
        $GLOBALS['wanyesea_ai_probe_models_classified_cache'] = array();
    }

    $cache = &$GLOBALS['wanyesea_ai_probe_models_classified_cache'];

    if ($force_refresh) {
        unset($cache[$provider_id]);
        if (class_exists('Wanyesea_AI_Relay_Official_Model_Metadata_Directory')) {
            Wanyesea_AI_Relay_Official_Model_Metadata_Directory::clearMergedMapCache($provider_id);
        }
    }

    if (isset($cache[$provider_id])) {
        return $cache[$provider_id];
    }

    if (!function_exists('wanyesea_ai_probe_provider_http')) {
        return $empty;
    }

    if (function_exists('wanyesea_ai_get_connector_api_key_resolved')
        && wanyesea_ai_get_connector_api_key_resolved($provider_id) === '') {
        return $empty;
    }

    $result = wanyesea_ai_probe_provider_http($provider_id, array());
    $models = isset($result['models']) && is_array($result['models']) ? $result['models'] : array();

    $classified = array(
        'text'  => isset($models['text']) && is_array($models['text']) ? array_values($models['text']) : array(),
        'image' => isset($models['image']) && is_array($models['image']) ? array_values($models['image']) : array(),
        'other' => isset($models['other']) && is_array($models['other']) ? array_values($models['other']) : array(),
    );

    $classified = apply_filters('wanyesea_ai_probe_models_classified', $classified, $provider_id, $result);

    $has_any = $classified['text'] !== array()
        || $classified['image'] !== array()
        || $classified['other'] !== array();

    if ($has_any) {
        $cache[$provider_id] = $classified;
    }

    return $classified;
}

/**
 * 测试页 / 加载模型：与前端「检测端点」相同的模型列表（不经过 Registry 能力过滤）。
 *
 * @param string $capability text|image
 * @return list<string>
 */
function wanyesea_ai_probe_model_ids_for_capability($provider_id, $capability = 'text', $force_refresh = false) {
    $provider_id = sanitize_key((string) $provider_id);
    $capability  = $capability === 'image' ? 'image' : 'text';
    $classified  = wanyesea_ai_probe_models_classified($provider_id, $force_refresh);

    if ($capability === 'image') {
        $ids = $classified['image'];
    } else {
        $ids = array_merge($classified['text'], $classified['other']);
        if (apply_filters('wanyesea_ai_relay_probe_merge_image_into_text_list', false, $provider_id)) {
            $ids = array_merge($ids, $classified['image']);
        }
    }

    $normalized = array();
    foreach ($ids as $model_id) {
        $model_id = wanyesea_ai_normalize_model_id($model_id);
        if ($model_id === '' || wanyesea_ai_is_nvidia_nim_entity_extraction_model($model_id)) {
            continue;
        }
        $normalized[] = $model_id;
    }

    if ($capability === 'text') {
        $normalized = wanyesea_ai_filter_chat_text_model_ids(array_values(array_unique($normalized)));
    } else {
        $normalized = array_values(array_unique($normalized));
    }

    return apply_filters('wanyesea_ai_probe_model_ids_for_capability', $normalized, $provider_id, $capability);
}

/**
 * 为中转网关上的出图模型构建元数据。
 */
function wanyesea_ai_build_relay_image_model_metadata($model_id, $provider_id = 'openai') {
    $model_id    = wanyesea_ai_normalize_model_id($model_id);
    $provider_id = sanitize_key((string) $provider_id);

    $options = function_exists('wanyesea_ai_openai_compatible_image_model_options')
        ? wanyesea_ai_openai_compatible_image_model_options($provider_id)
        : array();

    return new ModelMetadata(
        $model_id,
        $model_id,
        array(CapabilityEnum::imageGeneration()),
        $options
    );
}

/**
 * 经 HTTP /models 探测得到的文本模型 ID（text + other，与检测端点一致）。
 *
 * @return list<string>
 */
function wanyesea_ai_relay_official_probe_text_model_ids($provider_id) {
    if (!wanyesea_ai_relay_is_provider_active($provider_id)) {
        return array();
    }

    $ids = wanyesea_ai_probe_model_ids_for_capability($provider_id, 'text');

    return apply_filters('wanyesea_ai_relay_official_probe_text_model_ids', $ids, $provider_id);
}

/**
 * 经 HTTP /models 探测得到的图像模型 ID。
 *
 * @return list<string>
 */
function wanyesea_ai_relay_official_probe_image_model_ids($provider_id) {
    if (!wanyesea_ai_relay_is_provider_active($provider_id)) {
        return array();
    }

    $ids = wanyesea_ai_probe_model_ids_for_capability($provider_id, 'image');

    return apply_filters('wanyesea_ai_relay_official_probe_image_model_ids', $ids, $provider_id);
}

/**
 * 发现官方 Provider 的文本模型（Registry；中转时由装饰器合并 /models 结果）。
 *
 * @return list<string>
 */
function wanyesea_ai_discover_official_provider_text_model_ids($provider_id) {
    $provider_id = sanitize_key((string) $provider_id);

    if (wanyesea_ai_relay_is_provider_active($provider_id)) {
        $probe_ids = wanyesea_ai_relay_official_probe_text_model_ids($provider_id);
        if ($probe_ids !== array()) {
            return apply_filters('wanyesea_ai_discovered_official_text_model_ids', $probe_ids, $provider_id);
        }
    }

    if (!class_exists('WordPress\AiClient\AiClient')) {
        return array();
    }

    if (function_exists('wanyesea_ai_ensure_ai_client_auth')) {
        wanyesea_ai_ensure_ai_client_auth();
    }

    try {
        $registry = WordPress\AiClient\AiClient::defaultRegistry();
    } catch (Throwable $e) {
        return wanyesea_ai_relay_is_provider_active($provider_id)
            ? wanyesea_ai_relay_official_probe_text_model_ids($provider_id)
            : array();
    }

    if (!$registry->hasProvider($provider_id)) {
        return array();
    }

    $ids = array();

    try {
        if ($registry->isProviderConfigured($provider_id)) {
            $requirements = new WordPress\AiClient\Providers\Models\DTO\ModelRequirements(
                array(WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration()),
                array()
            );
            $metadata_list = $registry->findProviderModelsMetadataForSupport($provider_id, $requirements);
            foreach ($metadata_list as $metadata) {
                if ($metadata instanceof ModelMetadata) {
                    $ids[] = $metadata->getId();
                }
            }
            $ids = wanyesea_ai_filter_chat_text_model_ids($ids);
        }
    } catch (Throwable $e) {
        $ids = array();
    }

    if ($ids === array() && wanyesea_ai_relay_is_provider_active($provider_id)) {
        $ids = wanyesea_ai_relay_official_probe_text_model_ids($provider_id);
    }

    return apply_filters('wanyesea_ai_discovered_official_text_model_ids', $ids, $provider_id);
}

/**
 * 替换官方 Provider 的 modelMetadataDirectory，并清除 availability 静态缓存以便重新绑定。
 */
function wanyesea_ai_replace_provider_metadata_directory($class_name, ModelMetadataDirectoryInterface $directory) {
    if (!class_exists('WordPress\AiClient\Providers\AbstractProvider')) {
        return;
    }

    try {
        $ref  = new ReflectionClass('WordPress\AiClient\Providers\AbstractProvider');
        $prop = $ref->getProperty('modelMetadataDirectoryCache');
        $prop->setAccessible(true);
        $cache = $prop->getValue();
        if (!is_array($cache)) {
            $cache = array();
        }
        $cache[$class_name] = $directory;
        $prop->setValue(null, $cache);

        $avail_prop = $ref->getProperty('availabilityCache');
        $avail_prop->setAccessible(true);
        $avail_cache = $avail_prop->getValue();
        if (is_array($avail_cache)) {
            unset($avail_cache[$class_name]);
            $avail_prop->setValue(null, $avail_cache);
        }
    } catch (Throwable $e) {
        return;
    }
}

/**
 * 为已启用中转的官方 Provider（当前为 OpenAI）包装模型元数据目录。
 */
function wanyesea_ai_wrap_relay_official_metadata_directories() {
    static $wrapped = array();

    if (!class_exists('Wanyesea_AI_Relay') || !Wanyesea_AI_Relay::is_enabled()) {
        return;
    }

    if (wanyesea_ai_relay_is_provider_active('openai') && class_exists('Wanyesea_AI_Relay_OpenAi_Provider')) {
        wanyesea_ai_register_relay_openai_provider();
        return;
    }

    foreach (wanyesea_ai_relay_official_provider_class_map() as $provider_id => $class_name) {
        if (!Wanyesea_AI_Relay::is_provider_active($provider_id)) {
            continue;
        }
        if ($provider_id === 'openai') {
            continue;
        }
        if (!is_string($class_name) || $class_name === '' || !class_exists($class_name)) {
            continue;
        }
        if (isset($wrapped[$provider_id])) {
            continue;
        }

        try {
            $inner = $class_name::modelMetadataDirectory();
        } catch (Throwable $e) {
            continue;
        }

        if ($inner instanceof Wanyesea_AI_Relay_Official_Model_Metadata_Directory) {
            $wrapped[$provider_id] = true;
            continue;
        }

        wanyesea_ai_replace_provider_metadata_directory(
            $class_name,
            new Wanyesea_AI_Relay_Official_Model_Metadata_Directory($inner, $provider_id)
        );

        $wrapped[$provider_id] = true;
    }
}

/**
 * 将中转探测到的 OpenAI 文本模型加入 wpai_preferred_text_models（排在官方列表之前）。
 */
function wanyesea_ai_prepend_relay_official_text_models($preferred_models) {
    if (!is_array($preferred_models)) {
        $preferred_models = array();
    }

    if (!class_exists('Wanyesea_AI_Relay') || !Wanyesea_AI_Relay::is_enabled()) {
        return $preferred_models;
    }

    $prepend = array();
    foreach (array_keys(wanyesea_ai_relay_official_provider_class_map()) as $provider_id) {
        if (!Wanyesea_AI_Relay::is_provider_active($provider_id)) {
            continue;
        }
        if (function_exists('wanyesea_ai_get_connector_api_key_resolved')
            && wanyesea_ai_get_connector_api_key_resolved($provider_id) === '') {
            continue;
        }

        $ids = wanyesea_ai_relay_official_probe_text_model_ids($provider_id);
        if ($ids === array() && function_exists('wanyesea_ai_discover_official_provider_text_model_ids')) {
            $ids = wanyesea_ai_discover_official_provider_text_model_ids($provider_id);
        }

        $max = (int) apply_filters('wanyesea_ai_relay_official_text_model_preference_limit', 0, $provider_id);
        if ($max > 0 && count($ids) > $max) {
            $ids = array_slice($ids, 0, $max);
        }

        foreach ($ids as $model_id) {
            $prepend[] = array($provider_id, $model_id);
        }
    }

    if ($prepend === array()) {
        return $preferred_models;
    }

    return array_merge($prepend, $preferred_models);
}

add_filter('wpai_preferred_text_models', 'wanyesea_ai_prepend_relay_official_text_models', 18);

/**
 * 将 AI Client / 中转 API 异常转为测试页可读的提示。
 */
function wanyesea_ai_format_ai_client_error_message(Throwable $e, $provider_id = '') {
    $message = $e->getMessage();
    $provider_id = sanitize_key((string) $provider_id);

    if ($provider_id === 'openai'
        && wanyesea_ai_relay_is_provider_active('openai')
        && (stripos($message, '403') !== false || stripos($message, 'forbidden') !== false)
        && stripos($message, 'responses') !== false) {
        return '中转网关返回 403：New API / One API 通常不支持 OpenAI 官方 /v1/responses 接口。'
            . ' 请确认插件已更新并刷新页面（中转应自动改用 /v1/chat/completions）。';
    }

    if ($provider_id === 'openai' && wanyesea_ai_relay_is_provider_active('openai')
        && (stripos($message, '403') !== false || stripos($message, 'forbidden') !== false)) {
        return '中转网关拒绝请求（HTTP 403）：请检查 API Key、模型 ID 是否在该渠道可用，或渠道余额/权限。';
    }

    return $message;
}
