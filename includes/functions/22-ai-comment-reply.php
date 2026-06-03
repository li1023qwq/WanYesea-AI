<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

const WANYESEA_AI_COMMENT_REPLY_META           = '_wanyesea_ai_comment_reply';
const WANYESEA_AI_COMMENT_REPLY_META_TO        = '_wanyesea_ai_comment_reply_to';
const WANYESEA_AI_COMMENT_REPLY_META_WORKER    = '_wanyesea_ai_comment_reply_worker';
const WANYESEA_AI_COMMENT_REPLY_META_ERROR     = '_wanyesea_ai_comment_reply_error';
const WANYESEA_AI_COMMENT_REPLY_CRON_HOOK      = 'wanyesea_ai_process_comment_reply';
const WANYESEA_AI_COMMENT_REPLY_AGENT          = 'WanYesea-AI-Comment-Reply';

/**
 * 功能总开关。
 */
function wanyesea_ai_comment_reply_enabled() {
    return wanyesea_ai_switcher_on('comment_reply_enabled', false);
}

/**
 * 新评论通过后是否自动回复。
 */
function wanyesea_ai_comment_reply_auto_on_approve() {
    return wanyesea_ai_switcher_on('comment_reply_auto_on_approve', true);
}

/**
 * AI 回复是否自动审核通过。
 */
function wanyesea_ai_comment_reply_auto_approve() {
    return wanyesea_ai_switcher_on('comment_reply_auto_approve', true);
}

/**
 * 前台是否显示 AI 回复标识。
 */
function wanyesea_ai_comment_reply_show_badge() {
    return wanyesea_ai_switcher_on('comment_reply_show_badge', true);
}

/**
 * 回复身份：post_author | site_admin | custom。
 */
function wanyesea_ai_comment_reply_author_mode() {
    $mode = WanYesea_AI('comment_reply_author_mode', 'post_author');
    $mode = sanitize_key((string) $mode);

    if (!in_array($mode, array('post_author', 'site_admin', 'custom'), true)) {
        return 'post_author';
    }

    return $mode;
}

/**
 * 自定义回复用户 ID。
 */
function wanyesea_ai_comment_reply_custom_user_id() {
    return max(0, (int) WanYesea_AI('comment_reply_custom_user_id', 0));
}

/**
 * 额外创作说明（注入提示词）。
 */
function wanyesea_ai_comment_reply_extra_instructions() {
    return trim((string) WanYesea_AI('comment_reply_extra_instructions', ''));
}

/**
 * 回复最大字符数。
 */
function wanyesea_ai_comment_reply_max_chars() {
    $max = (int) WanYesea_AI('comment_reply_max_chars', 500);
    return max(80, min(2000, $max));
}

/**
 * 文章正文摘要最大字符数（注入提示词）。
 */
function wanyesea_ai_comment_reply_post_context_chars() {
    return max(400, min(6000, (int) apply_filters('wanyesea_ai_comment_reply_post_context_chars', 1800)));
}

/**
 * 是否屏蔽子比主题快捷回复语句。
 */
function wanyesea_ai_comment_reply_block_zibll_quick_enabled() {
    return wanyesea_ai_switcher_on('comment_reply_block_zibll_quick', true);
}

/**
 * 规范化评论/语句用于精确匹配（与子比快捷回复 trim 逻辑对齐）。
 */
function wanyesea_ai_comment_reply_normalize_for_match($content) {
    $text = trim(wp_strip_all_tags((string) $content));

    if ($text === '') {
        return '';
    }

    return trim(preg_replace('/\s+/u', ' ', $text));
}

/**
 * 解析子比 CSF 快捷回复选项（array of array('val' => '...')）。
 *
 * @param mixed $option
 * @return list<string>
 */
function wanyesea_ai_comment_reply_parse_zibll_quick_option($option) {
    $phrases = array();

    if (!is_array($option)) {
        return $phrases;
    }

    foreach ($option as $item) {
        if (is_array($item) && isset($item['val'])) {
            $text = trim((string) $item['val']);
        } elseif (is_string($item)) {
            $text = trim($item);
        } else {
            continue;
        }

        if ($text !== '') {
            $phrases[] = $text;
        }
    }

    return $phrases;
}

/**
 * 收集子比主题系统 + 评论者「我的快捷回复」语句。
 *
 * @param int|\WP_Comment|null $comment
 * @return list<string>
 */
