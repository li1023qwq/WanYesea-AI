<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * 小米 MiMo 官方鉴权：请求头 api-key（非 Bearer）。
 *
 * 须继承 ApiKeyRequestAuthentication，以满足 ProviderRegistry 对鉴权类型的校验。
 *
 * @see https://platform.xiaomimimo.com/docs/zh-CN/quick-start/first-api-call
 */
final class Wanyesea_AI_Mimo_Api_Key_Authentication extends ApiKeyRequestAuthentication {

    public function authenticateRequest(Request $request): Request {
        return $request->withHeader('api-key', $this->getApiKey());
    }
}

/**
 * 已配置 API Key 即视为可用；否则回退到拉取 /models 的可用性检测。
 */
final class Wanyesea_AI_Api_Key_Or_List_Models_Provider_Availability implements ProviderAvailabilityInterface {

    /** @var ListModelsApiBasedProviderAvailability */
    private $list_models_availability;

    /** @var string */
    private $provider_id;

    public function __construct(ModelMetadataDirectoryInterface $directory, $provider_id) {
        $this->list_models_availability = new ListModelsApiBasedProviderAvailability($directory);
        $this->provider_id              = sanitize_key((string) $provider_id);
    }

    public function isConfigured(): bool {
        if ($this->has_resolved_or_registry_api_key()) {
            return true;
        }

        if (function_exists('wanyesea_ai_relay_is_provider_active')
            && wanyesea_ai_relay_is_provider_active($this->provider_id)) {
            return true;
        }

        return $this->list_models_availability->isConfigured();
    }

    /**
     * 本插件选项 / Connectors 选项 / Registry 已注入的 Bearer（连接页保存密钥时会先 setProviderRequestAuthentication）。
     */
    private function has_resolved_or_registry_api_key(): bool {
        if (function_exists('wanyesea_ai_get_connector_api_key_resolved')
            && wanyesea_ai_get_connector_api_key_resolved($this->provider_id) !== '') {
            return true;
        }

        if (!class_exists('WordPress\AiClient\AiClient')) {
            return false;
        }

        try {
            $registry = WordPress\AiClient\AiClient::defaultRegistry();
            if (!$registry->hasProvider($this->provider_id)) {
                return false;
            }
            $auth = $registry->getProviderRequestAuthentication($this->provider_id);
            if ($auth instanceof WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication) {
                return trim($auth->getApiKey()) !== '';
            }
        } catch (Throwable $e) {
            return false;
        }

        return false;
    }
}

/**
 * 自定义 Connector 使用的 HTTP 鉴权对象。
 *
 * @return RequestAuthenticationInterface|null
 */
function wanyesea_ai_create_custom_provider_authentication($provider_id, $api_key) {
    $provider_id = sanitize_key((string) $provider_id);
    $api_key     = trim((string) $api_key);

    if ($api_key === '') {
        return null;
    }

    if ($provider_id === 'xiaomi') {
        return new Wanyesea_AI_Mimo_Api_Key_Authentication($api_key);
    }

    return new WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication($api_key);
}

/**
 * 自定义厂商 AI Client Provider 基类。
 */
abstract class Wanyesea_AI_Custom_Api_Provider_Base extends AbstractApiProvider {

    /** @return string */
    public static function providerId() {
        return static::providerIdValue();
    }

    /** @return string */
    abstract protected static function providerIdValue();

    /**
     * @return array<string, mixed>
     */
    protected static function providerDefinition() {
        $id   = static::providerIdValue();
        $defs = Wanyesea_AI_Custom_Connectors::definitions();
        return isset($defs[$id]) && is_array($defs[$id]) ? $defs[$id] : array();
    }

    /**
     * 供 WP AI Client / Connector 审批与 OpenAI 兼容 API 请求；须配置真实官方根地址（见 07-custom-connectors definitions）。
     */
    protected static function baseUrl(): string {
        $url = Wanyesea_AI_Custom_Connectors::get_official_base_url(static::providerId());
        if ($url !== '') {
            return $url;
        }

        throw new RuntimeException(
            sprintf(
                'WanYesea AI: missing official_base_url for provider "%s".',
                static::providerId()
            )
        );
    }

