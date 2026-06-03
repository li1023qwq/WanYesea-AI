<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Events\BeforeGenerateResultEvent;
use WordPress\AiClient\Providers\Http\Contracts\WithHttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;

/**
 * 在模型发请求前绑定 Registry 鉴权（兼容中转/自定义/网关/官方连接）。
 *
 * @param BeforeGenerateResultEvent $event
 */
function wanyesea_ai_on_wp_ai_client_before_generate($event) {
    if (function_exists('wanyesea_ai_reinject_ai_client_auth')) {
        wanyesea_ai_reinject_ai_client_auth();
    }

    if (!$event instanceof BeforeGenerateResultEvent || !class_exists(AiClient::class)) {
        return;
    }

    try {
        $model    = $event->getModel();
        $registry = AiClient::defaultRegistry();
        $registry->bindModelDependencies($model);

        $provider_id = $model->providerMetadata()->getId();
        $auth        = $registry->getProviderRequestAuthentication($provider_id);
        if ($auth instanceof \WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface
            && $model instanceof WithRequestAuthenticationInterface) {
            $model->setRequestAuthentication($auth);
        }

        if ($model instanceof WithHttpTransporterInterface) {
            $model->setHttpTransporter($registry->getHttpTransporter());
        }
    } catch (Throwable $e) {
        return;
    }
}

add_action('wp_ai_client_before_generate_result', 'wanyesea_ai_on_wp_ai_client_before_generate', 1);

/**
 * @return array<string, mixed>
 */
function wanyesea_ai_zib_seo_json_schema() {
    return array(
        'type'                 => 'object',
        'properties'           => array(
            'title'       => array(
                'type'        => 'string',
                'description' => 'SEO title without site name suffix',
            ),
            'keywords'    => array(
                'type'        => 'string',
                'description' => 'Comma-separated meta keywords',
            ),
            'description' => array(
                'type'        => 'string',
                'description' => 'Meta description, 80-150 Chinese characters',
            ),
        ),
        'required'             => array('title', 'keywords', 'description'),
        'additionalProperties' => false,
    );
}

/**
 * 合并子比三项 SEO 规则为一次请求的系统指令（支持主题自定义 prompt）。
 */
function wanyesea_ai_zib_seo_batch_system_instruction() {
    $custom_title = function_exists('_pz') ? trim((string) _pz('ai_seo_opt', '', 'title_prompt')) : '';
    $custom_keys  = function_exists('_pz') ? trim((string) _pz('ai_seo_opt', '', 'keywords_prompt')) : '';
    $custom_desc  = function_exists('_pz') ? trim((string) _pz('ai_seo_opt', '', 'description_prompt')) : '';

    $parts = array(
        '你是一名资深中文 SEO 编辑。请根据用户提供的文章或分类上下文，一次性生成 SEO 标题、关键词、描述。',
        '必须只输出一个 JSON 对象，包含且仅包含三个字段：title、keywords、description。不要 Markdown、不要代码块、不要解释。',
    );

    if ($custom_title !== '' || $custom_keys !== '' || $custom_desc !== '') {
        if ($custom_title !== '') {
            $parts[] = '【title 规则】' . $custom_title;
        }
        if ($custom_keys !== '') {
            $parts[] = '【keywords 规则】' . $custom_keys;
        }
        if ($custom_desc !== '') {
            $parts[] = '【description 规则】' . $custom_desc;
        }
    } else {
        $parts[] = '【title】20–40 个汉字；含主关键词；不要网站名/品牌后缀；不要引号或“标题：”等前缀。';
        $parts[] = '【keywords】4–8 个中文关键词，英文逗号分隔，第一个为主关键词，总长约 50 字内。';
        $parts[] = '【description】80–150 个汉字，1–4 句通顺中文，自然含主关键词，不要网址与品牌后缀。';
    }

    return implode("\n", $parts);
}

/**
 * 构建与 Zib_AI_SEO_Ability 一致的文章/分类上下文。
 *
 * @return string|WP_Error
 */