function wanyesea_ai_comment_reply_get_zibll_quick_phrases($comment = null) {
    if (!wanyesea_ai_comment_reply_block_zibll_quick_enabled()) {
        return array();
    }

    if (!function_exists('wanyesea_ai_is_zibll_active') || !wanyesea_ai_is_zibll_active()) {
        return array();
    }

    if (is_numeric($comment)) {
        $comment = get_comment((int) $comment);
    }

    $phrases = array();

    if (function_exists('zib_get_quick_often_items')) {
        $phrases = array_merge($phrases, (array) zib_get_quick_often_items());
    } elseif (function_exists('_pz')) {
        if (_pz('comment_quick_s')) {
            $phrases = array_merge(
                $phrases,
                wanyesea_ai_comment_reply_parse_zibll_quick_option(_pz('comment_quick_often'))
            );
        }
        if (_pz('bbs_comment_quick_s', true)) {
            $phrases = array_merge(
                $phrases,
                wanyesea_ai_comment_reply_parse_zibll_quick_option(_pz('bbs_comment_quick_often'))
            );
        }
    }

    if ($comment instanceof WP_Comment && (int) $comment->user_id > 0 && function_exists('zib_get_user_quick_often')) {
        $user_items = zib_get_user_quick_often((int) $comment->user_id);
        if (is_array($user_items)) {
            foreach ($user_items as $item) {
                $text = trim((string) $item);
                if ($text !== '') {
                    $phrases[] = $text;
                }
            }
        }
    }

    return apply_filters(
        'wanyesea_ai_comment_reply_zibll_quick_phrases',
        array_values(array_unique($phrases)),
        $comment
    );
}

/**
 * 插件设置中的自定义屏蔽语句。
 *
 * @return list<string>
 */
function wanyesea_ai_comment_reply_get_custom_block_phrases() {
    $raw   = WanYesea_AI('comment_reply_block_phrases', '');
    $lines = preg_split('/\r\n|\r|\n/', (string) $raw);
    $list  = array();

    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line !== '') {
            $list[] = $line;
        }
    }

    return apply_filters('wanyesea_ai_comment_reply_custom_block_phrases', $list);
}

/**
 * 合并全部屏蔽语句（自定义 + 子比快捷回复）。
 *
 * @param int|\WP_Comment|null $comment
 * @return list<string>
 */
function wanyesea_ai_comment_reply_get_block_phrases($comment = null) {
    if (is_numeric($comment)) {
        $comment = get_comment((int) $comment);
    }

    $phrases = array_merge(
        wanyesea_ai_comment_reply_get_custom_block_phrases(),
        wanyesea_ai_comment_reply_get_zibll_quick_phrases($comment)
    );

    $normalized_seen = array();
    $unique          = array();

    foreach ($phrases as $phrase) {
        $key = wanyesea_ai_comment_reply_normalize_for_match($phrase);
        if ($key === '' || isset($normalized_seen[$key])) {
            continue;
        }
        $normalized_seen[$key] = true;
        $unique[]              = $phrase;
    }

    return apply_filters('wanyesea_ai_comment_reply_block_phrases', $unique, $comment);
}

/**
 * 评论内容是否命中屏蔽语句（精确匹配）。
 *
 * @param int|\WP_Comment|null $comment
 */
function wanyesea_ai_comment_reply_is_blocked_comment($comment) {
    if (is_numeric($comment)) {
        $comment = get_comment((int) $comment);
    }

    if (!$comment instanceof WP_Comment) {
        return false;
    }

    $content = wanyesea_ai_comment_reply_normalize_for_match($comment->comment_content);
    if ($content === '') {
        return false;
    }

    foreach (wanyesea_ai_comment_reply_get_block_phrases($comment) as $phrase) {
        if (wanyesea_ai_comment_reply_normalize_for_match($phrase) === $content) {
            return true;
        }
    }

    return false;
}

/**
 * 是否应在当前后台页加载资源。
 *
 * @param string $hook_suffix
 */
function wanyesea_ai_comment_reply_should_enqueue($hook_suffix) {
    if (!wanyesea_ai_comment_reply_enabled()) {
        return false;
    }

    if (!current_user_can('moderate_comments')) {
        return false;
    }

    return in_array($hook_suffix, array('edit-comments.php', 'comment.php'), true);
}

/**
 * 是否已有针对该评论的 AI 回复。
 *
 * @param int $comment_id
 */
function wanyesea_ai_comment_reply_has_existing_reply($comment_id) {
    $comment_id = (int) $comment_id;
    if ($comment_id <= 0) {
        return false;
    }

    $children = get_comments(
        array(
            'parent'     => $comment_id,
            'status'     => 'approve',
            'meta_key'   => WANYESEA_AI_COMMENT_REPLY_META,
            'meta_value' => '1',
            'count'      => true,
        )
    );

    return (int) $children > 0;
}

/**
 * 评论是否已通过审核。
 *
 * @param int|\WP_Comment|null $comment
 */
function wanyesea_ai_comment_is_approved($comment) {
    if ($comment === null) {
        return false;
    }

    if (is_numeric($comment)) {
        $comment = get_comment((int) $comment);
    }

    if (!$comment instanceof WP_Comment) {
        return false;
    }

    $approved = (string) $comment->comment_approved;

    return $approved === '1' || $approved === 'approve';
}

/**
 * 是否为插件生成的 AI 回复评论。
 *
 * @param int|\WP_Comment|null $comment
 */
