<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleImageGenerationModel;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Results\DTO\Candidate;

/**
 * 根据模型 ID 推断是否为出图模型（中转 /models 常无 output_modalities 字段）。
 *
 * @param string $model_id
 */
function wanyesea_ai_openai_compatible_model_likely_image_by_id($model_id) {
    $model_id = strtolower(sanitize_text_field((string) $model_id));
    if ($model_id === '') {
        return false;
    }

    $needles = array(
        'gpt-image',
        'dall-e',
        'imagen',
        'sensenova-u1',
        '-image-preview',
        '-image-generation',
        '-flash-image',
        '-pro-image',
    );

    foreach ($needles as $needle) {
        if (strpos($model_id, $needle) !== false) {
            return true;
        }
    }

    if (preg_match('/(?:^|[\/\-])image(?:[\/\-]|$)/', $model_id) === 1) {
        return true;
    }

    return (bool) apply_filters('wanyesea_ai_openai_compatible_model_likely_image_by_id', false, $model_id);
}

/**
 * /models 条目是否支持图像输出（SenseNova sensenova-u1-fast 等）。
 *
 * @param array<string, mixed> $model_data
 */
function wanyesea_ai_openai_compatible_model_supports_image_output($model_data) {
    if (!is_array($model_data)) {
        return false;
    }

    if (!empty($model_data['output_modalities']) && is_array($model_data['output_modalities'])) {
        foreach ($model_data['output_modalities'] as $modality) {
            if (strtolower((string) $modality) === 'image') {
                return true;
            }
        }
    }

    $model_id = '';
    if (!empty($model_data['id'])) {
        $model_id = (string) $model_data['id'];
    } elseif (!empty($model_data['model'])) {
        $model_id = (string) $model_data['model'];
    }

    if ($model_id !== '' && wanyesea_ai_openai_compatible_model_likely_image_by_id($model_id)) {
        return true;
    }

    return false;
}

/**
 * OpenAI 兼容出图模型的 SupportedOption 列表。
 *
 * @return list<SupportedOption>
 */
function wanyesea_ai_openai_compatible_image_model_options($provider_id = '') {
    $provider_id = sanitize_key((string) $provider_id);
    $aspect_ratios = array('1:1', '16:9', '9:16', '3:2', '2:3', '3:4', '4:3', '4:5', '5:4', '21:9', '9:21');

    if ($provider_id === 'sensenova') {
        $aspect_ratios = array('1:1', '16:9', '9:16', '3:2', '2:3', '3:4', '4:3', '4:5', '5:4', '21:9', '9:21');
    }

    $options = array(
        new SupportedOption(OptionEnum::inputModalities(), array(array(ModalityEnum::text()))),
        new SupportedOption(OptionEnum::outputModalities(), array(array(ModalityEnum::image()))),
        new SupportedOption(OptionEnum::candidateCount()),
        new SupportedOption(OptionEnum::outputMimeType(), array('image/png')),
        new SupportedOption(OptionEnum::outputFileType(), array(FileTypeEnum::remote(), FileTypeEnum::inline())),
        new SupportedOption(OptionEnum::outputMediaOrientation(), array(
            MediaOrientationEnum::square(),
            MediaOrientationEnum::landscape(),
            MediaOrientationEnum::portrait(),
        )),
        new SupportedOption(OptionEnum::outputMediaAspectRatio(), $aspect_ratios),
        new SupportedOption(OptionEnum::customOptions()),
    );

    return apply_filters('wanyesea_ai_openai_compatible_image_model_options', $options, $provider_id);
}

/**
 * 从 /models 条目构建图像生成 ModelMetadata 列表。
 *
 * @param list<array<string, mixed>> $items
 * @return list<ModelMetadata>
 */
