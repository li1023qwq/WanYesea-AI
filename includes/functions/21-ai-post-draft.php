<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

const WANYESEA_AI_POST_DRAFT_META_STATUS   = '_wanyesea_ai_draft_status';
const WANYESEA_AI_POST_DRAFT_META_PROMPT   = '_wanyesea_ai_draft_prompt';
const WANYESEA_AI_POST_DRAFT_META_KEYWORDS = '_wanyesea_ai_draft_keywords';
const WANYESEA_AI_POST_DRAFT_META_ERROR    = '_wanyesea_ai_draft_error';
const WANYESEA_AI_POST_DRAFT_META_PROVIDER = '_wanyesea_ai_draft_provider';
const WANYESEA_AI_POST_DRAFT_META_MODEL    = '_wanyesea_ai_draft_model';
const WANYESEA_AI_POST_DRAFT_META_BATCH_ID    = '_wanyesea_ai_draft_batch_id';
const WANYESEA_AI_POST_DRAFT_META_BATCH_INDEX = '_wanyesea_ai_draft_batch_index';
const WANYESEA_AI_POST_DRAFT_META_BATCH_TOTAL = '_wanyesea_ai_draft_batch_total';
const WANYESEA_AI_POST_DRAFT_META_WORKER      = '_wanyesea_ai_draft_worker';
const WANYESEA_AI_POST_DRAFT_META_REQUEST_ID  = '_wanyesea_ai_draft_request_id';
const WANYESEA_AI_POST_DRAFT_META_GENERATED    = '_wanyesea_ai_generated';
const WANYESEA_AI_POST_DRAFT_CRON_HOOK     = 'wanyesea_ai_process_post_draft';

/**
 * 单次最多可生成的草稿篇数。
 */
function wanyesea_ai_post_draft_max_count() {
    return max(1, min(10, (int) apply_filters('wanyesea_ai_post_draft_max_count', 5)));
}

/**
 * 规范化生成篇数。
 */
function wanyesea_ai_post_draft_sanitize_count($count) {
    $max   = wanyesea_ai_post_draft_max_count();
    $count = (int) $count;

    if ($count < 1) {
        return 1;
    }
    if ($count > $max) {
        return $max;
    }

    return $count;
}

/**
 * 提交指纹（用于短时间去重，防止重复 POST 创建多篇占位草稿）。
 */
function wanyesea_ai_post_draft_submission_fingerprint($prompt, $keywords, $provider_id, $model_id, $count) {
    return hash(
        'sha256',
        implode(
            "\0",
            array(
                (string) get_current_user_id(),
                (string) $prompt,
                (string) $keywords,
                sanitize_key((string) $provider_id),
                function_exists('wanyesea_ai_normalize_model_id')
                    ? wanyesea_ai_normalize_model_id($model_id)
                    : trim((string) $model_id),
                (string) wanyesea_ai_post_draft_sanitize_count($count),
            )
        )
    );
}

/**
 * @return array<string, mixed>|null
 */
function wanyesea_ai_post_draft_get_idempotency_cache($request_id, $user_id) {
    $request_id = sanitize_text_field((string) $request_id);
    $user_id    = (int) $user_id;
    if ($request_id === '' || $user_id <= 0) {
        return null;
    }

    $cached = get_transient('wya_draft_idem_' . $user_id . '_' . md5($request_id));

    return is_array($cached) ? $cached : null;
}

/**
 * @param array<string, mixed> $response
 */
function wanyesea_ai_post_draft_set_idempotency_cache($request_id, $user_id, array $response) {
    $request_id = sanitize_text_field((string) $request_id);
    $user_id    = (int) $user_id;
    if ($request_id === '' || $user_id <= 0) {
        return;
    }

    set_transient(
        'wya_draft_idem_' . $user_id . '_' . md5($request_id),
        $response,
        10 * MINUTE_IN_SECONDS
    );
}

function wanyesea_ai_post_draft_try_acquire_create_lock($fingerprint) {
    $fingerprint = sanitize_text_field((string) $fingerprint);
    if ($fingerprint === '') {
        return true;
    }

    $key = 'wya_draft_create_lock_' . $fingerprint;
    if (get_transient($key)) {
        return false;
    }

    set_transient($key, '1', 90);

    return true;
}

function wanyesea_ai_post_draft_release_create_lock($fingerprint) {
    delete_transient('wya_draft_create_lock_' . sanitize_text_field((string) $fingerprint));
}

/**
 * 支持 AI 草稿的文章类型。
 *
 * @return list<string>
 */
function wanyesea_ai_post_draft_post_types() {
    return apply_filters('wanyesea_ai_post_draft_post_types', array('post'));
}

/**
 * 当前后台列表页是否应加载 AI 草稿资源。
 *
 * @param string $hook_suffix admin_enqueue_scripts hook。
 */
function wanyesea_ai_post_draft_should_enqueue($hook_suffix) {
    if ($hook_suffix !== 'edit.php') {
        return false;
    }

    $post_type = isset($GLOBALS['typenow']) ? (string) $GLOBALS['typenow'] : 'post';
    if (!in_array($post_type, wanyesea_ai_post_draft_post_types(), true)) {
        return false;
    }

    $post_type_object = get_post_type_object($post_type);
    if (!$post_type_object || !current_user_can($post_type_object->cap->create_posts)) {
        return false;
    }

    return true;
}

/**
 * 是否至少有一个文本厂商已配置 API Key（用于后台按钮轻量检测）。
 */
function wanyesea_ai_post_draft_has_text_api_key() {
    if (!function_exists('wanyesea_ai_text_capable_provider_ids')
        || !function_exists('wanyesea_ai_get_connector_api_key_resolved')) {
        return false;
    }

    foreach (wanyesea_ai_text_capable_provider_ids() as $provider_id) {
        if (wanyesea_ai_get_connector_api_key_resolved($provider_id) !== '') {
            return true;
        }
    }

    return false;
}

/**
 * 厂商默认模型 hint（自定义 Connector / 网关等）。
 */
function wanyesea_ai_post_draft_provider_model_hint($provider_id) {
    $provider_id = sanitize_key((string) $provider_id);
    if ($provider_id === '') {
        return '';
    }

    if (class_exists('Wanyesea_AI_Custom_Connectors')) {
        $defs = Wanyesea_AI_Custom_Connectors::definitions();
        if (isset($defs[$provider_id]['preferred_model_hint'])) {
            $hint = trim((string) $defs[$provider_id]['preferred_model_hint']);
            if ($hint !== '') {
                return $hint;
            }
        }
    }

    if ($provider_id === 'openai') {
        return 'gpt-4o-mini';
    }

    return '';
}

/**
 * 解析首个可用文本模型（已配置 Key；不强制 Registry「已校验」）。
 *
 * @return array{0: string, 1: string}|null
 */
function wanyesea_ai_post_draft_resolve_text_model() {
    if (!function_exists('wanyesea_ai_text_capable_provider_ids')) {
        return null;
    }

    wanyesea_ai_post_draft_ensure_auth();

    $hint_fallback = null;

    foreach (wanyesea_ai_text_capable_provider_ids() as $provider_id) {
        $provider_id = sanitize_key((string) $provider_id);
        if ($provider_id === '') {
            continue;
        }

        if (function_exists('wanyesea_ai_get_connector_api_key_resolved')
            && wanyesea_ai_get_connector_api_key_resolved($provider_id) === '') {
            continue;
        }

        $models = function_exists('wanyesea_ai_test_lab_list_model_ids')
            ? wanyesea_ai_test_lab_list_model_ids($provider_id, 'text', false)
            : array();

        if ($models !== array()) {
            return array($provider_id, $models[0]);
        }

        $hint = wanyesea_ai_post_draft_provider_model_hint($provider_id);
        if ($hint !== '' && $hint_fallback === null) {
            $hint_fallback = array($provider_id, $hint);
        }
    }

    return $hint_fallback;
}

/**
 * 读取任务绑定的 provider/model（创建任务时写入，避免 Cron 上下文探测失败）。
 *
 * @return array{0: string, 1: string}|null
 */
function wanyesea_ai_post_draft_get_bound_model($post_id) {
    $provider_id = sanitize_key((string) get_post_meta((int) $post_id, WANYESEA_AI_POST_DRAFT_META_PROVIDER, true));
    $model_id    = function_exists('wanyesea_ai_normalize_model_id')
        ? wanyesea_ai_normalize_model_id(get_post_meta((int) $post_id, WANYESEA_AI_POST_DRAFT_META_MODEL, true))
        : trim((string) get_post_meta((int) $post_id, WANYESEA_AI_POST_DRAFT_META_MODEL, true));

    if ($provider_id === '' || $model_id === '') {
        return null;
    }

    if (function_exists('wanyesea_ai_get_connector_api_key_resolved')
        && wanyesea_ai_get_connector_api_key_resolved($provider_id) === '') {
        return null;
    }

    return array($provider_id, $model_id);
}

/**
 * 绑定任务使用的 provider/model。
 *
 * @param array{0: string, 1: string} $model_pair
 */
function wanyesea_ai_post_draft_bind_model($post_id, array $model_pair) {
    update_post_meta((int) $post_id, WANYESEA_AI_POST_DRAFT_META_PROVIDER, sanitize_key((string) $model_pair[0]));
    $model_id = function_exists('wanyesea_ai_normalize_model_id')
        ? wanyesea_ai_normalize_model_id($model_pair[1])
        : trim((string) $model_pair[1]);
    update_post_meta((int) $post_id, WANYESEA_AI_POST_DRAFT_META_MODEL, $model_id);
}