function wanyesea_ai_comment_is_ai_reply($comment = null) {
    if ($comment === null) {
        $comment = get_comment();
    } elseif (is_numeric($comment)) {
        $comment = get_comment((int) $comment);
    }

    if (!$comment instanceof WP_Comment) {
        return false;
    }

    if ((string) $comment->comment_agent === WANYESEA_AI_COMMENT_REPLY_AGENT) {
        return true;
    }

    return get_comment_meta((int) $comment->comment_ID, WANYESEA_AI_COMMENT_REPLY_META, true) === '1';
}

/**
 * 解析回复所用 WordPress 用户。
 *
 * @param int $post_id
 * @return \WP_User|null
 */
function wanyesea_ai_comment_reply_resolve_author_user($post_id) {
    $post_id = (int) $post_id;
    $mode    = wanyesea_ai_comment_reply_author_mode();

    if ($mode === 'custom') {
        $user = get_user_by('id', wanyesea_ai_comment_reply_custom_user_id());
        if ($user instanceof WP_User) {
            return $user;
        }
    }

    if ($mode === 'site_admin') {
        $admins = get_users(
            array(
                'role'   => 'administrator',
                'number' => 1,
                'orderby' => 'ID',
                'order'   => 'ASC',
            )
        );
        if (!empty($admins[0]) && $admins[0] instanceof WP_User) {
            return $admins[0];
        }
    }

    if ($post_id > 0) {
        $post = get_post($post_id);
        if ($post && (int) $post->post_author > 0) {
            $author = get_user_by('id', (int) $post->post_author);
            if ($author instanceof WP_User) {
                return $author;
            }
        }
    }

    $fallback = get_users(
        array(
            'role'   => 'administrator',
            'number' => 1,
            'orderby' => 'ID',
            'order'   => 'ASC',
        )
    );

    return !empty($fallback[0]) && $fallback[0] instanceof WP_User ? $fallback[0] : null;
}

/**
 * 截取文本。
 */
function wanyesea_ai_comment_reply_truncate($text, $max_chars) {
    $text = trim((string) $text);
    $max  = max(1, (int) $max_chars);

    if ($text === '') {
        return '';
    }

    if (function_exists('mb_strlen') && mb_strlen($text) > $max) {
        return rtrim(mb_substr($text, 0, $max)) . '…';
    }

    if (strlen($text) > $max) {
        return rtrim(substr($text, 0, $max)) . '…';
    }

    return $text;
}

/**
 * 收集文章上下文。
 *
 * @return array{title: string, excerpt: string, content: string}
 */
function wanyesea_ai_comment_reply_collect_post_context($post_id) {
    $post = get_post((int) $post_id);
    if (!$post) {
        return array(
            'title'   => '',
            'excerpt' => '',
            'content' => '',
        );
    }

    $title   = get_the_title($post);
    $excerpt = has_excerpt($post) ? get_the_excerpt($post) : '';
    if ($excerpt === '') {
        $excerpt = wp_trim_words(wp_strip_all_tags($post->post_content), 40, '…');
    }

    $plain   = wp_strip_all_tags($post->post_content);
    $content = wanyesea_ai_comment_reply_truncate($plain, wanyesea_ai_comment_reply_post_context_chars());

    return array(
        'title'   => (string) $title,
        'excerpt' => trim((string) $excerpt),
        'content' => trim((string) $content),
    );
}

/**
 * 收集评论线程上下文。
 *
 * @return array{target: array<string, string>, thread: list<array<string, string>>}
 */
function wanyesea_ai_comment_reply_collect_thread_context(WP_Comment $comment) {
    $target = array(
        'author'  => (string) $comment->comment_author,
        'content' => trim((string) $comment->comment_content),
        'date'    => (string) $comment->comment_date,
    );

    $thread = array();
    $parent = (int) $comment->comment_parent;

    while ($parent > 0 && count($thread) < 6) {
        $parent_comment = get_comment($parent);
        if (!$parent_comment instanceof WP_Comment) {
            break;
        }

        array_unshift(
            $thread,
            array(
                'author'  => (string) $parent_comment->comment_author,
                'content' => wanyesea_ai_comment_reply_truncate($parent_comment->comment_content, 280),
            )
        );

        $parent = (int) $parent_comment->comment_parent;
    }

    $siblings = get_comments(
        array(
            'parent'  => (int) $comment->comment_ID,
            'status'  => 'approve',
            'number'  => 4,
            'orderby' => 'comment_date_gmt',
            'order'   => 'ASC',
        )
    );

    foreach ($siblings as $sibling) {
        if (!$sibling instanceof WP_Comment) {
            continue;
        }
        $thread[] = array(
            'author'  => (string) $sibling->comment_author,
            'content' => wanyesea_ai_comment_reply_truncate($sibling->comment_content, 220),
        );
    }

    return array(
        'target' => $target,
        'thread' => $thread,
    );
}

/**
 * 构建评论回复提示词。
 *
 * @param array<string, mixed> $extra
 */
