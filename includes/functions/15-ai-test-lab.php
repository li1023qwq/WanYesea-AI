<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

const WANYESEA_AI_TEST_LAB_PAGE_SLUG = 'wanyesea-ai-test-lab';

/**
 * 注册「设置 → 晚夜深秋-AI测试」。
 */
function wanyesea_ai_test_lab_register_menu() {
    add_options_page(
        '晚夜深秋-AI测试',
        '晚夜深秋-AI测试',
        'manage_options',
        WANYESEA_AI_TEST_LAB_PAGE_SLUG,
        'wanyesea_ai_test_lab_render_page'
    );
}

add_action('admin_menu', 'wanyesea_ai_test_lab_register_menu');

/**
 * 注入 AI Client 鉴权（与正式生成一致）。
 */
function wanyesea_ai_test_lab_ensure_auth() {
    if (function_exists('wanyesea_ai_ensure_ai_client_auth')) {
        wanyesea_ai_ensure_ai_client_auth();
        return;
    }
    if (function_exists('wanyesea_ai_inject_custom_provider_auth')) {
        wanyesea_ai_inject_custom_provider_auth();
    }
    if (function_exists('wanyesea_ai_inject_gateway_provider_auth')) {
        wanyesea_ai_inject_gateway_provider_auth();
    }
    if (class_exists('Wanyesea_AI_Connectors')) {
        Wanyesea_AI_Connectors::inject_ai_client_auth();
    }
    if (function_exists('wanyesea_ai_wrap_relay_official_metadata_directories')) {
        wanyesea_ai_wrap_relay_official_metadata_directories();
    }
}

/**
 * 列出厂商可用模型 ID。
 *
 * @param string $capability    text|image
 * @param bool   $force_refresh 加载模型 AJAX 应传 true，避免沿用空的探测缓存
 * @return list<string>
 */
function wanyesea_ai_test_lab_list_model_ids($provider_id, $capability = 'text', $force_refresh = false) {
    $provider_id = sanitize_key((string) $provider_id);
    $capability  = $capability === 'image' ? 'image' : 'text';

    wanyesea_ai_test_lab_ensure_auth();

    if (class_exists('Wanyesea_AI_Gateway_Settings', false)
        && Wanyesea_AI_Gateway_Settings::is_gateway_provider_id($provider_id)) {
        $local = wanyesea_ai_gateway_model_ids_for_capability($provider_id, $capability);
        if ($local !== array()) {
            return apply_filters('wanyesea_ai_test_lab_model_ids', $local, $provider_id, $capability);
        }
    }

    $is_custom = function_exists('wanyesea_ai_is_custom_connect_provider')
        && wanyesea_ai_is_custom_connect_provider($provider_id);

    if (!$is_custom
        && function_exists('wanyesea_ai_relay_is_provider_active')
        && wanyesea_ai_relay_is_provider_active($provider_id)
        && function_exists('wanyesea_ai_probe_model_ids_for_capability')) {
        $ids = wanyesea_ai_probe_model_ids_for_capability($provider_id, $capability, $force_refresh);
        if ($ids !== array()) {
            return apply_filters('wanyesea_ai_test_lab_model_ids', $ids, $provider_id, $capability);
        }
    }

    if (!class_exists(AiClient::class)) {
        return array();
    }

    $ids = array();

    if ($capability === 'text'
        && !$is_custom
        && function_exists('wanyesea_ai_discover_official_provider_text_model_ids')) {
        $ids = wanyesea_ai_discover_official_provider_text_model_ids($provider_id);
    }

    if ($ids === array()) {
        try {
            $registry = AiClient::defaultRegistry();
            if (!$registry->hasProvider($provider_id)) {
                if (function_exists('wanyesea_ai_probe_model_ids_for_capability')) {
                    return apply_filters(
                        'wanyesea_ai_test_lab_model_ids',
                        wanyesea_ai_probe_model_ids_for_capability($provider_id, $capability),
                        $provider_id,
                        $capability
                    );
                }
                return array();
            }

            $cap_enum = $capability === 'image'
                ? CapabilityEnum::imageGeneration()
                : CapabilityEnum::textGeneration();

            $requirements = new ModelRequirements(array($cap_enum), array());
            $metadata_list = $registry->findProviderModelsMetadataForSupport($provider_id, $requirements);
        } catch (Throwable $e) {
            $metadata_list = array();
        }

        foreach ($metadata_list as $metadata) {
            $ids[] = $metadata->getId();
        }

        if ($ids === array() && function_exists('wanyesea_ai_probe_model_ids_for_capability')) {
            $ids = wanyesea_ai_probe_model_ids_for_capability($provider_id, $capability);
        }
    }

    if ($capability === 'text' && function_exists('wanyesea_ai_filter_chat_text_model_ids')) {
        $ids = wanyesea_ai_filter_chat_text_model_ids($ids);
    }

    return apply_filters('wanyesea_ai_test_lab_model_ids', $ids, $provider_id, $capability);
}

