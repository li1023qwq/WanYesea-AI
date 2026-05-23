<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

$local_update_log   = Wanyesea_AI_UpdateLog::get_update_log_html();
$local_version_list = Wanyesea_AI_UpdateLog::get_version_list();
$has_local_log      = !empty($local_version_list);
$current_version    = Wanyesea_AI_Config::get_version();
$version_badge      = Wanyesea_AI_Config::get_version_badge_html();
$latest_line        = Wanyesea_AI_UpdateLog::get_latest_release_line_html();
$log_file           = basename(Wanyesea_AI_UpdateLog::get_log_file_path());
$github_status      = class_exists('Wanyesea_AI_Github_Updater')
    ? Wanyesea_AI_Github_Updater::get_status_panel_html()
    : '';

$latest_hint = $latest_line !== ''
    ? '<p class="muted-3-color em09">本地日志最新 ' . $latest_line . '</p>'
    : '';

$update_hint = class_exists('Wanyesea_AI_Github_Updater') && Wanyesea_AI_Github_Updater::is_enabled()
    ? '<p class="muted-3-color em09">已启用 GitHub 自动更新：WordPress「插件」页会显示新版本，Release 需附带完整插件 <code>.zip</code> 方可一键安装。</p>'
    : '<p class="muted-3-color em09">填写下方 GitHub 仓库后，可在 WordPress「插件」页收到更新提示；未配置时仍可通过手动替换插件目录升级。</p>';

// 更新日志
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
            'id'          => 'github_repo',
            'type'        => 'text',
            'title'       => 'GitHub 仓库',
            'subtitle'    => '格式 owner/repo，用于从 GitHub Releases 检查并安装更新',
            'default'     => 'li1023qwq/WanYesea-AI',
            'placeholder' => 'li1023qwq/WanYesea-AI',
            'desc'        => '默认 <a href="https://github.com/li1023qwq/WanYesea-AI" target="_blank" rel="noopener noreferrer">li1023qwq/WanYesea-AI</a>。也可在 wp-config.php 定义 <code>define(\'WANYESEA_AI_GITHUB_REPO\', \'owner/repo\');</code>（优先级更高）。',
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