function wanyesea_ai_comment_reply_build_prompt(WP_Comment $comment, array $extra = array()) {
    $post_ctx    = wanyesea_ai_comment_reply_collect_post_context((int) $comment->comment_post_ID);
    $thread_ctx  = wanyesea_ai_comment_reply_collect_thread_context($comment);
    $author_user = wanyesea_ai_comment_reply_resolve_author_user((int) $comment->comment_post_ID);
    $reply_as    = $author_user instanceof WP_User ? $author_user->display_name : '站点管理员';

    $lines = array(
        '你是一位 WordPress「子比 Zibll」主题博客的评论区助手，负责以站点官方身份回复读者评论。',
        '请根据文章与评论上下文，写一条自然、友好、有针对性的中文回复。',
        '只输出回复正文本身：不要标题、不要 JSON、不要 markdown 代码块、不要「回复：」等前缀。',
        '语气亲切但不油腻，尽量点名回应评论者关心的问题；若评论是感谢或闲聊，简短回应即可。',
        '长度控制在 ' . wanyesea_ai_comment_reply_max_chars() . ' 字以内，可使用少量 emoji（0-1 个）。',
        '',
        '你将代表：' . $reply_as,
        '',
        '【文章标题】' . ($post_ctx['title'] !== '' ? $post_ctx['title'] : '（无标题）'),
    );

    if ($post_ctx['excerpt'] !== '') {
        $lines[] = '【文章摘要】' . $post_ctx['excerpt'];
    }

    if ($post_ctx['content'] !== '') {
        $lines[] = '【文章正文节选】' . $post_ctx['content'];
    }

    if ($thread_ctx['thread'] !== array()) {
        $lines[] = '';
        $lines[] = '【对话上下文】';
        foreach ($thread_ctx['thread'] as $index => $item) {
            $lines[] = ($index + 1) . '. ' . $item['author'] . '：' . $item['content'];
        }
    }

    $lines[] = '';
    $lines[] = '【待回复评论】';
    $lines[] = $thread_ctx['target']['author'] . '：' . $thread_ctx['target']['content'];

    $extra_note = isset($extra['note']) ? trim((string) $extra['note']) : '';
    if ($extra_note === '') {
        $extra_note = wanyesea_ai_comment_reply_extra_instructions();
    }
    if ($extra_note !== '') {
        $lines[] = '';
        $lines[] = '【额外要求】' . $extra_note;
    }

    return implode("\n", $lines);
}

/**
 * 清洗模型输出。
 */
function wanyesea_ai_comment_reply_clean_text($raw) {
    $text = trim((string) $raw);
    if ($text === '') {
        return '';
    }

    $text = preg_replace('/^```[\w-]*\s*|\s*```$/u', '', $text);
    $text = trim($text, " \t\n\r\0\x0B\"'`");
    $text = preg_replace('/^(回复|答|Reply)[：:\s]*/u', '', $text);
    $text = wp_kses_post($text);
    $text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($text)));

    return wanyesea_ai_comment_reply_truncate($text, wanyesea_ai_comment_reply_max_chars());
}

/**
 * 调用文本模型生成回复。
 *
 * @param array{0: string, 1: string} $model_pair
 * @return string|\WP_Error
 */
function wanyesea_ai_comment_reply_generate(WP_Comment $comment, array $model_pair, array $extra = array()) {
    if (!function_exists('wanyesea_ai_post_draft_generate_text')) {
        return new WP_Error('wya_unavailable', '文本生成功能不可用');
    }

    $prompt = wanyesea_ai_comment_reply_build_prompt($comment, $extra);
    $raw    = wanyesea_ai_post_draft_generate_text($model_pair[0], $model_pair[1], $prompt);

    if (is_wp_error($raw)) {
        return $raw;
    }

    $text = wanyesea_ai_comment_reply_clean_text($raw);
    if ($text === '') {
        return new WP_Error('wya_empty_reply', 'AI 未返回有效回复内容');
    }

    return $text;
}

/**
 * 写入 AI 回复评论。
 *
 * @return int|\WP_Error
 */
function wanyesea_ai_comment_reply_insert(WP_Comment $parent_comment, $reply_text, WP_User $author_user) {
    $reply_text = wanyesea_ai_comment_reply_clean_text($reply_text);
    if ($reply_text === '') {
        return new WP_Error('wya_empty_reply', '回复内容为空');
    }

    $approved = wanyesea_ai_comment_reply_auto_approve() ? 1 : 0;

    $comment_data = array(
        'comment_post_ID'      => (int) $parent_comment->comment_post_ID,
        'comment_author'       => $author_user->display_name,
        'comment_author_email' => $author_user->user_email,
        'comment_author_url'   => $author_user->user_url,
        'comment_content'      => $reply_text,
        'comment_type'         => '',
        'comment_parent'       => (int) $parent_comment->comment_ID,
        'user_id'              => (int) $author_user->ID,
        'comment_approved'     => $approved,
        'comment_agent'        => WANYESEA_AI_COMMENT_REPLY_AGENT,
    );

    $reply_id = wp_insert_comment(wp_slash($comment_data));
    if (!$reply_id) {
        return new WP_Error('wya_insert_failed', '写入回复评论失败');
    }

    update_comment_meta((int) $reply_id, WANYESEA_AI_COMMENT_REPLY_META, '1');
    update_comment_meta((int) $reply_id, WANYESEA_AI_COMMENT_REPLY_META_TO, (int) $parent_comment->comment_ID);

    return (int) $reply_id;
}