function wanyesea_ai_zib_seo_build_context($type, $id) {
    $type = sanitize_key((string) $type);
    $id   = (int) $id;

    if ($type === 'post') {
        $post = get_post($id);
        if (!$post) {
            return new WP_Error('no_post', __('内容不存在', 'zib_language'));
        }

        $title   = trim((string) $post->post_title);
        $excerpt = trim((string) $post->post_excerpt);
        $content = wp_strip_all_tags((string) $post->post_content);
        $content = trim(preg_replace('/\s+/u', ' ', $content));
        $content = wp_trim_words($content, 1500, '');

        if ($title === '' && $content === '' && $excerpt === '') {
            return new WP_Error('empty_content', __('无可用内容，请先填写标题、摘要或正文并保存', 'zib_language'));
        }

        $parts = array();
        if ($title !== '') {
            $parts[] = '标题: ' . $title;
        }
        if ($excerpt !== '') {
            $parts[] = '摘要: ' . $excerpt;
        }
        if ($content !== '') {
            $parts[] = '正文:' . "\n" . $content;
        }

        return implode("\n\n", $parts);
    }

    if ($type === 'term') {
        $term = get_term($id);
        if (!$term || is_wp_error($term)) {
            return new WP_Error('no_term', __('分类不存在', 'zib_language'));
        }

        $parts = array();
        if ($term->name !== '') {
            $parts[] = '标题: ' . $term->name;
        }
        if ($term->description !== '') {
            $parts[] = '描述: ' . $term->description;
        }

        if ($parts === array()) {
            return new WP_Error('empty_term', __('没有可用的标题或描述', 'zib_language'));
        }

        return implode("\n\n", $parts);
    }

    return new WP_Error('invalid_type', __('无效的类型', 'zib_language'));
}

/**
 * @param string $text
 */
function wanyesea_ai_zib_seo_normalize_keywords($text) {
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }

    $text = trim($text, " \t\r\n\"'`*《》【】[]()（）。.,，;；");
    $text = preg_replace('/[\r\n、；;，]+/u', ',', $text);
    $text = preg_replace('/\s*,\s*/', ',', $text);
    $text = preg_replace('/,+/', ',', $text);
    $text = trim($text, ', ');

    $parts  = array_filter(array_map('trim', explode(',', $text)), 'strlen');
    $unique = array();
    foreach ($parts as $part) {
        if (!in_array($part, $unique, true)) {
            $unique[] = $part;
        }
    }

    return implode(',', $unique);
}

/**
 * @param array<string, mixed> $raw
 * @return array{title: string, keywords: string, description: string}|null
 */
function wanyesea_ai_zib_seo_normalize_parsed_fields(array $raw) {
    $title = isset($raw['title']) ? trim((string) $raw['title']) : '';
    $keys  = isset($raw['keywords']) ? wanyesea_ai_zib_seo_normalize_keywords($raw['keywords']) : '';
    $desc  = isset($raw['description']) ? trim((string) $raw['description']) : '';

    $desc = preg_replace('/[\r\n]+/u', ' ', $desc);
    $desc = trim($desc, " \t\r\n\"'`*");

    if ($title === '' && $keys === '' && $desc === '') {
        return null;
    }

    if ($title !== '' && function_exists('zib_get_delimiter_blog_name')) {
        $title = sanitize_text_field($title) . zib_get_delimiter_blog_name();
    } elseif ($title !== '') {
        $title = sanitize_text_field($title);
    }

    return array(
        'title'       => $title,
        'keywords'    => $keys !== '' ? sanitize_text_field($keys) : '',
        'description' => $desc !== '' ? sanitize_text_field($desc) : '',
    );
}

/**
 * @return array{title: string, keywords: string, description: string}|null
 */
function wanyesea_ai_zib_seo_parse_model_output($raw_text) {
    $raw_text = trim((string) $raw_text);
    if ($raw_text === '') {
        return null;
    }

    $json = function_exists('wanyesea_ai_extract_json_payload_from_text')
        ? wanyesea_ai_extract_json_payload_from_text($raw_text)
        : $raw_text;

    if ($json === '') {
        return null;
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return null;
    }

    return wanyesea_ai_zib_seo_normalize_parsed_fields($decoded);
}

/**
 * 直连 chat/completions（中转 OpenAI / 自定义端点），系统指令合并进 user 消息以兼容所有网关。
 *
 * @return string|WP_Error
 */
