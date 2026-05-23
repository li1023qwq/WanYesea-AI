# 晚夜深秋·AI插件 更新日志


## v1.2.3 (2026-05-23)

### 改进

- **界面汉化重做**：运行时翻译（gettext / gettext_with_context + wp.i18n.setLocaleData + DOM MutationObserver），词条表扩至 707 条
- **GitHub 更新**：移除后台自定义仓库字段，固定 `li1023qwq/WanYesea-AI`；推送 `v*` 标签由 Actions 自动打包 Release
- **修复**：内容分类（分类项建议）与编辑建议等文本 Ability 执行前预热 AI Registry，避免误报「请确认已连接支持文本生成的提供商」
- **修复**：内容分类「约 150 词」门槛对中文正文误判（`wp.wordcount` 按空格分词）；中文站点按约 300 字等效启用建议按钮，并更新提示文案
- **修复**：分类项/编辑建议等仍报「请确认已连接支持文本生成的提供商」——强化文本 Registry 预热（中转模型 bind、探测模型 ID、开发者指定模型；REST 双钩子）
- **修复**：内容分类 REST `504 Gateway Time-out`——编辑页改为轻量预热（取消每次强制 /models 刷新、每请求只预热一次），并延长文本 Ability 的 PHP/HTTP 超时
- **修复**：仅配置 NVIDIA / SenseNova 等自定义 Connector 时内容分类报 `unsupported_model`——编辑页轻量预热绑定 hint 静态文本模型（不依赖 GET /models）
- **修复**：SenseNova 等内容分类/JSON 结构化输出报 `unsupported_model`——OpenAI 兼容模型元数据补充 `outputMimeType` / `outputSchema` 声明；优先列表过滤未连接官方厂商
- **修复**：内容分类 REST `504` / `rest_ability_invalid_method`——文本 Ability 执行期间跳过 GET `/models` 与 Registry 元数据全量探测；编辑页 `apiFetch` 中间件对 `ai/*/run` 强制 POST
- **修复**：内容分类 `prompt_client_error` / `model is not found`（HTTP 404）——校验「设置 → AI」文本实验开发者选项，自动剔除出图模型（如 `sensenova-u1-fast`）与未配置厂商，并回退到 `sensenova-6.7-flash-lite` 等 hint 文本模型
- **修复**：内容分类 `json_schema.name is required`（HTTP 400）——OpenAI 兼容文本模型发送结构化输出时补全 `response_format.json_schema.name` / `schema` 信封（SenseNova 要求）
- **修复**：内容分类 `guided_grammar` / `Unsupported tokenizer`（HTTP 400）——SenseNova（Qwen 系）不再发送 `response_format`（避免 grammar 编译）；在 `parseResponseToGenerativeAiResult` 中规范化松散 JSON（`[{term,confidence}]` → `{"suggestions":[...]}`、剥离 markdown 代码块）
- **改进**：编辑建议界面与内容中文化——补全「已添加 N 条建议」等词条；中文站点为 `ai/editorial-notes` 等追加「与正文同语言」系统指令；Notes 保存/REST 返回时将 `[READABILITY]` 等标签汉化为 `[可读性]`（含历史数据与去重兼容）；DOM 脚本作兜底

## v1.2.2 (2026-05-23)

### 新增

- **GitHub 自动更新**：后台「更新日志」可填写 `owner/repo`；或通过 `WANYESEA_AI_GITHUB_REPO` / 过滤器配置
- WordPress「插件」页显示新版本、支持 Release 附件 `.zip` 一键升级；插件列表提供「检查更新 / GitHub」快捷链接
- 私有仓库可选 `WANYESEA_AI_GITHUB_TOKEN`；API 失败短时缓存，避免频繁请求

## v1.2.1 (2026-05-23)

### 改进

- **AI 统一网关**接入「晚夜深秋-AI测试」：已启用网关出现在文本/图像测试卡片，加载模型优先使用本地模型池
- **AI 连接 → 环境检测**新增「统一网关」区块，支持与厂商端点相同的 HTTP 探测
- 网关 Provider 纳入 `wanyesea_ai_get_provider_effective_endpoint`、Anthropic 探测头、Connectors 元数据与环境变量密钥解析链

## v1.2.0 (2026-05-23)

### 新增

