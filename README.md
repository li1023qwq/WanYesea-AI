# 晚夜深秋·AI插件

[![WordPress](https://img.shields.io/badge/WordPress-7.0%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Version](https://img.shields.io/badge/version-1.2.5-0f766e)](https://github.com/li1023qwq/WanYesea-AI/releases)
[![WordPress AI](https://img.shields.io/badge/WordPress%20AI-Connectors-155e75)](https://github.com/WordPress/ai)
[![Release](https://img.shields.io/github/actions/workflow/status/li1023qwq/WanYesea-AI/release.yml?label=release)](https://github.com/li1023qwq/WanYesea-AI/actions)

**[ English ](./README.en.md)**

![WanYesea-AI](assets/readme-banner.svg)

**晚夜深秋·AI 插件（WanYesea-AI）** 面向 [WordPress 7.0 官方 AI 生态](https://github.com/WordPress/ai)，在子比主题后台提供统一的 AI 连接、API 中转与网关管理能力。你可以在插件内配置各厂商 API Key 与中转 Base URL，并自动同步至 **Connectors**；同时支持将 One API / New API 等站点注册为独立 Provider，供文本、视觉与出图实验功能调用。

## 主要功能

- 厂商 API Key 与中转 Base URL 集中配置，启用后自动改写官方出站 URL。
- OpenAI 文本走 `chat/completions`，兼容 One API / New API 等常见中转。
- 环境变量 / `wp-config` 常量优先（`WANYESEA_AI_*_API_KEY`）。
- **AI 统一网关**：多个中转站注册为 `wanyesea-gateway-*` Provider，支持 OpenAI Compatible 与 Anthropic Messages。
- 本地模型池、一键拉取 `/models`、能力标记（文本 / 视觉 / 生图）。
- 出图：`chat/completions` 优先，失败兜底 `images/generations`。
- 「晚夜深秋-AI测试」文本 / 图像链路验证与环境检测。
- 官方 `ai` 插件界面汉化（707 条词条，DOM 动态替换）。
- GitHub Releases 自动更新（仓库 [li1023qwq/WanYesea-AI](https://github.com/li1023qwq/WanYesea-AI)）。
- **AI 文章草稿**：后台「文章 → 所有文章」批量生成符合子比格式的草稿，支持选模型、关键词与篇数，后台异步生成；可配置文章底部 AI 声明。
- **AI 评论回复**：评论过审后自动根据文章内容生成个性化回复；支持屏蔽子比快捷回复与自定义语句；前台可显示 AI 标识。

## 截图

### AI 连接
![晚夜深秋-AI测试](assets/screenshots/20260521195621410-ScreenShot_2026-05-21_195239_833.png)
![晚夜深秋-AI测试](assets/screenshots/20260522214129249-image-103.png)
![晚夜深秋-AI测试](assets/screenshots/20260521195621444-ScreenShot_2026-05-21_195350_307.png)
![晚夜深秋-AI测试](assets/screenshots/20260522214242480-image-105.png)
![晚夜深秋-AI测试](assets/screenshots/20260522214418532-image-106.png)
![晚夜深秋-AI测试](assets/screenshots/ScreenShot_2026-05-23_125213_040.png)
![晚夜深秋-AI测试](assets/screenshots/ScreenShot_2026-05-23_125327_096.png)
![晚夜深秋-AI测试](assets/screenshots/ScreenShot_2026-05-23_125357_551.png)
![晚夜深秋-AI测试](assets/screenshots/ScreenShot_2026-05-23_130659_436.png)
![晚夜深秋-AI测试](assets/screenshots/ScreenShot_2026-05-23_161958_616.png)
![晚夜深秋-AI测试](assets/screenshots/ScreenShot_2026-05-23_163029_744.png)
![晚夜深秋-AI测试](assets/screenshots/ScreenShot_2026-05-23_163915_065.png)
![晚夜深秋-AI测试](assets/screenshots/ScreenShot_2026-05-23_165514_449.png)
![晚夜深秋-AI测试](assets/screenshots/ScreenShot_2026-05-23_170638_499.png)
![晚夜深秋-AI测试](assets/screenshots/ScreenShot_2026-05-23_174312_796.png)
![晚夜深秋-AI测试](assets/screenshots/ScreenShot_2026-05-23_192723_697.png)
![晚夜深秋-AI测试](assets/screenshots/ScreenShot_2026-05-23_191156_575.png)
![晚夜深秋-AI测试](assets/screenshots/ScreenShot_2026-05-23_191401_506.png)
![晚夜深秋-AI测试](assets/screenshots/ScreenShot_2026-05-23_191431_802.png)
## 环境要求

| 项目 | 要求 |
|------|------|
| WordPress | 7.0 或更高 |
| PHP | 7.4 或更高 |
| 主题 | 子比（Zibll）主题及 CSF 框架 |
| 依赖 | WordPress 官方 [AI 插件](https://github.com/WordPress/ai) |

## 使用方法

### 1. 安装与启用

安装并启用 **WordPress AI 插件** 与本插件。

### 2. 配置 AI 连接

进入子比后台 **晚夜深秋·AI插件 → AI 连接**：

- 确认顶部环境检测均为就绪；
- 开启 **启用 API 中转**；
- 为需要的厂商填写 **API Key** 与 **中转 Base URL** 后保存。

### 3. 启用实验功能

在 WordPress **设置 → AI** 中启用所需实验（文本生成、出图、Alt 等）。

### 4. 统一网关（可选）

进入 **AI 统一网关**，添加 One API / New API 站点，拉取模型并标记能力。

### 5. 界面汉化（可选）

进入 **界面汉化**，开启 **启用 WordPress AI 界面汉化**。

### 6. AI 文章草稿（v1.2.4+）

1. 在 **晚夜深秋·AI插件 → AI 草稿** 中配置前台 AI 声明（可选）。
2. 进入 **文章 → 所有文章**，点击 **AI 草稿**，填写创作说明 / 关键词并选择模型。
3. 提交后可离开页面，草稿会在后台异步生成并出现在列表中。

### 7. AI 评论回复（v1.2.4+）

1. 在 **晚夜深秋·AI插件 → AI 评论回复** 中启用自动回复，并配置回复身份、屏蔽规则。
2. 读者评论过审后，系统将异步以文章作者（或指定用户）身份自动回复。
3. 子比「快捷回复」与自定义屏蔽语句会自动跳过；后台评论列表可手动 **AI 回复** 或查看失败原因。

### 8. 子比 AI SEO（v1.2.5+）

在子比主题开启 **独立 SEO 字段** 与 **AI SEO** 后，文章/分类编辑页的「AI 生成 SEO」由本插件增强：一次请求生成标题、关键词、描述，并兼容中转 OpenAI、自定义 Connector、统一网关及 WordPress 连接页已配置厂商。若安装 **WanYesea-API**，移动端可通过 REST `ai-seo/generate` 调用同一逻辑。

## 后台菜单

| 菜单 | 说明 |
|------|------|
| 开始 & 使用 | 环境说明与推荐流程 |
| AI 连接 | 厂商中转、密钥、Connectors 同步 |
| AI 统一网关 | 多网关 Provider、模型池 |
| 界面汉化 | 官方 AI 插件中文覆盖 |
| AI 草稿 | 批量生成子比格式文章草稿、前台 AI 声明 |
| AI 评论回复 | 自动 / 手动评论回复、快捷回复屏蔽 |
| 晚夜深秋-AI测试 | 文本 / 图像测试 |
| 备份 & 导入 | 设置导出 / 恢复 |
| 更新日志 | 版本记录与 GitHub 更新 |

## 架构示意

```mermaid
flowchart LR
    subgraph WP["WordPress 7.0"]
        AI["官方 AI 插件"]
        CON["Connectors"]
    end

    subgraph WYA["WanYesea-AI"]
        RELAY["厂商 API 中转"]
        GW["统一网关"]
        I18N["界面汉化"]
        LAB["测试实验室"]
    end

    subgraph OUT["出站"]
        VENDOR["官方厂商 API"]
        ONE["One API / New API"]
    end

    AI --> CON
    WYA --> CON
    RELAY --> ONE
    RELAY --> VENDOR
    GW --> ONE
    I18N --> AI
    LAB --> CON
```

## 环境变量（可选）

在 `wp-config.php` 中配置，**优先于**后台选项：

```php
define('WANYESEA_AI_OPENAI_API_KEY', 'sk-...');
define('WANYESEA_AI_GITHUB_TOKEN', 'ghp_...'); // 可选，私有仓库或提高 API 限额
```

命名规则：`WANYESEA_AI_{PROVIDER}_API_KEY`，`{PROVIDER}` 与 Connectors Provider ID 对应（大写、下划线）。

## 发布与更新

```bash
git add .
git commit -m "v1.2.4: AI 文章草稿与 AI 评论回复"
git push
git tag v1.2.4
git push origin v1.2.4
```

推送 `v*` 标签后，GitHub Actions 自动打包 Release。WordPress 站点在 **插件** 页收到更新提示。

本地手动打包（遵循 `.distignore`，不含 README、截图目录、汉化构建脚本等）：

```powershell
.\scripts\build-release.ps1
```

排除规则在插件根目录 **`.distignore`**，可自行增删；修改后本地脚本与 GitHub Actions 均生效。

## 常见问题

<details>
<summary><strong>设置页打不开或样式异常？</strong></summary>

请确认已启用 **子比主题**，设置页依赖主题自带的 CSF 框架。
</details>

<details>
<summary><strong>中转有模型，但测试报 No models found？</strong></summary>

启用中转后，测试与「加载模型」均走 HTTP `/models`；请确认 Base URL 正确，模型 ID 与中转返回一致。
</details>

<details>
<summary><strong>GitHub 暂无 Release？</strong></summary>

需先推送代码并发布 `v*` 标签，Actions 会自动上传 `WanYesea-AI.zip`。
</details>

## 相关链接

- 插件仓库：[github.com/li1023qwq/WanYesea-AI](https://github.com/li1023qwq/WanYesea-AI)
- 作者站点：[li1023.com](https://li1023.com/)
- WordPress AI：[github.com/WordPress/ai](https://github.com/WordPress/ai)
- 子比主题：[zibll.com](https://www.zibll.com/)

---

<sub>Copyright © 晚夜深秋 · <a href="https://li1023.com/">li1023.com</a> · <a href="./README.en.md">English</a></sub>