function wanyesea_ai_zib_seo_direct_chat($provider_id, $model_id, $context, $system_instruction, $max_tokens = 1024) {
    $provider_id = sanitize_key((string) $provider_id);
    $model_id    = function_exists('wanyesea_ai_normalize_model_id')
        ? wanyesea_ai_normalize_model_id($model_id)
        : trim((string) $model_id);

    $user_prompt = '请根据以下内容生成 SEO 的 title、keywords、description，并输出为 JSON 对象：' . "\n\n" . $context;
    if ($system_instruction !== '') {
        $user_prompt = $system_instruction . "\n\n" . $user_prompt;
    }

    if ($provider_id === 'openai'
        && function_exists('wanyesea_ai_relay_openai_direct_chat_completions')
        && function_exists('wanyesea_ai_relay_is_provider_active')
        && wanyesea_ai_relay_is_provider_active('openai')) {
        return wanyesea_ai_relay_openai_direct_chat_completions($model_id, $user_prompt, $max_tokens);
    }

    if (function_exists('wanyesea_ai_post_draft_direct_chat_completions')) {
        return wanyesea_ai_post_draft_direct_chat_completions($provider_id, $model_id, $user_prompt, $max_tokens);
    }

    return new WP_Error('wya_no_direct', '直连 chat 不可用');
}

/**
 * @return string|WP_Error
 */
function wanyesea_ai_zib_seo_via_wp_ai_client($provider_id, $model_id, $context, $system_instruction, $use_json_schema = true) {
    if (!function_exists('wp_ai_client_prompt')) {
        return new WP_Error('wya_no_client', '未检测到 WP AI Client');
    }

    $provider_id = sanitize_key((string) $provider_id);
    $model_id    = function_exists('wanyesea_ai_normalize_model_id')
        ? wanyesea_ai_normalize_model_id($model_id)
        : trim((string) $model_id);

    $user_prompt = '请根据以下内容生成 SEO 的 title、keywords、description：' . "\n\n" . $context;

    try {
        $timeout = (float) apply_filters('wanyesea_ai_zib_seo_request_timeout', 120.0, $provider_id);
        $options = RequestOptions::fromArray(array('timeout' => max(30.0, $timeout)));

        $builder = wp_ai_client_prompt($user_prompt)
            ->using_system_instruction($system_instruction)
            ->using_max_tokens((int) apply_filters('wanyesea_ai_zib_seo_max_tokens', 1024, $provider_id))
            ->using_temperature(0.5)
            ->using_request_options($options);

        $explicit = null;
        if ($provider_id === 'openai' && function_exists('wanyesea_ai_create_relay_openai_text_model_for_id')) {
            $explicit = wanyesea_ai_create_relay_openai_text_model_for_id($model_id);
        } elseif (function_exists('wanyesea_ai_create_custom_text_model_for_id')) {
            $explicit = wanyesea_ai_create_custom_text_model_for_id($provider_id, $model_id);
        }

        if ($explicit !== null) {
            $builder = $builder->usingModel($explicit);
        } else {
            $builder = $builder->using_model_preference(array($provider_id, $model_id));
        }

        if ($use_json_schema) {
            $builder = $builder->as_json_response(wanyesea_ai_zib_seo_json_schema());
        }

        $text = $builder->generate_text();
        if (is_wp_error($text)) {
            return $text;
        }
        if (!is_string($text) || trim($text) === '') {
            return new WP_Error('wya_empty', '模型未返回文本');
        }

        return $text;
    } catch (Throwable $e) {
        return new WP_Error('wya_exception', $e->getMessage());
    }
}

/**
 * 使用单个 provider/model 尝试批量生成。
 *
 * @return array{title: string, keywords: string, description: string}|WP_Error
 */
function wanyesea_ai_zib_seo_try_model_pair($provider_id, $model_id, $context, $system_instruction) {
    $max_tokens  = (int) apply_filters('wanyesea_ai_zib_seo_max_tokens', 1024, $provider_id);
    $last_error  = new WP_Error('wya_parse_failed', '无法解析模型返回的 SEO JSON');

    $raw = wanyesea_ai_zib_seo_direct_chat($provider_id, $model_id, $context, $system_instruction, $max_tokens);
    if (!is_wp_error($raw) && is_string($raw) && $raw !== '') {
        $parsed = wanyesea_ai_zib_seo_parse_model_output($raw);
        if (is_array($parsed) && $parsed['title'] !== '' && $parsed['keywords'] !== '' && $parsed['description'] !== '') {
            return $parsed;
        }
        $last_error = new WP_Error('wya_parse_failed', '直连返回无法解析为完整 SEO JSON');
    } elseif (is_wp_error($raw)) {
        $last_error = $raw;
    }

    foreach (array(true, false) as $use_schema) {
        $raw = wanyesea_ai_zib_seo_via_wp_ai_client($provider_id, $model_id, $context, $system_instruction, $use_schema);
        if (is_wp_error($raw)) {
            $last_error = $raw;
            continue;
        }
        $parsed = wanyesea_ai_zib_seo_parse_model_output($raw);
        if (is_array($parsed) && $parsed['title'] !== '' && $parsed['keywords'] !== '' && $parsed['description'] !== '') {
            return $parsed;
        }
        $last_error = new WP_Error('wya_parse_failed', 'Registry 返回无法解析为完整 SEO JSON');
    }

    return $last_error;
}