- **AI 统一网关**：后台新菜单「AI 统一网关」，支持多个 One API / New API 站点注册为独立 Provider（`wanyesea-gateway`、`wanyesea-gateway-*`）
- **双协议**：OpenAI Compatible 与 Anthropic Messages；本地模型池 + 一键拉取 `/models` + 能力勾选（文本 / 视觉 / 生图）
- **出图增强**：网关生图先 `chat/completions`（`modalities: image`），失败再 `images/generations`；自动解析 Markdown / URL / base64 并转 inline
- **密钥**：环境变量 / `wp-config` 常量（`WANYESEA_AI_*_API_KEY`）优先于选项；网关密钥可在此页填写并同步 Connectors
- **可选 GitHub 更新检查**：通过过滤器 `wanyesea_ai_github_updater_config` 配置 Release API

### 改进

- 厂商中转 Base URL 规范化（`Wanyesea_AI_Relay::normalize_relay_base_url`）
- 网关 Connectors 自动审批、中转站 `favicon` 图标、出站主机白名单
- **AI 统一网关**：修改后自动保存；切换 CSF 标签页会重新加载；未改动的 API Key 不再因保存被清空

## v1.1.2 (2026-05-22)

### 修复

- **特色图 Alt / 导入 REST 500「此站点遇到了致命错误」**：`wanyesea_ai_rest_request_reset_json_params()` 误将 `WP_REST_Request::$params` 置为 `null`，`get_json_params()` 再次解析时触发 PHP 致命错误；改为重置 `parsed_json` 并正确写入 `params['JSON']`
- **特色图 Alt / 导入缓存与请求体**：缓存未命中时 REST 返回 `wanyesea_editor_flow_cache_miss`（400）；`wp-api-fetch` 内联中间件提前注册；出图 CDN URL 拉取后写入 transient
- **块编辑器 `shop_cat` / `plate_cat` REST 403**：子比主题分类法未绑定普通 `post` 时，对带 `post=` 的 GET 返回空列表，消除控制台噪音
- **写文章「Generate featured image」生成提示词失败**：`wanyesea_ai_model_metadata_supports_image_input()` 误用 `SupportedOption::getId()`（AI Client 仅有 `getName()`），导致 `ai/image-prompt-generation` 回调抛异常
- **特色图后续 Alt 文本 / 导入媒体 `invalid_json`（此响应不是合法的 JSON 响应）**：编辑器链路统一 stub 已配置厂商的 GET `/models`；SenseNova 等在加速模式下直接走静态 fallback；拉长视觉 `chat/completions` 超时；`ai/image-import` 改用轻量 base64 校验并提高 REST 内存上限；出图结果写入用户级 transient，`wanyesea-ai-editor-image-flow.js` + REST 预处理改为小 JSON（Alt 用 `attachment_id`，导入用缓存 base64），避免重复 POST 数 MB 导致网关/PHP 返回 HTML
- **OpenAI + New API / One API 中转**：「检测端点」有模型但「加载模型」为空、文本测试报 `No models found for provider "openai" that support text_generation`——官方 `OpenAiModelMetadataDirectory` 仅识别 `gpt-*` / `o1` 等 ID，中转 `/models` 常返回 `openai/gpt-4o`、`claude-*`、`deepseek-chat` 等；新增元数据装饰器合并 HTTP 探测的对话模型并写入 `text_generation` 能力
- **中转文本测试 Forbidden (403) / admin-ajax 500**：官方 `ai-provider-for-openai` 文本走 `/v1/responses`，多数中转仅支持 `/v1/chat/completions`；启用中转时注册 `Wanyesea_AI_Relay_OpenAi_Provider` 自动改用 chat/completions
- **已选模型仍报 No models found for text_generation**：根因是「加载模型」走 HTTP `/models`，测试却走 AI Client Registry 二次能力筛选，二者列表不一致；测试页改为与加载列表同源，中转 OpenAI **直接用所选模型 ID 调 chat/completions**；保留模型 ID 原样（如 `64/gpt-5.2`）
- **加载模型与检测端点不一致**：中转开启时「加载模型」改为与「检测端点」相同的 HTTP `/models` 列表（文本 = text + other，图像 = image），不再依赖「AI Client 已校验」；修复空列表被静态缓存导致始终 0 个模型
- **测试页文本已成功仍显示「未校验」**：徽章与连接页判定对齐中转场景；`wanyesea_ai_ensure_ai_client_auth` 每次调用都会重新注册中转 OpenAI Provider
- **「设置 → 连接」仍显示「设置」而非「已连接」**：覆盖核心 `script_module_data_options-connectors-wp-admin` 的 `isConnected`；REST 保存密钥前预热 Registry
- **连接页 Fatal**：`wanyesea_ai_connectors_rest_prepare_validation` 误 `unset($response)`；改为始终原样返回 `WP_REST_Response`