/**
 * 对指定评论执行 AI 回复（生成 + 发布）。
 *
 * @param array<string, mixed> $args
 * @return array<string, mixed>|\WP_Error
 */
function wanyesea_ai_comment_reply_process($comment_id, array $args = array()) {
    if (!wanyesea_ai_comment_reply_enabled()) {
        return new WP_Error('wya_disabled', 'AI 评论回复功能未启用');
    }

    $comment = get_comment((int) $comment_id);
    if (!$comment instanceof WP_Comment) {
        return new WP_Error('wya_not_found', '评论不存在');
    }

    if (wanyesea_ai_comment_is_ai_reply($comment)) {
        return new WP_Error('wya_skip_ai', '不能对 AI 回复再次自动回复');
    }

    if ((string) $comment->comment_approved === 'trash' || (string) $comment->comment_approved === 'spam') {
        return new WP_Error('wya_invalid_status', '该评论状态不可回复');
    }

    if (wanyesea_ai_comment_reply_is_blocked_comment($comment)) {
        return new WP_Error('wya_blocked_phrase', '该评论属于屏蔽语句，无需 AI 回复');
    }

    $preview = !empty($args['preview']);
    $force   = !empty($args['force']);

    if (!$preview && !$force && wanyesea_ai_comment_reply_has_existing_reply((int) $comment->comment_ID)) {
        return new WP_Error('wya_already_replied', '该评论已有 AI 回复');
    }

    $provider_id = isset($args['provider_id']) ? sanitize_key((string) $args['provider_id']) : '';
    $model_id    = isset($args['model_id']) ? (string) $args['model_id'] : '';

    if (!function_exists('wanyesea_ai_post_draft_resolve_user_model_pair')) {
        return new WP_Error('wya_unavailable', '模型解析不可用');
    }

    $model_pair = wanyesea_ai_post_draft_resolve_user_model_pair($provider_id, $model_id);
    if (is_wp_error($model_pair)) {
        return $model_pair;
    }

    $extra = array(
        'note' => isset($args['note']) ? sanitize_textarea_field((string) $args['note']) : '',
    );

    $reply_text = wanyesea_ai_comment_reply_generate($comment, $model_pair, $extra);
    if (is_wp_error($reply_text)) {
        return $reply_text;
    }

    $result = array(
        'comment_id'  => (int) $comment->comment_ID,
        'reply_text'  => $reply_text,
        'provider_id' => $model_pair[0],
        'model_id'    => $model_pair[1],
        'preview'     => $preview,
        'published'   => false,
        'reply_id'    => 0,
    );

    if ($preview) {
        return $result;
    }

    $author_user = wanyesea_ai_comment_reply_resolve_author_user((int) $comment->comment_post_ID);
    if (!$author_user instanceof WP_User) {
        return new WP_Error('wya_no_author', '无法确定回复身份用户');
    }

    if ((int) $author_user->ID === (int) $comment->user_id && (int) $comment->user_id > 0) {
        return new WP_Error('wya_self_reply', '不能回复自己的评论');
    }

    $custom_text = isset($args['reply_text']) ? wanyesea_ai_comment_reply_clean_text($args['reply_text']) : '';
    if ($custom_text !== '') {
        $reply_text = $custom_text;
    }

    $reply_id = wanyesea_ai_comment_reply_insert($comment, $reply_text, $author_user);
    if (is_wp_error($reply_id)) {
        return $reply_id;
    }

    $result['published'] = true;
    $result['reply_id']  = (int) $reply_id;

    delete_comment_meta((int) $comment->comment_ID, WANYESEA_AI_COMMENT_REPLY_META_ERROR);

    return $result;
}

/**
 * 记录自动回复失败原因（便于后台排查）。
 */
function wanyesea_ai_comment_reply_log_error($comment_id, $message) {
    $comment_id = (int) $comment_id;
    if ($comment_id <= 0) {
        return;
    }

    update_comment_meta(
        $comment_id,
        WANYESEA_AI_COMMENT_REPLY_META_ERROR,
        wp_strip_all_tags(substr((string) $message, 0, 500))
    );
}

