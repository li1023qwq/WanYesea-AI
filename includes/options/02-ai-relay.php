<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

$connectors_url  = admin_url('options-connectors.php');
$ai_settings_url = admin_url('options-general.php?page=ai-wp-admin');

/**
 * 解析厂商官方 API 根地址。
 */
function wanyesea_ai_relay_official_base_url($provider_id) {
    $provider_id = sanitize_key((string) $provider_id);
    $urls        = Wanyesea_AI_Relay::official_base_urls();
    return isset($urls[$provider_id]) ? $urls[$provider_id] : '';
}

/**
 * AI 连接 / API 中转设置字段。
 * 总开关关闭时隐藏全部厂商配置区；开启后由「启用中转」控制 API Key 与 Base URL。
 *
 * @return list<array<string, mixed>>
 */
function wanyesea_ai_relay_build_provider_fields($provider_id) {
    $provider_id = sanitize_key((string) $provider_id);
    $meta_all    = wanyesea_ai_connect_provider_meta();
    $meta        = isset($meta_all[$provider_id]) ? $meta_all[$provider_id] : array(
        'label'   => ucfirst($provider_id),
        'icon'    => 'fa fa-plug',
        'tagline' => '',
        'color'   => $provider_id,
    );

    $is_custom = function_exists('wanyesea_ai_is_custom_connect_provider') && wanyesea_ai_is_custom_connect_provider($provider_id);
    $official  = wanyesea_ai_relay_official_base_url($provider_id);

    $block = 'wya-provider-' . sanitize_html_class($provider_id);

    if ($is_custom && class_exists('Wanyesea_AI_Custom_Connectors')) {
        $key_field = Wanyesea_AI_Custom_Connectors::option_field_id($provider_id);
        $key_set   = Wanyesea_AI_Custom_Connectors::is_configured($provider_id);
    } else {
        $key_field = Wanyesea_AI_Connectors::option_field_id($provider_id);
        $key_set   = Wanyesea_AI_Connectors::is_configured($provider_id);
    }

    $badge = $key_set
        ? '<span class="wya-badge wya-badge--ok">已配置</span>'
        : '<span class="wya-badge wya-badge--warn">未配置</span>';

    $relay_on      = Wanyesea_AI_Relay::is_enabled() && wanyesea_ai_switcher_on('relay_' . $provider_id . '_enabled', false);
    $has_relay_url = $relay_on && Wanyesea_AI_Relay::get_provider_base_url($provider_id) !== '';

    $endpoint_badge = $has_relay_url
        ? '<span class="wya-provider-head__relay em09">中转已启用</span>'
        : '<span class="wya-provider-head__official em09">官方端点</span>';

    $probe_btn = '<button type="button" class="button button-small wya-provider-probe-btn" data-wya-probe-run="'
        . esc_attr($provider_id) . '" data-wya-probe-in-card="1">'
        . '<i class="fa fa-refresh"></i> 检测连通</button>';

    $head_html = '<div class="wya-provider-head wya-provider-head--' . esc_attr($meta['color']) . '">'
        . '<div class="wya-provider-head__main">'
        . '<div class="wya-provider-head__brand">'
        . '<span class="wya-provider-head__icon"><i class="' . esc_attr($meta['icon']) . '"></i></span>'
        . '<span class="wya-provider-head__text">'
        . '<span class="wya-provider-head__title">' . esc_html($meta['label']) . '</span>'
        . '<span class="wya-provider-head__tagline">' . esc_html($meta['tagline']) . '</span>'
        . '</span></div>'
        . '<div class="wya-provider-head__actions">' . $badge . $probe_btn . '</div>'
        . '</div>'
        . '<div class="wya-provider-head__meta">'
        . ($official !== '' ? '官方 API：<code>' . esc_html($official) . '</code> ' : '')
        . $endpoint_badge
        . '</div>'
        . '<div class="wya-provider-probe-inline" data-wya-probe-inline="' . esc_attr($provider_id) . '" hidden></div>'
        . '</div>';

    $relay_master_dep = array(array('relay_enabled', '==', '1'));
    $relay_credentials_dep = array(
        array('relay_enabled', '==', '1'),
        array('relay_' . $provider_id . '_enabled', '==', '1'),
    );

    $key_subtitle = $is_custom
        ? '与「设置 → 连接」双向同步；留空保存保持原密钥，输入 REMOVE 可清除'
        : '在此填写即可，无需再去「设置 → 连接」；留空保存保持原密钥，输入 REMOVE 可清除';

    $base_desc = $official !== ''
        ? '留空且未启用中转时使用官方：<code>' . esc_html($official) . '</code>。须含版本路径，末尾不要 <code>/</code>。'
        : '须含版本路径，末尾不要加 <code>/</code>。';

    $fields   = array();
    $fields[] = array(
        'type'       => 'submessage',
        'class'      => 'wya-provider-block-start ' . $block,
        'content'    => $head_html,
        'dependency' => $relay_master_dep,
    );

    $fields[] = array(
        'id'         => 'relay_' . $provider_id . '_enabled',
        'type'       => 'switcher',
        'class'      => 'wya-provider-block-field wya-provider-block-field--relay ' . $block,
        'title'      => '启用中转',
        'subtitle'   => '关闭时使用官方 API；开启后显示 API Key 与中转 Base URL',
        'default'    => false,
        'dependency' => $relay_master_dep,
    );

    $fields[] = array(
        'id'          => $key_field,
        'type'        => 'text',
        'class'       => 'wya-provider-block-field wya-provider-block-field--key ' . $block,
        'title'       => 'API Key',
        'subtitle'    => $key_subtitle,
        'placeholder' => $key_set ? '留空则不修改' : 'sk-...',
        'default'     => '',
        'dependency'  => $relay_credentials_dep,
        'attributes'  => array(
            'type'               => 'password',
            'autocomplete'       => 'new-password',
            'data-wya-key-input' => $provider_id,
        ),
    );

    $fields[] = array(
        'id'          => 'relay_' . $provider_id . '_base_url',
        'type'        => 'text',
        'class'       => 'wya-provider-block-field wya-provider-block-field--url ' . $block,
        'title'       => '中转 Base URL',
        'subtitle'    => '仅在中转启用后生效',
        'placeholder' => $official !== '' ? $official : 'https://your-proxy.com/v1',
        'default'     => '',
        'desc'        => $base_desc,
        'dependency'  => $relay_credentials_dep,
    );

    $fields[] = array(
        'type'       => 'submessage',
        'class'      => 'wya-provider-block-end ' . $block,
        'content'    => '',
        'dependency' => $relay_master_dep,
    );

    return $fields;
}