/**
 * 收集可用于 SEO 的 provider/model 对（官方 + 中转 + 自定义 + 网关）。
 *
 * @return list<array{0: string, 1: string}>
 */
function wanyesea_ai_zib_seo_collect_model_pairs() {
    $pairs = array();

    if (function_exists('wanyesea_ai_collect_text_model_pairs_for_priming')) {
        $pairs = wanyesea_ai_collect_text_model_pairs_for_priming('', true);
    }

    if ($pairs === array() && function_exists('WordPress\AI\get_preferred_models_for_text_generation')) {
        $pairs = \WordPress\AI\get_preferred_models_for_text_generation();
    }

    if ($pairs === array()) {
        $pairs = (array) apply_filters('wpai_preferred_text_models', array());
    }

    if ($pairs === array() && function_exists('wanyesea_ai_text_capable_provider_ids')) {
        foreach (wanyesea_ai_text_capable_provider_ids() as $provider_id) {
            $provider_id = sanitize_key((string) $provider_id);
            if ($provider_id === '') {
                continue;
            }
            if (function_exists('wanyesea_ai_get_connector_api_key_resolved')
                && wanyesea_ai_get_connector_api_key_resolved($provider_id) === '') {
                continue;
            }
            $model_id = function_exists('wanyesea_ai_get_text_model_hint_for_provider')
                ? wanyesea_ai_get_text_model_hint_for_provider($provider_id)
                : '';
            if ($model_id !== '') {
                $pairs[] = array($provider_id, $model_id);
            }
        }
    }

    $pairs = wanyesea_ai_zib_seo_append_registry_configured_pairs($pairs);

    return apply_filters('wanyesea_ai_zib_seo_model_pairs', $pairs);
}

/**
 * 补充仅在 WordPress「设置 → 连接」配置密钥、未写入本插件 Connectors 的厂商。
 *
 * @param list<array{0: string, 1: string}> $pairs
 * @return list<array{0: string, 1: string}>
 */
function wanyesea_ai_zib_seo_append_registry_configured_pairs(array $pairs) {
    if (!class_exists(AiClient::class)) {
        return $pairs;
    }

    $seen = array();
    foreach ($pairs as $pair) {
        if (!is_array($pair) || !isset($pair[0], $pair[1])) {
            continue;
        }
        $seen[sanitize_key((string) $pair[0]) . "\0" . trim((string) $pair[1])] = true;
    }

    $represented = array();
    foreach ($pairs as $pair) {
        if (is_array($pair) && isset($pair[0])) {
            $represented[sanitize_key((string) $pair[0])] = true;
        }
    }

    try {
        if (function_exists('wanyesea_ai_ensure_ai_client_auth')) {
            wanyesea_ai_ensure_ai_client_auth();
        }

        $registry = AiClient::defaultRegistry();
        foreach ($registry->getRegisteredProviderIds() as $provider_id) {
            $provider_id = sanitize_key((string) $provider_id);
            if ($provider_id === '' || isset($represented[$provider_id])) {
                continue;
            }

            try {
                if (!$registry->isProviderConfigured($provider_id)) {
                    continue;
                }
            } catch (Throwable $e) {
                continue;
            }

            if (!$registry->getProviderRequestAuthentication($provider_id) instanceof \WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface) {
                continue;
            }

            $model_ids = array();
            if (function_exists('wanyesea_ai_get_text_model_hint_for_provider')) {
                $hint = wanyesea_ai_get_text_model_hint_for_provider($provider_id);
                if ($hint !== '') {
                    $model_ids[] = $hint;
                }
            }

            $max_extra = (int) apply_filters('wanyesea_ai_zib_seo_registry_extra_models_per_provider', 2, $provider_id);
            if ($max_extra > 0 && function_exists('wanyesea_ai_probe_model_ids_for_capability')) {
                foreach (wanyesea_ai_probe_model_ids_for_capability($provider_id, 'text', false) as $model_id) {
                    if (count($model_ids) >= $max_extra) {
                        break;
                    }
                    if ($model_id !== '' && !in_array($model_id, $model_ids, true)) {
                        $model_ids[] = $model_id;
                    }
                }
            }

            foreach ($model_ids as $model_id) {
                $key = $provider_id . "\0" . $model_id;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $pairs[]    = array($provider_id, $model_id);
            }
        }
    } catch (Throwable $e) {
        return $pairs;
    }

    return $pairs;
}

