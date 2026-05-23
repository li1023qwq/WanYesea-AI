<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

$ai_settings_url = admin_url('options-general.php?page=ai-wp-admin');
$connectors_url  = admin_url('options-connectors.php');
$locale_label    = function_exists('get_user_locale') ? get_user_locale() : get_locale();
$zh_site         = function_exists('wanyesea_ai_i18n_locale_is_chinese') && wanyesea_ai_i18n_locale_is_chinese();

CSF::createSection($prefix, array(
    'title'  => '界面汉化',
    'icon'   => 'fa fa-language',
    'class'  => 'wya-section-ai-i18n',
    'fields' => array(
        array(
            'type'    => 'submessage',
            'style'   => 'info',
            'content' => '<div class="wya-i18n-hero">
                <h3 class="wya-i18n-hero__title"><i class="fa fa-language fa-fw"></i>WordPress AI 界面汉化</h3>
                <p>官方 <code>ai</code> 插件目前尚无完整中文语言包。开启后，本插件通过 <strong>gettext + wp.i18n + DOM 动态替换</strong>（参考 chuyi-ai-relay 方案）翻译「<a href="' . esc_url($ai_settings_url) . '">设置 → AI</a>」、区块编辑器 AI 工具等界面，覆盖 React 动态渲染的英文文案。</p>
                <p class="muted-3-color em09">词条表见 <code>includes/i18n/ai-zh-cn-strings.php</code>（707 条）。官方发布语言包后可在下方关闭本功能。</p>
                <ul class="wya-i18n-hero__links">
                    <li>当前界面语言：<code>' . esc_html($locale_label) . '</code>' . ($zh_site ? '（已识别为中文，可启用汉化）' : '（非中文环境时本功能不生效）') . '</li>
                    <li><a href="' . esc_url($ai_settings_url) . '">设置 → AI</a></li>
                    <li><a href="' . esc_url($connectors_url) . '">设置 → 连接</a>（连接器页由 WordPress 核心翻译，通常已为中文）</li>
                </ul>
            </div>',
        ),
        array(
            'id'       => 'wp_ai_zh_i18n_enabled',
            'type'     => 'switcher',
            'title'    => '启用 WordPress AI 界面汉化',
            'subtitle' => '仅当站点或用户语言为中文（zh_*）时生效；关闭后恢复英文原文',
            'default'  => true,
        ),
        array(
            'type'    => 'submessage',
            'style'   => 'warning',
            'content' => '<details class="wya-i18n-tips">
                <summary><i class="fa fa-info-circle"></i> 说明与限制</summary>
                <ul>
                    <li>词条表为<strong>运行时维护</strong>（707 条），随官方 AI 版本更新可能有个别新字符串仍为英文；可在 <code>includes/i18n/ai-zh-cn-strings.php</code> 增补，或使用过滤器 <code>wanyesea_ai_i18n_strings</code>。</li>
                    <li>支持 DOM MutationObserver 自动翻译动态插入的节点（设置页 React 界面、区块编辑器等）。</li>
                    <li>不翻译其它插件（如 <code>ai-provider-for-openai</code>）的 text domain；连接器名称等多为品牌名，保持原文。</li>
                    <li>含占位符（<code>%s</code>、<code>%d</code>）的句子会保留占位符顺序，请勿删改词条中的占位符。</li>
                    <li>过滤器：<code>wanyesea_ai_i18n_enabled</code>、<code>wanyesea_ai_i18n_strings</code></li>
                </ul>
            </details>',
        ),
    ),
));
