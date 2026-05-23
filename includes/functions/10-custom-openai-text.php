<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;

/**
 * 判断 OpenAI 兼容 /models 列表中的模型是否应跳过（非对话类）。
 */
function wanyesea_ai_should_skip_openai_compatible_model($model_id) {
    $model_id = strtolower((string) $model_id);
    if ($model_id === '') {
        return true;
    }

    $skip_patterns = array(
        'embed',
        'embedding',
        'whisper',
        'tts',
        'dall-e',
        'dalle',
        'moderation',
        'davinci',
        'babbage',
        'realtime',
        'audio',
        'transcribe',
        'sora',
        'rerank',
    );

    foreach ($skip_patterns as $pattern) {
        if (strpos($model_id, $pattern) !== false) {
            return true;
        }
    }

    return (bool) apply_filters('wanyesea_ai_skip_openai_compatible_model', false, $model_id);
}

/**
 * NVIDIA NIM 实体/PII 抽取模型（如 nvidia/gliner-pii），走 chat/completions 但非对话生成。
 */
function wanyesea_ai_is_nvidia_nim_entity_extraction_model($model_id) {
    $model_id = strtolower((string) $model_id);

    return strpos($model_id, 'gliner') !== false
        || (bool) apply_filters('wanyesea_ai_is_nvidia_nim_entity_extraction_model', false, $model_id);
}

/**
 * 从 OpenAI 兼容 messages.content 提取纯文本（string 或 [{type,text}] 数组）。
 *
 * @param string|array<int|string, mixed>|null $content
 */
function wanyesea_ai_extract_openai_message_text_content($content) {
    if (is_string($content)) {
        return $content;
    }
    if (!is_array($content)) {
        return '';
    }

    $text = '';
    foreach ($content as $part) {
        if (is_string($part)) {
            $text .= $part;
            continue;
        }
        if (is_array($part) && isset($part['text'])) {
            $text .= (string) $part['text'];
        }
    }

    return $text;
}

/**
 * 解析 GLiNER-PII chat/completions 返回的 JSON 内容。
 *
 * @return array{total_entities: int, entities: list<array<string, mixed>>, tagged_text: string, formatted: string}|null
 */
function wanyesea_ai_parse_nvidia_gliner_pii_response($raw) {
    $raw  = trim((string) $raw);
    $data = json_decode($raw, true);

    if (!is_array($data) || (!array_key_exists('tagged_text', $data) && !array_key_exists('entities', $data))) {
        return null;
    }

    $entities = isset($data['entities']) && is_array($data['entities']) ? $data['entities'] : array();

    return array(
        'total_entities' => (int) ($data['total_entities'] ?? count($entities)),
        'entities'       => $entities,
        'tagged_text'    => (string) ($data['tagged_text'] ?? ''),
        'formatted'      => wanyesea_ai_format_nvidia_gliner_pii_text($raw),
    );
}

/**
 * 将 GLiNER-PII JSON 转为可读文本（测试页与 generate_text 展示）。
 */
function wanyesea_ai_format_nvidia_gliner_pii_text($raw) {
    $parsed = wanyesea_ai_parse_nvidia_gliner_pii_response($raw);
    if ($parsed === null) {
        return (string) $raw;
    }

    $lines   = array();
    $lines[] = '标注文本：' . $parsed['tagged_text'];
    $lines[] = '实体数量：' . $parsed['total_entities'];

    if ($parsed['entities'] !== array()) {
        $lines[] = '检测到的实体：';
        foreach ($parsed['entities'] as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $score = isset($entity['score']) ? round((float) $entity['score'], 3) : null;
            $lines[] = sprintf(
                '- [%s] %s（%s–%s%s）',
                (string) ($entity['label'] ?? '?'),
                (string) ($entity['text'] ?? ''),
                (string) ($entity['start'] ?? '?'),
                (string) ($entity['end'] ?? '?'),
                $score !== null ? '，置信度 ' . $score : ''
            );
        }
    } else {
        $lines[] = '未检测到实体。';
    }

    return implode("\n", $lines);
}

/**
 * 写文章等场景使用的对话模型 ID（排除 GLiNER 等 NIM 抽取模型）。
 *
 * @param list<string> $model_ids
 * @return list<string>
 */