function wanyesea_ai_build_openai_compatible_image_model_metadata(array $items, $provider_id) {
    $provider_id = sanitize_key((string) $provider_id);
    $models      = array();

    foreach ($items as $model_data) {
        if (!is_array($model_data)) {
            continue;
        }

        $model_id = '';
        if (!empty($model_data['id'])) {
            $model_id = (string) $model_data['id'];
        } elseif (!empty($model_data['model'])) {
            $model_id = (string) $model_data['model'];
        }

        if ($model_id === ''
            || wanyesea_ai_should_skip_openai_compatible_model($model_id)
            || !wanyesea_ai_openai_compatible_model_supports_image_output($model_data)) {
            continue;
        }

        $models[] = new ModelMetadata(
            $model_id,
            isset($model_data['name']) ? (string) $model_data['name'] : $model_id,
            array(CapabilityEnum::imageGeneration()),
            wanyesea_ai_openai_compatible_image_model_options($provider_id)
        );
    }

    if ($models === array() && $provider_id === 'sensenova') {
        $defs = class_exists('Wanyesea_AI_Custom_Connectors') ? Wanyesea_AI_Custom_Connectors::definitions() : array();
        $def  = isset($defs['sensenova']) && is_array($defs['sensenova']) ? $defs['sensenova'] : array();
        $hint = !empty($def['preferred_image_model_hint'])
            ? trim((string) $def['preferred_image_model_hint'])
            : 'sensenova-u1-fast';

        $models[] = new ModelMetadata(
            $hint,
            $hint,
            array(CapabilityEnum::imageGeneration()),
            wanyesea_ai_openai_compatible_image_model_options($provider_id)
        );
    }

    return apply_filters('wanyesea_ai_openai_compatible_image_model_metadata', $models, $provider_id, $items);
}

/**
 * 解析出图 HTTP 超时（须传入 Request，否则 WordPress HTTP 默认 5 秒会触发 cURL 28）。
 *
 * @param Wanyesea_AI_OpenAi_Compatible_Image_Generation_Model $model
 */
function wanyesea_ai_resolve_image_request_options($model) {
    $provider_id = '';
    if (method_exists($model, 'providerMetadata')) {
        $provider_id = sanitize_key((string) $model->providerMetadata()->getId());
    }

    $minimum = (float) apply_filters(
        'wanyesea_ai_image_generation_timeout',
        $provider_id === 'sensenova' ? 240.0 : 90.0,
        $provider_id
    );

    $options = $model->getRequestOptions();
    if ($options === null) {
        $options = new RequestOptions();
    }

    $current = $options->getTimeout();
    if ($current === null || $current < $minimum) {
        $options->setTimeout($minimum);
    }

    if ($options->getConnectTimeout() === null) {
        $options->setConnectTimeout(30.0);
    }

    return apply_filters('wanyesea_ai_image_generation_request_options', $options, $provider_id, $model);
}

/**
 * 将远程图片 URL 拉取为 base64（供 Generate_Image 等 inline 输出使用）。
 *
 * @return array{base64: string, mime: string}|null
 */
function wanyesea_ai_fetch_image_url_as_base64($url, $expected_mime = 'image/png') {
    $url = esc_url_raw((string) $url);
    if ($url === '' || !wp_http_validate_url($url)) {
        return null;
    }

    $timeout = (int) apply_filters('wanyesea_ai_image_download_timeout', 90);
    $response = wp_safe_remote_get(
        $url,
        array(
            'timeout'     => max(15, $timeout),
            'redirection' => 3,
        )
    );

    if (is_wp_error($response)) {
        return null;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        return null;
    }

    $body = wp_remote_retrieve_body($response);
    if ($body === '' || $body === false) {
        return null;
    }

    $mime = (string) $expected_mime;
    $content_type = wp_remote_retrieve_header($response, 'content-type');
    if (is_string($content_type) && $content_type !== '' && preg_match('#^image/[a-z0-9.+-]+#i', $content_type)) {
        $mime = strtolower(trim(strtok($content_type, ';')));
    }

    return array(
        'base64' => base64_encode($body),
        'mime'   => $mime,
    );
}

/**
 * 将 OpenAI 兼容出图 choice（url / b64_json）规范为 inline base64。
 *
 * @param array<string, mixed> $choiceData
 * @return array{0: array<string, mixed>, 1: string}
 */
function wanyesea_ai_normalize_image_choice_for_inline(array $choiceData, $expected_mime = 'image/png') {
    if (!empty($choiceData['b64_json']) && is_string($choiceData['b64_json'])) {
        return array($choiceData, (string) $expected_mime);
    }

    if (empty($choiceData['url']) || !is_string($choiceData['url'])) {
        return array($choiceData, (string) $expected_mime);
    }

    $fetched = wanyesea_ai_fetch_image_url_as_base64($choiceData['url'], $expected_mime);
    if ($fetched === null) {
        return array($choiceData, (string) $expected_mime);
    }

    return array(
        array('b64_json' => $fetched['base64']),
        $fetched['mime'],
    );
}

/**
 * OpenAI 兼容 images/generations 出图模型。
 */