function wanyesea_ai_comment_reply_acquire_lock($comment_id) {
    $comment_id = (int) $comment_id;
    if ($comment_id <= 0) {
        return false;
    }

    $worker = (int) get_comment_meta($comment_id, WANYESEA_AI_COMMENT_REPLY_META_WORKER, true);
    if ($worker > 0 && (time() - $worker) < 15 * MINUTE_IN_SECONDS) {
        return false;
    }

    update_comment_meta($comment_id, WANYESEA_AI_COMMENT_REPLY_META_WORKER, (string) time());

    return true;
}

function wanyesea_ai_comment_reply_release_lock($comment_id) {
    delete_comment_meta((int) $comment_id, WANYESEA_AI_COMMENT_REPLY_META_WORKER);
}

/**
 * 后台异步执行 AI 评论回复。
 */
function wanyesea_ai_comment_reply_process_job($comment_id) {
    $comment_id = (int) $comment_id;
    if ($comment_id <= 0) {
        return;
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit((int) apply_filters('wanyesea_ai_comment_reply_php_time_limit', 180));
    }
    if (function_exists('ignore_user_abort')) {
        @ignore_user_abort(true);
    }

    if (!wanyesea_ai_comment_reply_acquire_lock($comment_id)) {
        return;
    }

    $result = wanyesea_ai_comment_reply_process($comment_id, array('force' => false));

    if (is_wp_error($result)) {
        if ($result->get_error_code() !== 'wya_blocked_phrase') {
            wanyesea_ai_comment_reply_log_error($comment_id, $result->get_error_message());
        }
    }

    wanyesea_ai_comment_reply_release_lock($comment_id);
}

/**
 * Cron：异步自动回复。
 */
function wanyesea_ai_comment_reply_cron_handler($comment_id) {
    wanyesea_ai_comment_reply_process_job((int) $comment_id);
}

add_action(WANYESEA_AI_COMMENT_REPLY_CRON_HOOK, 'wanyesea_ai_comment_reply_cron_handler');

/**
 * 当前请求结束后继续执行（解决 WP-Cron 在本地/Windows 不触发的问题）。
 */
function wanyesea_ai_comment_reply_queue_shutdown_run($comment_id) {
    $comment_id = (int) $comment_id;
    if ($comment_id <= 0) {
        return;
    }

    $key = 'wanyesea_ai_comment_reply_shutdown_' . $comment_id;
    if (!empty($GLOBALS[$key])) {
        return;
    }
    $GLOBALS[$key] = true;

    add_action(
        'shutdown',
        static function () use ($comment_id) {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            wanyesea_ai_comment_reply_process_job($comment_id);
        },
        0
    );
}

/**
 * 调度自动回复：WP-Cron + 当前请求 shutdown 双保险。
 */
function wanyesea_ai_comment_reply_schedule_job($comment_id) {
    if (!wanyesea_ai_comment_reply_enabled() || !wanyesea_ai_comment_reply_auto_on_approve()) {
        return;
    }

    $comment_id = (int) $comment_id;
    if ($comment_id <= 0) {
        return;
    }

    $comment = get_comment($comment_id);
    if (!$comment instanceof WP_Comment) {
        return;
    }

    if (!wanyesea_ai_comment_is_approved($comment)) {
        return;
    }

    if (wanyesea_ai_comment_is_ai_reply($comment)) {
        return;
    }

    if (wanyesea_ai_comment_reply_has_existing_reply($comment_id)) {
        return;
    }

    if (wanyesea_ai_comment_reply_is_blocked_comment($comment)) {
        return;
    }

    $author_user = wanyesea_ai_comment_reply_resolve_author_user((int) $comment->comment_post_ID);
    if ($author_user instanceof WP_User && (int) $author_user->ID === (int) $comment->user_id && (int) $comment->user_id > 0) {
        return;
    }

    if (!wp_next_scheduled(WANYESEA_AI_COMMENT_REPLY_CRON_HOOK, array($comment_id))) {
        wp_schedule_single_event(time(), WANYESEA_AI_COMMENT_REPLY_CRON_HOOK, array($comment_id));
    }

    wanyesea_ai_comment_reply_queue_shutdown_run($comment_id);

    if (function_exists('spawn_cron')) {
        spawn_cron();
    }
}

/**
 * 前台新评论（已自动过审）立即排队。
 *
 * @param int                  $comment_id
 * @param int|string|bool      $comment_approved
 */
function wanyesea_ai_comment_reply_on_comment_post($comment_id, $comment_approved) {
    if ((string) $comment_approved !== '1') {
        return;
    }

    wanyesea_ai_comment_reply_schedule_job((int) $comment_id);
}

add_action('comment_post', 'wanyesea_ai_comment_reply_on_comment_post', 20, 2);

/**
 * 评论审核通过后排队自动回复。
 */
function wanyesea_ai_comment_reply_maybe_schedule_on_approve($new_status, $old_status, WP_Comment $comment) {
    if ($new_status !== 'approve' || $old_status === 'approve') {
        return;
    }

    wanyesea_ai_comment_reply_schedule_job((int) $comment->comment_ID);
}

add_action('transition_comment_status', 'wanyesea_ai_comment_reply_maybe_schedule_on_approve', 20, 3);

