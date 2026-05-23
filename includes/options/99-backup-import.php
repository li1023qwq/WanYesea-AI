<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

$admin_ajax_url = admin_url('admin-ajax.php', 'relative');
$options        = get_option($prefix . '_backup');
$lists          = '<div class="muted-3-color em09">暂无备份数据</div>';
$delete_but     = '';

if ($options) {
    $lists   = '';
    $options = array_reverse($options);
    $count   = 0;
    foreach ($options as $key => $val) {
        $ajax_url = add_query_arg('key', $key, $admin_ajax_url);
        $del      = '<a href="javascript:;" ajax-url="' . add_query_arg('action', 'wanyesea_ai_backup_delete', $ajax_url) . '" data-confirm="确认要删除此备份[' . esc_attr($key) . ']？删除后不可恢复！" class="but c-yellow ajax-get ml10">删除</a>';
        $restore  = '<a href="javascript:;" ajax-url="' . add_query_arg('action', 'wanyesea_ai_backup_restore', $ajax_url) . '" data-confirm="确认将插件设置恢复到此备份吗？[' . esc_attr($key) . ']" class="but c-blue ajax-get ml10">恢复</a>';
        $lists .= '<div class="backup-item flex ac jsb wanyesea-backup-row">';
        $ver_line = !empty($val['version']) ? ' · v' . esc_html($val['version']) : '';
        $lists .= '<div class="item-left"><div>' . esc_html($val['time']) . '</div><div class="muted-3-color em09"> [' . esc_html($val['type']) . $ver_line . ']</div></div>';
        $lists .= '<span class="shrink0">' . $restore . $del . '</span>';
        $lists .= '</div>';
        $count++;
    }
    if ($count > 3) {
        $delete_but = '<a href="javascript:;" ajax-url="' . add_query_arg(array('action' => 'wanyesea_ai_backup_delete_surplus', 'key' => 'all'), $admin_ajax_url) . '" data-confirm="确认要删除多余的备份数据吗？删除后不可恢复！" class="but jb-red ajax-get">删除备份 保留最新三份</a>';
    }
}

CSF::createSection($prefix, array(
    'title'       => '备份&导入',
    'icon'        => 'fa fa-database',
    'fields'      => array(
        array(
            'type'    => 'submessage',
            'style'   => 'warning',
            'content' => '<h3 class="wanyesea-section-title"><i class="csf-tab-icon fa fa-fw fa-copy"></i>备份与恢复</h3>
            <ajaxform class="ajax-form">
            <div class="wanyesea-block-spacing">
            <p>系统会在重置、导入等重要操作时自动备份插件设置（备份条目含插件版本号），您可在此恢复或手动备份</p>
            <p class="c-yellow">恢复备份后，请先保存一次插件设置，然后刷新后再做其它操作！</p>
            <p class="c-yellow">系统最多只能保存20次备份，如需长期保存，请手动导出后留存</p>
            <p><strong>备份列表</strong></p>
            <div class="wanyesea-card-box">
            ' . $lists . '
            </div>
            </div>
            <a href="javascript:;" ajax-url="' . add_query_arg('action', 'wanyesea_ai_backup_create', $admin_ajax_url) . '" class="but jb-blue ajax-get">备份当前配置</a>
            ' . $delete_but . '
            <div class="ajax-notice" style="margin-top: 10px;"></div>
            </ajaxform>',
        ),
        array(
            'type'    => 'submessage',
            'style'   => 'warning',
            'content' => '<h3 class="wanyesea-section-title"><i class="csf-tab-icon fa fa-fw fa-exchange"></i>导入与导出</h3>
            <ajaxform class="ajax-form">
            <div class="wanyesea-block-spacing">
            <p>您可以在此处将插件配置导出为 json 文件，也可以使用 json 内容进行导入；导入时请确保格式正确。</p>
            <textarea ajax-name="import_data" class="wanyesea-json-textarea" placeholder="粘贴导出的 json 数据以进行导入"></textarea>
            </div>
            <input type="hidden" ajax-name="action" value="wanyesea_ai_options_import">
            ' . wp_nonce_field('wanyesea_ai_options_import', '_wanyesea_import_nonce', false, false) . '
            <a href="javascript:;" class="but jb-yellow ajax-submit"><i class="fa fa-paper-plane-o"></i> 导入配置</a>
            <a href="' . add_query_arg(array('action' => 'csf-export', 'unique' => $prefix, 'nonce' => wp_create_nonce('csf_backup_nonce')), $admin_ajax_url) . '" class="but jb-green" target="_blank">导出当前配置</a>
            <div class="ajax-notice" style="margin-top: 10px;"></div>
            </ajaxform>',
        ),
    ),
));