function wanyesea_ai_filter_chat_text_model_ids(array $model_ids) {
    return array_values(array_filter($model_ids, function ($model_id) {
        return !wanyesea_ai_is_nvidia_nim_entity_extraction_model($model_id);
    }));
}

/**
 * 自定义 Provider 的静态文本模型表（/models 被跳过或失败时写入 Registry）。
 *
 * @return array<string, ModelMetadata>
 */
function wanyesea_ai_custom_provider_text_models_fallback_map($provider_id) {
    $provider_id = sanitize_key((string) $provider_id);

    if (!function_exists('wanyesea_ai_get_custom_connector_api_key_resolved')
        || wanyesea_ai_get_custom_connector_api_key_resolved($provider_id) === '') {
        return array();
    }

    if (!class_exists('Wanyesea_AI_Custom_Connectors')
        || !Wanyesea_AI_Custom_Connectors::is_custom_provider($provider_id)) {
        return array();
    }

    $defs = Wanyesea_AI_Custom_Connectors::definitions();
    $def  = isset($defs[$provider_id]) && is_array($defs[$provider_id]) ? $defs[$provider_id] : array();

    $hint = '';
    if (!empty($def['preferred_model_hint'])) {
        $hint = trim((string) $def['preferred_model_hint']);
    } elseif (!empty($def['default_model'])) {
        $hint = trim((string) $def['default_model']);
    }

    if ($hint === '' || wanyesea_ai_is_nvidia_nim_entity_extraction_model($hint)) {
        return array();
    }

    $options = wanyesea_ai_openai_compatible_chat_text_model_options(
        function_exists('wanyesea_ai_custom_provider_supports_vision_input')
            && wanyesea_ai_custom_provider_supports_vision_input($provider_id)
    );

    $map = array(
        $hint => new ModelMetadata(
            $hint,
            $hint,
            array(CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory()),
            $options
        ),
    );

    return apply_filters('wanyesea_ai_custom_provider_text_models_fallback_map', $map, $provider_id);
}

/**
 * OpenAI 兼容对话模型的 SupportedOption（可选图像输入，供 Alt 文本等视觉能力使用）。
 *
 * @return list<SupportedOption>
 */
function wanyesea_ai_openai_compatible_chat_text_model_options($include_image_input = false) {
    $input_modalities = array(array(ModalityEnum::text()));
    if ($include_image_input) {
        $input_modalities[] = array(ModalityEnum::text(), ModalityEnum::image());
        $input_modalities[] = array(ModalityEnum::image());
    }

    return array(
        new SupportedOption(OptionEnum::systemInstruction()),
        new SupportedOption(OptionEnum::candidateCount()),
        new SupportedOption(OptionEnum::maxTokens()),
        new SupportedOption(OptionEnum::temperature()),
        new SupportedOption(OptionEnum::topP()),
        new SupportedOption(OptionEnum::stopSequences()),
        new SupportedOption(OptionEnum::inputModalities(), $input_modalities),
        new SupportedOption(OptionEnum::outputModalities(), array(array(ModalityEnum::text()))),
    );
}

/**
 * 自定义 Connector 是否提供图像输入理解（Alt 文本 / 多模态对话）。
 */
function wanyesea_ai_custom_provider_supports_vision_input($provider_id) {
    $provider_id = sanitize_key((string) $provider_id);
    $vision_ids  = apply_filters('wanyesea_ai_vision_capable_custom_provider_ids', array('sensenova'));

    return in_array($provider_id, $vision_ids, true);
}

/**
 * 模型元数据是否声明支持图像输入（Alt 文本等）。
 */
function wanyesea_ai_model_metadata_supports_image_input($metadata) {
    if (!$metadata instanceof ModelMetadata) {
        return false;
    }

    foreach ($metadata->getSupportedOptions() as $option) {
        if (!$option->getName()->isInputModalities()) {
            continue;
        }
        foreach ($option->getSupportedValues() as $combination) {
            if (!is_array($combination)) {
                continue;
            }
            foreach ($combination as $modality) {
                if ($modality instanceof ModalityEnum && $modality->isImage()) {
                    return true;
                }
            }
        }
    }

    return false;
}