/**
 * wp_set_comment_status 兜底（子比后台 AJAX 审核等场景）。
 */
function wanyesea_ai_comment_reply_on_set_comment_status($comment_id, $comment_status) {
    $comment_status = strtolower((string) $comment_status);
    if ($comment_status !== 'approve' && $comment_status !== '1') {
        return;
    }

    wanyesea_ai_comment_reply_schedule_job((int) $comment_id);
}

add_action('wp_set_comment_status', 'wanyesea_ai_comment_reply_on_set_comment_status', 20, 2);

/**
 * REST 权限。
 */
function wanyesea_ai_rest_can_manage_comment_replies() {
    return current_user_can('moderate_comments');
}

/**
 * REST：厂商列表。
 */
function wanyesea_ai_rest_list_comment_reply_providers() {
    if (!function_exists('wanyesea_ai_post_draft_list_configured_providers')) {
        return rest_ensure_response(array('providers' => array(), 'default' => null));
    }

    $providers = wanyesea_ai_post_draft_list_configured_providers();
    $default   = function_exists('wanyesea_ai_post_draft_resolve_text_model')
        ? wanyesea_ai_post_draft_resolve_text_model()
        : null;

    return rest_ensure_response(
        array(
            'providers' => $providers,
            'default'   => $default !== null
                ? array('provider_id' => $default[0], 'model_id' => $default[1])
                : null,
        )
    );
}

/**
 * REST：模型列表。
 */
function wanyesea_ai_rest_list_comment_reply_models(WP_REST_Request $request) {
    if (!function_exists('wanyesea_ai_post_draft_list_post_draft_models')) {
        return new WP_Error('wya_unavailable', '模型列表不可用', array('status' => 500));
    }

    return wanyesea_ai_post_draft_list_post_draft_models($request);
}

/**
 * REST：预览或发布回复。
 */
function wanyesea_ai_rest_create_comment_reply(WP_REST_Request $request) {
    $comment_id = (int) $request->get_param('comment_id');
    if ($comment_id <= 0) {
        return new WP_Error('wya_invalid_comment', '请指定评论 ID', array('status' => 400));
    }

    if (!current_user_can('edit_comment', $comment_id)) {
        return new WP_Error('wya_forbidden', '无权操作该评论', array('status' => 403));
    }

    $preview = rest_sanitize_boolean($request->get_param('preview'));
    $force   = rest_sanitize_boolean($request->get_param('force'));

    $result = wanyesea_ai_comment_reply_process(
        $comment_id,
        array(
            'preview'     => $preview,
            'force'       => $force,
            'provider_id' => (string) $request->get_param('provider_id'),
            'model_id'    => (string) $request->get_param('model_id'),
            'note'        => (string) $request->get_param('note'),
            'reply_text'  => (string) $request->get_param('reply_text'),
        )
    );

    if (is_wp_error($result)) {
        $status = 400;
        if ($result->get_error_code() === 'wya_disabled') {
            $status = 403;
        }
        return new WP_Error($result->get_error_code(), $result->get_error_message(), array('status' => $status));
    }

    return rest_ensure_response($result);
}