/**
 * @return list<string>
 */
function wanyesea_ai_test_lab_text_provider_ids() {
    if (function_exists('wanyesea_ai_text_capable_provider_ids')) {
        return wanyesea_ai_text_capable_provider_ids();
    }

    return array();
}

/**
 * @return list<string>
 */
function wanyesea_ai_test_lab_image_provider_ids() {
    if (function_exists('wanyesea_ai_image_capable_provider_ids')) {
        return wanyesea_ai_image_capable_provider_ids();
    }

    return array();
}

/**
 * 后台资源。
 */
function wanyesea_ai_test_lab_enqueue_assets($hook) {
    if ($hook !== 'settings_page_' . WANYESEA_AI_TEST_LAB_PAGE_SLUG) {
        return;
    }

    $ver = Wanyesea_AI_Config::get_asset_version();

    wp_enqueue_style(
        'wanyesea-ai-test-lab',
        WanYesea_AI_url . '/assets/wanyesea-ai-test-lab.css',
        array(),
        $ver
    );

    wp_enqueue_script(
        'wanyesea-ai-test-lab',
        WanYesea_AI_url . '/assets/wanyesea-ai-test-lab.js',
        array('jquery'),
        $ver,
        true
    );

    $meta = function_exists('wanyesea_ai_connect_provider_meta') ? wanyesea_ai_connect_provider_meta() : array();

    wp_localize_script(
        'wanyesea-ai-test-lab',
        'wanyeseaAiTestLab',
        array(
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('wanyesea_ai_test_lab'),
            'probeNonce'    => wp_create_nonce('wanyesea_ai_probe'),
            'settingsUrl'   => admin_url('admin.php?page=WanYesea_AI'),
            'connectorsUrl' => admin_url('options-connectors.php'),
            'textProviders' => wanyesea_ai_test_lab_text_provider_ids(),
            'imageProviders'=> wanyesea_ai_test_lab_image_provider_ids(),
            'providerMeta'  => $meta,
            'defaults'      => array(
                'textPrompt'  => '请用一句话介绍你自己，不超过 30 字。',
                'imagePrompt' => 'A minimal flat icon of a moon over calm sea, teal and white, no text.',
            ),
            'i18n'          => array(
                'probe'         => '检测端点',
                'loadModels'    => '加载模型',
                'testText'      => '测试文本',
                'testImage'     => '测试出图',
                'working'       => '执行中…',
                'noKey'         => '未配置 API Key',
                'pickModel'     => '请先选择模型',
                'networkError'  => '请求失败',
            ),
        )
    );
}

add_action('admin_enqueue_scripts', 'wanyesea_ai_test_lab_enqueue_assets');

/**
 * 渲染厂商测试卡片。
 *
 * @param string $provider_id
 * @param string $section     text|image
 */
