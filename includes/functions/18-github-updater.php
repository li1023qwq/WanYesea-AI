<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

/**
 * GitHub Releases → WordPress 插件更新（固定仓库 li1023qwq/WanYesea-AI）。
 *
 * 可选：过滤器 wanyesea_ai_github_updater_config、WANYESEA_AI_GITHUB_TOKEN（私有仓库 / 提高 API 限额）。
 */
final class Wanyesea_AI_Github_Updater {

    const DEFAULT_REPO     = 'li1023qwq/WanYesea-AI';
    const CACHE_KEY       = 'wanyesea_ai_latest_release';
    const CACHE_FAIL_KEY  = 'wanyesea_ai_latest_release_fail';
    const CACHE_TTL       = 1800;
    const CACHE_FAIL_TTL  = 300;
    const ADMIN_NOTICE_KEY = 'wanyesea_ai_github_update_notice';

    public static function boot() {
        if (!self::is_enabled()) {
            return;
        }

        add_filter('pre_set_site_transient_update_plugins', array(__CLASS__, 'filter_update_transient'));
        add_filter('plugins_api', array(__CLASS__, 'filter_plugin_info'), 10, 3);
        add_filter('plugin_action_links_' . plugin_basename(WanYesea_AI_path . 'index.php'), array(__CLASS__, 'plugin_action_links'));
        add_action('admin_post_wanyesea_ai_check_github_update', array(__CLASS__, 'handle_check_update'));
        add_action('admin_notices', array(__CLASS__, 'render_admin_notice'));
    }

    public static function is_enabled() {
        $config = self::config();
        return $config['api_url'] !== '';
    }

    /**
     * @return array{repo:string, api_url:string, releases_url:string, token:string}
     */
    public static function config() {
        $defaults = array(
            'repo'         => self::resolve_repo_slug(),
            'api_url'      => '',
            'releases_url' => '',
            'token'        => self::resolve_token(),
        );

        $config = apply_filters('wanyesea_ai_github_updater_config', $defaults);
        if (!is_array($config)) {
            $config = $defaults;
        } else {
            $config = array_merge($defaults, $config);
        }

        $repo = self::normalize_repo_slug((string) ($config['repo'] ?? ''));
        if ($repo !== '') {
            if ($config['api_url'] === '') {
                $config['api_url'] = 'https://api.github.com/repos/' . $repo . '/releases/latest';
            }
            if ($config['releases_url'] === '') {
                $config['releases_url'] = 'https://github.com/' . $repo . '/releases';
            }
        }

        $config['repo'] = $repo;
        $config['token'] = trim((string) ($config['token'] ?? ''));

        return $config;
    }

    /**
     * @return array{version:string, body:string, homepage:string, package:string, published_at:string}|null
     */
    public static function get_available_update() {
        $release = self::get_release();
        if (!$release) {
            return null;
        }
        if (version_compare($release['version'], Wanyesea_AI_Config::get_version(), '<=')) {
            return null;
        }
        return $release;
    }

    /**
     * @return array{version:string, body:string, homepage:string, package:string, published_at:string}|null
     */
    public static function refresh_available_update() {
        self::clear_cache();
        return self::get_available_update();
    }

    public static function clear_cache() {
        delete_site_transient(self::CACHE_KEY);
        delete_site_transient(self::CACHE_FAIL_KEY);
    }

