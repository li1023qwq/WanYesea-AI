<?php
if (!defined('ABSPATH')) {
    die('禁止直接访问');
}

// 晚夜深秋·AI插件 更新日志类
class Wanyesea_AI_UpdateLog {

    // 更新日志文件路径
    const LOG_FILE = 'update_log.md';

    /**
     * 获取更新日志文件完整路径
     */
    public static function get_log_file_path() {
        return WanYesea_AI_path . self::LOG_FILE;
    }

    /**
     * 读取更新日志原始内容
     */
    public static function get_raw_log() {
        $file_path = self::get_log_file_path();
        if (!file_exists($file_path)) {
            return '';
        }
        $content = file_get_contents($file_path);
        return $content !== false ? $content : '';
    }

    /**
     * 将 Markdown 文本转换为 HTML（轻量解析，不依赖第三方库）
     * 支持：标题(h1-h4)、有序/无序列表、粗体、链接、代码
     */
    public static function markdown_to_html($markdown) {
        if (empty($markdown)) {
            return '';
        }

        $html = $markdown;

        // 转义HTML特殊字符（保留后续需要处理的标签）
        $html = htmlspecialchars($html, ENT_QUOTES, 'UTF-8', false);

        // 标题 h1-h4
        $html = preg_replace('/^#### (.+)$/m', '<h4>$1</h4>', $html);
        $html = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $html);
        $html = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $html);

        // 粗体
        $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);

        // 链接
        $html = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank">$1</a>', $html);

        // 行内代码
        $html = preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);

        // 无序列表（- 开头）
        $html = preg_replace('/^- (.+)$/m', '<li>$1</li>', $html);

        // 有序列表（数字. 开头）
        $html = preg_replace('/^\d+\. (.+)$/m', '<li>$1</li>', $html);

        // 将连续的 li 包裹在 ul 中
        $html = preg_replace_callback(
            '/((?:<li>.*<\/li>\s*)+)/',
            function ($matches) {
                return '<ul>' . $matches[1] . '</ul>';
            },
            $html
        );

        // 段落：将非标签开头的独立行包裹 <p>
        $lines = explode("\n", $html);
        $result = array();
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed)) {
                continue;
            }
            // 已经是HTML标签开头的行不再包裹
            if (preg_match('/^<[h|u|o|l|d|p]/', $trimmed)) {
                $result[] = $trimmed;
            } else {
                $result[] = '<p>' . $trimmed . '</p>';
            }
        }

        return implode("\n", $result);
    }

    /**
     * 获取解析后的更新日志 HTML
     */
    public static function get_update_log_html() {
        $raw = self::get_raw_log();
        if (empty($raw)) {
            return '<p style="color:#999;">暂无更新日志</p>';
        }
        return self::markdown_to_html($raw);
    }

    /**
     * 获取更新日志中的版本号列表
     * 从 Markdown 的 ## 标题中提取版本信息
     */
    public static function get_version_list() {
        $raw = self::get_raw_log();
        if (empty($raw)) {
            return array();
        }

        $versions = array();
        // 匹配 ## v1.0.0 或 ## 1.0.0 格式
        if (preg_match_all('/^##\s+(v?[\d.]+)\s*(?:\(([^)]+)\))?/m', $raw, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $versions[] = array(
                    'version' => $match[1],
                    'date'    => isset($match[2]) ? $match[2] : '',
                );
            }
        }
        return $versions;
    }

    /**
     * 获取指定版本的更新内容（纯文本）
     */
    public static function get_version_content($version) {
        $raw = self::get_raw_log();
        if (empty($raw)) {
            return '';
        }

        // 标准化版本号
        $version = ltrim($version, 'v');

        // 匹配指定版本区块内容（从 ## vX.X.X 到下一个 ## 或文件结尾）
        $pattern = '/^##\s+v?' . preg_quote($version, '/') . '\s*(?:\([^)]*\))?\s*\n(.*?)(?=^##\s|\z)/ms';
        if (preg_match($pattern, $raw, $match)) {
            return trim($match[1]);
        }
        return '';
    }

    /**
     * 更新日志中最新一条版本信息（版本号、日期、首段摘要）。
     *
     * @return array{version: string, date: string, summary: string}|null
     */
    public static function get_latest_release() {
        $versions = self::get_version_list();
        if (empty($versions)) {
            return null;
        }

        $latest  = $versions[0];
        $version = ltrim((string) $latest['version'], 'v');
        $content = self::get_version_content($version);
        $summary = '';

        if ($content !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
                $line = trim($line);
                if ($line === '' || preg_match('/^#+\s/', $line)) {
                    continue;
                }
                if (preg_match('/^[-*]\s+/', $line)) {
                    continue;
                }
                $summary = $line;
                break;
            }
        }

        return array(
            'version' => $version,
            'date'    => isset($latest['date']) ? (string) $latest['date'] : '',
            'summary' => $summary,
        );
    }

    /**
     * 最新版本一行说明（供后台面板使用）。
     */
    public static function get_latest_release_line_html() {
        $release = self::get_latest_release();
        if ($release === null) {
            return '';
        }

        $label = 'v' . esc_html($release['version']);
        if ($release['date'] !== '') {
            $label .= ' (' . esc_html($release['date']) . ')';
        }

        $line = '<strong>' . $label . '</strong>';
        if ($release['summary'] !== '') {
            $line .= '：' . esc_html($release['summary']);
        }

        return $line;
    }
}