class Wanyesea_AI_OpenAi_Compatible_Image_Generation_Model extends AbstractOpenAiCompatibleImageGenerationModel {

    /** @var class-string<Wanyesea_AI_Custom_Api_Provider_Base> */
    private $provider_class;

    /**
     * @param class-string<Wanyesea_AI_Custom_Api_Provider_Base> $provider_class
     */
    public function __construct($model_metadata, $provider_metadata, $provider_class) {
        parent::__construct($model_metadata, $provider_metadata);
        $this->provider_class = $provider_class;
    }

    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = array(), $data = null): Request {
        return new Request(
            $method,
            call_user_func(array($this->provider_class, 'url'), $path),
            $headers,
            $data,
            wanyesea_ai_resolve_image_request_options($this)
        );
    }
}

/**
 * SenseNova U1 Fast 信息图出图（官方尺寸映射，默认 URL 响应）。
 */
final class Wanyesea_AI_Sensenova_Image_Generation_Model extends Wanyesea_AI_OpenAi_Compatible_Image_Generation_Model {

    /**
     * @param class-string<Wanyesea_AI_Custom_Api_Provider_Base> $provider_class
     */
    public function __construct($model_metadata, $provider_metadata, $provider_class) {
        parent::__construct($model_metadata, $provider_metadata, $provider_class);
    }

    protected function prepareGenerateImageParams(array $prompt): array {
        $params = parent::prepareGenerateImageParams($prompt);

        unset($params['response_format'], $params['output_format']);

        return $params;
    }

    protected function prepareSizeParam(?MediaOrientationEnum $orientation, ?string $aspectRatio): string {
        $map = array(
            '1:1'  => '2048x2048',
            '16:9' => '2752x1536',
            '9:16' => '1536x2752',
            '3:2'  => '2496x1664',
            '2:3'  => '1664x2496',
            '3:4'  => '1760x2368',
            '4:3'  => '2368x1760',
            '4:5'  => '1824x2272',
            '5:4'  => '2272x1824',
            '21:9' => '3072x1376',
            '9:21' => '1344x3136',
        );

        if ($aspectRatio !== null && isset($map[$aspectRatio])) {
            return $map[$aspectRatio];
        }

        if ($orientation !== null) {
            if ($orientation->isLandscape()) {
                return '2752x1536';
            }
            if ($orientation->isPortrait()) {
                return '1536x2752';
            }
        }

        return '2752x1536';
    }

    protected function getResultId(array $responseData): string {
        return isset($responseData['created']) && is_int($responseData['created'])
            ? 'img-' . $responseData['created']
            : parent::getResultId($responseData);
    }

    /**
     * SenseNova 仅返回 CDN url；WordPress Generate_Image 需要 base64 inline 数据。
     */
    protected function parseResponseChoiceToCandidate(array $choiceData, int $index, string $expectedMimeType = 'image/png'): Candidate {
        list($choiceData, $expectedMimeType) = wanyesea_ai_normalize_image_choice_for_inline($choiceData, $expectedMimeType);

        if (!empty($choiceData['url']) && empty($choiceData['b64_json'])) {
            throw ResponseException::fromInvalidData(
                $this->providerMetadata()->getName(),
                "data[{$index}]",
                'The image URL could not be downloaded for inline use. Check that the server can reach the SenseNova CDN.'
            );
        }

        return parent::parseResponseChoiceToCandidate($choiceData, $index, $expectedMimeType);
    }
}

/**
 * 为自定义 Provider 创建出图模型实例。
 */
function wanyesea_ai_create_custom_image_generation_model($model_metadata, $provider_metadata, $provider_class) {
    $provider_id = is_string($provider_class) && method_exists($provider_class, 'providerId')
        ? $provider_class::providerId()
        : '';

    if ($provider_id === 'sensenova') {
        return new Wanyesea_AI_Sensenova_Image_Generation_Model($model_metadata, $provider_metadata, $provider_class);
    }

    return new Wanyesea_AI_OpenAi_Compatible_Image_Generation_Model($model_metadata, $provider_metadata, $provider_class);
}

/**
 * /models 不可用时的静态模型表（当前仅 SenseNova 需要保证出图元数据）。
 *
 * @return array<string, ModelMetadata>
 */
