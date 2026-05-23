<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

/**
 * 编辑建议 Notes 中 [READABILITY] 等英文类型标签 → 中文映射。
 *
 * @return array<string, string>
 */
function wanyesea_ai_get_editorial_notes_review_type_prefix_map() {
    return (array) apply_filters('wanyesea_ai_editorial_notes_review_type_prefix_map', array(
        '[READABILITY]'   => '[可读性]',
        '[GRAMMAR]'       => '[语法]',
        '[ACCESSIBILITY]' => '[无障碍]',
        '[SEO]'           => '[SEO]',
        '[GUIDELINES]'    => '[规范]',
    ));
}

/**
 * @return bool
 */
function wanyesea_ai_editorial_i18n_is_active() {
    return function_exists('wanyesea_ai_i18n_enabled')
        && wanyesea_ai_i18n_enabled()
        && function_exists('wanyesea_ai_i18n_locale_is_chinese')
        && wanyesea_ai_i18n_locale_is_chinese();
}

/**
 * 将备注正文中的英文类型标签替换为中文。
 *
 * @param string $content
 * @return string
 */
function wanyesea_ai_translate_editorial_note_review_type_prefixes($content) {
    if (!is_string($content) || $content === '') {
        return $content;
    }

    $map = wanyesea_ai_get_editorial_notes_review_type_prefix_map();
    foreach ($map as $english => $chinese) {
        if (!is_string($english) || !is_string($chinese) || $english === '') {
            continue;
        }
        $content = str_replace($english, $chinese, $content);
        $label   = trim($english, '[]');
        if ($label !== '') {
            $content = (string) preg_replace(
                '/\[' . preg_quote($label, '/') . '\]/i',
                $chinese,
                $content
            );
        }
    }

    return $content;
}

/**
 * 将中文类型标签还原为英文，供 editorial-notes 去重逻辑使用。
 *
 * @param string $content
 * @return string
 */
function wanyesea_ai_reverse_editorial_note_review_type_prefixes($content) {
    if (!is_string($content) || $content === '') {
        return $content;
    }

    $map = wanyesea_ai_get_editorial_notes_review_type_prefix_map();
    foreach ($map as $english => $chinese) {
        if (!is_string($english) || !is_string($chinese) || $chinese === '') {
            continue;
        }
        $content = str_replace($chinese, $english, $content);
    }

    return $content;
}

/**
 * 中文站点：为编辑建议等 Ability 追加「与正文同语言」系统指令（官方 editorial-notes 未包含）。
 *
 * @param string               $instruction
 * @param string               $ability_name
 * @param array<string, mixed> $data
 * @return string
 */
function wanyesea_ai_filter_wpai_system_instruction_zh_language($instruction, $ability_name, $data) {
    unset($data);

    if (!wanyesea_ai_editorial_i18n_is_active()) {
        return $instruction;
    }

    $abilities = apply_filters('wanyesea_ai_zh_language_instruction_abilities', array(
        'ai/editorial-notes',
        'ai/editorial-updates',
        'ai/content-classification',
        'ai/title-generation',
        'ai/excerpt-generation',
        'ai/meta-description',
        'ai/summarization',
        'ai/content-resizing',
    ));

    if (!is_string($ability_name) || !in_array($ability_name, $abilities, true)) {
        return $instruction;
    }

    if ($ability_name === 'ai/editorial-notes') {
        $append = apply_filters(
            'wanyesea_ai_editorial_notes_zh_system_instruction_appendix',
            "\n\n## Language (mandatory)\n"
            . "- When <block-content> is primarily Chinese, every suggestion \"text\" field MUST be written in Simplified Chinese (简体中文) only.\n"
            . "- Do not write suggestion text in English when the reviewed content is Chinese.\n"
            . "- Keep JSON field review_type as the English enum (accessibility, readability, grammar, seo, guidelines).\n"
            . "- Only the human-readable suggestion text should be localized; never translate review_type values.\n",
            $ability_name
        );
    } else {
        $append = apply_filters(
            'wanyesea_ai_zh_system_instruction_language_appendix',
            "\n\n## Language\n"
            . "- Write all user-facing suggestion text in the same language as the content being reviewed.\n"
            . "- When <block-content> or the main post content is primarily Chinese, write every suggestion in Simplified Chinese (简体中文).\n"
            . "- Do not advise the author to use English unless the reviewed content is actually in English.\n"
            . "- Keep JSON field review_type as the English enum (accessibility, readability, grammar, seo, guidelines); only translate the suggestion text field.\n",
            $ability_name
        );
    }

    if (!is_string($append) || $append === '') {
        return $instruction;
    }

    return rtrim((string) $instruction) . $append;
}

add_filter('wpai_system_instruction', 'wanyesea_ai_filter_wpai_system_instruction_zh_language', 20, 3);

/**
 * AI 编辑建议保存为 Note 时，将类型标签持久化为中文。
 *
 * @param array<string, mixed>|\WP_Error $prepared_comment
 * @param \WP_REST_Request               $request
 * @return array<string, mixed>|\WP_Error
 */