function wanyesea_ai_test_lab_render_provider_card($provider_id, $section) {
    $provider_id = sanitize_key((string) $provider_id);
    $section     = $section === 'image' ? 'image' : 'text';
    $meta_all    = function_exists('wanyesea_ai_connect_provider_meta') ? wanyesea_ai_connect_provider_meta() : array();
    $meta        = isset($meta_all[$provider_id]) ? $meta_all[$provider_id] : array(
        'label' => ucfirst($provider_id),
        'icon'  => 'fa fa-plug',
        'color' => $provider_id,
    );

    $endpoint = function_exists('wanyesea_ai_get_provider_effective_endpoint')
        ? wanyesea_ai_get_provider_effective_endpoint($provider_id)
        : array('url' => '', 'mode' => '');

    $has_key = function_exists('wanyesea_ai_get_connector_api_key_resolved')
        && wanyesea_ai_get_connector_api_key_resolved($provider_id) !== '';

    $registry = function_exists('wanyesea_ai_provider_registry_status')
        ? wanyesea_ai_provider_registry_status($provider_id)
        : array('registry_ok' => false);

    $models = wanyesea_ai_test_lab_list_model_ids($provider_id, $section);

    $color_class = sanitize_html_class($meta['color'] ?? $provider_id);
    $endpoint_url = $endpoint['url'] !== '' ? $endpoint['url'] : '—';
    $mode_label   = $endpoint['mode'] === 'relay' ? '中转' : ($endpoint['mode'] === 'gateway' ? '统一网关' : ($endpoint['mode'] === 'official' ? '官方' : '—'));

    echo '<div class="wya-test-card wya-test-card--' . esc_attr($color_class) . '" data-wya-test-provider="' . esc_attr($provider_id) . '" data-wya-test-section="' . esc_attr($section) . '">';
    echo '<div class="wya-test-card__head">';
    echo '<span class="wya-test-card__icon"><i class="' . esc_attr($meta['icon'] ?? 'fa fa-plug') . '"></i></span>';
    echo '<div class="wya-test-card__title">';
    echo '<strong>' . esc_html($meta['label'] ?? $provider_id) . '</strong>';
    echo '<span class="wya-test-card__id"><code>' . esc_html($provider_id) . '</code></span>';
    echo '</div>';
    echo '<div class="wya-test-card__badges">';
    echo $has_key
        ? '<span class="wya-badge wya-badge--ok">有密钥</span>'
        : '<span class="wya-badge wya-badge--warn">无密钥</span>';
    if (!empty($registry['registry_ok'])) {
        $verified_label = ($endpoint['mode'] === 'relay')
            ? '中转可用'
            : (($endpoint['mode'] === 'gateway') ? '网关可用' : 'AI Client 已校验');
        echo '<span class="wya-badge wya-badge--ok">' . esc_html($verified_label) . '</span>';
    } else {
        echo '<span class="wya-badge wya-badge--warn">未校验</span>';
    }
    echo '</div></div>';

    echo '<p class="wya-test-card__endpoint"><code>' . esc_html($endpoint_url) . '</code> <span class="em09 muted-3-color">' . esc_html($mode_label) . '</span></p>';

    echo '<div class="wya-test-card__toolbar">';
    echo '<button type="button" class="button button-small" data-wya-test-probe="' . esc_attr($provider_id) . '"><i class="fa fa-refresh"></i> 检测端点</button>';
    echo '<button type="button" class="button button-small" data-wya-test-load-models="' . esc_attr($provider_id) . '" data-wya-test-cap="' . esc_attr($section) . '">加载模型</button>';
    echo '</div>';

    echo '<label class="wya-test-card__label">模型</label>';
    echo '<select class="wya-test-card__model" data-wya-test-model="' . esc_attr($provider_id) . '">';
    echo '<option value="">— 选择模型 —</option>';
    foreach ($models as $model_id) {
        echo '<option value="' . esc_attr($model_id) . '">' . esc_html($model_id) . '</option>';
    }
    echo '</select>';

    if ($section === 'text') {
        echo '<label class="wya-test-card__label">文本提示词</label>';
        echo '<textarea class="wya-test-card__prompt" rows="2" data-wya-test-prompt-text="' . esc_attr($provider_id) . '">请用一句话介绍你自己，不超过 30 字。</textarea>';
        echo '<button type="button" class="button button-primary" data-wya-test-run-text="' . esc_attr($provider_id) . '">测试文本生成</button>';
    } else {
        echo '<label class="wya-test-card__label">图像提示词（英文效果更佳）</label>';
        echo '<textarea class="wya-test-card__prompt" rows="2" data-wya-test-prompt-image="' . esc_attr($provider_id) . '">A minimal flat icon of a moon over calm sea, teal and white, no text.</textarea>';
        echo '<button type="button" class="button button-primary" data-wya-test-run-image="' . esc_attr($provider_id) . '">测试图像生成</button>';
    }

    echo '<div class="wya-test-card__result" data-wya-test-result="' . esc_attr($provider_id) . '" hidden></div>';
    echo '</div>';
}