function wanyesea_ai_comment_reply_register_rest_routes() {
    register_rest_route(
        'wanyesea-ai/v1',
        '/comment-replies/providers',
        array(
            'methods'             => 'GET',
            'permission_callback' => 'wanyesea_ai_rest_can_manage_comment_replies',
            'callback'            => 'wanyesea_ai_rest_list_comment_reply_providers',
        )
    );

    register_rest_route(
        'wanyesea-ai/v1',
        '/comment-replies/models',
        array(
            'methods'             => 'GET',
            'permission_callback' => 'wanyesea_ai_rest_can_manage_comment_replies',
            'callback'            => 'wanyesea_ai_rest_list_comment_reply_models',
            'args'                => array(
                'provider_id' => array(
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_key',
                ),
                'refresh'     => array(
                    'type'    => 'boolean',
                    'default' => false,
                ),
            ),
        )
    );

    register_rest_route(
        'wanyesea-ai/v1',
        '/comment-replies',
        array(
            'methods'             => 'POST',
            'permission_callback' => 'wanyesea_ai_rest_can_manage_comment_replies',
            'callback'            => 'wanyesea_ai_rest_create_comment_reply',
            'args'                => array(
                'comment_id'  => array(
                    'type'              => 'integer',
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ),
                'preview'     => array(
                    'type'              => 'boolean',
                    'default'           => true,
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ),
                'force'       => array(
                    'type'              => 'boolean',
                    'default'           => false,
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ),
                'provider_id' => array(
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_key',
                ),
                'model_id'    => array(
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'note'        => array(
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_textarea_field',
                ),
                'reply_text'  => array(
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_textarea_field',
                ),
            ),
        )
    );
}

add_action('rest_api_init', 'wanyesea_ai_comment_reply_register_rest_routes');

/**
 * 评论列表行操作：AI 回复。
 */
function wanyesea_ai_comment_reply_row_actions($actions, $comment) {
    if (!wanyesea_ai_comment_reply_enabled() || !current_user_can('moderate_comments')) {
        return $actions;
    }

    if (!$comment instanceof WP_Comment) {
        return $actions;
    }

    if (wanyesea_ai_comment_is_ai_reply($comment)) {
        return $actions;
    }

    if (!current_user_can('edit_comment', $comment->comment_ID)) {
        return $actions;
    }

    $actions['wanyesea_ai_reply'] = sprintf(
        '<a href="#" class="wanyesea-ai-comment-reply-link" data-comment-id="%d">AI 回复</a>',
        (int) $comment->comment_ID
    );

    $error = (string) get_comment_meta((int) $comment->comment_ID, WANYESEA_AI_COMMENT_REPLY_META_ERROR, true);
    if ($error !== '') {
        $actions['wanyesea_ai_reply_error'] = sprintf(
            '<span class="wanyesea-ai-comment-reply-error" title="%s">AI 回复失败</span>',
            esc_attr($error)
        );
    }

    return $actions;
}

add_filter('comment_row_actions', 'wanyesea_ai_comment_reply_row_actions', 10, 2);


/**
 * 后台脚本与样式。
 */
function wanyesea_ai_comment_reply_enqueue_assets($hook_suffix) {
    if (!wanyesea_ai_comment_reply_should_enqueue($hook_suffix)) {
        return;
    }

    $ver = Wanyesea_AI_Config::get_asset_version();

    wp_enqueue_style(
        'wanyesea-ai-comment-reply',
        WanYesea_AI_url . '/assets/wanyesea-ai-comment-reply.css',
        array(),
        $ver
    );

    wp_enqueue_script('wp-api-fetch');

    wp_enqueue_script(
        'wanyesea-ai-comment-reply',
        WanYesea_AI_url . '/assets/wanyesea-ai-comment-reply.js',
        array('wp-api-fetch'),
        $ver,
        true
    );

    $configured_providers = function_exists('wanyesea_ai_post_draft_list_configured_providers')
        ? wanyesea_ai_post_draft_list_configured_providers()
        : array();
    $default_pair         = function_exists('wanyesea_ai_post_draft_resolve_text_model')
        ? wanyesea_ai_post_draft_resolve_text_model()
        : null;

    wp_localize_script(
        'wanyesea-ai-comment-reply',
        'wanyeseaAiCommentReply',
        array(
            'restRoot'   => esc_url_raw(rest_url()),
            'restNonce'  => wp_create_nonce('wp_rest'),
            'hasModel'   => $configured_providers !== array(),
            'providers'  => $configured_providers,
            'default'    => $default_pair !== null
                ? array('provider_id' => $default_pair[0], 'model_id' => $default_pair[1])
                : null,
            'settingsUrl'=> admin_url('admin.php?page=WanYesea_AI'),
            'i18n'       => array(
                'title'            => 'AI 评论回复',
                'generate'         => '生成回复',
                'regenerate'       => '重新生成',
                'publish'          => '发布回复',
                'cancel'           => '取消',
                'noteLabel'        => '补充说明（可选）',
                'notePlaceholder'  => '例如：语气更活泼一些，并引导读者查看文章第三节。',
                'replyLabel'       => '回复预览',
                'replyPlaceholder' => '生成后可在此编辑再发布',
                'providerLabel'    => 'AI 服务商',
                'modelLabel'       => '模型',
                'modelPlaceholder' => '选择模型',
                'modelCustomLabel' => '或手动输入模型 ID',
                'loadModels'       => '刷新模型列表',
                'loadingModels'    => '加载模型中…',
                'generating'       => '生成中…',
                'publishing'       => '发布中…',
                'published'        => '回复已发布',
                'noModel'          => '未检测到已配置 API Key 的文本服务商，请先在插件设置中配置。',
                'openSettings'     => '打开 AI 连接设置',
                'errorGeneric'     => '操作失败，请稍后重试。',
                'close'            => '关闭',
            ),
        )
    );
}

add_action('admin_enqueue_scripts', 'wanyesea_ai_comment_reply_enqueue_assets');

/**
 * 子比主题：评论用户名旁显示 AI 标识。
 */
function wanyesea_ai_comment_reply_zibll_badge($badge, $comment) {
    if (!wanyesea_ai_comment_reply_show_badge() || !wanyesea_ai_comment_is_ai_reply($comment)) {
        return $badge;
    }

    $label = apply_filters('wanyesea_ai_comment_reply_badge_label', 'AI');
    $badge .= '<span class="badg c-blue badg-sm flex0 ml3 wanyesea-ai-comment-reply-badge" title="AI 辅助回复" data-toggle="tooltip">' . esc_html($label) . '</span>';

    return $badge;
}

add_filter('comments_user_name_badge', 'wanyesea_ai_comment_reply_zibll_badge', 10, 2);
