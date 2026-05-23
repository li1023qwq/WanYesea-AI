<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

$connectors_url = admin_url('options-connectors.php');

CSF::createSection($prefix, array(
    'title'  => 'AI 统一网关',
    'icon'   => 'fa fa-cloud',
    'class'  => 'wya-section-ai-gateway',
    'fields' => array(
        array(
            'type'    => 'submessage',
            'class'   => 'wya-gateway-intro-field',
            'style'   => 'info',
            'content' => '<div class="wya-gateway-hero wya-ai-hero">
                <div class="wya-gateway-hero__main wya-ai-hero__main">
                    <h3 class="wya-gateway-hero__title wya-ai-hero__title"><i class="fa fa-cloud fa-fw"></i>多中转统一网关</h3>
                    <p>在 WordPress AI Client 中注册独立 Provider（<code>wanyesea-gateway</code>、<code>wanyesea-gateway-*</code>），支持 <strong>OpenAI Compatible</strong> 与 <strong>Anthropic Messages</strong>。与「AI 连接」里按厂商改写官方 URL 的方式<strong>并存</strong>。</p>
                    <ul class="wya-gateway-tips muted-3-color em09">
                        <li>根地址填 <code>https://你的网关.com</code>，勿填完整 <code>/v1/chat/completions</code></li>
                        <li>API Key 同步至 <a href="' . esc_url($connectors_url) . '">设置 → 连接</a>，或使用 <code>WANYESEA_AI_WANYESEA_GATEWAY_API_KEY</code></li>
                        <li>获取模型后勾选能力；Anthropic 模式勿配置生图</li>
                        <li><strong>修改后自动保存</strong>，切换标签或刷新后从服务器重新加载</li>
                    </ul>
                </div>
                <div class="wya-gateway-hero__aside">
                    <span class="wya-gateway-hero__pill"><i class="fa fa-plug"></i> 独立 Provider</span>
                    <span class="wya-gateway-hero__pill"><i class="fa fa-exchange"></i> 多站点</span>
                </div>
            </div>',
        ),
        array(
            'type'    => 'submessage',
            'class'   => 'wya-gateway-mount-field',
            'content' => '<div id="wya-gateway-app" class="wya-gateway-app" data-wya-gateway-app>
                <p class="muted-3-color em09">正在加载网关配置…</p>
            </div>',
        ),
    ),
));