### 改进

- 中转文本模型优先列表默认不再截断为 24 个（`wanyesea_ai_relay_official_text_model_preference_limit` 默认 `0` 表示不限制）
- 过滤器：`wanyesea_ai_probe_models_classified`、`wanyesea_ai_probe_model_ids_for_capability`、`wanyesea_ai_relay_probe_merge_image_into_text_list`

## v1.1.1 (2026-05-22)

连接页「已连接」判定、AI 能力环境检测与厂商端点列表对齐实际可用性；新增 WordPress AI 界面临时汉化。

### 新增

- **界面汉化**（`03-ai-i18n.php` / `16-ai-i18n.php`）：在中文环境下为官方 `ai` 插件注入临时中文（PHP `gettext` + `wp.i18n`）；词条表 `includes/i18n/ai-zh-cn-strings.php` 已按 `ai` 插件源码提取并校对（约 449 条）；可用 `includes/i18n/build-ai-zh-cn.py` 在升级官方 AI 插件后重新生成；官方发布语言包后可关闭并删除相关文件

### 修复

- **设置 → 连接页仅显示「设置」、不显示「已连接」**：已启用 API 中转时，对 AI Client 校验用的 `GET /models` 延长 HTTP 超时（默认 45 秒），避免 WordPress 默认 5 秒导致 `isProviderConfigured` 失败；不伪造「已连接」状态
- **AI 能力区误报「未校验」**（SenseNova / NVIDIA 等实际可用）：环境检测改为打开设置页时在 `admin_enqueue_scripts` 生成并注入（原在 `after_setup_theme` 生成，早于自定义 Provider 注册）；统一经 `wanyesea_ai_is_provider_registry_configured()` 判定并先注入鉴权
- **图像生成环境误报「需配置密钥」**：SenseNova 等自定义出图在 Generate Image 可用时，设置页「AI 能力 → 图像生成」与真实能力对齐（`wanyesea_ai_is_image_generation_available_for_env`）
- **厂商端点检测列表**：仅列出已开启「API 中转」且该厂商已「启用中转」的项；「全部检测」同步；未启用中转的厂商不再出现
- **中转提示误导**：「已启用中转但无法连通」不再针对走官方端点的自定义 Connector（SenseNova / NVIDIA 等）

### 改进

- 新增过滤器 `wanyesea_ai_relay_models_validation_timeout`（秒，默认 45，范围 15–180）
- 环境检测说明：连接页「已连接」与「厂商端点 → 检测」判定标准差异

## v1.1.beta (2026-05-21)

接入多家模型后「Generate featured image」出图能力修复与就绪检测增强。

### 修复

