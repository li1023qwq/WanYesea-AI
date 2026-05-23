<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

$plugin_name  = Wanyesea_AI_Config::get_name();
$description  = Wanyesea_AI_Config::get_description();
$author_uri   = Wanyesea_AI_Config::get_author_uri();
$plugin_uri   = Wanyesea_AI_Config::get_plugin_uri();
$version_badge = Wanyesea_AI_Config::get_version_badge_html();
$env_html     = Wanyesea_AI_Config::get_env_requirements_html();

$author_link = $author_uri !== ''
    ? '<a target="_blank" rel="noopener noreferrer" href="' . esc_url($author_uri) . '">' . esc_html($author_uri) . '</a>'
    : '';

$desc_block = $description !== ''
    ? '<p>' . esc_html($description) . '</p>'
    : '';

// 开始&使用
CSF::createSection($prefix, array(
    'title'  => '开始&使用',
    'icon'   => 'fa fa-magic',
    'fields' => array(
        array(
            'type'    => 'submessage',
            'style'   => '',
            'content' => '<div class="wanyesea-welcome-card">
                <h3 class="wanyesea-welcome-card__title">
                    <i class="fa fa-magic fa-fw"></i>欢迎使用' . esc_html($plugin_name) . '
                    ' . $version_badge . '
                </h3>
                ' . $desc_block . '
                <p><strong>环境要求</strong>：' . $env_html . '。</p>
                <p><strong>推荐流程</strong>：</p>
                <ol class="wanyesea-welcome-card__steps">
                    <li>打开 <strong>AI 连接</strong>，确认顶部环境检测均为就绪；</li>
                    <li>开启 <strong>启用 API 中转</strong>，为需走网关的厂商打开 <strong>启用中转</strong>；</li>
                    <li>填写该厂商的 <strong>API Key</strong> 与 <strong>中转 Base URL</strong> 后保存（将自动同步至 Connectors）；</li>
                    <li>在 WordPress「设置 → AI」中启用所需实验功能。</li>
                </ol>
                <p class="muted-3-color em09">配置 GitHub 仓库后可在 WordPress「插件」页一键更新；未配置时请在「更新日志」查看说明并手动替换插件文件。</p>
                <ul class="wanyesea-welcome-card__links">
                <li>子比主题官网：<a target="_blank" rel="noopener noreferrer" href="https://www.zibll.com/">https://www.zibll.com/</a></li>
                ' . ($author_link !== '' ? '<li>作者官网：' . $author_link . '</li>' : '') . '
                <li>WordPress AI：<a target="_blank" rel="noopener noreferrer" href="https://github.com/WordPress/ai">github.com/WordPress/ai</a></li>
                </ul>
                </div>',
        ),
    ),
));