/**
 * 一次请求生成 title / keywords / description（遍历所有已配置 AI）。
 *
 * @return array{title: string, keywords: string, description: string}|WP_Error
 */
function wanyesea_ai_zib_seo_generate_batch($type, $id) {
    if (function_exists('wanyesea_ai_ensure_ai_client_auth')) {
        wanyesea_ai_ensure_ai_client_auth();
    }
    if (function_exists('wanyesea_ai_prime_registry_for_text_generation')) {
        wanyesea_ai_prime_registry_for_text_generation('', true);
    }

    if (function_exists('ini_set')) {
        @ini_set('max_execution_time', (string) (int) apply_filters('wanyesea_ai_zib_seo_max_execution_time', 180));
    }

    $GLOBALS['wanyesea_ai_text_ability_running'] = true;

    $context = wanyesea_ai_zib_seo_build_context($type, $id);
    if (is_wp_error($context)) {
        unset($GLOBALS['wanyesea_ai_text_ability_running']);
        return $context;
    }

    $system  = wanyesea_ai_zib_seo_batch_system_instruction();
    $pairs   = wanyesea_ai_zib_seo_collect_model_pairs();
    $errors  = array();

    foreach ($pairs as $pair) {
        if (!is_array($pair) || !isset($pair[0], $pair[1])) {
            continue;
        }

        $result = wanyesea_ai_zib_seo_try_model_pair($pair[0], $pair[1], $context, $system);
        if (is_array($result)) {
            unset($GLOBALS['wanyesea_ai_text_ability_running']);
            return $result;
        }
        if (is_wp_error($result)) {
            $errors[] = $pair[0] . '/' . $pair[1] . ': ' . $result->get_error_message();
        }
    }

    unset($GLOBALS['wanyesea_ai_text_ability_running']);

    $message = $errors !== array()
        ? implode('；', array_slice($errors, 0, 3))
        : '没有可用的文本生成模型，请先在晚夜深秋 AI 或 WordPress 连接页配置 API Key';

    return new WP_Error('wya_seo_batch_failed', $message);
}

/**
 * @param string $value
 * @param string $error
 * @return array<string, string>
 */
function wanyesea_ai_zib_seo_field_payload($value, $error = '') {
    if ($error !== '') {
        return array('error' => $error);
    }
    if ($value === '') {
        return array('error' => '未生成内容');
    }

    return array('content' => $value);
}

/**
 * 子比 AJAX 结构：title / keywords / description。
 *
 * @return array<string, array<string, string>>
 */
function wanyesea_ai_zib_seo_to_ajax_response($type, $id) {
    $type = sanitize_key((string) $type);
    $id   = (int) $id;

    $batch = wanyesea_ai_zib_seo_generate_batch($type, $id);
    if (is_wp_error($batch)) {
        $msg = $batch->get_error_message();
        return array(
            'title'       => wanyesea_ai_zib_seo_field_payload('', $msg),
            'keywords'    => wanyesea_ai_zib_seo_field_payload('', $msg),
            'description' => wanyesea_ai_zib_seo_field_payload('', $msg),
        );
    }

    $out = array(
        'title'       => wanyesea_ai_zib_seo_field_payload($batch['title']),
        'keywords'    => wanyesea_ai_zib_seo_field_payload($batch['keywords']),
        'description' => wanyesea_ai_zib_seo_field_payload($batch['description']),
    );

    $map = array(
        'title'       => 'seo-title',
        'keywords'    => 'seo-keywords',
        'description' => 'seo-description',
    );

    foreach ($map as $field => $ability) {
        if (!empty($out[$field]['content'])) {
            continue;
        }
        if (!function_exists('zib_ai_seo_run_field')) {
            continue;
        }
        if (function_exists('wanyesea_ai_ensure_ai_client_auth')) {
            wanyesea_ai_ensure_ai_client_auth();
        }
        $single = zib_ai_seo_run_field($ability, $type, $id);
        if (is_array($single) && !empty($single['content'])) {
            $out[$field] = array('content' => (string) $single['content']);
        } elseif (is_array($single) && !empty($single['error'])) {
            $out[$field] = array('error' => (string) $single['error']);
        }
    }

    return $out;
}

