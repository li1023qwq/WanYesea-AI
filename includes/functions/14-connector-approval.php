<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

/**
 * 为晚夜深秋·AI 插件自动通过 WordPress AI「Connector 审批」。
 *
 * 官方 ai 插件会拦截携带 API Key 的出站请求：若调用方插件未获批准使用该 Connector，则返回
 * wpai_connector_not_approved（例如 DeepSeek 未被 WanYesea-AI/index.php 批准）。
 */
function wanyesea_ai_connector_approval_basename() {
    return plugin_basename(WanYesea_AI_path . 'index.php');
}

/**
 * @return list<string>
 */
function wanyesea_ai_connector_approval_ids() {
    $ids = array();

    if (function_exists('wanyesea_ai_connect_provider_ids')) {
        $ids = wanyesea_ai_connect_provider_ids();
    } elseif (class_exists('Wanyesea_AI_Connectors')) {
        $ids = Wanyesea_AI_Connectors::provider_ids();
    }

    return apply_filters('wanyesea_ai_connector_approval_ids', $ids);
}

/**
 * 将本插件标记为已批准使用所列 Connector（幂等）。
 */
function wanyesea_ai_approve_connectors_for_plugin() {
    if (!class_exists('WordPress\AI\Connector_Approval\Approvals_Store')) {
        return;
    }

    $store    = new WordPress\AI\Connector_Approval\Approvals_Store();
    $basename = wanyesea_ai_connector_approval_basename();
    $changed  = false;

    foreach (wanyesea_ai_connector_approval_ids() as $connector_id) {
        $connector_id = sanitize_key((string) $connector_id);
        if ($connector_id === '') {
            continue;
        }
        if (!$store->is_approved($basename, $connector_id)) {
            $store->set_approval($basename, $connector_id, true);
            $changed = true;
        }
    }

    if ($changed) {
        do_action('wanyesea_ai_connector_approvals_updated', $basename);
    }
}

add_action('plugins_loaded', 'wanyesea_ai_approve_connectors_for_plugin', 25);

/**
 * WordPress 官方 AI 插件 basename（设置页 /ai/v1/providers 等 REST 由该插件发起出站请求）。
 */
function wanyesea_ai_official_ai_plugin_basename() {
    return 'ai/ai.php';
}

/**
 * 为官方 ai 插件批准本站点已注册的 Connector，避免设置页拉取 /models 时被 Connector 审批拦截。
 */
function wanyesea_ai_approve_connectors_for_official_ai_plugin() {
    if (!class_exists('WordPress\AI\Connector_Approval\Approvals_Store')) {
        return;
    }

    $store    = new WordPress\AI\Connector_Approval\Approvals_Store();
    $basename = wanyesea_ai_official_ai_plugin_basename();
    $changed  = false;

    foreach (wanyesea_ai_connector_approval_ids() as $connector_id) {
        $connector_id = sanitize_key((string) $connector_id);
        if ($connector_id === '') {
            continue;
        }
        if (!$store->is_approved($basename, $connector_id)) {
            $store->set_approval($basename, $connector_id, true);
            $changed = true;
        }
    }

    if ($changed) {
        do_action('wanyesea_ai_official_ai_connector_approvals_updated', $basename);
    }
}

add_action('plugins_loaded', 'wanyesea_ai_approve_connectors_for_official_ai_plugin', 26);

/**
 * 本插件是否已对指定 Connector 通过审批。
 */
function wanyesea_ai_is_connector_approved_for_plugin($connector_id) {
    $connector_id = sanitize_key((string) $connector_id);

    if (!class_exists('WordPress\AI\Connector_Approval\Approvals_Store')) {
        return true;
    }

    $store = new WordPress\AI\Connector_Approval\Approvals_Store();
    return $store->is_approved(wanyesea_ai_connector_approval_basename(), $connector_id);
}

/**
 * Connector 审批页 URL（工具 → Connector Approvals）。
 */
function wanyesea_ai_connector_approval_admin_url() {
    if (class_exists('WordPress\AI\Experiments\Connector_Approval\Admin_Page')) {
        return WordPress\AI\Experiments\Connector_Approval\Admin_Page::url();
    }

    return admin_url('tools.php?page=ai-connector-approval');
}