/**
 * 注入 AI Client 鉴权。
 */
function wanyesea_ai_post_draft_ensure_auth() {
    if (function_exists('wanyesea_ai_ensure_ai_client_auth')) {
        wanyesea_ai_ensure_ai_client_auth();
        return;
    }
    if (function_exists('wanyesea_ai_test_lab_ensure_auth')) {
        wanyesea_ai_test_lab_ensure_auth();
    }
}

/**
 * 构建面向子比主题的文章生成提示词。
 *
 * @param string $user_prompt 用户提示词。
 * @param string $keywords    关键词（逗号或换行分隔）。
 * @param int    $batch_index 批次序号（从 1 起）。
 * @param int    $batch_total 批次总数。
 */
function wanyesea_ai_post_draft_build_generation_prompt($user_prompt, $keywords = '', $batch_index = 1, $batch_total = 1) {
    $user_prompt = trim((string) $user_prompt);
    $keywords    = trim((string) $keywords);
    $batch_index = max(1, (int) $batch_index);
    $batch_total = max(1, (int) $batch_total);

    $lines = array(
        '你是一位资深中文博客作者，正在为 WordPress「子比 Zibll」主题站点撰写文章草稿。',
        '请根据以下信息创作，并只输出一个 JSON 对象（不要 markdown 代码块、不要额外说明）：',
        '{"title":"文章标题","subtitle":"一句话副标题","excerpt":"120字以内摘要","content_html":"正文 HTML"}',
        '',
        '要求：',
        '- content_html 必须使用 HTML 标签（<h2> 小标题、<p> 段落、<ul><li> 列表），禁止只输出纯文本；',
        '- content_html 内如需引号请用「」或单引号，不要使用未转义的双引号；',
        '- JSON 字符串内的双引号须转义为 \\"，确保可被 json_decode 解析；',
        '- 语气自然、可读性强，适合资讯/教程类博客；',
        '- title 简洁有吸引力；subtitle 对应子比文章「副标题」字段；excerpt 适合用作 WordPress 摘要。',
    );

    if ($keywords !== '') {
        $lines[] = '';
        $lines[] = '关键词：' . $keywords;
    }

    $lines[] = '';
    $lines[] = '创作说明：' . ($user_prompt !== '' ? $user_prompt : '围绕关键词写一篇完整文章。');

    if ($batch_total > 1) {
        $lines[] = '';
        $lines[] = '批次要求：这是同一批次的第 ' . $batch_index . '/' . $batch_total . ' 篇，请独立成文，标题、结构与切入角度须与其他篇明显不同，避免重复。';
    }

    return implode("\n", $lines);
}

/**
 * OpenAI 兼容 chat/completions 直连（任意已配置端点的厂商）。
 *
 * @return string|\WP_Error
 */
function wanyesea_ai_post_draft_direct_chat_completions($provider_id, $model_id, $prompt, $max_tokens = 4096) {
    $provider_id = sanitize_key((string) $provider_id);
    $model_id    = function_exists('wanyesea_ai_normalize_model_id')
        ? wanyesea_ai_normalize_model_id($model_id)
        : trim(wp_unslash((string) $model_id));
    $prompt      = (string) $prompt;

    if ($provider_id === '' || $model_id === '' || $prompt === '') {
        return new WP_Error('wya_invalid_args', '模型或提示词无效');
    }

    if ($provider_id === 'openai'
        && function_exists('wanyesea_ai_relay_openai_direct_chat_completions')
        && function_exists('wanyesea_ai_relay_is_provider_active')
        && wanyesea_ai_relay_is_provider_active('openai')) {
        return wanyesea_ai_relay_openai_direct_chat_completions($model_id, $prompt, $max_tokens);
    }

    if (!function_exists('wanyesea_ai_get_provider_effective_endpoint')
        || !function_exists('wanyesea_ai_get_connector_api_key_resolved')) {
        return new WP_Error('wya_no_endpoint', '端点解析不可用');
    }

    $api_key = wanyesea_ai_get_connector_api_key_resolved($provider_id);
    if ($api_key === '') {
        return new WP_Error('wya_no_key', '未配置 API Key');
    }

    $endpoint = wanyesea_ai_get_provider_effective_endpoint($provider_id);
    $base_url = isset($endpoint['url']) ? rtrim((string) $endpoint['url'], '/') : '';
    if ($base_url === '') {
        return new WP_Error('wya_no_endpoint', '未配置 API 端点');
    }

    $timeout = (float) apply_filters('wanyesea_ai_post_draft_timeout', 180.0, $provider_id);
    $timeout = max(30, min(300, $timeout));

    $headers = array(
        'Authorization' => 'Bearer ' . $api_key,
        'Content-Type'  => 'application/json',
    );
    if ($provider_id === 'xiaomi') {
        $headers = array(
            'api-key'        => $api_key,
            'Content-Type'   => 'application/json',
        );
    }

    $payload = wp_json_encode(array(
        'model'       => $model_id,
        'messages'    => array(
            array(
                'role'    => 'user',
                'content' => $prompt,
            ),
        ),
        'max_tokens'            => max(512, (int) $max_tokens),
        'max_completion_tokens' => max(512, (int) $max_tokens),
        'temperature'             => 0.6,
    ));

    if (!is_string($payload)) {
        return new WP_Error('wya_payload', '无法构建请求 JSON');
    }

    $response = wp_safe_remote_post(
        $base_url . '/chat/completions',
        array(
            'timeout' => $timeout,
            'headers' => $headers,
            'body'    => $payload,
        )
    );

    if (is_wp_error($response)) {
        return $response;
    }

    $code     = (int) wp_remote_retrieve_response_code($response);
    $raw_body = (string) wp_remote_retrieve_body($response);
    $body     = json_decode($raw_body, true);

    if ($code < 200 || $code >= 300) {
        $snippet = wp_strip_all_tags(substr($raw_body, 0, 400));
        return new WP_Error(
            'wya_api_http',
            sprintf('HTTP %d：%s', $code, $snippet !== '' ? $snippet : '请求被拒绝')
        );
    }

    if (!is_array($body)) {
        return new WP_Error('wya_api_json', 'API 返回不是有效 JSON');
    }

    $text = wanyesea_ai_post_draft_extract_chat_completion_text($body);
    if ($text !== '') {
        return $text;
    }

    if (!empty($body['error']['message'])) {
        return new WP_Error('wya_api_error', (string) $body['error']['message']);
    }

    return new WP_Error('wya_api_empty', 'API 返回成功但未包含文本内容');
}

/**
 * 从 chat/completions 响应中提取文本（兼容多种厂商字段）。
 *
 * @param array<string, mixed> $body
 */
function wanyesea_ai_post_draft_extract_chat_completion_text(array $body) {
    $choice = isset($body['choices'][0]) && is_array($body['choices'][0]) ? $body['choices'][0] : array();
    $message = isset($choice['message']) && is_array($choice['message']) ? $choice['message'] : array();

    if (isset($message['content'])) {
        if (is_string($message['content']) && trim($message['content']) !== '') {
            return (string) $message['content'];
        }
        if (is_array($message['content'])) {
            $parts = array();
            foreach ($message['content'] as $part) {
                if (!is_array($part)) {
                    continue;
                }
                if (!empty($part['text']) && is_string($part['text'])) {
                    $parts[] = $part['text'];
                }
            }
            $joined = trim(implode("\n", $parts));
            if ($joined !== '') {
                return $joined;
            }
        }
    }

    if (!empty($choice['text']) && is_string($choice['text'])) {
        return (string) $choice['text'];
    }

    foreach (array('reasoning_content', 'reasoning') as $key) {
        if (!empty($message[$key]) && is_string($message[$key]) && trim($message[$key]) !== '') {
            return (string) $message[$key];
        }
    }

    return '';
}

/**
 * 长文文本生成（草稿专用，较高 max_tokens；不走测试页 256 token 路径）。
 *
 * @return string|\WP_Error
 */
function wanyesea_ai_post_draft_generate_text($provider_id, $model_id, $prompt) {
    $provider_id = sanitize_key((string) $provider_id);
    $model_id    = function_exists('wanyesea_ai_normalize_model_id')
        ? wanyesea_ai_normalize_model_id($model_id)
        : trim(wp_unslash((string) $model_id));
    $prompt      = (string) $prompt;
    $max_tokens  = (int) apply_filters('wanyesea_ai_post_draft_max_tokens', 8192);

    if ($provider_id === '' || $model_id === '' || $prompt === '') {
        return new WP_Error('wya_invalid_args', '模型或提示词无效');
    }

    wanyesea_ai_post_draft_ensure_auth();

    $direct = wanyesea_ai_post_draft_direct_chat_completions($provider_id, $model_id, $prompt, $max_tokens);
    if (is_string($direct) && $direct !== '') {
        return $direct;
    }

    if (!function_exists('wp_ai_client_prompt')) {
        return is_wp_error($direct)
            ? $direct
            : new WP_Error('wya_no_client', '未检测到 WP AI Client，请先在「晚夜深秋·AI插件」中配置连接。');
    }

    try {
        $options = \WordPress\AiClient\Providers\Http\DTO\RequestOptions::fromArray(
            array('timeout' => (float) apply_filters('wanyesea_ai_post_draft_timeout', 180.0, $provider_id))
        );
        $builder = wp_ai_client_prompt($prompt)->using_provider($provider_id);

        if (function_exists('wanyesea_ai_create_relay_openai_text_model_for_id')) {
            $explicit = wanyesea_ai_create_relay_openai_text_model_for_id($model_id);
            if ($explicit !== null) {
                $builder = $builder->usingModel($explicit);
            } else {
                $builder = $builder->using_model_preference(array($provider_id, $model_id));
            }
        } else {
            $builder = $builder->using_model_preference(array($provider_id, $model_id));
        }

        $text = $builder
            ->using_max_tokens($max_tokens)
            ->using_temperature(0.6)
            ->using_request_options($options)
            ->generate_text();

        if (is_wp_error($text)) {
            return is_wp_error($direct) ? $direct : $text;
        }
        if (is_string($text) && $text !== '') {
            return $text;
        }

        return is_wp_error($direct)
            ? $direct
            : new WP_Error('wya_empty', '模型未返回文本内容');
    } catch (Throwable $e) {
        if (is_wp_error($direct)) {
            return new WP_Error(
                'wya_generate_failed',
                $direct->get_error_message() . '（' . $e->getMessage() . '）'
            );
        }
        return new WP_Error('wya_generate_failed', $e->getMessage());
    }
}