/**
 * 请求内缓存的批量 SEO（供 API 单字段调用复用）。
 *
 * @return array<string, array<string, string>>
 */
function wanyesea_ai_zib_seo_get_cached_response($type, $id) {
    static $cache = array();

    $key = sanitize_key((string) $type) . ':' . (int) $id;
    if (!isset($cache[$key])) {
        $cache[$key] = wanyesea_ai_zib_seo_to_ajax_response($type, $id);
    }

    return $cache[$key];
}

/**
 * 兼容 WanYesea-API：按字段读取批量结果。
 *
 * @param string $ability seo-title|seo-keywords|seo-description
 * @return array<string, mixed>
 */
function wanyesea_ai_run_zib_seo_field($ability, $type, $id) {
    $map = array(
        'seo-title'       => 'title',
        'seo-keywords'    => 'keywords',
        'seo-description' => 'description',
    );

    $ability = sanitize_key((string) $ability);
    if (!isset($map[$ability])) {
        return array('error' => '未知的 SEO 能力');
    }

    $response = wanyesea_ai_zib_seo_get_cached_response($type, $id);
    $field    = $map[$ability];

    return isset($response[$field]) ? $response[$field] : array('error' => '生成失败');
}

/**
 * REST / 内部：返回扁平 fields + errors。
 *
 * @return array<string, mixed>|WP_Error
 */
function wanyesea_ai_generate_zib_seo_all($type, $id) {
    $response = wanyesea_ai_zib_seo_get_cached_response($type, $id);
    $fields   = array();
    $errors   = array();

    foreach (array('title', 'keywords', 'description') as $field) {
        $item = isset($response[$field]) && is_array($response[$field]) ? $response[$field] : array();
        if (!empty($item['content'])) {
            $fields[$field] = (string) $item['content'];
        } else {
            $fields[$field] = '';
            $errors[$field]  = !empty($item['error']) ? (string) $item['error'] : '未生成内容';
        }
    }

    return array(
        'type'   => sanitize_key((string) $type),
        'id'     => (int) $id,
        'fields' => $fields,
        'errors' => $errors,
    );
}

function wanyesea_ai_replace_zib_ai_seo_ajax() {
    if (!function_exists('zib_ajax_ai_seo_generate')) {
        return;
    }

    remove_action('wp_ajax_ai_seo_generate', 'zib_ajax_ai_seo_generate');
    remove_action('wp_ajax_nopriv_ai_seo_generate', 'zib_ajax_ai_seo_generate');
    add_action('wp_ajax_ai_seo_generate', 'wanyesea_ai_ajax_zib_ai_seo_generate');
}

add_action('init', 'wanyesea_ai_replace_zib_ai_seo_ajax', 99);

function wanyesea_ai_ajax_zib_ai_seo_generate() {
    if (!function_exists('zib_ai_seo_is_enabled') || !zib_ai_seo_is_enabled()) {
        if (function_exists('zib_send_json_error')) {
            zib_send_json_error(__('AI SEO 功能未启用', 'zib_language'));
        }
        wp_send_json_error(array('message' => __('AI SEO 功能未启用', 'zib_language')));
    }

    if (function_exists('zib_ajax_wp_verify_nonce')) {
        zib_ajax_wp_verify_nonce('zib_ai');
    }

    $type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : '';
    $id   = isset($_POST['id']) ? (int) $_POST['id'] : 0;

    if ($type === '' || !in_array($type, array('post', 'term'), true) || $id <= 0) {
        if (function_exists('zib_send_json_error')) {
            zib_send_json_error(__('参数错误', 'zib_language'));
        }
        wp_send_json_error(array('message' => __('参数错误', 'zib_language')));
    }

    if ($type === 'post' && !current_user_can('edit_post', $id)) {
        if (function_exists('zib_send_json_error')) {
            zib_send_json_error(__('权限不足', 'zib_language'));
        }
        wp_send_json_error(array('message' => __('权限不足', 'zib_language')));
    }

    if ($type === 'term' && !current_user_can('edit_term', $id)) {
        if (function_exists('zib_send_json_error')) {
            zib_send_json_error(__('权限不足', 'zib_language'));
        }
        wp_send_json_error(array('message' => __('权限不足', 'zib_language')));
    }

    $payload = wanyesea_ai_zib_seo_get_cached_response($type, $id);

    if (function_exists('zib_send_json_success')) {
        zib_send_json_success($payload);
    }

    wp_send_json_success($payload);
}
