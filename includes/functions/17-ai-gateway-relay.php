<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleImageGenerationModel;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

/**
 * 通用 AI 网关（多中转站、OpenAI 兼容 / Anthropic Messages、本地模型池）。
 * 与「按厂商改写官方 URL」并存，不替代现有 relay 逻辑。
 */
final class Wanyesea_AI_Gateway_Settings {

    const OPTION_KEY   = 'wanyesea_ai_gateway_relays';
    const RELAYS_KEY   = 'relays';
    const DEFAULT_SLOT = 'default';
    const MODE_OPENAI  = 'openai';
    const MODE_ANTHROPIC = 'anthropic';
    const PROVIDER_PREFIX = 'wanyesea-gateway';

    /**
     * @return list<array<string, mixed>>
     */
    public static function get_relays() {
        $stored = get_option(self::OPTION_KEY, null);
        if (!is_array($stored)) {
            return array(self::default_relay(0));
        }

        $raw = isset($stored[self::RELAYS_KEY]) && is_array($stored[self::RELAYS_KEY])
            ? $stored[self::RELAYS_KEY]
            : array();

        $relays = self::normalize_relays($raw);
        if ($relays !== $raw) {
            update_option(self::OPTION_KEY, array(self::RELAYS_KEY => $relays), false);
        }

        return $relays;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function get_slots() {
        $slots = array();
        foreach (self::get_relays() as $index => $relay) {
            $slot_id = $relay['key'];
            $slots[$slot_id] = self::relay_to_slot($relay, $slot_id, $index);
        }
        return $slots;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function get_registerable_slots() {
        $out = array();
        foreach (self::get_slots() as $slot_id => $slot) {
            if (!empty($slot['enabled']) && !empty($slot['site_url'])) {
                $out[$slot_id] = $slot;
            }
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_slot($slot_id = self::DEFAULT_SLOT) {
        $slot_id = self::normalize_slot_id($slot_id);
        $slots   = self::get_slots();
        if (isset($slots[$slot_id])) {
            return $slots[$slot_id];
        }
        $default = self::default_relay(0);
        $default['key'] = $slot_id;
        return self::relay_to_slot($default, $slot_id, 0);
    }

    public static function provider_id_for_slot($slot_id = self::DEFAULT_SLOT) {
        $slot_id = self::normalize_slot_id($slot_id);
        if ($slot_id === self::DEFAULT_SLOT) {
            return self::PROVIDER_PREFIX;
        }
        return self::PROVIDER_PREFIX . '-' . preg_replace('/[^a-z0-9-]/', '-', strtolower(str_replace('_', '-', $slot_id)));
    }

    public static function slot_id_for_provider_id($provider_id) {
        $provider_id = sanitize_key((string) $provider_id);
        if ($provider_id === self::PROVIDER_PREFIX) {
            return self::DEFAULT_SLOT;
        }
        if (preg_match('/^' . preg_quote(self::PROVIDER_PREFIX, '/') . '-([a-z0-9][a-z0-9_-]*)$/', $provider_id, $m)) {
            return self::normalize_slot_id(str_replace('-', '_', $m[1]));
        }
        return self::DEFAULT_SLOT;
    }

    public static function is_gateway_provider_id($provider_id) {
        $provider_id = sanitize_key((string) $provider_id);
        return $provider_id === self::PROVIDER_PREFIX
            || strpos($provider_id, self::PROVIDER_PREFIX . '-') === 0;
    }

    /**
     * @return list<string>
     */
    public static function registerable_provider_ids() {
        $ids = array();
        foreach (self::get_registerable_slots() as $slot_id => $slot) {
            unset($slot);
            $ids[] = self::provider_id_for_slot($slot_id);
        }
        return $ids;
    }

    public static function get_site_url($slot_id = self::DEFAULT_SLOT) {
        $slot = self::get_slot($slot_id);
        return isset($slot['site_url']) ? (string) $slot['site_url'] : '';
    }

    public static function api_base_url($slot_id = self::DEFAULT_SLOT) {
        $site = self::get_site_url($slot_id);
        return $site === '' ? '' : rtrim($site, '/') . '/v1';
    }

    public static function get_mode($slot_id = self::DEFAULT_SLOT) {
        $slot = self::get_slot($slot_id);
        $mode = isset($slot['mode']) ? sanitize_key((string) $slot['mode']) : self::MODE_OPENAI;
        return in_array($mode, array(self::MODE_OPENAI, self::MODE_ANTHROPIC), true) ? $mode : self::MODE_OPENAI;
    }

    public static function get_slot_name($slot_id = self::DEFAULT_SLOT) {
        $slot = self::get_slot($slot_id);
        $name = isset($slot['name']) ? trim((string) $slot['name']) : '';
        return $name !== '' ? $name : ($slot_id === self::DEFAULT_SLOT ? '晚秋 AI 网关' : '晚秋 AI 网关 ' . $slot_id);
    }

    public static function url_for_slot($slot_id, $path = '') {
        $base = self::api_base_url($slot_id);
        if ($base === '') {
            $base = 'https://example.invalid/v1';
        }
        if ($path === '') {
            return $base;
        }
        if (self::get_mode($slot_id) === self::MODE_ANTHROPIC && $path === 'chat/completions') {
            $path = 'messages';
        }
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    public static function url_for_provider($provider_id, $path = '') {
        return self::url_for_slot(self::slot_id_for_provider_id($provider_id), $path);
    }

    public static function connector_option_name($slot_id = self::DEFAULT_SLOT) {
        $pid = str_replace('-', '_', self::provider_id_for_slot($slot_id));
        return 'connectors_ai_' . $pid . '_api_key';
    }

    public static function env_constant_name($slot_id = self::DEFAULT_SLOT) {
        $pid = strtoupper(str_replace('-', '_', self::provider_id_for_slot($slot_id)));
        return $pid . '_API_KEY';
    }

    public static function get_api_key($slot_id = self::DEFAULT_SLOT) {
        $provider_id = self::provider_id_for_slot($slot_id);

        if (function_exists('wanyesea_ai_resolve_env_api_key')) {
            $from_env = wanyesea_ai_resolve_env_api_key($provider_id);
            if ($from_env !== '') {
                return $from_env;
            }
        }

        $slot = self::get_slot($slot_id);
        if (!empty($slot['api_key']) && is_string($slot['api_key'])) {
            return trim($slot['api_key']);
        }

        $opt = get_option(self::connector_option_name($slot_id), '');
        return is_string($opt) ? trim($opt) : '';
    }

    public static function get_logo_url($slot_id = self::DEFAULT_SLOT) {
        $site = self::get_site_url($slot_id);
        return $site === '' ? '' : esc_url_raw(rtrim($site, '/') . '/favicon.ico');
    }

    /**
     * @return list<array{id:string,name:string,capabilities:list<string>}>
     */
    public static function get_models($slot_id = self::DEFAULT_SLOT) {
        $slot = self::get_slot($slot_id);
        if (empty($slot['models']) || !is_array($slot['models'])) {
            return array();
        }
        return self::normalize_models($slot['models']);
    }

    /**
     * @param list<array<string, mixed>> $relays
     */
    public static function save_relays($relays) {
        update_option(self::OPTION_KEY, array(self::RELAYS_KEY => self::normalize_relays($relays)), false);
    }

    /**
     * 合并保存：请求里未带的 API Key 保留数据库中的值。
     *
     * @param list<array<string, mixed>> $incoming
     * @return list<array<string, mixed>>
     */
    public static function merge_relays_for_save(array $incoming) {
        $existing = array();
        foreach (self::get_relays() as $relay) {
            $existing[$relay['key']] = $relay;
        }

        $merged = self::normalize_relays($incoming);
        foreach ($merged as $index => $relay) {
            $key = $relay['key'];
            if (($relay['api_key'] ?? '') === '' && !empty($existing[$key]['api_key'])) {
                $merged[$index]['api_key'] = $existing[$key]['api_key'];
            }
        }

        return $merged;
    }

    /**
     * REST / 前台用：不下发明文 API Key。
     *
     * @return list<array<string, mixed>>
     */
    public static function relays_for_rest() {
        $relays = array();
        foreach (self::get_relays() as $relay) {
            $row = $relay;
            $row['api_key_configured'] = self::get_api_key($relay['key']) !== '';
            unset($row['api_key']);
            $relays[] = $row;
        }
        return $relays;
    }

    /**
     * @param array<string, mixed> $updates
     */
    public static function update_slot($slot_id, array $updates) {
        $slot_id = self::normalize_slot_id($slot_id);
        $relays  = self::get_relays();
        $found   = false;

        foreach ($relays as $i => $relay) {
            if (($relay['key'] ?? '') !== $slot_id) {
                continue;
            }
            $relays[$i] = array_merge($relay, $updates, array('key' => $slot_id));
            $found      = true;
            break;
        }

        if (!$found) {
            $relays[] = array_merge(self::default_relay(count($relays)), $updates, array('key' => $slot_id));
        }

        self::save_relays($relays);
        if (isset($updates['api_key'])) {
            self::sync_api_key_to_connectors($slot_id, trim((string) $updates['api_key']));
        }
    }

    public static function sync_api_key_to_connectors($slot_id, $api_key) {
        $option = self::connector_option_name($slot_id);
        if ($api_key === '') {
            delete_option($option);
            return;
        }
        update_option($option, sanitize_text_field($api_key), false);
    }

    /**
     * 仅保留 scheme + host + port，自动拼 /v1。
     */
    public static function normalize_site_url($url) {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        $url = preg_replace('/\s+/', '', $url);
        if (!preg_match('#^https?://#i', $url)) {
            return '';
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $out    = $scheme . '://' . strtolower((string) $parts['host']);
        if (!empty($parts['port'])) {
            $out .= ':' . (int) $parts['port'];
        }
        return esc_url_raw($out);
    }

    /**
     * @param list<array{id:string,name?:string}> $models
     */
    public static function merge_fetched_models($slot_id, array $models) {
        $slot_id = self::normalize_slot_id($slot_id);
        $slot    = self::get_slot($slot_id);
        $existing = array();
        foreach (self::get_models($slot_id) as $m) {
            $existing[$m['id']] = $m;
        }

        $rows = array();
        foreach ($models as $model) {
            $id = isset($model['id']) ? sanitize_text_field((string) $model['id']) : '';
            if ($id === '') {
                continue;
            }
            $name = isset($model['name']) && $model['name'] !== '' ? (string) $model['name'] : $id;
            $caps = isset($existing[$id]['capabilities']) ? $existing[$id]['capabilities'] : self::infer_capabilities($id);
            $rows[] = array('id' => $id, 'name' => $name, 'capabilities' => $caps);
        }

        self::update_slot($slot_id, array('models' => $rows));
    }

    /**
     * @return list<string>
     */
    public static function infer_capabilities($model_id) {
        $id = strtolower((string) $model_id);
        if (preg_match('/(dall-e|gpt-image|imagen|flux|stable-diffusion|sdxl|midjourney|sensenova-u1|-image)/', $id)) {
            return array('image_generation');
        }
        if (preg_match('/(embedding|embed|rerank|moderation|tts|whisper|transcribe|realtime|sora)/', $id)) {
            return array();
        }
        if (preg_match('/(gpt-4o|gpt-4\.1|gpt-5|^o1|^o3|^o4|vision|\bvl\b|qwen-vl|glm-4v|llava|claude-3|claude-sonnet|claude-opus|claude-haiku)/', $id)) {
            return array('text_generation', 'vision');
        }
        return array('text_generation');
    }

    /**
     * @param array<mixed> $capabilities
     * @return list<string>
     */
    public static function sanitize_capabilities(array $capabilities) {
        $allowed = array('text_generation', 'vision', 'image_generation');
        $capabilities = array_values(array_intersect($allowed, array_map('strval', $capabilities)));
        if (in_array('image_generation', $capabilities, true)) {
            return array('image_generation');
        }
        if (in_array('vision', $capabilities, true) && !in_array('text_generation', $capabilities, true)) {
            array_unshift($capabilities, 'text_generation');
        }
        return array_values(array_unique($capabilities));
    }

    /**
     * @param mixed $relays
     * @return list<array<string, mixed>>
     */
    public static function normalize_relays($relays) {
        if (!is_array($relays)) {
            return array(self::default_relay(0));
        }
        $out = array();
        foreach ($relays as $index => $relay) {
            if (!is_array($relay)) {
                continue;
            }
            $out[] = self::normalize_relay($relay, (int) $index);
        }
        return $out !== array() ? $out : array(self::default_relay(0));
    }

    /**
     * @param array<string, mixed> $relay
     * @return array<string, mixed>
     */
    private static function normalize_relay(array $relay, $index) {
        $def = self::default_relay($index);
        $key = isset($relay['key']) ? self::normalize_slot_id((string) $relay['key']) : $def['key'];
        if ($key === '') {
            $key = $index === 0 ? self::DEFAULT_SLOT : 'slot_' . ($index + 1);
        }
        $mode = isset($relay['mode']) ? sanitize_key((string) $relay['mode']) : $def['mode'];
        if (!in_array($mode, array(self::MODE_OPENAI, self::MODE_ANTHROPIC), true)) {
            $mode = self::MODE_OPENAI;
        }
        $status = isset($relay['status']) && is_array($relay['status']) ? $relay['status'] : $def['status'];

        return array(
            'key'      => $key,
            'enabled'  => self::normalize_enabled($relay['enabled'] ?? false),
            'name'     => isset($relay['name']) ? sanitize_text_field((string) $relay['name']) : $def['name'],
            'site_url' => self::normalize_site_url(isset($relay['site_url']) ? (string) $relay['site_url'] : ''),
            'mode'     => $mode,
            'api_key'  => isset($relay['api_key']) ? sanitize_text_field((string) $relay['api_key']) : '',
            'models'   => isset($relay['models']) && is_array($relay['models']) ? self::normalize_models($relay['models']) : array(),
            'status'   => array(
                'latency' => isset($status['latency']) ? (int) $status['latency'] : 0,
                'ok'      => array_key_exists('ok', $status) ? $status['ok'] : null,
                'message' => isset($status['message']) ? sanitize_text_field((string) $status['message']) : '',
                'checked' => isset($status['checked']) ? sanitize_text_field((string) $status['checked']) : '',
            ),
        );
    }

    /**
     * @param mixed $models
     * @return list<array{id:string,name:string,capabilities:list<string>}>
     */
    private static function normalize_models($models) {
        $out = array();
        foreach ((array) $models as $model) {
            if (!is_array($model) || empty($model['id'])) {
                continue;
            }
            $id   = sanitize_text_field((string) $model['id']);
            $name = isset($model['name']) ? sanitize_text_field((string) $model['name']) : $id;
            $caps = isset($model['capabilities']) && is_array($model['capabilities'])
                ? self::sanitize_capabilities($model['capabilities'])
                : self::infer_capabilities($id);
            $out[] = array('id' => $id, 'name' => $name, 'capabilities' => $caps);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $relay
     * @return array<string, mixed>
     */
    private static function relay_to_slot(array $relay, $slot_id, $index) {
        $relay = self::normalize_relay($relay, $index);
        return array_merge($relay, array(
            'id'          => $slot_id,
            'index'       => $index,
            'provider_id' => self::provider_id_for_slot($slot_id),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function default_relay($index) {
        return array(
            'key'      => $index === 0 ? self::DEFAULT_SLOT : 'slot_' . ($index + 1),
            'enabled'  => false,
            'name'     => $index === 0 ? '晚秋 AI 网关' : '晚秋 AI 网关 ' . ($index + 1),
            'site_url' => '',
            'mode'     => self::MODE_OPENAI,
            'api_key'  => '',
            'models'   => array(),
            'status'   => array('latency' => 0, 'ok' => null, 'message' => '', 'checked' => ''),
        );
    }

    public static function normalize_slot_id($slot_id) {
        $slot_id = sanitize_key(str_replace('-', '_', strtolower(trim((string) $slot_id))));
        return $slot_id !== '' ? $slot_id : self::DEFAULT_SLOT;
    }

    /**
     * @param mixed $value
     */
    public static function normalize_enabled($value): bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }
        $value = strtolower(trim((string) $value));
        return in_array($value, array('1', 'true', 'on', 'yes'), true);
    }
}

/**
 * Anthropic Messages 鉴权头。
 */
final class Wanyesea_AI_Gateway_Anthropic_Auth extends ApiKeyRequestAuthentication {

    public function authenticateRequest(Request $request): Request {
        return $request
            ->withHeader('anthropic-version', '2023-06-01')
            ->withHeader('x-api-key', $this->getApiKey());
    }
}

/**
 * 网关 Provider 可用性：根地址 + API Key。
 */
final class Wanyesea_AI_Gateway_Provider_Availability implements ProviderAvailabilityInterface {

    /** @var string */
    private $slot_id;

    public function __construct($slot_id) {
        $this->slot_id = Wanyesea_AI_Gateway_Settings::slot_id_for_provider_id($slot_id);
    }

    public function isConfigured(): bool {
        return Wanyesea_AI_Gateway_Settings::api_base_url($this->slot_id) !== ''
            && Wanyesea_AI_Gateway_Settings::get_api_key($this->slot_id) !== '';
    }
}

/**
 * 本地模型池优先的元数据目录。
 */
final class Wanyesea_AI_Gateway_Model_Metadata_Directory extends AbstractOpenAiCompatibleModelMetadataDirectory {

    /** @var string */
    private $slot_id;

    /** @var class-string<Wanyesea_AI_Gateway_Provider_Base> */
    private $provider_class;

    public function __construct($provider_class, $slot_id) {
        $this->provider_class = $provider_class;
        $this->slot_id        = $slot_id;
    }

    public function getRequestAuthentication(): RequestAuthenticationInterface {
        $auth = parent::getRequestAuthentication();
        if (Wanyesea_AI_Gateway_Settings::get_mode($this->slot_id) !== Wanyesea_AI_Gateway_Settings::MODE_ANTHROPIC
            || !$auth instanceof ApiKeyRequestAuthentication) {
            return $auth;
        }
        return new Wanyesea_AI_Gateway_Anthropic_Auth($auth->getApiKey());
    }

    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = array(), $data = null): Request {
        return new Request(
            $method,
            call_user_func(array($this->provider_class, 'url'), $path),
            $headers,
            $data,
            wanyesea_ai_default_text_request_options(Wanyesea_AI_Gateway_Settings::provider_id_for_slot($this->slot_id))
        );
    }

    /**
     * @return array<string, ModelMetadata>
     */
    protected function sendListModelsRequest(): array {
        $saved = Wanyesea_AI_Gateway_Settings::get_models($this->slot_id);
        if ($saved === array()) {
            return parent::sendListModelsRequest();
        }

        $models = array();
        foreach ($saved as $model) {
            $models[$model['id']] = new ModelMetadata(
                $model['id'],
                $model['name'],
                $this->capabilities_from_list($model['capabilities']),
                $this->options_for_model($model['id'], $model['capabilities'])
            );
        }
        uasort($models, array($this, 'sortModels'));
        return $models;
    }

    /**
     * @param list<string> $caps
     * @return list<CapabilityEnum>
     */
    private function capabilities_from_list(array $caps) {
        $caps = Wanyesea_AI_Gateway_Settings::sanitize_capabilities($caps);
        if (in_array('image_generation', $caps, true)) {
            return array(CapabilityEnum::imageGeneration());
        }
        if (in_array('text_generation', $caps, true) || in_array('vision', $caps, true)) {
            return array(
                CapabilityEnum::textGeneration(),
                CapabilityEnum::chatHistory(),
            );
        }
        return array();
    }

    /**
     * @param list<string> $caps
     * @return list<SupportedOption>
     */
    private function options_for_model($model_id, array $caps) {
        unset($model_id, $caps);
        return array();
    }

    /**
     * 远程 GET /models 回退路径（本地模型池为空时）。
     *
     * @return list<ModelMetadata>
     */
    protected function parseResponseToModelMetadataList(Response $response): array {
        $response_data = $response->getData();
        if (!is_array($response_data) || empty($response_data['data']) || !is_array($response_data['data'])) {
            throw ResponseException::fromMissingData(
                Wanyesea_AI_Gateway_Settings::get_slot_name($this->slot_id),
                'data'
            );
        }

        $capability_map = $this->model_capabilities_map();
        $models         = array();

        foreach ($response_data['data'] as $model_data) {
            if (!is_array($model_data)) {
                continue;
            }
            $model_id = '';
            if (!empty($model_data['id'])) {
                $model_id = sanitize_text_field((string) $model_data['id']);
            } elseif (!empty($model_data['model'])) {
                $model_id = sanitize_text_field((string) $model_data['model']);
            }
            if ($model_id === '') {
                continue;
            }
            if (function_exists('wanyesea_ai_should_skip_openai_compatible_model')
                && wanyesea_ai_should_skip_openai_compatible_model($model_id)) {
                continue;
            }

            $model_name = $model_id;
            foreach (array('name', 'display_name') as $name_key) {
                if (!empty($model_data[$name_key]) && is_string($model_data[$name_key])) {
                    $model_name = sanitize_text_field($model_data[$name_key]);
                    break;
                }
            }

            $raw_caps = isset($capability_map[$model_id])
                ? $capability_map[$model_id]
                : Wanyesea_AI_Gateway_Settings::infer_capabilities($model_id);
            $raw_caps = $this->capabilities_for_model_id($model_id, $raw_caps);

            $models[] = new ModelMetadata(
                $model_id,
                $model_name,
                $this->capabilities_from_list($raw_caps),
                $this->options_for_model($model_id, $raw_caps)
            );
        }

        if ($models === array()) {
            throw ResponseException::fromMissingData(
                Wanyesea_AI_Gateway_Settings::get_slot_name($this->slot_id),
                'data[].id'
            );
        }

        usort($models, array($this, 'sortModels'));
        return $models;
    }

    /**
     * @return array<string, list<string>>
     */
    private function model_capabilities_map() {
        $map = array();
        foreach (Wanyesea_AI_Gateway_Settings::get_models($this->slot_id) as $model) {
            $map[$model['id']] = $model['capabilities'];
        }
        return $map;
    }

    /**
     * @param list<string> $capabilities
     * @return list<string>
     */
    private function capabilities_for_model_id($model_id, array $capabilities) {
        unset($model_id);
        $capabilities = Wanyesea_AI_Gateway_Settings::sanitize_capabilities($capabilities);
        if (Wanyesea_AI_Gateway_Settings::get_mode($this->slot_id) === Wanyesea_AI_Gateway_Settings::MODE_ANTHROPIC) {
            $capabilities = array_values(array_diff($capabilities, array('image_generation')));
            if ($capabilities === array()) {
                $capabilities = array('text_generation');
            }
        }
        return $capabilities;
    }

    protected function getBaseCacheKey(): string {
        $cache_state = array(
            'class'    => static::class,
            'slot_id'  => $this->slot_id,
            'mode'     => Wanyesea_AI_Gateway_Settings::get_mode($this->slot_id),
            'base_url' => Wanyesea_AI_Gateway_Settings::api_base_url($this->slot_id),
            'models'   => Wanyesea_AI_Gateway_Settings::get_models($this->slot_id),
        );
        return 'ai_client_' . AiClient::VERSION . '_' . md5((string) wp_json_encode($cache_state));
    }
}

/**
 * 网关文本模型（含 Anthropic Messages）。
 */
final class Wanyesea_AI_Gateway_Text_Model extends AbstractOpenAiCompatibleTextGenerationModel {

    /** @var class-string<Wanyesea_AI_Gateway_Provider_Base> */
    private $provider_class;

    /** @var string */
    private $slot_id;

    public function __construct($model_metadata, $provider_metadata, $provider_class, $slot_id) {
        parent::__construct($model_metadata, $provider_metadata);
        $this->provider_class = $provider_class;
        $this->slot_id        = $slot_id;
    }

    public function getRequestAuthentication(): RequestAuthenticationInterface {
        $auth = parent::getRequestAuthentication();
        if (Wanyesea_AI_Gateway_Settings::get_mode($this->slot_id) !== Wanyesea_AI_Gateway_Settings::MODE_ANTHROPIC
            || !$auth instanceof ApiKeyRequestAuthentication) {
            return $auth;
        }
        return new Wanyesea_AI_Gateway_Anthropic_Auth($auth->getApiKey());
    }

    /**
     * @param array<string, mixed>|null $outputSchema
     * @return array<string, mixed>
     */
    protected function prepareResponseFormatParam(?array $outputSchema): array {
        if (function_exists('wanyesea_ai_prepare_openai_compatible_response_format')) {
            $provider_id = class_exists('Wanyesea_AI_Gateway_Settings', false)
                ? Wanyesea_AI_Gateway_Settings::provider_id_for_slot($this->slot_id)
                : '';

            return wanyesea_ai_prepare_openai_compatible_response_format(
                $outputSchema,
                'structured_output',
                $provider_id,
                $this->metadata()->getId()
            );
        }

        return parent::prepareResponseFormatParam($outputSchema);
    }

    /**
     * 规范化松散 JSON 回复（内容分类等）。
     */
    protected function parseResponseToGenerativeAiResult(Response $response): GenerativeAiResult {
        if (function_exists('wanyesea_ai_normalize_openai_compatible_structured_json_response')) {
            $response = wanyesea_ai_normalize_openai_compatible_structured_json_response($response);
        }

        return parent::parseResponseToGenerativeAiResult($response);
    }

    /**
     * @param list<\WordPress\AiClient\Messages\DTO\Message> $prompt
     * @return array<string, mixed>
     */
    protected function prepareGenerateTextParams(array $prompt): array {
        $params = parent::prepareGenerateTextParams($prompt);

        $provider_id = class_exists('Wanyesea_AI_Gateway_Settings', false)
            ? Wanyesea_AI_Gateway_Settings::provider_id_for_slot($this->slot_id)
            : '';
        if (function_exists('wanyesea_ai_provider_prefers_json_object_response_format')
            && wanyesea_ai_provider_prefers_json_object_response_format($provider_id, $this->metadata()->getId())) {
            unset($params['response_format']);
        }

        return $params;
    }

    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = array(), $data = null): Request {
        if (Wanyesea_AI_Gateway_Settings::get_mode($this->slot_id) === Wanyesea_AI_Gateway_Settings::MODE_ANTHROPIC) {
            $headers['Content-Type'] = 'application/json';
            $anthropic_data          = is_array($data) ? $this->prepare_anthropic_params($data) : $data;
            return new Request(
                $method,
                Wanyesea_AI_Gateway_Settings::url_for_slot($this->slot_id, 'messages'),
                $headers,
                $anthropic_data,
                wanyesea_ai_resolve_text_request_options($this)
            );
        }
        return new Request(
            $method,
            call_user_func(array($this->provider_class, 'url'), $path),
            $headers,
            $data,
            wanyesea_ai_resolve_text_request_options($this)
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function prepare_anthropic_params(array $data) {
        $messages = isset($data['messages']) && is_array($data['messages']) ? $data['messages'] : array();
        $max_tokens = isset($data['max_tokens']) ? (int) $data['max_tokens'] : 4096;
        return array(
            'model'      => $data['model'] ?? $this->metadata()->getId(),
            'max_tokens' => max(1, $max_tokens),
            'messages'   => $messages,
        );
    }
}

/**
 * 网关出图：chat/completions 优先，失败再 images/generations；URL 转 base64。
 */
final class Wanyesea_AI_Gateway_Image_Model extends AbstractOpenAiCompatibleImageGenerationModel {

    /** @var class-string<Wanyesea_AI_Gateway_Provider_Base> */
    private $provider_class;

    /** @var string */
    private $slot_id;

    public function __construct($model_metadata, $provider_metadata, $provider_class, $slot_id) {
        parent::__construct($model_metadata, $provider_metadata);
        $this->provider_class = $provider_class;
        $this->slot_id        = $slot_id;
    }

    public function generateImageResult(array $prompt): GenerativeAiResult {
        $params = $this->prepareGenerateImageParams($prompt);
        $expected = isset($params['output_format']) && is_string($params['output_format'])
            ? 'image/' . $params['output_format']
            : 'image/png';

        try {
            $req = $this->createRequest(
                HttpMethodEnum::POST(),
                'chat/completions',
                array('Content-Type' => 'application/json'),
                $this->prepare_chat_image_params($params)
            );
            $req = $this->getRequestAuthentication()->authenticateRequest($req);
            $res = $this->getHttpTransporter()->send($req);
            $this->throwIfNotSuccessful($res);
            return $this->parse_chat_image_response($res, $expected);
        } catch (Throwable $e) {
            $req = $this->createRequest(
                HttpMethodEnum::POST(),
                'images/generations',
                array('Content-Type' => 'application/json'),
                $params
            );
            $req = $this->getRequestAuthentication()->authenticateRequest($req);
            $res = $this->getHttpTransporter()->send($req);
            $this->throwIfNotSuccessful($res);
            return $this->parse_inline_image_response($res, $expected);
        }
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

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function prepare_chat_image_params(array $params) {
        $prompt = isset($params['prompt']) && is_string($params['prompt']) ? $params['prompt'] : '';
        $chat   = array(
            'model'    => $this->metadata()->getId(),
            'messages' => array(array('role' => 'user', 'content' => $prompt)),
            'modalities' => array('image'),
        );
        if (isset($params['n'])) {
            $chat['n'] = $params['n'];
        }
        if (isset($params['size'])) {
            $chat['size'] = $params['size'];
        }
        return $chat;
    }

    private function parse_chat_image_response(Response $response, $expected_mime) {
        $data = $response->getData();
        if (!is_array($data) || empty($data['choices'])) {
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'choices');
        }
        $image_data = array();
        foreach ($data['choices'] as $choice) {
            $extracted = wanyesea_ai_gateway_extract_image_from_choice($choice);
            if ($extracted !== null) {
                $image_data[] = $extracted;
            }
        }
        if ($image_data === array()) {
            throw ResponseException::fromInvalidData($this->providerMetadata()->getName(), 'choices', 'No image in chat response.');
        }
        $body = wp_json_encode(array('data' => $image_data, 'usage' => $data['usage'] ?? array()));
        return $this->parseResponseToGenerativeAiResult(
            new Response($response->getStatusCode(), $response->getHeaders(), (string) $body),
            $expected_mime
        );
    }

    private function parse_inline_image_response(Response $response, $expected_mime) {
        $data = $response->getData();
        if (is_array($data) && !empty($data['data']) && is_array($data['data'])) {
            foreach ($data['data'] as $i => $item) {
                if (!is_array($item) || empty($item['url']) || !empty($item['b64_json'])) {
                    continue;
                }
                $b64 = wanyesea_ai_gateway_fetch_url_as_base64((string) $item['url']);
                if ($b64 !== '') {
                    $data['data'][$i]['b64_json'] = $b64;
                    unset($data['data'][$i]['url']);
                }
            }
            $body = wp_json_encode($data);
            if (is_string($body)) {
                $response = new Response($response->getStatusCode(), $response->getHeaders(), $body);
            }
        }
        return $this->parseResponseToGenerativeAiResult($response, $expected_mime);
    }
}

/**
 * @param array<string, mixed> $choice
 * @return array{b64_json:string}|null
 */
function wanyesea_ai_gateway_extract_image_from_choice(array $choice) {
    $content = '';
    if (isset($choice['message']['content'])) {
        $content = is_string($choice['message']['content'])
            ? $choice['message']['content']
            : wp_json_encode($choice['message']['content']);
    }
    if ($content === '') {
        return null;
    }
    if (preg_match('#!\[[^\]]*\]\(([^)]+)\)#', $content, $m)) {
        $url = trim($m[1]);
        if (strpos($url, 'data:image') === 0 && preg_match('#;base64,(.+)$#is', $url, $dm)) {
            return array('b64_json' => preg_replace('/\s+/', '', $dm[1]));
        }
        $b64 = wanyesea_ai_gateway_fetch_url_as_base64($url);
        return $b64 !== '' ? array('b64_json' => $b64) : null;
    }
    if (preg_match('#https?://[^\s\)"\'<>]+#i', $content, $m)) {
        $b64 = wanyesea_ai_gateway_fetch_url_as_base64($m[0]);
        return $b64 !== '' ? array('b64_json' => $b64) : null;
    }
    if (preg_match('#data:image/[^;]+;base64,([A-Za-z0-9+/=\s]+)#is', $content, $m)) {
        return array('b64_json' => preg_replace('/\s+/', '', $m[1]));
    }
    return null;
}

function wanyesea_ai_gateway_fetch_url_as_base64($url) {
    $url = trim((string) $url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return '';
    }
    $response = wp_remote_get($url, array(
        'timeout'     => 30,
        'redirection' => 3,
        'headers'     => array('Accept' => 'image/*,*/*;q=0.8'),
    ));
    if (is_wp_error($response)) {
        return '';
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    if ($code < 200 || $code >= 300 || $body === '') {
        return '';
    }
    return base64_encode($body);
}

/**
 * 网关 AI Client Provider 基类。
 */
abstract class Wanyesea_AI_Gateway_Provider_Base extends AbstractApiProvider {

    public const SLOT_ID = 'default';

    public static function slot_id() {
        return static::SLOT_ID;
    }

    public static function providerId(): string {
        return Wanyesea_AI_Gateway_Settings::provider_id_for_slot(static::slot_id());
    }

    protected static function baseUrl(): string {
        $url = Wanyesea_AI_Gateway_Settings::api_base_url(static::slot_id());
        if ($url === '') {
            throw new RuntimeException('WanYesea AI Gateway: missing site URL for slot "' . static::slot_id() . '".');
        }
        return $url;
    }

    /**
     * @return class-string<Wanyesea_AI_Gateway_Provider_Base>
     */
    public static function class_for_slot($slot_id) {
        $slot_id = Wanyesea_AI_Gateway_Settings::get_slot($slot_id)['id'] ?? Wanyesea_AI_Gateway_Settings::DEFAULT_SLOT;
        if ($slot_id === Wanyesea_AI_Gateway_Settings::DEFAULT_SLOT) {
            return Wanyesea_AI_Gateway_Provider::class;
        }
        $suffix = preg_replace('/[^A-Za-z0-9_]/', '_', ucwords(str_replace(array('-', '_'), '_', $slot_id), '_'));
        $suffix = str_replace('_', '', (string) $suffix);
        if ($suffix === '' || ctype_digit($suffix[0])) {
            $suffix = 'K' . $suffix;
        }
        $class = 'Wanyesea_AI_Gateway_Provider_' . $suffix;
        if (!class_exists($class, false)) {
            eval('final class ' . $class . ' extends Wanyesea_AI_Gateway_Provider_Base { public const SLOT_ID = ' . var_export($slot_id, true) . '; }');
        }
        return $class;
    }

    protected static function createProviderMetadata(): ProviderMetadata {
        $mode_label = Wanyesea_AI_Gateway_Settings::get_mode(static::slot_id()) === Wanyesea_AI_Gateway_Settings::MODE_ANTHROPIC
            ? 'Anthropic Messages'
            : 'OpenAI Compatible';
        $site = Wanyesea_AI_Gateway_Settings::get_site_url(static::slot_id());
        $cred = $site !== '' ? preg_replace('#/v\d+(?:\.\d+)?$#i', '', Wanyesea_AI_Gateway_Settings::api_base_url(static::slot_id())) : null;

        $args = array(
            static::providerId(),
            Wanyesea_AI_Gateway_Settings::get_slot_name(static::slot_id()),
            ProviderTypeEnum::server(),
            $cred,
            \WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod::apiKey(),
        );
        if (class_exists(AiClient::class) && version_compare(AiClient::VERSION, '1.2.0', '>=')) {
            $args[] = sprintf('通过 %s 协议访问统一 AI 网关。', $mode_label);
        }
        return new ProviderMetadata(...$args);
    }

    protected static function createProviderAvailability(): ProviderAvailabilityInterface {
        return new Wanyesea_AI_Gateway_Provider_Availability(static::providerId());
    }

    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
        return new Wanyesea_AI_Gateway_Model_Metadata_Directory(static::class, static::slot_id());
    }

    protected static function createModel(ModelMetadata $modelMetadata, ProviderMetadata $providerMetadata): ModelInterface {
        foreach ($modelMetadata->getSupportedCapabilities() as $capability) {
            if ($capability->isImageGeneration()) {
                if (Wanyesea_AI_Gateway_Settings::get_mode(static::slot_id()) === Wanyesea_AI_Gateway_Settings::MODE_ANTHROPIC) {
                    throw new RuntimeException('Anthropic Messages 模式不支持生图模型。');
                }
                return new Wanyesea_AI_Gateway_Image_Model($modelMetadata, $providerMetadata, static::class, static::slot_id());
            }
        }
        foreach ($modelMetadata->getSupportedCapabilities() as $capability) {
            if ($capability->isTextGeneration()) {
                return new Wanyesea_AI_Gateway_Text_Model($modelMetadata, $providerMetadata, static::class, static::slot_id());
            }
        }
        throw new RuntimeException('WanYesea AI Gateway: unsupported model capabilities.');
    }
}

final class Wanyesea_AI_Gateway_Provider extends Wanyesea_AI_Gateway_Provider_Base {
    public const SLOT_ID = 'default';
}

final class Wanyesea_AI_Gateway_Relay {

    public static function boot() {
        add_action('init', array(__CLASS__, 'register_providers'), 5);
        add_action('wp_connectors_init', array(__CLASS__, 'apply_connector_favicons'), 20);
        add_action('plugins_loaded', array(__CLASS__, 'approve_gateway_connectors'), 22);
        add_filter('wanyesea_ai_connect_provider_ids', array(__CLASS__, 'filter_connect_provider_ids'));
        add_filter('wanyesea_ai_connector_approval_ids', array(__CLASS__, 'filter_approval_ids'));
        add_action('rest_api_init', array(__CLASS__, 'register_rest_routes'));
        add_filter('http_request_host_is_external', array(__CLASS__, 'allow_gateway_hosts'), 10, 3);
    }

    public static function register_providers() {
        if (!class_exists(AiClient::class)) {
            return;
        }
        try {
            $registry = AiClient::defaultRegistry();
        } catch (Throwable $e) {
            return;
        }

        foreach (Wanyesea_AI_Gateway_Settings::get_registerable_slots() as $slot_id => $slot) {
            unset($slot);
            $class = Wanyesea_AI_Gateway_Provider_Base::class_for_slot($slot_id);
            $pid   = Wanyesea_AI_Gateway_Settings::provider_id_for_slot($slot_id);
            try {
                if (!$registry->hasProvider($pid) && !$registry->hasProvider($class)) {
                    $registry->registerProvider($class);
                }
            } catch (Throwable $e) {
                continue;
            }
        }
    }

    public static function apply_connector_favicons($connector_registry) {
        if (!is_object($connector_registry) || !method_exists($connector_registry, 'is_registered')) {
            return;
        }
        foreach (Wanyesea_AI_Gateway_Settings::get_registerable_slots() as $slot_id => $slot) {
            unset($slot);
            $provider_id = Wanyesea_AI_Gateway_Settings::provider_id_for_slot($slot_id);
            if (!$connector_registry->is_registered($provider_id)) {
                continue;
            }
            $connector = $connector_registry->unregister($provider_id);
            if (!is_array($connector)) {
                continue;
            }
            $logo = Wanyesea_AI_Gateway_Settings::get_logo_url($slot_id);
            if ($logo !== '') {
                $connector['logo_url'] = $logo;
            } else {
                unset($connector['logo_url']);
            }
            $connector_registry->register($provider_id, $connector);
        }
    }

    public static function approve_gateway_connectors() {
        $basename = plugin_basename(WanYesea_AI_path . 'index.php');
        $approvals = get_option('wpai_connector_approvals', array());
        if (!is_array($approvals)) {
            $approvals = array();
        }
        if (!isset($approvals[$basename]) || !is_array($approvals[$basename])) {
            $approvals[$basename] = array();
        }
        $changed = false;
        foreach (Wanyesea_AI_Gateway_Settings::registerable_provider_ids() as $provider_id) {
            if (empty($approvals[$basename][$provider_id])) {
                $approvals[$basename][$provider_id] = true;
                $changed = true;
            }
        }
        if ($changed) {
            update_option('wpai_connector_approvals', $approvals, false);
        }
    }

    public static function filter_connect_provider_ids($ids) {
        if (!is_array($ids)) {
            $ids = array();
        }
        return array_values(array_unique(array_merge($ids, Wanyesea_AI_Gateway_Settings::registerable_provider_ids())));
    }

    public static function filter_approval_ids($ids) {
        return self::filter_connect_provider_ids($ids);
    }

    public static function allow_gateway_hosts($allow, $host, $url) {
        unset($url);
        if ($allow) {
            return $allow;
        }
        $host = strtolower((string) $host);
        foreach (Wanyesea_AI_Gateway_Settings::get_registerable_slots() as $slot_id => $slot) {
            unset($slot);
            $parsed = wp_parse_url(Wanyesea_AI_Gateway_Settings::get_site_url($slot_id));
            if (!empty($parsed['host']) && strtolower((string) $parsed['host']) === $host) {
                return true;
            }
        }
        return $allow;
    }

    public static function register_rest_routes() {
        register_rest_route('wanyesea-ai/v1', '/gateway', array(
            'methods'             => 'GET',
            'permission_callback' => array(__CLASS__, 'rest_can_manage'),
            'callback'            => array(__CLASS__, 'rest_get_gateway'),
        ));
        register_rest_route('wanyesea-ai/v1', '/gateway', array(
            'methods'             => 'POST',
            'permission_callback' => array(__CLASS__, 'rest_can_manage'),
            'callback'            => array(__CLASS__, 'rest_save_gateway'),
        ));
        register_rest_route('wanyesea-ai/v1', '/gateway/(?P<slot>[a-zA-Z0-9_-]+)/fetch-models', array(
            'methods'             => 'POST',
            'permission_callback' => array(__CLASS__, 'rest_can_manage'),
            'callback'            => array(__CLASS__, 'rest_fetch_models'),
        ));
        register_rest_route('wanyesea-ai/v1', '/gateway/(?P<slot>[a-zA-Z0-9_-]+)/probe', array(
            'methods'             => 'POST',
            'permission_callback' => array(__CLASS__, 'rest_can_manage'),
            'callback'            => array(__CLASS__, 'rest_probe'),
        ));
    }

    public static function rest_can_manage() {
        return current_user_can('manage_options');
    }

    public static function rest_get_gateway() {
        return rest_ensure_response(array(
            'relays' => Wanyesea_AI_Gateway_Settings::relays_for_rest(),
            'slots'  => array_values(Wanyesea_AI_Gateway_Settings::get_slots()),
            'modes'  => array(
                array('value' => Wanyesea_AI_Gateway_Settings::MODE_OPENAI, 'label' => 'OpenAI Compatible'),
                array('value' => Wanyesea_AI_Gateway_Settings::MODE_ANTHROPIC, 'label' => 'Anthropic Messages'),
            ),
            'capabilities' => array(
                array('value' => 'text_generation', 'label' => '文本'),
                array('value' => 'vision', 'label' => '视觉'),
                array('value' => 'image_generation', 'label' => '生图'),
            ),
        ));
    }

    public static function rest_save_gateway($request) {
        $body = $request->get_json_params();
        if (!is_array($body) || empty($body['relays']) || !is_array($body['relays'])) {
            return new WP_Error('invalid_data', '缺少 relays 数据', array('status' => 400));
        }
        $relays = Wanyesea_AI_Gateway_Settings::merge_relays_for_save($body['relays']);
        foreach ($relays as $relay) {
            if (!empty($relay['api_key'])) {
                Wanyesea_AI_Gateway_Settings::sync_api_key_to_connectors($relay['key'], $relay['api_key']);
            }
        }
        Wanyesea_AI_Gateway_Settings::save_relays($relays);
        self::register_providers();
        return self::rest_get_gateway();
    }

    public static function rest_fetch_models($request) {
        $slot_id = Wanyesea_AI_Gateway_Settings::normalize_slot_id((string) $request['slot']);

        $base = Wanyesea_AI_Gateway_Settings::api_base_url($slot_id);
        $key  = Wanyesea_AI_Gateway_Settings::get_api_key($slot_id);
        if ($base === '') {
            return rest_ensure_response(array('ok' => false, 'message' => '请先保存网关根地址。'));
        }
        if ($key === '') {
            return rest_ensure_response(array('ok' => false, 'message' => '请先填写 API Key。'));
        }

        $headers = self::http_headers_for_slot($slot_id, $key);
        $response = wp_remote_get(Wanyesea_AI_Gateway_Settings::url_for_slot($slot_id, 'models'), array(
            'timeout' => 30,
            'headers' => $headers,
        ));

        if (is_wp_error($response)) {
            return rest_ensure_response(array('ok' => false, 'message' => $response->get_error_message()));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300) {
            return rest_ensure_response(array('ok' => false, 'message' => 'HTTP ' . $code));
        }

        $data   = json_decode($body, true);
        $models = array();
        if (is_array($data) && !empty($data['data']) && is_array($data['data'])) {
            foreach ($data['data'] as $row) {
                if (!is_array($row) || empty($row['id'])) {
                    continue;
                }
                $id = sanitize_text_field((string) $row['id']);
                if (function_exists('wanyesea_ai_should_skip_openai_compatible_model')
                    && wanyesea_ai_should_skip_openai_compatible_model($id)) {
                    continue;
                }
                $models[] = array(
                    'id'   => $id,
                    'name' => isset($row['name']) ? (string) $row['name'] : $id,
                );
            }
        }

        if ($models === array()) {
            return rest_ensure_response(array('ok' => false, 'message' => '未返回可用模型。'));
        }

        Wanyesea_AI_Gateway_Settings::merge_fetched_models($slot_id, $models);
        return rest_ensure_response(array('ok' => true, 'message' => '已获取 ' . count($models) . ' 个模型', 'models' => $models));
    }

    public static function rest_probe($request) {
        $slot_id = Wanyesea_AI_Gateway_Settings::normalize_slot_id((string) $request['slot']);

        $base = Wanyesea_AI_Gateway_Settings::api_base_url($slot_id);
        $key  = Wanyesea_AI_Gateway_Settings::get_api_key($slot_id);
        if ($base === '' || $key === '') {
            return rest_ensure_response(array('ok' => false, 'message' => '请先配置地址与 API Key', 'latency' => 0));
        }

        $start    = microtime(true);
        $response = wp_remote_get(Wanyesea_AI_Gateway_Settings::url_for_slot($slot_id, 'models'), array(
            'timeout' => 20,
            'headers' => self::http_headers_for_slot($slot_id, $key),
        ));
        $latency = (int) round((microtime(true) - $start) * 1000);

        if (is_wp_error($response)) {
            $msg = '失败 · ' . $latency . 'ms · ' . $response->get_error_message();
            self::update_slot_status($slot_id, false, $msg, $latency);
            return rest_ensure_response(array('ok' => false, 'message' => $msg, 'latency' => $latency));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            $msg = '失败 · ' . $latency . 'ms · HTTP ' . $code;
            self::update_slot_status($slot_id, false, $msg, $latency);
            return rest_ensure_response(array('ok' => false, 'message' => $msg, 'latency' => $latency));
        }

        $msg = '成功 · ' . $latency . 'ms';
        self::update_slot_status($slot_id, true, $msg, $latency);
        return rest_ensure_response(array('ok' => true, 'message' => $msg, 'latency' => $latency));
    }

    private static function update_slot_status($slot_id, $ok, $message, $latency) {
        Wanyesea_AI_Gateway_Settings::update_slot($slot_id, array(
            'status' => array(
                'latency' => $latency,
                'ok'      => $ok,
                'message' => $message,
                'checked' => gmdate('Y-m-d H:i:s'),
            ),
        ));
    }

    /**
     * @return array<string, string>
     */
    private static function http_headers_for_slot($slot_id, $api_key) {
        $headers = array('Accept' => 'application/json');
        if (Wanyesea_AI_Gateway_Settings::get_mode($slot_id) === Wanyesea_AI_Gateway_Settings::MODE_ANTHROPIC) {
            $headers['x-api-key']         = $api_key;
            $headers['anthropic-version'] = '2023-06-01';
        } else {
            $headers['Authorization'] = 'Bearer ' . $api_key;
        }
        return $headers;
    }
}

add_action('plugins_loaded', array('Wanyesea_AI_Gateway_Relay', 'boot'), 18);

/**
 * 网关 Provider 的 API Key 解析（供 wanyesea_ai_get_connector_api_key_resolved 调用）。
 */
function wanyesea_ai_get_gateway_api_key_resolved($provider_id) {
    if (!Wanyesea_AI_Gateway_Settings::is_gateway_provider_id($provider_id)) {
        return '';
    }
    return Wanyesea_AI_Gateway_Settings::get_api_key(
        Wanyesea_AI_Gateway_Settings::slot_id_for_provider_id($provider_id)
    );
}

/**
 * 向 AI Client Registry 注入网关 Provider 鉴权。
 */
function wanyesea_ai_inject_gateway_provider_auth() {
    if (!class_exists(AiClient::class) || !class_exists('Wanyesea_AI_Gateway_Settings', false)) {
        return;
    }

    try {
        $registry = AiClient::defaultRegistry();
    } catch (Throwable $e) {
        return;
    }

    foreach (Wanyesea_AI_Gateway_Settings::registerable_provider_ids() as $provider_id) {
        if (!$registry->hasProvider($provider_id)) {
            continue;
        }
        $slot_id = Wanyesea_AI_Gateway_Settings::slot_id_for_provider_id($provider_id);
        $key     = Wanyesea_AI_Gateway_Settings::get_api_key($slot_id);
        if ($key === '') {
            continue;
        }
        if (Wanyesea_AI_Gateway_Settings::get_mode($slot_id) === Wanyesea_AI_Gateway_Settings::MODE_ANTHROPIC) {
            $registry->setProviderRequestAuthentication(
                $provider_id,
                new Wanyesea_AI_Gateway_Anthropic_Auth($key)
            );
        } else {
            $registry->setProviderRequestAuthentication(
                $provider_id,
                new ApiKeyRequestAuthentication($key)
            );
        }
    }
}