/**
 * 设置子页面 HTML。
 */
function wanyesea_ai_test_lab_render_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('您没有权限访问此页面。'));
    }

    $plugin_name = Wanyesea_AI_Config::get_name();
    $settings_url = admin_url('admin.php?page=WanYesea_AI');
    ?>
    <div class="wrap wya-test-lab-wrap">
        <h1><?php echo esc_html('晚夜深秋-AI测试'); ?>
            <span class="wya-version-badge"><?php echo esc_html(Wanyesea_AI_Config::get_version_label()); ?></span>
        </h1>
        <p class="description">
            在此对各厂商的<strong>文本模型</strong>与<strong>图像能力</strong>做真实调用测试（走 WP AI Client，与写文章生成标题 / 出图相同链路）。
            API Key 请在 <a href="<?php echo esc_url($settings_url); ?>"><?php echo esc_html($plugin_name); ?> → AI 连接 / AI 统一网关</a> 或
            <a href="<?php echo esc_url(admin_url('options-connectors.php')); ?>">设置 → 连接</a> 中配置。
        </p>

        <div class="wya-test-lab-panels">
            <section class="wya-test-lab-panel" id="wya-test-panel-text">
                <h2>文本模型测试</h2>
                <p class="description">对每个已配置密钥的厂商选择模型并发送短提示词，验证 <code>chat/completions</code> 是否可用。</p>
                <div class="wya-test-lab-grid">
                    <?php
                    foreach (wanyesea_ai_test_lab_text_provider_ids() as $provider_id) {
                        wanyesea_ai_test_lab_render_provider_card($provider_id, 'text');
                    }
                    ?>
                </div>
            </section>

            <section class="wya-test-lab-panel" id="wya-test-panel-image">
                <h2>图像能力测试</h2>
                <p class="description">支持 Google、OpenAI、SenseNova 等出图模型；生成可能需 30～180 秒。</p>
                <div class="wya-test-lab-grid">
                    <?php
                    foreach (wanyesea_ai_test_lab_image_provider_ids() as $provider_id) {
                        wanyesea_ai_test_lab_render_provider_card($provider_id, 'image');
                    }
                    ?>
                </div>
            </section>
        </div>
    </div>
    <?php
}

/**
 * AJAX：加载模型列表。
 */
function wanyesea_ai_ajax_test_lab_models() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => '权限不足'), 403);
    }

    check_ajax_referer('wanyesea_ai_test_lab', 'nonce');

    $provider_id = isset($_POST['provider_id']) ? sanitize_key((string) $_POST['provider_id']) : '';
    $capability  = isset($_POST['capability']) && $_POST['capability'] === 'image' ? 'image' : 'text';

    if ($provider_id === '') {
        wp_send_json_error(array('message' => '无效的厂商'), 400);
    }

    $models = wanyesea_ai_test_lab_list_model_ids($provider_id, $capability, true);

    wp_send_json_success(array(
        'provider_id' => $provider_id,
        'capability'  => $capability,
        'models'      => $models,
    ));
}

add_action('wp_ajax_wanyesea_ai_test_lab_models', 'wanyesea_ai_ajax_test_lab_models');

/**
 * AJAX：测试文本生成。
 */