function wanyesea_ai_custom_provider_models_fallback_map($provider_id) {
    $provider_id = sanitize_key((string) $provider_id);

    if (!function_exists('wanyesea_ai_get_custom_connector_api_key_resolved')
        || wanyesea_ai_get_custom_connector_api_key_resolved($provider_id) === '') {
        return array();
    }

    if ($provider_id !== 'sensenova') {
        return array();
    }

    $defs = class_exists('Wanyesea_AI_Custom_Connectors') ? Wanyesea_AI_Custom_Connectors::definitions() : array();
    $def  = isset($defs['sensenova']) && is_array($defs['sensenova']) ? $defs['sensenova'] : array();

    $text_hint  = !empty($def['preferred_model_hint']) ? trim((string) $def['preferred_model_hint']) : 'sensenova-6.7-flash-lite';
    $image_hint = !empty($def['preferred_image_model_hint']) ? trim((string) $def['preferred_image_model_hint']) : 'sensenova-u1-fast';

    $text_options = function_exists('wanyesea_ai_openai_compatible_chat_text_model_options')
        ? wanyesea_ai_openai_compatible_chat_text_model_options(
            function_exists('wanyesea_ai_custom_provider_supports_vision_input')
                && wanyesea_ai_custom_provider_supports_vision_input('sensenova')
        )
        : array();

    $map = array(
        $text_hint => new ModelMetadata(
            $text_hint,
            $text_hint,
            array(CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory()),
            $text_options
        ),
    );

    foreach (wanyesea_ai_build_openai_compatible_image_model_metadata(array(), 'sensenova') as $metadata) {
        $map[$metadata->getId()] = $metadata;
    }

    if (!isset($map[$image_hint])) {
        $map[$image_hint] = new ModelMetadata(
            $image_hint,
            $image_hint,
            array(CapabilityEnum::imageGeneration()),
            wanyesea_ai_openai_compatible_image_model_options('sensenova')
        );
    }

    return apply_filters('wanyesea_ai_custom_provider_models_fallback_map', $map, $provider_id);
}

/**
 * 编辑器出图链路：用静态 fallback 伪造 GET /models 响应体，避免真实探测拖垮网关。
 *
 * @return string JSON 或空字符串（无法伪造时）
 */
function wanyesea_ai_models_probe_json_body_for_provider($provider_id) {
    $provider_id = sanitize_key((string) $provider_id);

    if (!function_exists('wanyesea_ai_custom_provider_models_fallback_map')) {
        return '';
    }

    $map = wanyesea_ai_custom_provider_models_fallback_map($provider_id);
    if ($map === array()) {
        return '';
    }

    $data = array();
    foreach ($map as $model_id => $metadata) {
        if (!$metadata instanceof ModelMetadata) {
            continue;
        }

        $output_modalities = array('text');
        $input_modalities  = array('text');
        foreach ($metadata->getSupportedCapabilities() as $capability) {
            if ($capability->isImageGeneration()) {
                $output_modalities = array('image');
                break;
            }
        }

        if (function_exists('wanyesea_ai_model_metadata_supports_image_input')
            && wanyesea_ai_model_metadata_supports_image_input($metadata)) {
            $input_modalities = array('text', 'image');
        }

        $data[] = array(
            'id'                => (string) $model_id,
            'name'              => $metadata->getName(),
            'input_modalities'  => $input_modalities,
            'output_modalities' => $output_modalities,
        );
    }

    if ($data === array()) {
        return '';
    }

    $encoded = wp_json_encode(array('data' => $data));

    return is_string($encoded) ? $encoded : '';
}

/**
 * 出图模型 hint（不触发 /models）；用于写文章页 REST 出图，避免网关 504。
 *
 * @return string
 */
function wanyesea_ai_get_image_model_hint_for_provider($provider_id) {
    $provider_id = sanitize_key((string) $provider_id);

    $defaults = array(
        'google'    => 'gemini-2.5-flash-image',
        'openai'    => 'gpt-image-1.5',
        'sensenova' => 'sensenova-u1-fast',
    );

    if (function_exists('wanyesea_ai_is_custom_connect_provider')
        && wanyesea_ai_is_custom_connect_provider($provider_id)
        && class_exists('Wanyesea_AI_Custom_Connectors')) {
        $defs = Wanyesea_AI_Custom_Connectors::definitions();
        $def  = isset($defs[$provider_id]) && is_array($defs[$provider_id]) ? $defs[$provider_id] : array();
        if (!empty($def['preferred_image_model_hint'])) {
            return trim((string) $def['preferred_image_model_hint']);
        }
    }

    return isset($defaults[$provider_id]) ? $defaults[$provider_id] : '';
}