/**
 * Alt 文本等场景使用的视觉模型 ID（不触发 /models）。
 */
function wanyesea_ai_get_vision_model_hint_for_provider($provider_id) {
    $provider_id = sanitize_key((string) $provider_id);

    if (!wanyesea_ai_custom_provider_supports_vision_input($provider_id)) {
        return '';
    }

    if (class_exists('Wanyesea_AI_Custom_Connectors')) {
        $defs = Wanyesea_AI_Custom_Connectors::definitions();
        $def  = isset($defs[$provider_id]) && is_array($defs[$provider_id]) ? $defs[$provider_id] : array();
        if (!empty($def['preferred_model_hint'])) {
            return trim((string) $def['preferred_model_hint']);
        }
    }

    return $provider_id === 'sensenova' ? 'sensenova-6.7-flash-lite' : '';
}

/**
 * /models 条目是否支持文本输出（SenseNova 等返回 output_modalities）。
 *
 * @param array<string, mixed> $model_data
 */
function wanyesea_ai_openai_compatible_model_supports_text_output($model_data) {
    if (!is_array($model_data) || empty($model_data['output_modalities']) || !is_array($model_data['output_modalities'])) {
        return true;
    }

    foreach ($model_data['output_modalities'] as $modality) {
        if (strtolower((string) $modality) === 'text') {
            return true;
        }
    }

    return false;
}

/**
 * 自定义 Connector 解析后的 API Key（本插件选项 → Connectors 选项）。
 */
function wanyesea_ai_get_custom_connector_api_key_resolved($provider_id) {
    if (!class_exists('Wanyesea_AI_Custom_Connectors')) {
        return '';
    }

    $provider_id = sanitize_key((string) $provider_id);
    $key         = Wanyesea_AI_Custom_Connectors::get_api_key($provider_id);
    if ($key !== '') {
        return $key;
    }

    $wp_option = Wanyesea_AI_Custom_Connectors::wp_option_name($provider_id);
    if ($wp_option !== '') {
        $stored = get_option($wp_option, '');
        if (is_string($stored) && trim($stored) !== '') {
            return trim($stored);
        }
    }

    return '';
}

/**
 * 从 /models 响应中解析模型条目列表（兼容 data / models 等字段）。
 *
 * @param array<string, mixed> $response_data
 * @return list<array<string, mixed>>
 */
function wanyesea_ai_parse_openai_compatible_models_payload($response_data) {
    if (!is_array($response_data)) {
        return array();
    }

    foreach (array('data', 'models', 'model_list') as $key) {
        if (!empty($response_data[$key]) && is_array($response_data[$key])) {
            return array_values($response_data[$key]);
        }
    }

    return array();
}

/**
 * 从 /models 响应中解析端点声明的默认模型 ID（中转网关常见字段）。
 *
 * @param array<string, mixed> $response_data
 * @return string
 */
function wanyesea_ai_parse_openai_compatible_default_model_id($response_data) {
    if (!is_array($response_data)) {
        return '';
    }

    foreach (array('default_model', 'default_model_id', 'default', 'defaultModel') as $key) {
        if (!empty($response_data[$key]) && is_string($response_data[$key])) {
            return trim($response_data[$key]);
        }
    }

    return (string) apply_filters('wanyesea_ai_openai_compatible_default_model_id', '', $response_data);
}

/**
 * 对已发现的模型 ID 排序：端点默认 → 配置提示 → 其余按 /models 返回顺序。
 *
 * @param list<string> $model_ids
 * @return list<string>
 */
function wanyesea_ai_rank_custom_text_model_ids($provider_id, array $model_ids, $endpoint_default = '', $hint = '') {
    $provider_id = sanitize_key((string) $provider_id);
    $model_ids   = array_values(array_unique(array_filter(array_map('strval', $model_ids))));

    if ($model_ids === array()) {
        return array();
    }

    $primary = '';
    if ($endpoint_default !== '' && in_array($endpoint_default, $model_ids, true)) {
        $primary = $endpoint_default;
    } elseif ($hint !== '' && in_array($hint, $model_ids, true)) {
        $primary = $hint;
    } else {
        $primary = $model_ids[0];
    }

    $ordered = array($primary);
    foreach ($model_ids as $model_id) {
        if ($model_id !== $primary) {
            $ordered[] = $model_id;
        }
    }

    $max = (int) apply_filters('wanyesea_ai_custom_text_model_preference_limit', 12, $provider_id);
    if ($max > 0 && count($ordered) > $max) {
        $ordered = array_slice($ordered, 0, $max);
    }

    return apply_filters('wanyesea_ai_rank_custom_text_model_ids', $ordered, $provider_id, $endpoint_default, $hint);
}

