<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

function wanyesea_ai_admin_enqueue_assets() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'WanYesea_AI') {
        return;
    }
    $deps = array();
    if (wanyesea_ai_is_zibll_active() && wp_style_is('zib_admin_man', 'registered')) {
        $deps[] = 'zib_admin_man';
    }
    $asset_ver = Wanyesea_AI_Config::get_asset_version();

    wp_enqueue_style(
        'wanyesea-ai-admin',
        WanYesea_AI_url . '/assets/wanyesea-ai-admin.css',
        $deps,
        $asset_ver
    );

    wp_enqueue_script(
        'wanyesea-ai-admin',
        WanYesea_AI_url . '/assets/wanyesea-ai-admin.js',
        array('jquery'),
        $asset_ver,
        true
    );

    $official_providers = class_exists('Wanyesea_AI_Connectors')
        ? Wanyesea_AI_Connectors::provider_ids()
        : array('openai', 'google', 'anthropic');
    $custom_providers = function_exists('wanyesea_ai_custom_connect_provider_ids')
        ? wanyesea_ai_custom_connect_provider_ids()
        : array();

    $env_grid_html = '';
    if (function_exists('wanyesea_ai_connect_env_grid_html')) {
        $env_grid_html = wanyesea_ai_connect_env_grid_html();
    }

    wp_enqueue_script(
        'wanyesea-ai-gateway',
        WanYesea_AI_url . '/assets/wanyesea-ai-gateway.js',
        array('jquery'),
        $asset_ver,
        true
    );

    wp_localize_script(
        'wanyesea-ai-gateway',
        'wanyeseaAiGateway',
        array(
            'restUrl'   => esc_url_raw(rest_url('wanyesea-ai/v1')),
            'restNonce' => wp_create_nonce('wp_rest'),
        )
    );

    $gateway_providers = function_exists('wanyesea_ai_gateway_text_provider_ids')
        ? wanyesea_ai_gateway_text_provider_ids()
        : array();

    wp_localize_script(
        'wanyesea-ai-admin',
        'wanyeseaAiAdmin',
        array(
            'providers'         => array_values(array_unique(array_merge($official_providers, $custom_providers, $gateway_providers))),
            'officialProviders' => $official_providers,
            'customProviders'   => $custom_providers,
            'envGridHtml'       => $env_grid_html,
            'ajaxUrl'           => admin_url('admin-ajax.php'),
            'probeNonce'        => wp_create_nonce('wanyesea_ai_probe'),
            'i18n'              => array(
                'probing'      => '检测中…',
                'probe'        => '检测',
                'probeAll'     => '全部检测',
                'noKey'        => '请先填写 API Key，或在上方环境区配置密钥后再检测',
                'networkError' => '请求失败，请稍后重试',
                'textModels'   => '文本模型',
                'imageModels'  => '图像模型',
                'otherModels'  => '其它模型',
                'latency'      => '耗时',
                'endpoint'     => '端点',
                'httpCode'     => 'HTTP',
            ),
        )
    );
}
add_action('admin_enqueue_scripts', 'wanyesea_ai_admin_enqueue_assets', 20);

if (class_exists('CSF')) {
    $prefix = 'WanYesea_AI';
    $plugin_name    = Wanyesea_AI_Config::get_name();
    $plugin_version = Wanyesea_AI_Config::get_version();

    CSF::createOptions($prefix, array(
        'menu_title' => $plugin_name,
        'menu_slug' => 'WanYesea_AI',
        'framework_title' => $plugin_name . ' <small>v' . esc_html($plugin_version) . '</small>',
        'framework_class' => 'wanyesea-ai-zibll-shell',
        'show_in_customizer' => true,
        'footer_text' => $plugin_name,
        'footer_credit' => '<i class="fa fa-heart" style="color:#ff4757"></i> 感谢使用',
        'theme' => 'light',
    ));

    $options_dir = plugin_dir_path(__FILE__) . 'options/';
    if (file_exists($options_dir)) {
        $option_files = scandir($options_dir);

        foreach ($option_files as $file) {
            if (in_array($file, array('.', '..'))) {
                continue;
            }
            if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                require_once $options_dir . $file;
            }
        }
    }
}