/**
 * 发现某厂商在 AI Client 中可用的出图模型 ID。
 *
 * @param bool $allow_network 为 false 时不访问 /models（供 wpai_preferred_image_models 等热路径使用）。
 * @return list<string>
 */
function wanyesea_ai_discover_provider_image_model_ids($provider_id, $allow_network = true) {
    $provider_id = sanitize_key((string) $provider_id);

    if (!class_exists(AiClient::class) || !function_exists('wanyesea_ai_get_connector_api_key_resolved')) {
        return array();
    }

    if (wanyesea_ai_get_connector_api_key_resolved($provider_id) === '') {
        return array();
    }

    if (!$allow_network) {
        $hint = wanyesea_ai_get_image_model_hint_for_provider($provider_id);
        return $hint !== '' ? array($hint) : array();
    }

    if (function_exists('wanyesea_ai_ensure_ai_client_auth')) {
        wanyesea_ai_ensure_ai_client_auth();
    }

    try {
        $registry = AiClient::defaultRegistry();
    } catch (Throwable $e) {
        return array();
    }

    if (!$registry->hasProvider($provider_id)) {
        return array();
    }

    try {
        if (!$registry->isProviderConfigured($provider_id)) {
            return array();
        }
    } catch (Throwable $e) {
        return array();
    }

    try {
        $requirements  = new ModelRequirements(array(CapabilityEnum::imageGeneration()), array());
        $metadata_list = $registry->findProviderModelsMetadataForSupport($provider_id, $requirements);
    } catch (Throwable $e) {
        return array();
    }

    $ids = array();
    foreach ($metadata_list as $metadata) {
        if ($metadata instanceof ModelMetadata) {
            $ids[] = $metadata->getId();
        }
    }

    return apply_filters('wanyesea_ai_discovered_image_model_ids', $ids, $provider_id, $allow_network);
}

/**
 * 带请求内缓存的出图模型优先列表（不拉 /models，仅 hint + 可选端点默认）。
 *
 * @return list<array{0: string, 1: string}>
 */
function wanyesea_ai_get_provider_image_model_preferences_cached($provider_id) {
    static $cache = null;

    $provider_id = sanitize_key((string) $provider_id);

    if ($cache === null) {
        $cache = array();
        if (function_exists('wanyesea_ai_ensure_ai_client_auth')) {
            wanyesea_ai_ensure_ai_client_auth();
        }
    }

    if (array_key_exists($provider_id, $cache)) {
        return $cache[$provider_id];
    }

    $cache[$provider_id] = wanyesea_ai_get_provider_image_model_preferences($provider_id, false);

    return $cache[$provider_id];
}

/**
 * 构建某厂商的出图模型优先列表 [[provider, model], ...]。
 *
 * @param bool $allow_network 为 false 时仅用 hint，避免编辑页 REST 多次 /models 导致 504。
 * @return list<array{0: string, 1: string}>
 */
function wanyesea_ai_get_provider_image_model_preferences($provider_id, $allow_network = true) {
    $provider_id = sanitize_key((string) $provider_id);
    $model_ids   = wanyesea_ai_discover_provider_image_model_ids($provider_id, $allow_network);

    if ($model_ids === array()) {
        return array();
    }

    $hint = wanyesea_ai_get_image_model_hint_for_provider($provider_id);
    if ($hint !== '' && in_array($hint, $model_ids, true)) {
        $model_ids = array($hint);
    } else {
        $model_ids = array_slice($model_ids, 0, 1);
    }

    if ($allow_network && class_exists('Wanyesea_AI_OpenAi_Compatible_Model_Metadata_Directory')) {
        $endpoint_default = Wanyesea_AI_OpenAi_Compatible_Model_Metadata_Directory::getLastListedDefaultModelId($provider_id);
        if ($endpoint_default !== ''
            && $endpoint_default !== $model_ids[0]
            && !in_array($endpoint_default, $model_ids, true)) {
            $model_ids[] = $endpoint_default;
        }
    }

    $max = (int) apply_filters('wanyesea_ai_image_model_preference_per_provider_limit', 2);
    if ($max > 0 && count($model_ids) > $max) {
        $model_ids = array_slice($model_ids, 0, $max);
    }

    $pairs = array();
    foreach ($model_ids as $model_id) {
        $pairs[] = array($provider_id, $model_id);
    }

    return apply_filters('wanyesea_ai_provider_image_model_preferences', $pairs, $provider_id, $allow_network);
}