    /**
     * 更新页状态 HTML。
     */
    public static function get_status_panel_html() {
        $cfg     = self::config();
        $current = Wanyesea_AI_Config::get_version();
        $release = self::get_release();
        $check   = wp_nonce_url(
            admin_url('admin-post.php?action=wanyesea_ai_check_github_update'),
            'wanyesea_ai_check_github_update'
        );

        $html  = '<div class="wya-github-update-panel">';
        $html .= '<p><strong>GitHub 仓库：</strong><code>' . esc_html($cfg['repo']) . '</code> '
            . '<a class="button button-small" href="' . esc_url($check) . '"><i class="fa fa-refresh"></i> 检查更新</a></p>';

        if ($release === null) {
            $fail = (string) get_site_transient(self::CACHE_FAIL_KEY);
            if ($fail === 'not_found') {
                $html .= '<p class="muted-3-color em09" style="margin:0">'
                    . '仓库已配置，但尚无 Release（GitHub 仓库可能仍为空）。请先推送代码，再创建首个 Release（tag 如 <code>v' . esc_html($current) . '</code>），并上传 <code>WanYesea-AI.zip</code>。'
                    . ' <a href="' . esc_url($cfg['releases_url']) . '" target="_blank" rel="noopener noreferrer">前往 GitHub Releases</a></p>';
            } elseif ($fail === 'rate_limit') {
                $html .= '<p class="muted-3-color em09" style="margin:0">GitHub API 请求过于频繁，请稍后再试；私有仓库可在 wp-config 定义 <code>WANYESEA_AI_GITHUB_TOKEN</code>。</p>';
            } else {
                $html .= '<p class="muted-3-color em09" style="margin:0">暂时无法获取 Release 信息，请稍后点击「检查更新」重试。</p>';
            }
        } elseif (version_compare($release['version'], $current, '>')) {
            $html .= '<p class="wya-github-update-panel__new"><span class="wya-badge wya-badge--warn">有新版本</span> '
                . 'GitHub <strong>v' . esc_html($release['version']) . '</strong>，当前 <strong>v' . esc_html($current) . '</strong>。'
                . ' 请前往 <a href="' . esc_url(admin_url('plugins.php')) . '">插件</a> 页点击「现在更新」，或 '
                . '<a href="' . esc_url($cfg['releases_url']) . '" target="_blank" rel="noopener noreferrer">下载 Release</a> 手动替换。</p>';
            if (!empty($release['package'])) {
                $html .= '<p class="muted-3-color em09" style="margin:0">WordPress 将尝试从 Release 附件 <code>.zip</code> 自动安装（请确保 Release 中上传了完整插件包）。</p>';
            } else {
                $html .= '<p class="muted-3-color em09" style="margin:0">当前 Release 未提供 <code>.zip</code> 附件，请手动下载后替换插件目录。</p>';
            }
        } else {
            $html .= '<p class="wya-github-update-panel__ok"><span class="wya-badge wya-badge--ok">已是最新</span> '
                . '当前 v' . esc_html($current) . '，与 GitHub 最新 Release 一致。</p>';
        }

        $html .= '</div>';
        return $html;
    }

    public static function plugin_action_links($links) {
        $cfg = self::config();

        if (self::get_available_update()) {
            array_unshift(
                $links,
                '<a href="' . esc_url(admin_url('plugins.php')) . '" style="color:#d63638;font-weight:600">有新版本可用</a>'
            );
        }

        $check = wp_nonce_url(
            admin_url('admin-post.php?action=wanyesea_ai_check_github_update'),
            'wanyesea_ai_check_github_update'
        );
        array_unshift($links, '<a href="' . esc_url($check) . '">检查更新</a>');

        if ($cfg['releases_url'] !== '') {
            array_unshift(
                $links,
                '<a href="' . esc_url($cfg['releases_url']) . '" target="_blank" rel="noopener noreferrer">GitHub</a>'
            );
        }

        return $links;
    }