/**
 * 从已配置的自定义 Provider 拉取文本模型 ID（顺序与 /models 一致）。
 *
 * @return list<string>
 */
function wanyesea_ai_discover_custom_provider_text_model_ids($provider_id) {
    $provider_id = sanitize_key((string) $provider_id);

    if (!class_exists(AiClient::class) || wanyesea_ai_get_custom_connector_api_key_resolved($provider_id) === '') {
        return array();
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
        $requirements = new ModelRequirements(array(CapabilityEnum::textGeneration()), array());
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

    $ids = wanyesea_ai_filter_chat_text_model_ids($ids);

    return apply_filters('wanyesea_ai_discovered_custom_text_model_ids', $ids, $provider_id);
}

/**
 * 构建自定义 Provider 的文本生成模型优先列表 [[provider, model], ...]。
 *
 * @return list<array{0: string, 1: string}>
 */
function wanyesea_ai_get_custom_provider_text_model_preferences($provider_id) {
    $provider_id = sanitize_key((string) $provider_id);
    $model_ids   = wanyesea_ai_discover_custom_provider_text_model_ids($provider_id);

    if ($model_ids === array()) {
        return array();
    }

    $defs = class_exists('Wanyesea_AI_Custom_Connectors') ? Wanyesea_AI_Custom_Connectors::definitions() : array();
    $def  = isset($defs[$provider_id]) && is_array($defs[$provider_id]) ? $defs[$provider_id] : array();

    $hint = '';
    if (!empty($def['preferred_model_hint'])) {
        $hint = trim((string) $def['preferred_model_hint']);
    } elseif (!empty($def['default_model'])) {
        $hint = trim((string) $def['default_model']);
    }

    $endpoint_default = '';
    if (class_exists('Wanyesea_AI_OpenAi_Compatible_Model_Metadata_Directory')) {
        $endpoint_default = Wanyesea_AI_OpenAi_Compatible_Model_Metadata_Directory::getLastListedDefaultModelId($provider_id);
    }

    $ranked = wanyesea_ai_rank_custom_text_model_ids($provider_id, $model_ids, $endpoint_default, $hint);
    $pairs  = array();

    foreach ($ranked as $model_id) {
        $pairs[] = array($provider_id, $model_id);
    }

    return apply_filters('wanyesea_ai_custom_provider_text_model_preferences', $pairs, $provider_id);
}

/**
 * 解析文本生成 HTTP 超时（须传入 Request，否则 WordPress HTTP 默认 5 秒会触发 cURL 28）。
 *
 * @param Wanyesea_AI_OpenAi_Compatible_Text_Generation_Model $model
 */
function wanyesea_ai_resolve_text_request_options($model) {
    $provider_id = '';
    if (method_exists($model, 'providerMetadata')) {
        $provider_id = sanitize_key((string) $model->providerMetadata()->getId());
    }

    $minimum = (float) apply_filters(
        'wanyesea_ai_text_generation_timeout',
        $provider_id === 'sensenova' ? 120.0 : 60.0,
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

    return apply_filters('wanyesea_ai_text_generation_request_options', $options, $provider_id, $model);
}

/**
 * GET /models 等无 Model 实例时的默认 RequestOptions。
 */
function wanyesea_ai_default_text_request_options($provider_id = '') {
    $provider_id = sanitize_key((string) $provider_id);
    $minimum     = (float) apply_filters(
        'wanyesea_ai_text_generation_timeout',
        $provider_id === 'sensenova' ? 120.0 : 60.0,
        $provider_id
    );

    $options = new RequestOptions();
    $options->setTimeout($minimum);
    $options->setConnectTimeout(30.0);

    return apply_filters('wanyesea_ai_text_generation_request_options', $options, $provider_id, null);
}

/**
 * OpenAI 兼容厂商的模型元数据目录（GET /v1/models + chat/completions）。
 */
final class Wanyesea_AI_OpenAi_Compatible_Model_Metadata_Directory extends AbstractOpenAiCompatibleModelMetadataDirectory {

    /** @var array<string, string> */
    private static $last_default_model_by_provider = array();

    /** @var class-string<Wanyesea_AI_Custom_Api_Provider_Base> */
    private $provider_class;

    /** @var string */
    private $provider_id;

    /**
     * @param class-string<Wanyesea_AI_Custom_Api_Provider_Base> $provider_class
     * @param string                                           $provider_id
     */
    public function __construct($provider_class, $provider_id) {
        $this->provider_class = $provider_class;
        $this->provider_id    = sanitize_key((string) $provider_id);
    }

    public static function getLastListedDefaultModelId($provider_id) {
        $provider_id = sanitize_key((string) $provider_id);
        return isset(self::$last_default_model_by_provider[$provider_id])
            ? self::$last_default_model_by_provider[$provider_id]
            : '';
    }

    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = array(), $data = null): Request {
        return new Request(
            $method,
            call_user_func(array($this->provider_class, 'url'), $path),
            $headers,
            $data,
            wanyesea_ai_default_text_request_options($this->provider_id)
        );
    }

    /**
     * /models 失败时（中转异常、网络超时等）对已配置密钥的 SenseNova 返回静态模型表，避免出图能力被判定为不可用。
     *
     * @return array<string, ModelMetadata>
     */
    protected function sendListModelsRequest(): array {
        if (function_exists('wanyesea_ai_editor_ai_image_flow_is_active')
            && wanyesea_ai_editor_ai_image_flow_is_active()) {
            if (function_exists('wanyesea_ai_custom_provider_models_fallback_map')
                && function_exists('wanyesea_ai_get_connector_api_key_resolved')
                && wanyesea_ai_get_connector_api_key_resolved($this->provider_id) !== '') {
                $fallback = wanyesea_ai_custom_provider_models_fallback_map($this->provider_id);
                if ($fallback !== array()) {
                    return $fallback;
                }
            }
            if (function_exists('wanyesea_ai_custom_provider_text_models_fallback_map')) {
                $text_fallback = wanyesea_ai_custom_provider_text_models_fallback_map($this->provider_id);
                if ($text_fallback !== array()) {
                    return $text_fallback;
                }
            }

            return array();
        }

        if (function_exists('wanyesea_ai_image_generation_rest_is_active')
            && wanyesea_ai_image_generation_rest_is_active()
            && function_exists('wanyesea_ai_text_only_custom_provider_ids')
            && in_array($this->provider_id, wanyesea_ai_text_only_custom_provider_ids(), true)) {
            $text_fallback = function_exists('wanyesea_ai_custom_provider_text_models_fallback_map')
                ? wanyesea_ai_custom_provider_text_models_fallback_map($this->provider_id)
                : array();
            if ($text_fallback !== array()) {
                return $text_fallback;
            }

            return array();
        }

        if (function_exists('wanyesea_ai_custom_provider_models_fallback_map')
            && function_exists('wanyesea_ai_get_connector_api_key_resolved')
            && wanyesea_ai_get_connector_api_key_resolved($this->provider_id) !== '') {
            $fallback = wanyesea_ai_custom_provider_models_fallback_map($this->provider_id);
            if ($fallback !== array()) {
                return $fallback;
            }
        }

        if (function_exists('wanyesea_ai_custom_provider_text_models_fallback_map')
            && function_exists('wanyesea_ai_get_connector_api_key_resolved')
            && wanyesea_ai_get_connector_api_key_resolved($this->provider_id) !== '') {
            $text_only_ids = function_exists('wanyesea_ai_text_only_custom_provider_ids')
                ? wanyesea_ai_text_only_custom_provider_ids()
                : array();
            if (in_array($this->provider_id, $text_only_ids, true)) {
                $text_fallback = wanyesea_ai_custom_provider_text_models_fallback_map($this->provider_id);
                if ($text_fallback !== array()) {
                    return $text_fallback;
                }
            }
        }

        try {
            return parent::sendListModelsRequest();
        } catch (Throwable $e) {
            if (function_exists('wanyesea_ai_custom_provider_text_models_fallback_map')) {
                $text_fallback = wanyesea_ai_custom_provider_text_models_fallback_map($this->provider_id);
                if ($text_fallback !== array()) {
                    return $text_fallback;
                }
            }

            $fallback = wanyesea_ai_custom_provider_models_fallback_map($this->provider_id);
            if ($fallback !== array()) {
                return $fallback;
            }

            throw $e;
        }
    }

    protected function parseResponseToModelMetadataList(Response $response): array {
        $response_data = $response->getData();
        $items         = wanyesea_ai_parse_openai_compatible_models_payload($response_data);

        if ($items === array()) {
            throw ResponseException::fromMissingData($this->provider_id, 'data');
        }

        $endpoint_default = wanyesea_ai_parse_openai_compatible_default_model_id($response_data);
        self::$last_default_model_by_provider[$this->provider_id] = $endpoint_default;

        $capabilities = array(
            CapabilityEnum::textGeneration(),
            CapabilityEnum::chatHistory(),
        );
        $options = wanyesea_ai_openai_compatible_chat_text_model_options(
            function_exists('wanyesea_ai_openai_compatible_model_supports_image_input')
                && wanyesea_ai_openai_compatible_model_supports_image_input($model_data)
        );

        $models = array();
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
                || !wanyesea_ai_openai_compatible_model_supports_text_output($model_data)) {
                continue;
            }
            if ($endpoint_default === '' && !empty($model_data['default']) && filter_var($model_data['default'], FILTER_VALIDATE_BOOLEAN)) {
                $endpoint_default = $model_id;
                self::$last_default_model_by_provider[$this->provider_id] = $model_id;
            }
            $models[] = new ModelMetadata(
                $model_id,
                isset($model_data['name']) ? (string) $model_data['name'] : $model_id,
                $capabilities,
                $options
            );
        }

        if (function_exists('wanyesea_ai_build_openai_compatible_image_model_metadata')) {
            $models = array_merge(
                $models,
                wanyesea_ai_build_openai_compatible_image_model_metadata($items, $this->provider_id)
            );
        }

        if ($models === array()) {
            throw ResponseException::fromMissingData($this->provider_id, 'data');
        }

        return $models;
    }
}