/**
 * 去重合并 [[provider, model], ...] 列表。
 *
 * @param list<array{0: string, 1: string}> $lists
 * @return list<array{0: string, 1: string}>
 */
function wanyesea_ai_merge_image_model_preference_lists(...$lists) {
    $seen  = array();
    $merged = array();

    foreach ($lists as $list) {
        if (!is_array($list)) {
            continue;
        }
        foreach ($list as $pair) {
            if (!is_array($pair) || !isset($pair[0], $pair[1])) {
                continue;
            }
            $provider = sanitize_key((string) $pair[0]);
            $model    = sanitize_text_field((string) $pair[1]);
            if ($provider === '' || $model === '') {
                continue;
            }
            $key = $provider . "\0" . $model;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $merged[]   = array($provider, $model);
        }
    }

    return $merged;
}

/**
 * 将已配置密钥的出图厂商 hint 模型排在官方列表之前；保留同厂商官方回退项，避免 hint 未注册时 unsupported_model。
 */
function wanyesea_ai_prepend_configured_image_models($preferred_models) {
    if (!is_array($preferred_models)) {
        $preferred_models = array();
    }

    if (!function_exists('wanyesea_ai_image_capable_provider_ids')) {
        return $preferred_models;
    }

    $configured = array();
    foreach (wanyesea_ai_image_capable_provider_ids() as $provider_id) {
        if (!function_exists('wanyesea_ai_get_connector_api_key_resolved')
            || wanyesea_ai_get_connector_api_key_resolved($provider_id) === '') {
            continue;
        }
        $pairs = wanyesea_ai_get_provider_image_model_preferences_cached($provider_id);
        if ($pairs !== array()) {
            $configured = array_merge($configured, $pairs);
        }
    }

    $filtered_official = array();
    foreach ($preferred_models as $pair) {
        if (!is_array($pair) || !isset($pair[0], $pair[1])) {
            continue;
        }
        $provider = sanitize_key((string) $pair[0]);
        if (function_exists('wanyesea_ai_get_connector_api_key_resolved')
            && wanyesea_ai_get_connector_api_key_resolved($provider) === ''
            && in_array($provider, wanyesea_ai_image_capable_provider_ids(), true)) {
            continue;
        }
        $filtered_official[] = array($provider, sanitize_text_field((string) $pair[1]));
    }

    if ($configured === array()) {
        return $filtered_official;
    }

    $per_provider_cap = array();
    $configured_trim  = array();
    foreach ($configured as $pair) {
        if (!isset($pair[0])) {
            continue;
        }
        $pid = sanitize_key((string) $pair[0]);
        if (!isset($per_provider_cap[$pid])) {
            $per_provider_cap[$pid] = 0;
        }
        $cap = (int) apply_filters('wanyesea_ai_image_model_preference_per_provider_limit', 2);
        if ($cap > 0 && $per_provider_cap[$pid] >= $cap) {
            continue;
        }
        $configured_trim[] = $pair;
        $per_provider_cap[$pid]++;
    }
    $configured = $configured_trim;

    $configured_ids = function_exists('wanyesea_ai_get_configured_image_provider_ids_with_keys')
        ? wanyesea_ai_get_configured_image_provider_ids_with_keys()
        : array();

    if (count($configured_ids) === 1) {
        $only = $configured_ids[0];
        $solo = array();
        foreach ($configured as $pair) {
            if (isset($pair[0]) && sanitize_key((string) $pair[0]) === $only) {
                $solo[] = $pair;
            }
        }
        if ($solo !== array()) {
            $max = (int) apply_filters('wanyesea_ai_image_model_preference_total_limit', 3);
            if ($max > 0 && count($solo) > $max) {
                $solo = array_slice($solo, 0, $max);
            }

            return $solo;
        }
    }

    $merged = wanyesea_ai_merge_image_model_preference_lists($configured, $filtered_official);

    $default_max = count($configured_ids) > 1 ? 9 : 6;
    $max         = (int) apply_filters('wanyesea_ai_image_model_preference_total_limit', $default_max);
    if ($max > 0 && count($merged) > $max) {
        $merged = array_slice($merged, 0, $max);
    }

    return $merged;
}

add_filter('wpai_preferred_image_models', 'wanyesea_ai_prepend_configured_image_models', 15);