/**
 * 规范化模型原始输出（去除 markdown 包裹等；不替换正文内引号，避免截断 JSON 字段）。
 */
function wanyesea_ai_post_draft_normalize_raw_model_output($raw) {
    return trim((string) $raw);
}

/**
 * 反转义 JSON 字符串片段。
 */
function wanyesea_ai_post_draft_unescape_json_string($value) {
    $value = (string) $value;
    if ($value === '') {
        return '';
    }

    return stripcslashes(
        str_replace(
            array('\\n', '\\r', '\\t', '\\/'),
            array("\n", "\r", "\t", '/'),
            $value
        )
    );
}

/**
 * 按 JSON 规则扫描提取字符串字段（支持 content_html 内未转义引号时的容错）。
 *
 * @return string
 */
function wanyesea_ai_post_draft_extract_json_string_value($raw, $key, $allow_unclosed = false) {
    $raw = (string) $raw;
    $key = (string) $key;
    if ($raw === '' || $key === '') {
        return '';
    }

    if (!preg_match('/"' . preg_quote($key, '/') . '"\s*:/u', $raw, $match, PREG_OFFSET_CAPTURE)) {
        return '';
    }

    $pos = (int) $match[0][1] + strlen($match[0][0]);
    $len = strlen($raw);

    while ($pos < $len && ctype_space($raw[$pos])) {
        $pos++;
    }

    if ($pos >= $len || $raw[$pos] !== '"') {
        return '';
    }

    $pos++;
    $value   = '';
    $escaped = false;

    while ($pos < $len) {
        $ch = $raw[$pos];

        if ($escaped) {
            $value   .= $ch;
            $escaped  = false;
            $pos++;
            continue;
        }

        if ($ch === '\\') {
            $value   .= $ch;
            $escaped  = true;
            $pos++;
            continue;
        }

        if ($ch === '"') {
            break;
        }

        $value .= $ch;
        $pos++;
    }

    if ($pos >= $len && $allow_unclosed && $value !== '') {
        return wanyesea_ai_post_draft_unescape_json_string($value);
    }

    if ($pos >= $len) {
        return '';
    }

    return wanyesea_ai_post_draft_unescape_json_string($value);
}

/**
 * 提取 content_html（优先取最长可用片段，避免在正文引号处被截断）。
 *
 * @return string
 */
function wanyesea_ai_post_draft_extract_content_html_from_raw($raw) {
    $raw = (string) $raw;
    if ($raw === '') {
        return '';
    }

    $candidates = array();

    $strict = wanyesea_ai_post_draft_extract_json_string_value($raw, 'content_html', false);
    if ($strict !== '') {
        $candidates[] = $strict;
    }

    $truncated = wanyesea_ai_post_draft_extract_json_string_value($raw, 'content_html', true);
    if ($truncated !== '') {
        $candidates[] = $truncated;
    }

    if (preg_match('/"content_html"\s*:\s*"(.*)$/su', $raw, $matches)) {
        $tail = (string) $matches[1];
        $tail = preg_replace('/"\s*}\s*$/u', '', $tail);
        $tail = preg_replace('/"\s*,\s*"(?:subtitle|excerpt|title|content|body)"\s*:/u', '', $tail);
        $tail = wanyesea_ai_post_draft_unescape_json_string(rtrim((string) $tail, '"'));
        if ($tail !== '') {
            $candidates[] = $tail;
        }
    }

    foreach (array('content', 'body', 'html', 'post_content') as $alt_key) {
        $alt = wanyesea_ai_post_draft_extract_json_string_value($raw, $alt_key, true);
        if ($alt !== '') {
            $candidates[] = $alt;
        }
    }

    if ($candidates === array()) {
        return '';
    }

    usort(
        $candidates,
        static function ($a, $b) {
            return strlen((string) $b) <=> strlen((string) $a);
        }
    );

    return (string) $candidates[0];
}

/**
 * 正则从非标准 JSON 中提取字段（兼容部分模型未转义引号的情况）。
 *
 * @return array<string, string>
 */
function wanyesea_ai_post_draft_regex_extract_json_fields($raw) {
    $raw    = wanyesea_ai_post_draft_normalize_raw_model_output($raw);
    $fields = array();
    $keys   = array('title', 'subtitle', 'excerpt');

    foreach ($keys as $key) {
        $value = wanyesea_ai_post_draft_extract_json_string_value($raw, $key, true);
        if ($value !== '') {
            $fields[$key] = $value;
        }
    }

    $content_html = wanyesea_ai_post_draft_extract_content_html_from_raw($raw);
    if ($content_html !== '') {
        $fields['content_html'] = $content_html;
    }

    return $fields;
}

/**
 * 从模型输出中解析 JSON。
 *
 * @return array<string, mixed>|\WP_Error
 */
function wanyesea_ai_post_draft_parse_model_json($raw) {
    $raw = wanyesea_ai_post_draft_normalize_raw_model_output($raw);
    if ($raw === '') {
        return new WP_Error('wya_empty_json', '模型返回为空');
    }

    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $raw, $matches)) {
        $raw = trim($matches[1]);
    }

    $start = strpos($raw, '{');
    $end   = strrpos($raw, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $raw = substr($raw, $start, $end - $start + 1);
    }

    $data = json_decode($raw, true);
    if (is_array($data) && $data !== array()) {
        return $data;
    }

    // 部分模型使用中文弯引号包裹 JSON 键值，仅在 json_decode 失败时再尝试规范化。
    $normalized = strtr(
        $raw,
        array(
            "\xE2\x80\x9C" => '"',
            "\xE2\x80\x9D" => '"',
            '“'            => '"',
            '”'            => '"',
        )
    );
    if ($normalized !== $raw) {
        $data = json_decode($normalized, true);
        if (is_array($data) && $data !== array()) {
            return $data;
        }
        $raw = $normalized;
    }

    $regex_fields = wanyesea_ai_post_draft_regex_extract_json_fields($raw);
    if ($regex_fields !== array()) {
        return $regex_fields;
    }

    return new WP_Error('wya_json_parse', '无法解析 AI 返回的 JSON，请调整提示词后重试。');
}

/**
 * 纯文本正文转为 HTML（无标签时自动分段、识别小标题）。
 */
function wanyesea_ai_post_draft_plain_text_to_html($text) {
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }

    if (preg_match('/<[a-z][^>]*>/i', $text)) {
        return $text;
    }

    if (strpos($text, '{') !== false && (strpos($text, '"content_html"') !== false || strpos($text, '"title"') !== false)) {
        $extracted = wanyesea_ai_post_draft_regex_extract_json_fields($text);
        if (!empty($extracted['content_html'])) {
            $text = $extracted['content_html'];
        } elseif (!empty($extracted['content'])) {
            $text = $extracted['content'];
        } elseif (!empty($extracted['body'])) {
            $text = $extracted['body'];
        }
    }

    $text = str_replace(array('\\n', '\\r'), array("\n", ''), $text);
    $text = preg_replace('/\s*[\r\n]+\s*/u', "\n\n", $text);

    $text = preg_replace(
        '/(?<=[。！？!?])\s+(?=[\x{4e00}-\x{9fff}A-Za-z0-9·《》【】（）]{2,18}(?:\s|$))/u',
        "\n\n",
        $text
    );

    $blocks  = preg_split('/\n\s*\n+/u', $text);
    $html    = array();

    if (!is_array($blocks)) {
        $blocks = array($text);
    }

    foreach ($blocks as $block) {
        $block = trim((string) $block);
        if ($block === '') {
            continue;
        }

        $is_heading = (bool) preg_match('/^[\x{4e00}-\x{9fff}A-Za-z0-9·《》【】（）\s]{2,18}$/u', $block)
            && !preg_match('/[。！？!?]$/u', $block)
            && mb_strlen($block) <= 18;

        if ($is_heading) {
            $html[] = '<h2>' . esc_html($block) . '</h2>';
            continue;
        }

        if (preg_match('/^([\x{4e00}-\x{9fff}A-Za-z0-9·《》【】（）]{2,18})\s+([\s\S]+)$/u', $block, $parts)) {
            $maybe_heading = trim($parts[1]);
            $body          = trim($parts[2]);
            if (mb_strlen($maybe_heading) <= 18 && !preg_match('/[。！？!?]$/u', $maybe_heading) && $body !== '') {
                $html[] = '<h2>' . esc_html($maybe_heading) . '</h2>';
                foreach (preg_split('/(?<=[。！？!?])\s*/u', $body, -1, PREG_SPLIT_NO_EMPTY) as $sentence) {
                    $sentence = trim($sentence);
                    if ($sentence !== '') {
                        $html[] = '<p>' . esc_html($sentence) . '</p>';
                    }
                }
                continue;
            }
        }

        foreach (preg_split('/(?<=[。！？!?])\s*/u', $block, -1, PREG_SPLIT_NO_EMPTY) as $sentence) {
            $sentence = trim($sentence);
            if ($sentence !== '') {
                $html[] = '<p>' . esc_html($sentence) . '</p>';
            }
        }
    }

    if ($html === array()) {
        $html[] = '<p>' . esc_html($text) . '</p>';
    }

    return implode("\n", $html);
}