/**
 * OpenAI 兼容 chat/completions 文本生成模型。
 */
final class Wanyesea_AI_OpenAi_Compatible_Text_Generation_Model extends AbstractOpenAiCompatibleTextGenerationModel {

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
            wanyesea_ai_resolve_text_request_options($this)
        );
    }

    /**
     * NVIDIA GLiNER-PII：仅最后一条 user 消息、content 为纯字符串，省略对话类采样参数。
     *
     * @param list<\WordPress\AiClient\Messages\DTO\Message> $prompt
     * @return array<string, mixed>
     */
    protected function prepareGenerateTextParams(array $prompt): array {
        $params = parent::prepareGenerateTextParams($prompt);

        if (!wanyesea_ai_is_nvidia_nim_entity_extraction_model($this->metadata()->getId())) {
            return $params;
        }

        $last_user_text = '';
        foreach (array_reverse($prompt) as $message) {
            if (!$message->getRole()->isUser()) {
                continue;
            }
            foreach ($message->getParts() as $part) {
                if ($part->getType()->isText() && !$part->getChannel()->isThought()) {
                    $text = $part->getText();
                    if ($text !== '') {
                        $last_user_text = $text;
                    }
                }
            }
            break;
        }

        if ($last_user_text === '' && !empty($params['messages']) && is_array($params['messages'])) {
            $messages = $params['messages'];
            for ($i = count($messages) - 1; $i >= 0; $i--) {
                if (!is_array($messages[$i]) || ($messages[$i]['role'] ?? '') !== 'user') {
                    continue;
                }
                $last_user_text = wanyesea_ai_extract_openai_message_text_content($messages[$i]['content'] ?? '');
                break;
            }
        }

        $params['messages'] = array(
            array(
                'role'    => 'user',
                'content' => $last_user_text !== '' ? $last_user_text : ' ',
            ),
        );

        unset(
            $params['max_tokens'],
            $params['temperature'],
            $params['top_p'],
            $params['stop'],
            $params['presence_penalty'],
            $params['frequency_penalty']
        );

        return $params;
    }
}

