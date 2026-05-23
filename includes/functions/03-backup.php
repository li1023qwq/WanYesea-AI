<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

// 晚夜深秋·AI插件 备份&导入功能

/**
 * 写入备份条目时的插件版本号。
 */
function wanyesea_ai_backup_plugin_version() {
    return class_exists('Wanyesea_AI_Config') ? Wanyesea_AI_Config::get_version() : '';
}

/**
 * 备份当前配置
 */
function wanyesea_ai_backup_create() {
    $prefix  = 'WanYesea_AI';
    $options = get_option($prefix);
    $backups = get_option($prefix . '_backup', array());

    $key = date('Y-m-d H:i:s');
    $backups[$key] = array(
        'time'    => $key,
        'type'    => '手动备份',
        'version' => wanyesea_ai_backup_plugin_version(),
        'options' => $options,
    );

    if (count($backups) > 20) {
        $backups = array_slice($backups, -20, 20, true);
    }

    update_option($prefix . '_backup', $backups);

    wp_send_json_success(array(
        'msg' => '备份成功：' . $key,
    ));
}
add_action('wp_ajax_wanyesea_ai_backup_create', 'wanyesea_ai_backup_create');

/**
 * 恢复备份
 */
function wanyesea_ai_backup_restore() {
    $prefix = 'WanYesea_AI';
    $key    = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';

    if (empty($key)) {
        wp_send_json_error(array('msg' => '缺少备份标识'));
    }

    $backups = get_option($prefix . '_backup', array());

    if (!isset($backups[$key])) {
        wp_send_json_error(array('msg' => '备份不存在'));
    }

    $current_options = get_option($prefix);
    $auto_key = date('Y-m-d H:i:s');
    $backups[$auto_key] = array(
        'time'    => $auto_key,
        'type'    => '恢复前自动备份',
        'version' => wanyesea_ai_backup_plugin_version(),
        'options' => $current_options,
    );

    if (count($backups) > 20) {
        $backups = array_slice($backups, -20, 20, true);
    }

    $restore_options = $backups[$key]['options'];
    update_option($prefix, $restore_options);
    update_option($prefix . '_backup', $backups);

    wp_send_json_success(array(
        'msg' => '已恢复到备份：' . $key . '（恢复前已自动备份当前配置）',
    ));
}
add_action('wp_ajax_wanyesea_ai_backup_restore', 'wanyesea_ai_backup_restore');

/**
 * 删除指定备份
 */
function wanyesea_ai_backup_delete() {
    $prefix = 'WanYesea_AI';
    $key    = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';

    if (empty($key)) {
        wp_send_json_error(array('msg' => '缺少备份标识'));
    }

    $backups = get_option($prefix . '_backup', array());

    if (!isset($backups[$key])) {
        wp_send_json_error(array('msg' => '备份不存在'));
    }

    unset($backups[$key]);
    update_option($prefix . '_backup', $backups);

    wp_send_json_success(array(
        'msg' => '备份已删除：' . $key,
    ));
}
add_action('wp_ajax_wanyesea_ai_backup_delete', 'wanyesea_ai_backup_delete');

/**
 * 删除多余备份（保留最新三份）
 */
function wanyesea_ai_backup_delete_surplus() {
    $prefix  = 'WanYesea_AI';
    $backups = get_option($prefix . '_backup', array());

    if (count($backups) <= 3) {
        wp_send_json_error(array('msg' => '备份数量不超过3份，无需清理'));
    }

    $backups = array_slice($backups, -3, 3, true);
    update_option($prefix . '_backup', $backups);

    wp_send_json_success(array(
        'msg' => '已清理多余备份，保留最新3份',
    ));
}
add_action('wp_ajax_wanyesea_ai_backup_delete_surplus', 'wanyesea_ai_backup_delete_surplus');

/**
 * 导入配置
 */
function wanyesea_ai_options_import() {
    $prefix = 'WanYesea_AI';

    $nonce = isset($_POST['_wanyesea_import_nonce']) ? sanitize_text_field($_POST['_wanyesea_import_nonce']) : '';
    if (!wp_verify_nonce($nonce, 'wanyesea_ai_options_import')) {
        wp_send_json_error(array('msg' => '安全验证失败，请刷新页面重试'));
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('msg' => '权限不足'));
    }

    $import_data = isset($_POST['import_data']) ? wp_unslash(trim($_POST['import_data'])) : '';

    if (empty($import_data)) {
        wp_send_json_error(array('msg' => '请粘贴要导入的JSON数据'));
    }

    $data = json_decode($import_data, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        wp_send_json_error(array('msg' => 'JSON格式错误：' . json_last_error_msg()));
    }

    if (!is_array($data) || empty($data)) {
        wp_send_json_error(array('msg' => '无效的配置数据'));
    }

    $current_options = get_option($prefix);
    $backups = get_option($prefix . '_backup', array());
    $auto_key = date('Y-m-d H:i:s');
    $backups[$auto_key] = array(
        'time'    => $auto_key,
        'type'    => '导入前自动备份',
        'version' => wanyesea_ai_backup_plugin_version(),
        'options' => $current_options,
    );
    if (count($backups) > 20) {
        $backups = array_slice($backups, -20, 20, true);
    }
    update_option($prefix . '_backup', $backups);

    update_option($prefix, $data);

    wp_send_json_success(array(
        'msg' => '配置导入成功（导入前已自动备份当前配置）',
    ));
}
add_action('wp_ajax_wanyesea_ai_options_import', 'wanyesea_ai_options_import');

/**
 * 自动备份（供其他功能调用）
 */
function wanyesea_ai_auto_backup($type = '自动备份') {
    $prefix  = 'WanYesea_AI';
    $options = get_option($prefix);
    $backups = get_option($prefix . '_backup', array());

    $key = date('Y-m-d H:i:s');
    $backups[$key] = array(
        'time'    => $key,
        'type'    => $type,
        'version' => wanyesea_ai_backup_plugin_version(),
        'options' => $options,
    );

    if (count($backups) > 20) {
        $backups = array_slice($backups, -20, 20, true);
    }

    update_option($prefix . '_backup', $backups);
}