/**
 * 清洗并补全 content_html（含纯文本转 HTML）。
 */
function wanyesea_ai_post_draft_prepare_content_html($html) {
    $html = trim((string) $html);
    if ($html === '') {
        return '';
    }

    if (strpos($html, '{') !== false && strpos($html, 'content_html') !== false) {
        $extracted = wanyesea_ai_post_draft_regex_extract_json_fields($html);
        if (!empty($extracted['content_html'])) {
            $html = $extracted['content_html'];
        }
    }

    if (!preg_match('/<[a-z][^>]*>/i', $html)) {
        $html = wanyesea_ai_post_draft_plain_text_to_html($html);
    }

    return $html;
}

/**
 * 统一 content 字段名，并补全缺失键。
 *
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function wanyesea_ai_post_draft_normalize_payload_fields(array $data) {
    if (empty($data['content_html'])) {
        foreach (array('content', 'body', 'article', 'html', 'post_content') as $key) {
            if (!empty($data[$key])) {
                $data['content_html'] = (string) $data[$key];
                break;
            }
        }
    }

    if (!empty($data['content_html'])) {
        $data['content_html'] = wanyesea_ai_post_draft_prepare_content_html($data['content_html']);
    }

    return $data;
}

/**
 * 解析 JSON；失败时用纯文本兜底为可发布草稿。
 *
 * @return array<string, mixed>|\WP_Error
 */
function wanyesea_ai_post_draft_parse_or_fallback($raw, $user_prompt = '', $keywords = '') {
    $raw_normalized = wanyesea_ai_post_draft_normalize_raw_model_output($raw);
    $parsed         = wanyesea_ai_post_draft_parse_model_json($raw_normalized);

    if (!is_wp_error($parsed)) {
        $parsed = wanyesea_ai_post_draft_normalize_payload_fields($parsed);
        if (!empty($parsed['content_html'])) {
            $regex_html = wanyesea_ai_post_draft_extract_content_html_from_raw($raw_normalized);
            if ($regex_html !== '' && strlen($regex_html) > strlen((string) $parsed['content_html'])) {
                $parsed['content_html'] = wanyesea_ai_post_draft_prepare_content_html($regex_html);
            }
            if (empty($parsed['title'])) {
                $regex = wanyesea_ai_post_draft_regex_extract_json_fields($raw_normalized);
                if (!empty($regex['title'])) {
                    $parsed['title'] = $regex['title'];
                }
            }
            return $parsed;
        }
    }

    $regex = wanyesea_ai_post_draft_regex_extract_json_fields($raw_normalized);
    if (!empty($regex['content_html']) || !empty($regex['content']) || !empty($regex['body'])) {
        $payload = wanyesea_ai_post_draft_normalize_payload_fields($regex);
        if (!empty($payload['content_html'])) {
            return $payload;
        }
    }

    $plain = trim(wp_strip_all_tags($raw_normalized));
    if ($plain === '' || (strpos($plain, '"title"') !== false && strpos($plain, 'content_html') !== false)) {
        if ($regex !== array() && !empty($regex['content_html'])) {
            return wanyesea_ai_post_draft_normalize_payload_fields($regex);
        }
    }

    if ($plain === '') {
        return is_wp_error($parsed)
            ? $parsed
            : new WP_Error('wya_json_parse', '无法解析 AI 返回内容');
    }

    $title_source = !empty($regex['title'])
        ? $regex['title']
        : ($user_prompt !== '' ? $user_prompt : $keywords);
    $title        = function_exists('mb_substr')
        ? mb_substr(wp_strip_all_tags($title_source), 0, 60)
        : substr(wp_strip_all_tags($title_source), 0, 60);
    if ($title === '') {
        $lines = preg_split('/\r\n|\r|\n/', $plain);
        $title = isset($lines[0]) ? (function_exists('mb_substr') ? mb_substr($lines[0], 0, 60) : substr($lines[0], 0, 60)) : 'AI 草稿';
    }

    $content_html = wanyesea_ai_post_draft_plain_text_to_html($plain);
    $excerpt      = !empty($regex['excerpt'])
        ? $regex['excerpt']
        : (function_exists('mb_substr') ? mb_substr(wp_strip_all_tags($plain), 0, 120) : substr(wp_strip_all_tags($plain), 0, 120));

    return array(
        'title'        => $title,
        'subtitle'     => isset($regex['subtitle']) ? $regex['subtitle'] : '',
        'excerpt'      => $excerpt,
        'content_html' => $content_html,
        '_fallback'    => true,
    );
}

/**
 * 正文过短时自动续写一次（部分模型输出 token 不足或被截断）。
 *
 * @return string
 */
function wanyesea_ai_post_draft_maybe_extend_short_html($provider_id, $model_id, $html, $user_prompt = '', $keywords = '') {
    $html = trim((string) $html);
    if ($html === '') {
        return '';
    }

    $plain_len = function_exists('mb_strlen')
        ? mb_strlen(wp_strip_all_tags($html))
        : strlen(wp_strip_all_tags($html));
    $min_len = (int) apply_filters('wanyesea_ai_post_draft_min_content_chars', 400);

    if ($plain_len >= $min_len) {
        return $html;
    }

    $extend_prompt = implode(
        "\n",
        array(
            '以下是一篇博客文章草稿 HTML，但内容过短或未写完。请补全并续写为完整正文。',
            '只输出一个 JSON 对象：{"content_html":"完整 HTML 正文"}',
            '要求：保留已有段落含义，补全未写完的句子，正文不少于 800 字，使用 <h2>、<p> 等标签。',
            '',
            '主题：' . ($user_prompt !== '' ? $user_prompt : $keywords),
            '',
            '已有正文：',
            $html,
        )
    );

    $raw = wanyesea_ai_post_draft_generate_text($provider_id, $model_id, $extend_prompt);
    if (is_wp_error($raw) || !is_string($raw) || trim($raw) === '') {
        return $html;
    }

    $extended = wanyesea_ai_post_draft_parse_or_fallback($raw, $user_prompt, $keywords);
    if (is_wp_error($extended) || empty($extended['content_html'])) {
        return $html;
    }

    $extended_html = wanyesea_ai_post_draft_prepare_content_html((string) $extended['content_html']);
    $extended_len  = function_exists('mb_strlen')
        ? mb_strlen(wp_strip_all_tags($extended_html))
        : strlen(wp_strip_all_tags($extended_html));

    if ($extended_len <= $plain_len) {
        return $html;
    }

    return $extended_html;
}

/**
 * 将 HTML 转为古腾堡块内容。
 */
function wanyesea_ai_post_draft_html_to_blocks($html) {
    $html = trim((string) $html);
    if ($html === '') {
        return '';
    }

    if (strpos($html, '<!-- wp:') !== false) {
        return $html;
    }

    $html = wp_kses_post($html);

    libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $wrapped  = '<?xml encoding="utf-8" ?><div id="wya-root">' . $html . '</div>';
    if (!@$document->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
        return "<!-- wp:paragraph -->\n<p>" . esc_html(wp_strip_all_tags($html)) . "</p>\n<!-- /wp:paragraph -->";
    }

    $root = $document->getElementById('wya-root');
    if (!$root) {
        return "<!-- wp:paragraph -->\n<p>" . esc_html(wp_strip_all_tags($html)) . "</p>\n<!-- /wp:paragraph -->";
    }

    $blocks = array();
    foreach ($root->childNodes as $node) {
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            continue;
        }

        $tag   = strtolower($node->nodeName);
        $inner = trim($document->saveHTML($node));
        if ($inner === '') {
            continue;
        }

        if ($tag === 'p') {
            $blocks[] = "<!-- wp:paragraph -->\n{$inner}\n<!-- /wp:paragraph -->";
            continue;
        }

        if (in_array($tag, array('h1', 'h2', 'h3', 'h4', 'h5', 'h6'), true)) {
            $level = max(1, min(6, (int) substr($tag, 1)));
            if ($level === 1) {
                $level = 2;
            }
            $blocks[] = "<!-- wp:heading {\"level\":{$level}} -->\n{$inner}\n<!-- /wp:heading -->";
            continue;
        }

        if ($tag === 'ul' || $tag === 'ol') {
            $list_type = $tag === 'ol' ? 'ol' : 'ul';
            $blocks[]  = "<!-- wp:list {\"ordered\":" . ($list_type === 'ol' ? 'true' : 'false') . "} -->\n{$inner}\n<!-- /wp:list -->";
            continue;
        }

        if ($tag === 'blockquote') {
            $blocks[] = "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">" . esc_html($node->textContent) . "</blockquote>\n<!-- /wp:quote -->";
            continue;
        }

        $blocks[] = "<!-- wp:html -->\n{$inner}\n<!-- /wp:html -->";
    }

    if ($blocks === array()) {
        return "<!-- wp:paragraph -->\n<p>" . esc_html(wp_strip_all_tags($html)) . "</p>\n<!-- /wp:paragraph -->";
    }

    return implode("\n\n", $blocks);
}