/**
 * 为已配置的自定义 Provider 注入 AI Client 鉴权。
 */
function wanyesea_ai_inject_custom_provider_auth() {
    if (!class_exists('WordPress\AiClient\AiClient') || !class_exists('Wanyesea_AI_Custom_Connectors')) {
        return;
    }

    try {
        $registry = WordPress\AiClient\AiClient::defaultRegistry();
    } catch (Throwable $e) {
        return;
    }

    foreach (Wanyesea_AI_Custom_Connectors::provider_ids() as $provider_id) {
        if (!$registry->hasProvider($provider_id)) {
            continue;
        }
        $key = wanyesea_ai_get_custom_connector_api_key_resolved($provider_id);
        if ($key === '') {
            continue;
        }
        $auth = function_exists('wanyesea_ai_create_custom_provider_authentication')
            ? wanyesea_ai_create_custom_provider_authentication($provider_id, $key)
            : new WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication($key);
        if ($auth === null) {
            continue;
        }
        $registry->setProviderRequestAuthentication($provider_id, $auth);
    }
}

add_action('init', 'wanyesea_ai_inject_custom_provider_auth', 21);

/**
 * 带请求内缓存的文本模型优先列表；/models 不可用时回退到配置中的 preferred_model_hint。
 *
 * @return list<array{0: string, 1: string}>
 */