    public static function filter_update_transient($transient) {
        if (!is_object($transient)) {
            return $transient;
        }

        $release     = self::get_release();
        $plugin_file = plugin_basename(WanYesea_AI_path . 'index.php');
        $current     = Wanyesea_AI_Config::get_version();

        if (!$release || version_compare($release['version'], $current, '<=')) {
            if (!isset($transient->no_update) || !is_array($transient->no_update)) {
                $transient->no_update = array();
            }
            $transient->no_update[$plugin_file] = self::build_update_object($release ?: array('version' => $current));
            return $transient;
        }

        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = array();
        }
        $transient->response[$plugin_file] = self::build_update_object($release);
        return $transient;
    }

    public static function filter_plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? '') !== self::slug()) {
            return $result;
        }

        $release = self::get_release() ?: array('version' => Wanyesea_AI_Config::get_version(), 'body' => '');
        $info    = self::build_update_object($release);
        $info->sections = array(
            'description' => Wanyesea_AI_Config::get_description(),
            'changelog'   => self::format_changelog((string) ($release['body'] ?? '')),
        );
        return $info;
    }

    public static function handle_check_update() {
        if (!current_user_can('update_plugins')) {
            wp_die(esc_html__('您没有权限执行此操作。'));
        }

        check_admin_referer('wanyesea_ai_check_github_update');

        $release = self::refresh_available_update();
        $message = array(
            'type'    => 'info',
            'message' => '已检查 GitHub Release，当前已是最新版本。',
        );

        if ($release === null && self::get_release() === null) {
            $fail = (string) get_site_transient(self::CACHE_FAIL_KEY);
            if ($fail === 'not_found') {
                $message = array(
                    'type'    => 'warning',
                    'message' => '仓库 li1023qwq/WanYesea-AI 已连接，但尚无 Release。请先推送代码并创建首个 Release（附 WanYesea-AI.zip）。',
                );
            } else {
                $message = array(
                    'type'    => 'error',
                    'message' => '无法获取 GitHub Release，请确认仓库地址、Release 是否存在，或稍后再试。',
                );
            }
        } elseif ($release !== null) {
            $message = array(
                'type'    => 'success',
                'message' => sprintf(
                    '发现新版本 v%s，请前往「插件」页更新。',
                    $release['version']
                ),
            );
        }

        set_transient(self::ADMIN_NOTICE_KEY, $message, 60);
        wp_safe_redirect(admin_url('admin.php?page=WanYesea_AI#tab=更新日志'));
        exit;
    }

    public static function render_admin_notice() {
        if (!current_user_can('update_plugins')) {
            return;
        }

        $message = get_transient(self::ADMIN_NOTICE_KEY);
        if (!is_array($message) || empty($message['message'])) {
            return;
        }

        delete_transient(self::ADMIN_NOTICE_KEY);

        $class = 'notice-info';
        if (($message['type'] ?? '') === 'success') {
            $class = 'notice-success';
        } elseif (($message['type'] ?? '') === 'warning') {
            $class = 'notice-warning';
        } elseif (($message['type'] ?? '') === 'error') {
            $class = 'notice-error';
        }

        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>'
            . esc_html((string) $message['message']) . '</p></div>';
    }

    /**
     * @param array<string, mixed> $release
     */
    private static function build_update_object(array $release) {
        $cfg = self::config();
        $obj = new stdClass();
        $obj->slug          = self::slug();
        $obj->plugin        = plugin_basename(WanYesea_AI_path . 'index.php');
        $obj->new_version   = (string) ($release['version'] ?? Wanyesea_AI_Config::get_version());
        $obj->url           = (string) ($release['homepage'] ?? $cfg['releases_url']);
        $obj->package       = (string) ($release['package'] ?? '');
        $obj->tested        = '7.0';
        $obj->requires      = Wanyesea_AI_Config::get_requires_wp() !== '' ? Wanyesea_AI_Config::get_requires_wp() : '7.0';
        $obj->requires_php  = Wanyesea_AI_Config::get_requires_php() !== '' ? Wanyesea_AI_Config::get_requires_php() : '7.4';
        $obj->name          = Wanyesea_AI_Config::get_name();
        $obj->homepage      = $cfg['releases_url'];
        $obj->last_updated  = (string) ($release['published_at'] ?? '');
        return $obj;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function get_release() {
        if (!self::is_enabled()) {
            return null;
        }

        if (get_site_transient(self::CACHE_FAIL_KEY)) {
            return null;
        }

        $cached = get_site_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $cfg      = self::config();
        $headers  = array(
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'wanyesea-ai/' . Wanyesea_AI_Config::get_version(),
        );
        if ($cfg['token'] !== '') {
            $headers['Authorization'] = 'Bearer ' . $cfg['token'];
        }

        $response = wp_remote_get($cfg['api_url'], array(
            'timeout' => 15,
            'headers' => $headers,
        ));

        if (is_wp_error($response)) {
            self::set_fail_cache('network');
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($code === 404) {
            self::set_fail_cache('not_found');
            return null;
        }
        if ($code === 403 && stripos($body, 'rate limit') !== false) {
            self::set_fail_cache('rate_limit');
            return null;
        }
        if ($code === 429) {
            self::set_fail_cache('rate_limit');
            return null;
        }
        if ($code < 200 || $code >= 300 || $body === '') {
            self::set_fail_cache('invalid');
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || !empty($data['draft']) || !empty($data['prerelease'])) {
            self::set_fail_cache('invalid');
            return null;
        }

        $version = preg_replace('/^v/i', '', trim((string) ($data['tag_name'] ?? '')));
        if ($version === '' || !preg_match('/^\d+(?:\.\d+){1,3}/', $version)) {
            self::set_fail_cache('invalid');
            return null;
        }

        $release = array(
            'version'      => $version,
            'body'         => isset($data['body']) ? (string) $data['body'] : '',
            'homepage'     => isset($data['html_url']) ? esc_url_raw((string) $data['html_url']) : $cfg['releases_url'],
            'package'      => self::resolve_package_url($data),
            'published_at' => isset($data['published_at']) ? (string) $data['published_at'] : '',
        );

        set_site_transient(self::CACHE_KEY, $release, self::CACHE_TTL);
        return $release;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function resolve_package_url(array $data) {
        $assets = isset($data['assets']) && is_array($data['assets']) ? $data['assets'] : array();
        $slug   = self::slug();

        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = isset($asset['name']) ? strtolower((string) $asset['name']) : '';
            $url  = isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';
            if ($url === '' || substr($name, -4) !== '.zip') {
                continue;
            }
            if (strpos($name, $slug) !== false || strpos($name, 'wanyesea') !== false) {
                return esc_url_raw($url);
            }
        }

        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = isset($asset['name']) ? strtolower((string) $asset['name']) : '';
            $url  = isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';
            if ($url !== '' && substr($name, -4) === '.zip') {
                return esc_url_raw($url);
            }
        }

        return isset($data['zipball_url']) ? esc_url_raw((string) $data['zipball_url']) : '';
    }

    private static function resolve_repo_slug() {
        return self::DEFAULT_REPO;
    }

    private static function set_fail_cache($reason) {
        set_site_transient(self::CACHE_FAIL_KEY, sanitize_key((string) $reason), self::CACHE_FAIL_TTL);
    }

    private static function resolve_token() {
        if (defined('WANYESEA_AI_GITHUB_TOKEN')) {
            $constant = constant('WANYESEA_AI_GITHUB_TOKEN');
            if (is_scalar($constant) && trim((string) $constant) !== '') {
                return trim((string) $constant);
            }
        }

        $env = getenv('WANYESEA_AI_GITHUB_TOKEN');
        return is_string($env) ? trim($env) : '';
    }

    private static function normalize_repo_slug($repo) {
        $repo = trim((string) $repo);
        $repo = preg_replace('#^https?://github\.com/#i', '', $repo);
        $repo = trim($repo, '/');
        if ($repo === '') {
            return '';
        }
        if (preg_match('#^([^/]+)/([^/]+?)(?:\.git)?$#', $repo, $m)) {
            return sanitize_text_field($m[1]) . '/' . sanitize_text_field($m[2]);
        }
        return '';
    }

    private static function format_changelog($body) {
        $body = trim($body);
        if ($body === '') {
            return '暂无 GitHub Release 说明。';
        }
        return nl2br(esc_html($body));
    }

    private static function slug() {
        return dirname(plugin_basename(WanYesea_AI_path . 'index.php'));
    }
}

add_action('plugins_loaded', array('Wanyesea_AI_Github_Updater', 'boot'), 12);