    protected static function createProviderMetadata(): ProviderMetadata {
        $def  = static::providerDefinition();
        $id   = static::providerIdValue();
        $name = isset($def['name']) ? (string) $def['name'] : ucfirst($id);

        $args = array(
            $id,
            $name,
            ProviderTypeEnum::cloud(),
            isset($def['credentials_url']) ? (string) $def['credentials_url'] : null,
            RequestAuthenticationMethod::apiKey(),
        );

        if (version_compare(AiClient::VERSION, '1.2.0', '>=')) {
            $args[] = isset($def['description']) ? (string) $def['description'] : '';
        }
        if (version_compare(AiClient::VERSION, '1.3.0', '>=')) {
            $logo = isset($def['logo']) ? (string) $def['logo'] : '';
            $args[] = $logo !== '' ? WanYesea_AI_path . ltrim($logo, '/\\') : null;
        }

        return new ProviderMetadata(...$args);
    }

    protected static function createProviderAvailability(): ProviderAvailabilityInterface {
        return new Wanyesea_AI_Api_Key_Or_List_Models_Provider_Availability(
            static::modelMetadataDirectory(),
            static::providerId()
        );
    }

    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
        return new Wanyesea_AI_OpenAi_Compatible_Model_Metadata_Directory(static::class, static::providerId());
    }

    protected static function createModel(ModelMetadata $modelMetadata, ProviderMetadata $providerMetadata): ModelInterface {
        foreach ($modelMetadata->getSupportedCapabilities() as $capability) {
            if ($capability->isTextGeneration()) {
                return new Wanyesea_AI_OpenAi_Compatible_Text_Generation_Model(
                    $modelMetadata,
                    $providerMetadata,
                    static::class
                );
            }
            if ($capability->isImageGeneration() && function_exists('wanyesea_ai_create_custom_image_generation_model')) {
                return wanyesea_ai_create_custom_image_generation_model(
                    $modelMetadata,
                    $providerMetadata,
                    static::class
                );
            }
        }

        throw new RuntimeException(
            'Unsupported model capabilities for WanYesea custom connector: ' . $modelMetadata->getId()
        );
    }
}

final class Wanyesea_AI_Provider_Deepseek extends Wanyesea_AI_Custom_Api_Provider_Base {
    protected static function providerIdValue() {
        return 'deepseek';
    }
}

final class Wanyesea_AI_Provider_Moonshot extends Wanyesea_AI_Custom_Api_Provider_Base {
    protected static function providerIdValue() {
        return 'moonshot';
    }
}

final class Wanyesea_AI_Provider_Zhipu extends Wanyesea_AI_Custom_Api_Provider_Base {
    protected static function providerIdValue() {
        return 'zhipu';
    }
}

final class Wanyesea_AI_Provider_Xiaomi extends Wanyesea_AI_Custom_Api_Provider_Base {
    protected static function providerIdValue() {
        return 'xiaomi';
    }
}

final class Wanyesea_AI_Provider_Nvidia extends Wanyesea_AI_Custom_Api_Provider_Base {
    protected static function providerIdValue() {
        return 'nvidia';
    }
}

final class Wanyesea_AI_Provider_Sensenova extends Wanyesea_AI_Custom_Api_Provider_Base {
    protected static function providerIdValue() {
        return 'sensenova';
    }
}

/**
 * 向 WP AI Client 注册自定义 Provider，供 Connector 审批 / 连接页识别。
 */
final class Wanyesea_AI_Custom_Ai_Providers {

    /**
     * @return list<class-string>
     */
    public static function provider_classes() {
        $classes = array(
            Wanyesea_AI_Provider_Deepseek::class,
            Wanyesea_AI_Provider_Moonshot::class,
            Wanyesea_AI_Provider_Zhipu::class,
            Wanyesea_AI_Provider_Xiaomi::class,
            Wanyesea_AI_Provider_Nvidia::class,
            Wanyesea_AI_Provider_Sensenova::class,
        );

        return apply_filters('wanyesea_ai_custom_ai_provider_classes', $classes);
    }

    public static function register_ai_client_providers() {
        if (!class_exists(AiClient::class)) {
            return;
        }

        try {
            $registry = AiClient::defaultRegistry();
        } catch (Throwable $e) {
            return;
        }

        foreach (self::provider_classes() as $class_name) {
            try {
                if (!$registry->hasProvider($class_name)) {
                    $registry->registerProvider($class_name);
                }
            } catch (Throwable $e) {
                continue;
            }
        }
    }
}

add_action('init', array('Wanyesea_AI_Custom_Ai_Providers', 'register_ai_client_providers'), 5);