- **写文章「Generate featured image」`unsupported_model`（Image prompt generation failed）**：编辑器出图流程不再向 Registry 写入空的仅文本 Connector；特色图提示词阶段用 `preferred_model_hint` 静态文本模型；出图厂商无可用文本模型时回退 DeepSeek 等已配置文本 Connector；执行 `ai/image-prompt-generation` 前预热 Registry
- **仅配置 SenseNova 时出图报 `No model with ID sensenova-u1-fast was found`**：`/models` 短路时先返回含出图模型的完整 fallback（不再对 SenseNova 只返回文本 hint）；执行 `ai/image-generation` 前预热 Registry 并解析 hint 模型
- **特色图已生成但 Alt 文本 `unsupported_model`**：为 SenseNova 静态模型声明 `text`+`image` 输入模态；`wpai_preferred_vision_models` 优先 `sensenova-6.7-flash-lite`；`ai/alt-text-generation` 前预热 Registry
- **SenseNova 出图 504 Gateway Time-out**：编辑器链路对 SenseNova 等已配置出图厂商的 `GET /models` 返回静态 JSON（不再真实探测）；`POST /v1/images/generations` 与 CDN 下载 HTTP 超时提升至 240s；出图 Ability PHP 时限 600s。若仍 504，需将 Nginx/PHP-FPM 的 `fastcgi_read_timeout` / `proxy_read_timeout` 调至 ≥300s（`sensenova-u1-fast` 信息图常需 1–3 分钟）
- **AI 设置页「Image Generation and Editing」模型下拉仅「— Default —」**：中转 `/models` 无 `output_modalities` 时按模型 ID 推断出图能力；`GET /ai/v1/providers?capability=image_generation` 合并已配置厂商的出图模型与 `wpai_preferred_image_models` 列表
- **写文章页出图 504 / unsupported_model**：特色图像链路仅屏蔽仅文本 Connector 的 `/models`；多厂商 + API 中转均允许探测（含自定义 Connector 中转 URL 改写）；每家出图厂商各保留有限模型回退；中转 `/models` 超时 28s
- **古腾堡 Quirks Mode 警告**：块编辑器下移除子比主题 `admin_footer` 的 `console.log` 性能输出（避免干扰编辑器文档模式）
- **Generate featured image** 接入多家模型后提示无出图 Provider：自动清除开发者模式中误选的仅文本厂商；已配置 Google / OpenAI / SenseNova 动态加入出图优先列表；SenseNova 在 `/models` 失败时使用静态出图元数据；有 API Key 即视为 Provider 已配置
- **Generate featured image** 提示 `Failed to generate prompt: 此响应不是合法的 JSON 响应`：缓存自定义厂商文本模型优先列表，避免每次 `ai/image-prompt-generation` 重复拉取多家 `/models` 导致 PHP 超时；延长 AI Ability 与 HTTP 默认超时

### 改进

- REST / 后台请求前提前注入 AI Client 鉴权（`wanyesea_ai_ensure_ai_client_auth`）
- 环境检测文案：提示开发者模式勿将仅文本厂商绑定到「图像生成」
- 文本模型优先列表：`/models` 不可用时回退 `preferred_model_hint`；限制并入列表的自定义模型数量

## v1.0.beta (2026-05-21)

WordPress 7.0 AI 连接、API Key 桥接、第三方 API 中转与自定义 Connector（含 SenseNova 出图）一体化配置。

### 新增

- 插件基础架构与子比主题 CSF 设置框架（分页：开始&使用、AI 连接、更新日志、备份&导入）
- 配置备份、恢复与导入（最多 20 条记录，含插件版本号）
- **AI 连接** 设置页：环境检测（AI 核心、AI Client、各 Provider）、厂商卡片与配置状态
- **API Key 桥接**（`Wanyesea_AI_Connectors`）：插件内填写密钥并同步至 WordPress Connectors；注入 PHP AI Client 鉴权；留空不修改、输入 `REMOVE` 清除
- **API 中转**（`Wanyesea_AI_Relay`）：总开关与各厂商中转；出站 URL 改写为 One API / New API 等 Base URL；中转域名加入 `wp_safe_remote` 白名单
- **自定义 Connector**（`Wanyesea_AI_Custom_Connectors`）：DeepSeek、Moonshot、智谱 AI、小米 MiMo、NVIDIA、**SenseNova**；注册至「设置 → 连接」与本插件双向同步 API Key
- **自定义 AI Provider**（`08-custom-ai-providers.php`）：注册至 WP AI Client 与 **工具 → Connector 审批**；OpenAI 兼容 `/models` 与 `chat/completions` 文本生成
- **文本生成适配**：动态发现 `/models` 模型列表；`wpai_preferred_text_models` 优先列表；环境区「文本生成」就绪检测
- **图像生成适配**：Google / OpenAI / SenseNova；`sensenova-u1-fast` 对接 `POST /v1/images/generations`；`wpai_preferred_image_models`；环境区「图像生成」就绪检测
- **Generate Image**：SenseNova 返回 CDN `url` 时自动下载并转为 inline `base64`；出图请求超时（SenseNova 180 秒，其它 90 秒）
- 后台交互（`wanyesea-ai-admin.js` / `wanyesea-ai-admin.css`）：中转总开关显隐、厂商卡片样式与 SenseNova 等品牌色

### 改进

