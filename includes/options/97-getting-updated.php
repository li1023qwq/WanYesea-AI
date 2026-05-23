<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

$local_update_log   = Wanyesea_AI_UpdateLog::get_update_log_html();
$local_version_list = Wanyesea_AI_UpdateLog::get_version_list();
$has_local_log      = !empty($local_version_list);
$version_badge      = Wanyesea_AI_Config::get_version_badge_html();
$latest_line        = Wanyesea_AI_UpdateLog::get_latest_release_line_html();
$github_status      = class_exists('Wanyesea_AI_Github_Updater')
    ? Wanyesea_AI_Github_Updater::get_status_panel_html()
    : '';

$latest_hint = $latest_line !== ''
    ? '<p class="muted-3-color em09">本地日志最新 ' . $latest_line . '</p>'
    : '';

$update_hint = '<p class="muted-3-color em09">GitHub 自动更新已绑定 <a href="https://github.com/li1023qwq/WanYesea-AI" target="_blank" rel="noopener noreferrer">li1023qwq/WanYesea-AI</a>；推送 <code>v*</code> 标签后 Actions 自动打包 Release，WordPress「插件」页可一键升级。</p>';

CSF::createSection($prefix, array(
    'title'  => '更新日志',
    'icon'   => 'fa fa-history',
    'fields' => array(
        array(
            'content' => '
                <div class="wanyesea-update-panel">
                    <p class="em14"><strong>当前版本：</strong>' . $version_badge . '</p>
                    ' . $latest_hint . '
                    ' . $update_hint . '
                </div>
            ',
            'style'   => 'info',
            'type'    => 'submessage',
        ),
        array(
            'content' => $github_status,
            'style'   => 'normal',
            'type'    => 'submessage',
        ),
        array(
            'content' => '
                <div style="color:#8a6d3b;"><h3 style="margin: 0;"><i class="fa fa-fw fa-info-circle fa-fw"></i>更新日志：</h3><br>
                    <div id="update_log" style="background: #f9f9f9; padding: 15px; border-radius: 5px; max-height: 500px; overflow-y: auto;">
                        ' . ($has_local_log ? $local_update_log : '<p style="color:#999;">暂无更新日志</p>') . '
                    </div>
                </div>
            ',
            'style'   => 'info',
            'type'    => 'submessage',
        ),
    ),
));
