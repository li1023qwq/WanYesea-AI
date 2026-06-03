<?php
/**
 * Plugin Name: 晚夜深秋·AI插件
 * Plugin URI: https://github.com/li1023qwq/WanYesea-AI
 * Description: WordPress 7.0 AI 连接与 API 中转：在本插件配置 API Key 并同步至 Connectors，支持 One API / New API 等网关出站。
 * Version: 1.2.5
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Author: 晚夜深秋
 * Author URI: https://li1023.com/
 * Text Domain: wanyesea-ai
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WanYesea_AI_url', plugins_url('', __FILE__));
define('WanYesea_AI_path', plugin_dir_path(__FILE__));

if (!function_exists('WanYesea_AI')) {
    function WanYesea_AI($option = '', $default = null) {
        static $options = null;
        if ($options === null) {
            $options = get_option('WanYesea_AI');
        }
        return (isset($options[$option])) ? $options[$option] : $default;
    }
}

// 功能模块尽早加载（中转 / Connectors 需挂 plugins_loaded）
require_once WanYesea_AI_path . 'includes/functions.php';

/**
 * 设置页依赖子比主题的 CSF，须在主题加载后注册。
 */
function WanYesea_AI_init_options() {
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;
    require_once WanYesea_AI_path . 'includes/options.php';
}

add_action('after_setup_theme', 'WanYesea_AI_init_options');