function wanyesea_ai_ajax_test_lab_text() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => '权限不足'), 403);
    }

    check_ajax_referer('wanyesea_ai_test_lab', 'nonce');

    if (!function_exists('wp_ai_client_prompt')) {
        wp_send_json_error(array('message' => '未检测到 WP AI Client'), 500);
    }

    $provider_id = isset($_POST['provider_id']) ? sanitize_key((string) $_POST['provider_id']) : '';
    $model_id    = isset($_POST['model_id']) ? (function_exists('wanyesea_ai_normalize_model_id')
        ? wanyesea_ai_normalize_model_id((string) $_POST['model_id'])
        : trim(wp_unslash((string) $_POST['model_id']))) : '';
    $prompt      = isset($_POST['prompt']) ? sanitize_textarea_field(wp_unslash((string) $_POST['prompt'])) : '';

    if ($provider_id === '' || $model_id === '') {
        wp_send_json_error(array('message' => '请选择厂商与模型'), 400);
    }

    if ($prompt === '') {
        $prompt = '请用一句话介绍你自己，不超过 30 字。';
    }

    wanyesea_ai_test_lab_ensure_auth();

    $started = microtime(true);

    if (!function_exists('wanyesea_ai_test_lab_generate_text')) {
        wp_send_json_error(array('message' => '测试模块未加载'), 500);
    }

    $text = wanyesea_ai_test_lab_generate_text($provider_id, $model_id, $prompt);

    if (is_wp_error($text)) {
        wp_send_json_error(array(
            'message' => $text->get_error_message(),
            'code'    => $text->get_error_code(),
        ));
    }

    $gliner = function_exists('wanyesea_ai_parse_nvidia_gliner_pii_response')
        ? wanyesea_ai_parse_nvidia_gliner_pii_response($text)
        : null;

    if ($gliner !== null) {
        $text = $gliner['formatted'];
    }

    wp_send_json_success(array(
        'provider_id' => $provider_id,
        'model_id'    => $model_id,
        'text'        => $text,
        'gliner'      => $gliner,
        'latency_ms'  => (int) round((microtime(true) - $started) * 1000),
    ));
}

add_action('wp_ajax_wanyesea_ai_test_lab_text', 'wanyesea_ai_ajax_test_lab_text');

/**
 * AJAX：测试图像生成。
 */
function wanyesea_ai_ajax_test_lab_image() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => '权限不足'), 403);
    }

    check_ajax_referer('wanyesea_ai_test_lab', 'nonce');

    if (!function_exists('wp_ai_client_prompt')) {
        wp_send_json_error(array('message' => '未检测到 WP AI Client'), 500);
    }

    $provider_id = isset($_POST['provider_id']) ? sanitize_key((string) $_POST['provider_id']) : '';
    $model_id    = isset($_POST['model_id']) ? (function_exists('wanyesea_ai_normalize_model_id')
        ? wanyesea_ai_normalize_model_id((string) $_POST['model_id'])
        : trim(wp_unslash((string) $_POST['model_id']))) : '';
    $prompt      = isset($_POST['prompt']) ? sanitize_textarea_field(wp_unslash((string) $_POST['prompt'])) : '';

    if ($provider_id === '' || $model_id === '') {
        wp_send_json_error(array('message' => '请选择厂商与模型'), 400);
    }

    if ($prompt === '') {
        $prompt = 'A minimal flat icon of a moon over calm sea, teal and white, no text.';
    }

    wanyesea_ai_test_lab_ensure_auth();

    $started = microtime(true);
    $timeout = (float) apply_filters('wanyesea_ai_test_lab_image_timeout', 180.0, $provider_id);

    try {
        $options = RequestOptions::fromArray(array('timeout' => $timeout));
        $builder = wp_ai_client_prompt($prompt)
            ->using_provider($provider_id)
            ->using_model_preference(array($provider_id, $model_id))
            ->using_request_options($options)
            ->as_output_file_type(FileTypeEnum::inline());

        $file = $builder->generate_image();
    } catch (Throwable $e) {
        wp_send_json_error(array(
            'message' => $e->getMessage(),
        ), 500);
    }

    if (is_wp_error($file)) {
        wp_send_json_error(array(
            'message' => $file->get_error_message(),
            'code'    => $file->get_error_code(),
        ), 500);
    }

    try {
        $mime = $file->getMimeType() ?: 'image/png';
        $b64  = $file->getBase64Data();
    } catch (Throwable $e) {
        wp_send_json_error(array('message' => $e->getMessage()), 500);
    }

    if ($b64 === null || $b64 === '') {
        wp_send_json_error(array('message' => '未返回图像数据（可能仅返回 URL，请检查中转或 SenseNova CDN）'), 500);
    }

    wp_send_json_success(array(
        'provider_id' => $provider_id,
        'model_id'    => $model_id,
        'mime'        => $mime,
        'base64'      => $b64,
        'data_url'    => 'data:' . $mime . ';base64,' . $b64,
        'latency_ms'  => (int) round((microtime(true) - $started) * 1000),
    ));
}

add_action('wp_ajax_wanyesea_ai_test_lab_image', 'wanyesea_ai_ajax_test_lab_image');
