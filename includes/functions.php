<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

// 引入functions目录下的所有文件
$functions_dir = plugin_dir_path(__FILE__) . 'functions/';
if (file_exists($functions_dir)) {
    $function_files = scandir($functions_dir);
    
    foreach ($function_files as $file) {
        if (in_array($file, array('.', '..'))) continue;
        if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
            require_once $functions_dir . $file;
        }
    }
}