/**
 * @return list<array<string, mixed>>
 */
function wanyesea_ai_relay_all_provider_fields() {
    $fields = array();
    foreach (wanyesea_ai_connect_provider_ids() as $provider_id) {
        $fields = array_merge($fields, wanyesea_ai_relay_build_provider_fields($provider_id));
    }
    return $fields;
}

CSF::createSection($prefix, array(
    'title' => 'AI 连接',
    'icon'  => 'fa fa-plug',
    'class' => 'wya-section-ai-connect',
    'fields' => array_merge(
        array(
            array(
                'type'  => 'submessage',
                'class' => 'wya-ai-intro-field',
                'style' => 'info',
                'content' => '<div class="wya-ai-hero">
                    <div class="wya-ai-hero__main">
                        <h3 class="wya-ai-hero__title"><i class="fa fa-plug fa-fw"></i>AI 连接与中转</h3>
                        <p>适配 <a href="https://github.com/WordPress/ai" target="_blank" rel="noopener noreferrer">WordPress AI</a> 官方 Provider 与自定义 Connector（DeepSeek、Moonshot、智谱、小米 MiMo、NVIDIA、SenseNova 等）。<strong>默认走各厂商官方 API</strong>；开启「API 中转」并为对应厂商启用中转后，可填写 API Key 与网关 Base URL。</p>
                    </div>
                    <div class="wya-ai-hero__actions">
                        <a class="button button-primary" href="' . esc_url($ai_settings_url) . '">AI 功能设置</a>
                        <a class="button" href="' . esc_url($connectors_url) . '">官方连接页（可选）</a>
                        <a class="button" href="' . esc_url(admin_url('options-general.php?page=wanyesea-ai-test-lab')) . '">AI 能力测试</a>
                    </div>
                </div>',
            ),
            array(
                'type'  => 'submessage',
                'class' => 'wya-ai-env-field',
                'style' => 'normal',
                'content' => '<p class="wya-ai-env-field__title"><strong>环境检测</strong></p><p class="muted-3-color em09" style="margin:0 0 12px">点击各厂商「检测」可验证 API 是否连通并列出可用模型；支持使用表单中尚未保存的 API Key。</p>'
                    . '<div id="wya-connect-env-grid" class="wya-connect-env-grid-mount"><p class="muted-3-color em09">正在加载环境状态…</p></div>',
            ),
            array(
                'type'  => 'submessage',
                'class' => 'wya-ai-relay-panel-field',
                'style' => 'normal',
                'content' => '<div class="wya-relay-panel-head">
                    <span class="wya-relay-panel-head__icon"><i class="fa fa-exchange"></i></span>
                    <div>
                        <strong>API 中转</strong>
                        <p class="muted-3-color em09">关闭时隐藏全部厂商配置区；开启后可为各厂商单独启用中转（未启用中转时仍走官方端点）。</p>
                    </div>
                </div>',
            ),
            array(
                'id'      => 'relay_enabled',
                'type'    => 'switcher',
                'class'   => 'wya-relay-master-field',
                'title'   => '启用 API 中转',
                'subtitle' => '关闭后隐藏全部厂商（含自定义 Connector）；开启后由「启用中转」控制 API Key 与 Base URL',
                'default' => false,
            ),
        ),
        wanyesea_ai_relay_all_provider_fields(),
        array(
            array(
                'type'  => 'submessage',
                'class' => 'wya-ai-tips-field',
                'style' => 'info',
                'content' => '<details class="wya-ai-tips">
                    <summary><i class="fa fa-lightbulb-o"></i> 使用说明</summary>
                    <ul>
                        <li><strong>默认官方</strong>：未开启「启用中转」或 Base URL 留空时，HTTP 请求保持各厂商官方域名。</li>
                        <li>须先开启「启用 API 中转」，再为对应厂商开启「启用中转」，才会显示 API Key 与中转 Base URL。</li>
                        <li>自定义 Connector 的 API Key 与「设置 → 连接」双向同步；也可仅在连接页配置。</li>
                        <li><strong>图像生成</strong>支持 <strong>Google（Gemini / Imagen）</strong>、<strong>OpenAI（gpt-image）</strong> 或 <strong>SenseNova（sensenova-u1-fast 信息图）</strong>；DeepSeek、Moonshot 等其它自定义 Connector 仅用于文本。</li>
                        <li>未开启「API 中转」时，可在 <a href="' . esc_url($connectors_url) . '">设置 → 连接</a> 填写 Google / OpenAI 密钥。</li>
                        <li>使用 One API / New API：填写网关 API Key 与兼容的 Base URL。</li>
                        <li>「设置 → 连接」的「已连接」：开启中转且已填密钥时，与测试页「中转可用」同源判定；保存密钥前会预热中转 OpenAI Provider。</li>
                        <li>环境变量 / 常量密钥：<code>WANYESEA_AI_OPENAI_API_KEY</code>、<code>WANYESEA_AI_DEEPSEEK_API_KEY</code> 等（优先于后台选项）</li>
                        <li>多网关集中管理见侧栏 <strong>AI 统一网关</strong>（独立 Provider，支持 Anthropic Messages）</li>
                        <li>过滤器：<code>wanyesea_ai_official_base_urls</code>、<code>wanyesea_ai_custom_official_base_urls</code>、<code>wanyesea_ai_relay_base_url</code>、<code>wanyesea_ai_provider_probe_timeout</code>、<code>wanyesea_ai_provider_probe_result</code>、<code>wanyesea_ai_relay_models_validation_timeout</code>、<code>wanyesea_ai_github_updater_config</code></li>
                    </ul>
                </details>',
            ),
        )
    ),
));