/**
 * 写入子比副标题等扩展字段。
 */
function wanyesea_ai_post_draft_apply_zibll_meta($post_id, array $payload) {
    $subtitle = isset($payload['subtitle']) ? sanitize_text_field((string) $payload['subtitle']) : '';
    if ($subtitle === '') {
        return;
    }

    $zib_meta = get_post_meta($post_id, 'zib_other_data', true);
    if (!is_array($zib_meta)) {
        $zib_meta = array();
    }
    $zib_meta['subtitle'] = $subtitle;
    update_post_meta($post_id, 'zib_other_data', $zib_meta);
}

/**
 * 更新草稿任务状态。
 */
function wanyesea_ai_post_draft_set_status($post_id, $status, $error_message = '') {
    update_post_meta($post_id, WANYESEA_AI_POST_DRAFT_META_STATUS, sanitize_key((string) $status));
    if ($error_message !== '') {
        update_post_meta(
            $post_id,
            WANYESEA_AI_POST_DRAFT_META_ERROR,
            wp_strip_all_tags(substr((string) $error_message, 0, 500))
        );
    } else {
        delete_post_meta($post_id, WANYESEA_AI_POST_DRAFT_META_ERROR);
    }
}

/**
 * 避免 Cron 与 shutdown 或并发请求重复调用 API。
 */
function wanyesea_ai_post_draft_acquire_lock($post_id) {
    $post_id = (int) $post_id;
    if ($post_id <= 0) {
        return false;
    }

    $worker = (int) get_post_meta($post_id, WANYESEA_AI_POST_DRAFT_META_WORKER, true);
    if ($worker > 0 && (time() - $worker) < 20 * MINUTE_IN_SECONDS) {
        return false;
    }

    update_post_meta($post_id, WANYESEA_AI_POST_DRAFT_META_WORKER, (string) time());

    return true;
}

function wanyesea_ai_post_draft_release_lock($post_id) {
    delete_post_meta((int) $post_id, WANYESEA_AI_POST_DRAFT_META_WORKER);
}

/**
 * 后台异步执行 AI 草稿生成。
 *
 * @param int $post_id 草稿文章 ID。
 */
function wanyesea_ai_process_post_draft_job($post_id) {
    $post_id = (int) $post_id;
    if ($post_id <= 0) {
        return;
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit((int) apply_filters('wanyesea_ai_post_draft_php_time_limit', 300));
    }
    if (function_exists('ignore_user_abort')) {
        @ignore_user_abort(true);
    }

    $post = get_post($post_id);
    if (!$post || $post->post_type === 'revision') {
        return;
    }

    $job_status = get_post_meta($post_id, WANYESEA_AI_POST_DRAFT_META_STATUS, true);
    if (!in_array($job_status, array('pending', 'processing'), true)) {
        return;
    }

    if (!wanyesea_ai_post_draft_acquire_lock($post_id)) {
        return;
    }

    wanyesea_ai_post_draft_set_status($post_id, 'processing');

    $user_prompt = (string) get_post_meta($post_id, WANYESEA_AI_POST_DRAFT_META_PROMPT, true);
    $keywords    = (string) get_post_meta($post_id, WANYESEA_AI_POST_DRAFT_META_KEYWORDS, true);
    $model_pair  = wanyesea_ai_post_draft_get_bound_model($post_id);
    if ($model_pair === null) {
        $model_pair = wanyesea_ai_post_draft_resolve_text_model();
    }

    if ($model_pair === null) {
        wanyesea_ai_post_draft_set_status($post_id, 'failed', '未找到可用的文本模型，请先在插件中配置 API Key 并验证连接。');
        wanyesea_ai_post_draft_release_lock($post_id);
        return;
    }

    wanyesea_ai_post_draft_bind_model($post_id, $model_pair);

    $batch_index = max(1, (int) get_post_meta($post_id, WANYESEA_AI_POST_DRAFT_META_BATCH_INDEX, true));
    $batch_total = max(1, (int) get_post_meta($post_id, WANYESEA_AI_POST_DRAFT_META_BATCH_TOTAL, true));

    $generation_prompt = wanyesea_ai_post_draft_build_generation_prompt(
        $user_prompt,
        $keywords,
        $batch_index,
        $batch_total
    );
    $raw               = wanyesea_ai_post_draft_generate_text($model_pair[0], $model_pair[1], $generation_prompt);

    if (is_wp_error($raw)) {
        wanyesea_ai_post_draft_set_status(
            $post_id,
            'failed',
            $raw->get_error_message() . ' [' . $model_pair[0] . ' / ' . $model_pair[1] . ']'
        );
        wanyesea_ai_post_draft_release_lock($post_id);
        return;
    }

    $payload = wanyesea_ai_post_draft_parse_or_fallback($raw, $user_prompt, $keywords);
    if (is_wp_error($payload)) {
        wanyesea_ai_post_draft_set_status($post_id, 'failed', $payload->get_error_message());
        wanyesea_ai_post_draft_release_lock($post_id);
        return;
    }

    $title   = isset($payload['title']) ? sanitize_text_field((string) $payload['title']) : '';
    $excerpt = isset($payload['excerpt']) ? sanitize_textarea_field((string) $payload['excerpt']) : '';
    $html    = isset($payload['content_html']) ? (string) $payload['content_html'] : '';
    $html    = wanyesea_ai_post_draft_maybe_extend_short_html(
        $model_pair[0],
        $model_pair[1],
        $html,
        $user_prompt,
        $keywords
    );

    if ($title === '') {
        $title = mb_substr(wp_strip_all_tags($user_prompt !== '' ? $user_prompt : $keywords), 0, 60);
    }
    if ($title === '') {
        $title = 'AI 草稿 ' . wp_date('Y-m-d H:i');
    }

    $content = wanyesea_ai_post_draft_html_to_blocks($html);
    if ($content === '') {
        wanyesea_ai_post_draft_set_status($post_id, 'failed', '正文为空，请重试或调整提示词。');
        wanyesea_ai_post_draft_release_lock($post_id);
        return;
    }

    $update = wp_update_post(
        array(
            'ID'           => $post_id,
            'post_title'   => $title,
            'post_excerpt' => $excerpt,
            'post_content' => $content,
        ),
        true
    );

    if (is_wp_error($update)) {
        wanyesea_ai_post_draft_set_status($post_id, 'failed', $update->get_error_message());
        wanyesea_ai_post_draft_release_lock($post_id);
        return;
    }

    wanyesea_ai_post_draft_apply_zibll_meta($post_id, $payload);
    wanyesea_ai_post_draft_set_status($post_id, 'complete');
    update_post_meta($post_id, WANYESEA_AI_POST_DRAFT_META_GENERATED, '1');
    wanyesea_ai_post_draft_release_lock($post_id);

    do_action('wanyesea_ai_post_draft_completed', $post_id, $payload, $model_pair);
}

add_action(WANYESEA_AI_POST_DRAFT_CRON_HOOK, 'wanyesea_ai_process_post_draft_job');

/**
 * 调度后台生成：默认 WP-Cron 独立请求（避免 REST 连接关闭后 shutdown 生成被中断）。
 */
function wanyesea_ai_post_draft_schedule_job($post_id) {
    $post_id = (int) $post_id;
    if ($post_id <= 0) {
        return;
    }

    if (!wp_next_scheduled(WANYESEA_AI_POST_DRAFT_CRON_HOOK, array($post_id))) {
        wp_schedule_single_event(time(), WANYESEA_AI_POST_DRAFT_CRON_HOOK, array($post_id));
    }

    $use_shutdown = (bool) apply_filters(
        'wanyesea_ai_post_draft_use_shutdown',
        defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
        $post_id
    );

    if ($use_shutdown) {
        wanyesea_ai_post_draft_queue_shutdown_run($post_id);
        return;
    }

    if (function_exists('spawn_cron')) {
        spawn_cron();
    }
}

/**
 * 在 REST 响应发送后继续执行（降低 WP-Cron 未触发导致一直失败/卡住的概率）。
 */
function wanyesea_ai_post_draft_queue_shutdown_run($post_id) {
    $post_id = (int) $post_id;
    if ($post_id <= 0) {
        return;
    }

    $key = 'wanyesea_ai_post_draft_shutdown_' . $post_id;
    if (!empty($GLOBALS[$key])) {
        return;
    }
    $GLOBALS[$key] = true;

    add_action(
        'shutdown',
        static function () use ($post_id) {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            wanyesea_ai_process_post_draft_job($post_id);
        },
        0
    );
}

/**
 * 已配置 API Key 的文本厂商列表（供弹窗选择）。
 *
 * @return list<array{id: string, label: string, default_model: string}>
 */
function wanyesea_ai_post_draft_list_configured_providers() {
    if (!function_exists('wanyesea_ai_text_capable_provider_ids')) {
        return array();
    }

    $meta_all = function_exists('wanyesea_ai_connect_provider_meta')
        ? wanyesea_ai_connect_provider_meta()
        : array();
    $providers = array();

    foreach (wanyesea_ai_text_capable_provider_ids() as $provider_id) {
        $provider_id = sanitize_key((string) $provider_id);
        if ($provider_id === '') {
            continue;
        }

        if (function_exists('wanyesea_ai_get_connector_api_key_resolved')
            && wanyesea_ai_get_connector_api_key_resolved($provider_id) === '') {
            continue;
        }

        $meta  = isset($meta_all[$provider_id]) ? $meta_all[$provider_id] : array();
        $label = isset($meta['label']) ? (string) $meta['label'] : ucfirst($provider_id);

        $providers[] = array(
            'id'            => $provider_id,
            'label'         => $label,
            'default_model' => wanyesea_ai_post_draft_provider_model_hint($provider_id),
        );
    }

    return apply_filters('wanyesea_ai_post_draft_configured_providers', $providers);
}