function wanyesea_ai_get_custom_provider_text_model_preferences_cached($provider_id) {
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

    $pairs = wanyesea_ai_get_custom_provider_text_model_preferences($provider_id);

    if ($pairs === array() && function_exists('wanyesea_ai_get_custom_connector_api_key_resolved')
        && wanyesea_ai_get_custom_connector_api_key_resolved($provider_id) !== ''
        && class_exists('Wanyesea_AI_Custom_Connectors')) {
        $defs = Wanyesea_AI_Custom_Connectors::definitions();
        $def  = isset($defs[$provider_id]) && is_array($defs[$provider_id]) ? $defs[$provider_id] : array();
        $hint = !empty($def['preferred_model_hint']) ? trim((string) $def['preferred_model_hint']) : '';
        if ($hint !== '' && !wanyesea_ai_is_nvidia_nim_entity_extraction_model($hint)) {
            $pairs = array(array($provider_id, $hint));
        }
    }

    $cache[$provider_id] = $pairs;

    return $pairs;
}

/**
 * 将已配置且可用的自定义厂商模型加入文本生成优先列表（排在官方模型之前）。
 * 模型 ID 来自 /models 端点；优先端点默认，否则按列表顺序依次回退。
 */
function wanyesea_ai_prepend_custom_text_models($preferred_models) {
    if (!is_array($preferred_models)) {
        $preferred_models = array();
    }
    if (!class_exists('Wanyesea_AI_Custom_Connectors')) {
        return $preferred_models;
    }

    $prepend = array();
    foreach (Wanyesea_AI_Custom_Connectors::provider_ids() as $provider_id) {
        if (function_exists('wanyesea_ai_get_custom_connector_api_key_resolved')
            && wanyesea_ai_get_custom_connector_api_key_resolved($provider_id) === '') {
            continue;
        }
        $pairs = wanyesea_ai_get_custom_provider_text_model_preferences_cached($provider_id);
        if ($pairs !== array()) {
            $prepend = array_merge($prepend, $pairs);
        }
    }

    $prepend = array_values(array_filter($prepend, function ($pair) {
        return is_array($pair)
            && isset($pair[0], $pair[1])
            && is_string($pair[0])
            && is_string($pair[1])
            && $pair[1] !== ''
            && !wanyesea_ai_is_nvidia_nim_entity_extraction_model($pair[1]);
    }));

    if ($prepend === array()) {
        return $preferred_models;
    }

    $max = (int) apply_filters('wanyesea_ai_custom_text_model_preference_total_limit', 18);
    if ($max > 0 && count($prepend) > $max) {
        $prepend = array_slice($prepend, 0, $max);
    }

    return array_merge($prepend, $preferred_models);
}

add_filter('wpai_preferred_text_models', 'wanyesea_ai_prepend_custom_text_models', 20);