function wanyesea_ai_rest_pre_insert_comment_translate_ai_note($prepared_comment, $request) {
    if (is_wp_error($prepared_comment) || !wanyesea_ai_editorial_i18n_is_active()) {
        return $prepared_comment;
    }

    $meta = $request->get_param('meta');
    if (!is_array($meta) || empty($meta['ai_note'])) {
        return $prepared_comment;
    }

    if (isset($prepared_comment['comment_content']) && is_string($prepared_comment['comment_content'])) {
        $prepared_comment['comment_content'] = wanyesea_ai_translate_editorial_note_review_type_prefixes(
            $prepared_comment['comment_content']
        );
    }

    return $prepared_comment;
}

add_filter('rest_pre_insert_comment', 'wanyesea_ai_rest_pre_insert_comment_translate_ai_note', 25, 2);

/**
 * REST 返回 Note 时汉化类型标签（含历史英文数据）。
 *
 * @param \WP_REST_Response|\WP_HTTP_Response|\WP_Error|mixed $response
 * @param \WP_Comment                                         $comment
 * @param \WP_REST_Request                                    $request
 * @return \WP_REST_Response|\WP_HTTP_Response|\WP_Error|mixed
 */
function wanyesea_ai_rest_prepare_comment_translate_ai_note($response, $comment, $request) {
    unset($request);

    if (!wanyesea_ai_editorial_i18n_is_active() || !($response instanceof WP_REST_Response)) {
        return $response;
    }

    if (!(bool) get_comment_meta($comment->comment_ID, 'ai_note', true)) {
        return $response;
    }

    $data = $response->get_data();
    if (!is_array($data) || !isset($data['content']) || !is_array($data['content'])) {
        return $response;
    }

    if (isset($data['content']['raw']) && is_string($data['content']['raw'])) {
        $data['content']['raw'] = wanyesea_ai_translate_editorial_note_review_type_prefixes($data['content']['raw']);
    }

    if (isset($data['content']['rendered']) && is_string($data['content']['rendered'])) {
        $data['content']['rendered'] = wanyesea_ai_translate_editorial_note_review_type_prefixes($data['content']['rendered']);
    }

    $response->set_data($data);

    return $response;
}

add_filter('rest_prepare_comment', 'wanyesea_ai_rest_prepare_comment_translate_ai_note', 25, 3);

/**
 * 再次生成编辑建议前，将 existing_notes 中的中文标签还原为英文，避免去重失效。
 *
 * @param mixed              $result
 * @param \WP_REST_Server    $server
 * @param \WP_REST_Request   $request
 * @return mixed
 */
function wanyesea_ai_rest_pre_dispatch_normalize_editorial_existing_notes($result, $server, $request) {
    unset($server);

    if ($result !== null || !($request instanceof WP_REST_Request)) {
        return $result;
    }

    if (!wanyesea_ai_editorial_i18n_is_active()) {
        return $result;
    }

    if ($request->get_method() !== 'POST') {
        return $result;
    }

    $route = (string) $request->get_route();
    if (!preg_match('#^/wp-abilities/v1/abilities/(?P<name>.+)/run$#', $route, $matches)) {
        return $result;
    }

    if (($matches['name'] ?? '') !== 'ai/editorial-notes') {
        return $result;
    }

    $params = $request->get_json_params();
    if (!is_array($params) || !isset($params['input']) || !is_array($params['input'])) {
        return $result;
    }

    if (!isset($params['input']['existing_notes']) || !is_array($params['input']['existing_notes'])) {
        return $result;
    }

    $params['input']['existing_notes'] = array_map(
        'wanyesea_ai_reverse_editorial_note_review_type_prefixes',
        $params['input']['existing_notes']
    );

    $request->set_body(wp_json_encode($params));

    return $result;
}

add_filter('rest_pre_dispatch', 'wanyesea_ai_rest_pre_dispatch_normalize_editorial_existing_notes', 5, 3);

/**
 * 块编辑器：将编辑建议备注中的 [READABILITY] 等标签汉化（DOM 兜底）。
 */
function wanyesea_ai_enqueue_editorial_notes_label_i18n() {
    if (!wanyesea_ai_editorial_i18n_is_active()) {
        return;
    }

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || empty($screen->is_block_editor())) {
        return;
    }

    $asset_ver = class_exists('Wanyesea_AI_Config')
        ? Wanyesea_AI_Config::get_asset_version()
        : '1.2.3';

    wp_enqueue_script(
        'wanyesea-ai-editorial-notes-i18n',
        WanYesea_AI_url . 'assets/wanyesea-ai-editorial-notes-i18n.js',
        array(),
        $asset_ver,
        true
    );

    wp_localize_script(
        'wanyesea-ai-editorial-notes-i18n',
        'wanyeseaAiEditorialNotesI18n',
        array(
            'prefixMap' => wanyesea_ai_get_editorial_notes_review_type_prefix_map(),
        )
    );
}

add_action('enqueue_block_editor_assets', 'wanyesea_ai_enqueue_editorial_notes_label_i18n', 101);