/**
 * 解析用户选择的厂商与模型；留空则自动选择。
 *
 * @return array{0: string, 1: string}|\WP_Error
 */
function wanyesea_ai_post_draft_resolve_user_model_pair($provider_id, $model_id) {
    $provider_id = sanitize_key((string) $provider_id);
    $model_id    = function_exists('wanyesea_ai_normalize_model_id')
        ? wanyesea_ai_normalize_model_id($model_id)
        : trim(wp_unslash((string) $model_id));

    if ($provider_id === '' && $model_id === '') {
        $auto = wanyesea_ai_post_draft_resolve_text_model();
        if ($auto === null) {
            return new WP_Error(
                'wya_no_model',
                '未找到可用的文本模型。请先在「晚夜深秋·AI插件 → AI 连接」配置 API Key。'
            );
        }
        return $auto;
    }

    if ($provider_id === '') {
        return new WP_Error('wya_no_provider', '请选择 AI 服务商');
    }

    if ($model_id === '') {
        return new WP_Error('wya_no_model_id', '请选择或填写模型 ID');
    }

    if (!function_exists('wanyesea_ai_text_capable_provider_ids')
        || !in_array($provider_id, wanyesea_ai_text_capable_provider_ids(), true)) {
        return new WP_Error('wya_invalid_provider', '不支持的 AI 服务商');
    }

    if (function_exists('wanyesea_ai_get_connector_api_key_resolved')
        && wanyesea_ai_get_connector_api_key_resolved($provider_id) === '') {
        return new WP_Error('wya_no_key', '该服务商未配置 API Key');
    }

    return array($provider_id, $model_id);
}

/**
 * REST：已配置 Key 的文本厂商列表。
 */