- **设置 → 晚夜深秋-AI测试**：各厂商文本模型与图像能力真实调用测试页（`15-ai-test-lab.php`）；文本区与图像区卡片状态（模型选择、结果区）互不干扰
- **环境检测**：分为系统依赖、厂商端点、AI 能力；各厂商支持「检测」与「全部检测」，通过 `GET /models` 校验连通并列出文本/图像模型（厂商卡片内亦可检测，可使用未保存的 API Key）
- 设置表单集中于 `includes/options/02-ai-relay.php`；适配子比 CSF 与 `zib_admin_man` 样式
- 官方 Provider 环境状态为「插件已启用且 API Key 已配置」
- 全部厂商（含自定义 Connector）统一中转显隐与字段布局；各厂商默认官方 API 根地址
- 文本模型从 `/models` 动态获取，优先端点默认模型，兼容 `data` / `models` 等响应格式
- SenseNova 对齐 [platform.sensenova.cn/docs](https://platform.sensenova.cn/docs)；文本按 `output_modalities` 过滤；出图专用尺寸映射（16:9 默认 `2752x1536` 等）
- 小米 MiMo 对齐 [官方首次调用 API 文档](https://platform.xiaomimimo.com/docs/zh-CN/quick-start/first-api-call)：`https://api.xiaomimimo.com/v1`、`api-key` 请求头、默认模型 `mimo-v2.5-pro`
- **NVIDIA GLiNER-PII**（`nvidia/gliner-pii`）：按 NIM 规范发送纯字符串 `user` 消息；解析 `total_entities` / `entities` / `tagged_text` 为可读结果；测试页结构化展示；不参与写文章文本模型优先列表
- `Wanyesea_AI_Connectors` 鉴权合并读取 Connectors 选项中的密钥

### 修复

- 自定义 Connector 文本生成与 Connector 审批矩阵识别
- 自动通过 WordPress AI「Connector 审批」，避免 `The "deepseek" AI connector has not been approved for use by "WanYesea-AI/index.php"` 拦截出站请求
- 文本 `chat/completions` 与 `GET /models` 附带 `RequestOptions`，避免写文章生成标题等场景 WordPress HTTP 默认 5 秒导致 `cURL error 28`（如 SenseNova `token.sensenova.cn`）
- 出图请求附带 `RequestOptions`，避免 WordPress HTTP 默认 5 秒导致 `cURL error 28`
- Generate Image「No image data was generated」（SenseNova 仅 `url`、无 `b64_json`）
- SenseNova 出图模型 `parseResponseChoiceToCandidate` 缺少 `: Candidate` 返回类型，与 PHP AI Client 父类不兼容导致 Fatal error

### 开发者过滤器

- `wanyesea_ai_official_base_urls` / `wanyesea_ai_custom_official_base_urls` / `wanyesea_ai_relay_base_url` / `wanyesea_ai_rewritten_request_url`
- `wanyesea_ai_connect_provider_meta` / `wanyesea_ai_connect_provider_ids` / `wanyesea_ai_connector_api_key`
- `wanyesea_ai_custom_connectors` / `wanyesea_ai_custom_connector_api_key`
- `wanyesea_ai_provider_probe_timeout` / `wanyesea_ai_provider_probe_result` / `wanyesea_ai_relay_models_validation_timeout` / `wanyesea_ai_connect_endpoint_probe_provider_ids` / `wanyesea_ai_is_image_generation_available_for_env` / `wanyesea_ai_test_lab_image_timeout`
- `wanyesea_ai_text_generation_timeout` / `wanyesea_ai_text_generation_request_options`
- `wanyesea_ai_image_generation_timeout` / `wanyesea_ai_image_download_hosts`
- `wanyesea_ai_image_model_preference_per_provider_limit` / `wanyesea_ai_image_model_preference_total_limit`
- `wanyesea_ai_editor_image_flow_rest_routes` / `wanyesea_ai_editor_image_flow_text_model_limit` / `wanyesea_ai_editor_image_flow_relay_models_probe_timeout` / `wanyesea_ai_image_model_preference_per_provider_limit` / `wanyesea_ai_all_official_base_urls_for_relay`

### 说明

- 本版本为本地运行插件，不提供在线升级与远程授权
- 建议使用 WordPress 7.0 并启用官方 `ai` 与各 `ai-provider-for-*` 插件（仅配置 SenseNova 亦可完成 SenseNova 出图）
- DeepSeek、Moonshot 等自定义 Connector 仅用于文本；图像生成支持 Google、OpenAI、SenseNova
