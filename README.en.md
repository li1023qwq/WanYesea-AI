# WanYesea-AI

[![WordPress](https://img.shields.io/badge/WordPress-7.0%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Version](https://img.shields.io/badge/version-1.2.4-0f766e)](https://github.com/li1023qwq/WanYesea-AI/releases)
[![WordPress AI](https://img.shields.io/badge/WordPress%20AI-Connectors-155e75)](https://github.com/WordPress/ai)
[![Release](https://img.shields.io/github/actions/workflow/status/li1023qwq/WanYesea-AI/release.yml?label=release)](https://github.com/li1023qwq/WanYesea-AI/actions)

**[ 简体中文 ](./README.md)**

![WanYesea-AI](assets/readme-banner.svg)

**WanYesea-AI** extends the [WordPress 7.0 AI stack](https://github.com/WordPress/ai) with a Zibll-theme admin panel for AI connectivity, API relay, and unified gateway management. Configure vendor API keys and relay base URLs in one place—they sync to **Connectors** automatically. Register One API / New API endpoints as first-class providers for text, vision, and image-generation experiments.

## Features

- Centralized API keys and relay base URLs; rewrites official outbound URLs when relay is enabled.
- OpenAI text uses `chat/completions` for compatibility with One API / New API gateways.
- Environment variables / `wp-config` constants take precedence (`WANYESEA_AI_*_API_KEY`).
- **Unified AI Gateway**: multiple relays as `wanyesea-gateway-*` providers; OpenAI Compatible and Anthropic Messages.
- Local model pool, `/models` fetch, capability tags (text / vision / image).
- Image flow: `chat/completions` first, `images/generations` fallback.
- Built-in text / image test lab and environment probes.
- Runtime zh_CN overlay for the official `ai` plugin (707 strings, DOM mutation observer).
- GitHub Releases auto-update ([li1023qwq/WanYesea-AI](https://github.com/li1023qwq/WanYesea-AI)).

## Screenshots

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

## Requirements

| Item | Minimum |
|------|---------|
| WordPress | 7.0+ |
| PHP | 7.4+ |
| Theme | Zibll (CSF framework required) |
| Dependency | Official [WordPress AI plugin](https://github.com/WordPress/ai) |

## Usage

### 1. Install

Install and activate the **WordPress AI plugin** and **WanYesea-AI**.

### 2. AI Connect

Open **WanYesea-AI → AI Connect** in the Zibll admin:

- Verify environment checks are green;
- Enable **API relay**;
- Set **API Key** and **relay Base URL** for each vendor, then save.

### 3. Enable experiments

In WordPress **Settings → AI**, enable the experiments you need.

### 4. Unified gateway (optional)

Under **Unified AI Gateway**, add One API / New API sites, fetch models, and tag capabilities.

### 5. Interface i18n (optional)

Under **Interface i18n**, enable the zh_CN overlay for the AI admin UI.

## Admin sections

| Section | Description |
|---------|-------------|
| Getting Started | Environment notes and workflow |
| AI Connect | Vendor relay, keys, Connectors sync |
| Unified AI Gateway | Multi-gateway providers and model pools |
| Interface i18n | zh_CN overlay toggle |
| AI Test Lab | Text / image tests |
| Backup & Import | Export / restore settings |
| Changelog | Version history and GitHub updates |

## Architecture

```mermaid
flowchart LR
    subgraph WP["WordPress 7.0"]
        AI["Official AI Plugin"]
        CON["Connectors"]
    end

    subgraph WYA["WanYesea-AI"]
        RELAY["Vendor Relay"]
        GW["Unified Gateway"]
        I18N["Admin i18n"]
        LAB["Test Lab"]
    end

    subgraph OUT["Outbound"]
        VENDOR["Vendor APIs"]
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

## Environment variables (optional)

In `wp-config.php`; they **override** stored options:

```php
define('WANYESEA_AI_OPENAI_API_KEY', 'sk-...');
define('WANYESEA_AI_GITHUB_TOKEN', 'ghp_...'); // optional
```

Pattern: `WANYESEA_AI_{PROVIDER}_API_KEY` (uppercase provider ID with underscores).

## Release workflow

```bash
git add .
git commit -m "v1.2.4: AI post draft and AI comment reply"
git push
git tag v1.2.4
git push origin v1.2.4
```

Pushing a `v*` tag triggers GitHub Actions to build the Release. Sites update from **Plugins → Updates**.

Local zip (optional):

```powershell
.\scripts\build-release.ps1
```

## FAQ

<details>
<summary><strong>Settings page missing or unstyled?</strong></summary>

Requires the **Zibll theme** and its CSF options framework.
</details>

<details>
<summary><strong>Relay lists models but tests fail with "No models found"?</strong></summary>

With relay enabled, tests use the HTTP `/models` list. Verify the base URL and exact model IDs from your gateway.
</details>

<details>
<summary><strong>No GitHub Release yet?</strong></summary>

Push code and tag: `git tag vX.Y.Z && git push origin vX.Y.Z`. Actions uploads `WanYesea-AI.zip` automatically.
</details>

## Links

- Repository: [github.com/li1023qwq/WanYesea-AI](https://github.com/li1023qwq/WanYesea-AI)
- Author: [li1023.com](https://li1023.com/)
- WordPress AI: [github.com/WordPress/ai](https://github.com/WordPress/ai)
- Zibll theme: [zibll.com](https://www.zibll.com/)

---

<sub>Copyright © WanYesea · <a href="https://li1023.com/">li1023.com</a> · <a href="./README.md">简体中文</a></sub>