function wanyesea_ai_rest_list_post_draft_providers() {
    $providers = wanyesea_ai_post_draft_list_configured_providers();
    $default   = wanyesea_ai_post_draft_resolve_text_model();

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
 * REST：指定厂商的文本模型列表。
 */
function wanyesea_ai_rest_list_post_draft_models(WP_REST_Request $request) {
    $provider_id = sanitize_key((string) $request->get_param('provider_id'));
    $refresh     = rest_sanitize_boolean($request->get_param('refresh'));

    if ($provider_id === '') {
        return new WP_Error('wya_invalid_provider', '请指定服务商', array('status' => 400));
    }

    if (function_exists('wanyesea_ai_get_connector_api_key_resolved')
        && wanyesea_ai_get_connector_api_key_resolved($provider_id) === '') {
        return new WP_Error('wya_no_key', '该服务商未配置 API Key', array('status' => 400));
    }

    wanyesea_ai_post_draft_ensure_auth();

    $models = function_exists('wanyesea_ai_test_lab_list_model_ids')
        ? wanyesea_ai_test_lab_list_model_ids($provider_id, 'text', $refresh)
        : array();

    $hint = wanyesea_ai_post_draft_provider_model_hint($provider_id);
    if ($hint !== '' && !in_array($hint, $models, true)) {
        array_unshift($models, $hint);
    }

    return rest_ensure_response(
        array(
            'provider_id' => $provider_id,
            'models'      => array_values($models),
        )
    );
}

/**
 * 创建单篇占位草稿并排队生成。
 *
 * @param array{0: string, 1: string} $model_pair
 * @return int|\WP_Error
 */
function wanyesea_ai_post_draft_create_single_task(
    $post_type,
    $title_base,
    $prompt,
    $keywords,
    array $model_pair,
    $batch_id,
    $batch_index,
    $batch_total,
    $request_id = ''
) {
    $batch_index = max(1, (int) $batch_index);
    $batch_total = max(1, (int) $batch_total);
    $title       = (string) $title_base;

    if ($batch_total > 1) {
        $title .= ' (' . $batch_index . '/' . $batch_total . ')';
    }

    $post_id = wp_insert_post(
        array(
            'post_type'    => $post_type,
            'post_status'  => 'draft',
            'post_title'   => $title,
            'post_content' => '',
            'post_author'  => get_current_user_id(),
        ),
        true
    );

    if (is_wp_error($post_id)) {
        return $post_id;
    }

    update_post_meta($post_id, WANYESEA_AI_POST_DRAFT_META_PROMPT, $prompt);
    update_post_meta($post_id, WANYESEA_AI_POST_DRAFT_META_KEYWORDS, $keywords);
    update_post_meta($post_id, WANYESEA_AI_POST_DRAFT_META_BATCH_ID, sanitize_text_field((string) $batch_id));
    update_post_meta($post_id, WANYESEA_AI_POST_DRAFT_META_BATCH_INDEX, $batch_index);
    update_post_meta($post_id, WANYESEA_AI_POST_DRAFT_META_BATCH_TOTAL, $batch_total);
    if ($request_id !== '') {
        update_post_meta($post_id, WANYESEA_AI_POST_DRAFT_META_REQUEST_ID, sanitize_text_field((string) $request_id));
    }
    wanyesea_ai_post_draft_bind_model($post_id, $model_pair);
    wanyesea_ai_post_draft_set_status($post_id, 'pending');
    wanyesea_ai_post_draft_schedule_job($post_id);

    return (int) $post_id;
}

/**
 * REST：创建 AI 草稿任务。
 */
function wanyesea_ai_rest_create_post_draft(WP_REST_Request $request) {
    $post_type = sanitize_key((string) $request->get_param('post_type'));
    if ($post_type === '') {
        $post_type = 'post';
    }

    if (!in_array($post_type, wanyesea_ai_post_draft_post_types(), true)) {
        return new WP_Error('wya_invalid_post_type', '不支持的文章类型', array('status' => 400));
    }

    $post_type_object = get_post_type_object($post_type);
    if (!$post_type_object || !current_user_can($post_type_object->cap->create_posts)) {
        return new WP_Error('wya_forbidden', '没有创建文章的权限', array('status' => 403));
    }

    $model_pair = wanyesea_ai_post_draft_resolve_user_model_pair(
        (string) $request->get_param('provider_id'),
        (string) $request->get_param('model_id')
    );
    if (is_wp_error($model_pair)) {
        $model_pair->add_data(array('status' => 503));
        return $model_pair;
    }

    $prompt   = sanitize_textarea_field((string) $request->get_param('prompt'));
    $keywords = sanitize_textarea_field((string) $request->get_param('keywords'));

    if ($prompt === '' && $keywords === '') {
        return new WP_Error('wya_empty_prompt', '请填写提示词或关键词', array('status' => 400));
    }

    $count      = wanyesea_ai_post_draft_sanitize_count($request->get_param('count'));
    $request_id = sanitize_text_field((string) $request->get_param('request_id'));
    $user_id    = (int) get_current_user_id();
    $fingerprint = wanyesea_ai_post_draft_submission_fingerprint(
        $prompt,
        $keywords,
        $model_pair[0],
        $model_pair[1],
        $count
    );

    $cached = wanyesea_ai_post_draft_get_idempotency_cache($request_id, $user_id);
    if ($cached !== null) {
        return rest_ensure_response($cached);
    }

    $fp_cached = get_transient('wya_draft_fp_' . $fingerprint);
    if (is_array($fp_cached) && !empty($fp_cached['post_ids'])) {
        return rest_ensure_response($fp_cached);
    }

    if (!wanyesea_ai_post_draft_try_acquire_create_lock($fingerprint)) {
        $cached = wanyesea_ai_post_draft_get_idempotency_cache($request_id, $user_id);
        if ($cached !== null) {
            return rest_ensure_response($cached);
        }
        $fp_cached = get_transient('wya_draft_fp_' . $fingerprint);
        if (is_array($fp_cached) && !empty($fp_cached['post_ids'])) {
            return rest_ensure_response($fp_cached);
        }

        return new WP_Error(
            'wya_duplicate_in_progress',
            '相同内容的生成任务正在提交，请勿重复点击。',
            array('status' => 409)
        );
    }

    $batch_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('wya_', true);
    $title_base = 'AI 生成中：' . (function_exists('mb_substr')
        ? mb_substr(
            $prompt !== '' ? wp_strip_all_tags($prompt) : wp_strip_all_tags($keywords),
            0,
            48
        )
        : substr(
            $prompt !== '' ? wp_strip_all_tags($prompt) : wp_strip_all_tags($keywords),
            0,
            48
        ));

    $post_ids = array();
    for ($i = 1; $i <= $count; $i++) {
        $created = wanyesea_ai_post_draft_create_single_task(
            $post_type,
            $title_base,
            $prompt,
            $keywords,
            $model_pair,
            $batch_id,
            $i,
            $count,
            $request_id
        );

        if (is_wp_error($created)) {
            if ($post_ids === array()) {
                wanyesea_ai_post_draft_release_create_lock($fingerprint);
                return $created;
            }
            break;
        }

        $post_ids[] = $created;
    }

    if ($post_ids === array()) {
        wanyesea_ai_post_draft_release_create_lock($fingerprint);
        return new WP_Error('wya_create_failed', '创建草稿失败', array('status' => 500));
    }

    $created_count = count($post_ids);
    $message       = $created_count > 1
        ? sprintf('已提交 %d 篇草稿后台生成，你可以离开此页面；完成后会出现在列表中。', $created_count)
        : '已提交后台生成，你可以离开此页面；完成后草稿会出现在列表中。';

    $response = array(
        'post_ids'    => $post_ids,
        'post_id'     => $post_ids[0],
        'count'       => $created_count,
        'batch_id'    => $batch_id,
        'request_id'  => $request_id,
        'status'      => 'pending',
        'provider_id' => $model_pair[0],
        'model_id'    => $model_pair[1],
        'edit_url'    => get_edit_post_link($post_ids[0], 'raw'),
        'list_url'    => admin_url($post_type === 'post' ? 'edit.php' : 'edit.php?post_type=' . $post_type),
        'message'     => $message,
    );

    wanyesea_ai_post_draft_set_idempotency_cache($request_id, $user_id, $response);
    set_transient('wya_draft_fp_' . $fingerprint, $response, 30);
    wanyesea_ai_post_draft_release_create_lock($fingerprint);

    return rest_ensure_response($response);
}

/**
 * REST：重试失败的 AI 草稿任务。
 */
function wanyesea_ai_rest_retry_post_draft(WP_REST_Request $request) {
    $post_id = (int) $request['id'];
    $post    = get_post($post_id);

    if (!$post) {
        return new WP_Error('wya_not_found', '草稿不存在', array('status' => 404));
    }

    if (!current_user_can('edit_post', $post_id)) {
        return new WP_Error('wya_forbidden', '没有编辑该草稿的权限', array('status' => 403));
    }

    $status = (string) get_post_meta($post_id, WANYESEA_AI_POST_DRAFT_META_STATUS, true);
    if ($status !== 'failed') {
        return new WP_Error('wya_not_failed', '仅可重试标记为失败的任务', array('status' => 400));
    }

    wanyesea_ai_post_draft_release_lock($post_id);
    wanyesea_ai_post_draft_set_status($post_id, 'pending');
    delete_post_meta($post_id, WANYESEA_AI_POST_DRAFT_META_ERROR);
    wanyesea_ai_post_draft_schedule_job($post_id);

    return rest_ensure_response(
        array(
            'post_id' => $post_id,
            'status'  => 'pending',
            'message' => '已重新提交生成',
        )
    );
}

/**
 * REST：查询 AI 草稿任务状态。
 */
function wanyesea_ai_rest_get_post_draft(WP_REST_Request $request) {
    $post_id = (int) $request['id'];
    $post    = get_post($post_id);

    if (!$post) {
        return new WP_Error('wya_not_found', '草稿不存在', array('status' => 404));
    }

    if (!current_user_can('edit_post', $post_id)) {
        return new WP_Error('wya_forbidden', '没有查看该草稿的权限', array('status' => 403));
    }

    $status = (string) get_post_meta($post_id, WANYESEA_AI_POST_DRAFT_META_STATUS, true);
    $error  = (string) get_post_meta($post_id, WANYESEA_AI_POST_DRAFT_META_ERROR, true);

    return rest_ensure_response(
        array(
            'post_id'  => $post_id,
            'status'   => $status !== '' ? $status : 'unknown',
            'error'    => $error,
            'title'    => get_the_title($post),
            'edit_url' => current_user_can('edit_post', $post_id) ? get_edit_post_link($post_id, 'raw') : '',
        )
    );
}

/**
 * REST 参数：保留网关模型 ID 原样（如 64/gpt-4o）。
 */
function wanyesea_ai_post_draft_sanitize_model_id_param($value) {
    if (function_exists('wanyesea_ai_normalize_model_id')) {
        return wanyesea_ai_normalize_model_id($value);
    }

    return trim(wp_unslash((string) $value));
}

/**
 * REST 权限：创建草稿。
 */
function wanyesea_ai_rest_can_create_post_draft() {
    foreach (wanyesea_ai_post_draft_post_types() as $post_type) {
        $object = get_post_type_object($post_type);
        if ($object && current_user_can($object->cap->create_posts)) {
            return true;
        }
    }
    return false;
}

/**
 * REST 权限：查看指定草稿。
 */
function wanyesea_ai_rest_can_read_post_draft(WP_REST_Request $request) {
    $post_id = (int) $request['id'];
    return $post_id > 0 && current_user_can('edit_post', $post_id);
}

function wanyesea_ai_post_draft_register_rest_routes() {
    register_rest_route(
        'wanyesea-ai/v1',
        '/post-drafts/providers',
        array(
            'methods'             => 'GET',
            'permission_callback' => 'wanyesea_ai_rest_can_create_post_draft',
            'callback'            => 'wanyesea_ai_rest_list_post_draft_providers',
        )
    );

    register_rest_route(
        'wanyesea-ai/v1',
        '/post-drafts/models',
        array(
            'methods'             => 'GET',
            'permission_callback' => 'wanyesea_ai_rest_can_create_post_draft',
            'callback'            => 'wanyesea_ai_rest_list_post_draft_models',
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
        '/post-drafts',
        array(
            'methods'             => 'POST',
            'permission_callback' => 'wanyesea_ai_rest_can_create_post_draft',
            'callback'            => 'wanyesea_ai_rest_create_post_draft',
            'args'                => array(
                'prompt'      => array(
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_textarea_field',
                ),
                'keywords'    => array(
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_textarea_field',
                ),
                'post_type'   => array(
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_key',
                ),
                'provider_id' => array(
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_key',
                ),
                'model_id'    => array(
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'wanyesea_ai_post_draft_sanitize_model_id_param',
                ),
                'count'       => array(
                    'type'              => 'integer',
                    'required'          => false,
                    'default'           => 1,
                    'sanitize_callback' => 'wanyesea_ai_post_draft_sanitize_count',
                ),
                'request_id'  => array(
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        )
    );

    register_rest_route(
        'wanyesea-ai/v1',
        '/post-drafts/(?P<id>\d+)',
        array(
            'methods'             => 'GET',
            'permission_callback' => 'wanyesea_ai_rest_can_read_post_draft',
            'callback'            => 'wanyesea_ai_rest_get_post_draft',
        )
    );

    register_rest_route(
        'wanyesea-ai/v1',
        '/post-drafts/(?P<id>\d+)/retry',
        array(
            'methods'             => 'POST',
            'permission_callback' => 'wanyesea_ai_rest_can_read_post_draft',
            'callback'            => 'wanyesea_ai_rest_retry_post_draft',
        )
    );
}

add_action('rest_api_init', 'wanyesea_ai_post_draft_register_rest_routes');

/**
 * 列表「状态」列展示 AI 生成进度。
 */
function wanyesea_ai_post_draft_display_states($states, $post) {
    $status = (string) get_post_meta($post->ID, WANYESEA_AI_POST_DRAFT_META_STATUS, true);
    if ($status === 'pending' || $status === 'processing') {
        $batch_index = (int) get_post_meta($post->ID, WANYESEA_AI_POST_DRAFT_META_BATCH_INDEX, true);
        $batch_total = (int) get_post_meta($post->ID, WANYESEA_AI_POST_DRAFT_META_BATCH_TOTAL, true);
        $label       = 'AI 生成中';
        if ($batch_total > 1 && $batch_index > 0) {
            $label .= ' (' . $batch_index . '/' . $batch_total . ')';
        }
        $states['wanyesea_ai_generating'] = $label;
    } elseif ($status === 'failed') {
        $error = (string) get_post_meta($post->ID, WANYESEA_AI_POST_DRAFT_META_ERROR, true);
        $label = 'AI 生成失败';
        if ($error !== '') {
            $label .= '：' . wp_html_excerpt($error, 36, '…');
        }
        $states['wanyesea_ai_failed'] = $label;
    }

    return $states;
}

add_filter('display_post_states', 'wanyesea_ai_post_draft_display_states', 10, 2);

/**
 * 列表行操作：失败任务可一键重试。
 */
function wanyesea_ai_post_draft_row_actions($actions, $post) {
    if (!is_object($post) || !in_array($post->post_type, wanyesea_ai_post_draft_post_types(), true)) {
        return $actions;
    }

    if ((string) get_post_meta($post->ID, WANYESEA_AI_POST_DRAFT_META_STATUS, true) !== 'failed') {
        return $actions;
    }

    if (!current_user_can('edit_post', $post->ID)) {
        return $actions;
    }

    $actions['wanyesea_ai_retry'] = sprintf(
        '<a href="#" class="wanyesea-ai-retry-draft" data-post-id="%d">重试 AI</a>',
        (int) $post->ID
    );

    return $actions;
}

add_filter('post_row_actions', 'wanyesea_ai_post_draft_row_actions', 10, 2);

/**
 * 文章列表页：为卡住的任务补调度 Cron（例如 REST 重复提交后第一篇未跑完）。
 */
function wanyesea_ai_post_draft_rescue_stale_pending_on_list() {
    if (!current_user_can('edit_posts')) {
        return;
    }

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->base !== 'edit' || $screen->post_type === 'attachment') {
        return;
    }

    if (!in_array($screen->post_type, wanyesea_ai_post_draft_post_types(), true)) {
        return;
    }

    if (get_transient('wya_draft_rescue_scan_' . get_current_user_id())) {
        return;
    }
    set_transient('wya_draft_rescue_scan_' . get_current_user_id(), '1', 45);

    $pending_posts = get_posts(
        array(
            'post_type'      => $screen->post_type,
            'post_status'    => 'draft',
            'author'         => get_current_user_id(),
            'posts_per_page' => 5,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'   => WANYESEA_AI_POST_DRAFT_META_STATUS,
                    'value' => array('pending', 'processing'),
                    'compare' => 'IN',
                ),
            ),
        )
    );

    if ($pending_posts === array()) {
        return;
    }

    foreach ($pending_posts as $post_id) {
        if (!wp_next_scheduled(WANYESEA_AI_POST_DRAFT_CRON_HOOK, array((int) $post_id))) {
            wp_schedule_single_event(time(), WANYESEA_AI_POST_DRAFT_CRON_HOOK, array((int) $post_id));
        }
    }

    if (function_exists('spawn_cron')) {
        spawn_cron();
    }
}

add_action('admin_footer-edit.php', 'wanyesea_ai_post_draft_rescue_stale_pending_on_list');

/**
 * 加载后台脚本与样式。
 */
function wanyesea_ai_post_draft_enqueue_assets($hook_suffix) {
    if (!wanyesea_ai_post_draft_should_enqueue($hook_suffix)) {
        return;
    }

    $ver       = Wanyesea_AI_Config::get_asset_version();
    $post_type = isset($GLOBALS['typenow']) ? (string) $GLOBALS['typenow'] : 'post';

    wp_enqueue_style(
        'wanyesea-ai-post-draft',
        WanYesea_AI_url . '/assets/wanyesea-ai-post-draft.css',
        array(),
        $ver
    );

    wp_enqueue_script('wp-api-fetch');

    wp_enqueue_script(
        'wanyesea-ai-post-draft',
        WanYesea_AI_url . '/assets/wanyesea-ai-post-draft.js',
        array('wp-api-fetch'),
        $ver,
        true
    );

    $configured_providers = wanyesea_ai_post_draft_list_configured_providers();
    $default_pair         = wanyesea_ai_post_draft_resolve_text_model();

    wp_localize_script(
        'wanyesea-ai-post-draft',
        'wanyeseaAiPostDraft',
        array(
            'postType'   => $post_type,
            'restRoot'   => esc_url_raw(rest_url()),
            'restNonce'  => wp_create_nonce('wp_rest'),
            'hasModel'   => $configured_providers !== array(),
            'providers'  => $configured_providers,
            'default'    => $default_pair !== null
                ? array('provider_id' => $default_pair[0], 'model_id' => $default_pair[1])
                : null,
            'maxCount'   => wanyesea_ai_post_draft_max_count(),
            'defaultCount' => 1,
            'settingsUrl'=> admin_url('admin.php?page=WanYesea_AI'),
            'i18n'       => array(
                'button'          => 'AI 草稿',
                'title'           => 'AI 文章草稿',
                'countLabel'      => '生成篇数',
                'countHelp'       => '一次提交将创建多篇独立草稿，每篇由 AI 分别生成。',
                'providerLabel'   => 'AI 服务商',
                'modelLabel'      => '模型',
                'modelPlaceholder'=> '选择模型',
                'modelCustomLabel'=> '或手动输入模型 ID',
                'loadModels'      => '刷新模型列表',
                'loadingModels'   => '加载模型中…',
                'noProviders'     => '暂无已配置 API Key 的文本服务商',
                'pickProvider'    => '请先选择服务商',
                'pickModel'       => '请选择或填写模型 ID',
                'promptLabel'     => '创作说明',
                'promptPlaceholder'=> '例如：写一篇关于 WordPress 7.0 AI 功能的入门教程，面向中文站长，语气轻松。',
                'keywordsLabel'   => '关键词（可选）',
                'keywordsPlaceholder'=> '多个关键词用逗号或换行分隔',
                'submit'          => '开始生成',
                'cancel'          => '取消',
                'submitting'      => '提交中…',
                'submitted'       => '已提交后台生成，你可以离开此页面。完成后草稿会出现在列表中。',
                'submittedBatch'  => '已提交 %d 篇草稿后台生成，你可以离开此页面。',
                'batchComplete'   => '批次完成：%d/%d 篇已生成',
                'batchProgress'   => '生成进度：%d/%d 篇完成',
                'noModel'         => '未检测到已配置 API Key 的文本服务商，请先在插件设置中配置。',
                'openSettings'    => '打开 AI 连接设置',
                'errorGeneric'    => '提交失败，请稍后重试。',
                'emptyInput'      => '请填写创作说明或关键词。',
                'close'           => '关闭',
                'retry'           => '重试 AI',
                'retrying'        => '重试中…',
            ),
        )
    );
}

add_action('admin_enqueue_scripts', 'wanyesea_ai_post_draft_enqueue_assets');

/**
 * 是否启用文章底部 AI 生成提示。
 */
function wanyesea_ai_post_draft_notice_enabled() {
    return wanyesea_ai_switcher_on('post_draft_ai_notice_s', true);
}

/**
 * AI 生成提示标题。
 */
function wanyesea_ai_post_draft_notice_title() {
    $title = WanYesea_AI('post_draft_ai_notice_title', 'AI 内容声明');
    $title = trim((string) $title);

    if ($title === '') {
        $title = 'AI 内容声明';
    }

    return apply_filters('wanyesea_ai_post_draft_notice_title', $title);
}

/**
 * AI 生成提示正文。
 */
function wanyesea_ai_post_draft_notice_text() {
    $text = WanYesea_AI('post_draft_ai_notice_text', '');
    $text = trim((string) $text);

    if ($text === '') {
        $text = '本文由 AI 辅助生成，内容仅供参考，请核实后使用。';
    }

    return apply_filters('wanyesea_ai_post_draft_notice_text', $text);
}

/**
 * 是否为 AI 草稿插件生成的文章。
 *
 * @param int|\WP_Post|null $post
 */
function wanyesea_ai_post_is_ai_generated($post = null) {
    $post = get_post($post);
    if (!$post) {
        return false;
    }

    if (get_post_meta($post->ID, WANYESEA_AI_POST_DRAFT_META_GENERATED, true) === '1') {
        return true;
    }

    return (string) get_post_meta($post->ID, WANYESEA_AI_POST_DRAFT_META_STATUS, true) === 'complete';
}

/**
 * 生成 AI 提示 HTML。
 *
 * @param int|\WP_Post|null $post
 * @return string
 */
function wanyesea_ai_post_draft_get_notice_html($post = null) {
    if (!wanyesea_ai_post_draft_notice_enabled()) {
        return '';
    }

    $post = get_post($post);
    if (!$post || !wanyesea_ai_post_is_ai_generated($post)) {
        return '';
    }

    if (!in_array($post->post_type, wanyesea_ai_post_draft_post_types(), true)) {
        return '';
    }

    if (!is_singular($post->post_type) && !is_preview()) {
        return '';
    }

    $title = wanyesea_ai_post_draft_notice_title();
    $text  = wanyesea_ai_post_draft_notice_text();

    $html  = '<div class="em09 muted-3-color wanyesea-ai-post-notice">';
    $html .= '<div><span>©</span> ' . esc_html($title) . '</div>';
    $html .= '<div class="posts-copyright">' . wp_kses_post($text) . '</div>';
    $html .= '</div>';

    return $html;
}

/**
 * 输出文章底部 AI 提示（子比版权声明同款结构）。
 *
 * @param int|\WP_Post|null $post
 */
function wanyesea_ai_post_draft_render_notice($post = null) {
    $html = wanyesea_ai_post_draft_get_notice_html($post);
    if ($html === '') {
        return;
    }

    echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * 子比主题：在页脚输出隐藏模板，由 JS 移至版权声明右侧并排。
 */
function wanyesea_ai_post_draft_footer_notice_template() {
    if (!function_exists('wanyesea_ai_is_zibll_active') || !wanyesea_ai_is_zibll_active()) {
        return;
    }

    if (!is_singular()) {
        return;
    }

    $html = wanyesea_ai_post_draft_get_notice_html(get_queried_object_id());
    if ($html === '') {
        return;
    }

    echo '<div id="wanyesea-ai-post-notice-template" class="wanyesea-ai-post-notice--hidden">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

add_action('wp_footer', 'wanyesea_ai_post_draft_footer_notice_template', 20);

/**
 * 前台加载 AI 提示样式与布局脚本。
 */
function wanyesea_ai_post_draft_enqueue_frontend_notice_assets() {
    if (!is_singular()) {
        return;
    }

    $post = get_queried_object();
    if (!$post instanceof WP_Post || !wanyesea_ai_post_is_ai_generated($post)) {
        return;
    }

    if (!wanyesea_ai_post_draft_notice_enabled()) {
        return;
    }

    $ver = Wanyesea_AI_Config::get_asset_version();

    wp_enqueue_style(
        'wanyesea-ai-post-notice',
        WanYesea_AI_url . '/assets/wanyesea-ai-post-notice.css',
        array(),
        $ver
    );

    if (function_exists('wanyesea_ai_is_zibll_active') && wanyesea_ai_is_zibll_active()) {
        wp_enqueue_script(
            'wanyesea-ai-post-notice',
            WanYesea_AI_url . '/assets/wanyesea-ai-post-notice.js',
            array(),
            $ver,
            true
        );
    }
}

add_action('wp_enqueue_scripts', 'wanyesea_ai_post_draft_enqueue_frontend_notice_assets');

/**
 * 非子比主题：正文末尾追加 AI 提示。
 *
 * @param string $content
 */
function wanyesea_ai_post_draft_append_notice_to_content($content) {
    if (!is_singular() || !in_the_loop() || !is_main_query()) {
        return $content;
    }

    if (function_exists('wanyesea_ai_is_zibll_active') && wanyesea_ai_is_zibll_active()) {
        return $content;
    }

    $html = wanyesea_ai_post_draft_get_notice_html(get_post());
    if ($html === '') {
        return $content;
    }

    return $content . $html;
}

add_filter('the_content', 'wanyesea_ai_post_draft_append_notice_to_content', 99);
