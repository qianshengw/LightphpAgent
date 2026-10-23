<div align="center">

# LightphpAgent

**轻量级、功能齐全的 PHP AI Agent 框架**

零外部依赖 · 纯 PHP · OpenAI 兼容协议 · 事件溯源 · 安全内建

[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-Apache--2.0-D22128?logo=apache&logoColor=white)](https://www.apache.org/licenses/LICENSE-2.0)
[![Tests](https://img.shields.io/badge/tests-675%20passed-4CAF50?logo=phpunit&logoColor=white)](https://phpunit.de/)
[![Deps](https://img.shields.io/badge/dependencies-0-success)](https://packagist.org/)
[![Protocol](https://img.shields.io/badge/protocol-OpenAI%20Chat%20Completions-10A37F?logo=openai&logoColor=white)](https://platform.openai.com/docs/api-reference/chat)

[快速开始](#快速开始) · [功能总览](#功能总览) · [安装](#安装) · [核心概念](#核心概念) · [模型兼容](#模型兼容性) · [测试](#测试) · [详细文档](README-V.md)

</div>

---

## 这是什么

**LightphpAgent** 是一个可以直接放进生产环境的 PHP AI Agent 框架。它把「写 Agent」这件事需要的全部基础设施——工具调用、流式输出、会话持久化、记忆、知识库检索、工作流编排、持久化 DAG、人工审批、护栏、预算、追踪评估、MCP 接入——做成了开箱即用的一等公民。

它没有 LangChain，没有 LlamaIndex，**没有任何 Composer 依赖**。异步内核（Fiber 协程 + 事件循环）和 YAML 解析器全部随仓库分发，克隆下来 `require` 一行就能跑。

> 📖 **想看完整 API 手册？** 详细的逐模块文档（19 个功能模块、构造选项全表、语义修正对照表、已知限制）在 **[README-V.md](README-V.md)**。本文件是入口与概览。

### 三个设计取舍

| 取舍 | 做法 | 为什么 |
| :--- | :--- | :--- |
| **依赖** | 零外部依赖，异步内核 vendored 进仓库 | 部署即拷贝，不需要 Composer、不需要网络安装、不会因为上游升级炸掉 |
| **协议** | 只实现 OpenAI Chat Completions | 换服务商只需改 `base_url`——OpenAI / DeepSeek / Qwen / vLLM / Ollama / one-api / Azure 全部即插即用 |
| **失败** | 错误有稳定错误码，失败被隔离 | 工具抛异常不会让会话报废；调用方能明确区分「成功」与「失败」，而不是猜 `error` 字段 |

---

## 快速开始

### 30 秒跑通第一个 Agent

```php
<?php

require 'autoload.php';

use LightAgent\Core\LightAgent;
use LightAgent\Tools\ToolFunction;

// 1. 定义一个模型可以调用的工具
$weather = new ToolFunction(
    callable: static fn (string $city): string => "Query result: {$city} is sunny, 25C.",
    toolInfo: [
        'tool_name'        => 'get_weather',
        'tool_description' => 'Get current weather for a city',
        'tool_params'      => [
            ['name' => 'city', 'type' => 'string', 'description' => 'City name', 'required' => true],
        ],
    ],
);

// 2. 创建 Agent
$agent = new LightAgent([
    'model'    => 'gpt-4o-mini',
    'api_key'  => getenv('OPENAI_API_KEY'),
    'base_url' => 'https://api.openai.com/v1',   // 任何 OpenAI 兼容端点
    'auto_discover_skills' => false,
]);
$agent->registerTool($weather);

// 3. 运行 —— 框架自动完成：构建提示 → 调模型 → 解析 tool_calls → 执行工具 → 回传结果 → 生成最终回复
echo $agent->run('What is the weather in Shanghai?');
```

### 换成任意 OpenAI 兼容服务

```php
$agent = new LightAgent([
    'model'    => 'LongCat-2.5-Preview',
    'api_key'  => 'ak_...',
    'base_url' => 'https://api.longcat.chat/openai/v1',
]);
```

### 流式输出

```php
// 字符串增量
foreach ($agent->run('写一首关于秋天的诗', ['stream' => true]) as $chunk) {
    if (is_string($chunk)) { echo $chunk; }
}

// 结构化事件（content / tool_call / tool_result / error）
foreach ($agent->run('...', ['stream' => true, 'result_format' => 'event']) as $event) {
    // $event->type / $event->data
}
```

### 结构化结果（判断成功/失败）

```php
$result = $agent->run('...', ['result_format' => 'object']);

$result->success;    // 权威判据：本次运行是否成功
$result->stage;      // 失败发生在哪个阶段（model_request / max_tool_calls / …）
$result->content;    // 回复文本
$result->usage;      // token 用量
$result->toolCalls;  // 本次运行的工具调用
$result->error;      // 失败原因（带稳定错误码）
```

> ⚠️ **不要只看 `error`。** `success === false` 才是权威判据；`error` 只在有具体错误时填充。详见 [README-V.md §1](README-V.md)。

---

## 功能总览

19 个功能模块，全部开箱可用。按能力分组：

### 🧠 智能体核心

| 模块 | 说明 |
| :--- | :--- |
| **Agent 核心** | 完整的工具调用循环：提示构建 → 模型调用 → `tool_calls` 解析 → 工具执行 → 结果回传 → 最终回复 |
| **工具系统** | 一个 callable + 元数据 = 一个模型可调用的函数；注册即校验、参数自动验证与类型匹配 |
| **流式输出** | SSE 逐字节容错解析、跨 chunk 增量拼装 `tool_calls`、流式路径同样计入 token 预算 |
| **多代理协作** | `LightSwarm`：注册多个 Agent，由入口 Agent 决定 handoff，支持嵌套转移 |
| **思维树 (ToT)** | 主循环前用推理模型生成 → 反思 → 收敛到相关工具，再进主循环 |

### 🗂️ 记忆与知识

| 模块 | 说明 |
| :--- | :--- |
| **会话管理** | append-only 事件溯源：可回放、可分叉、可断点续跑；内存 / JSONL / SQLite 三种存储 |
| **记忆系统** | `MemoryProtocol` 接口 + 内置 `SharedMemoryPool`；**中文 bigram 分词**，子串查询也能命中 |
| **知识库 RAG** | SQLite FTS5，`owner`/`tenant` 过滤写在 SQL 的 `WHERE` 里；**中文走 trigram 双索引** |
| **上下文管理** | 预算感知的上下文压缩：超预算自动裁剪，可选摘要；CJK 按字计权 |

### ⚙️ 编排与执行

| 模块 | 说明 |
| :--- | :--- |
| **LightFlow** | 声明式多步工作流：依赖拓扑、逐依赖注入、超时、fallback、审批暂停与恢复、原子检查点 |
| **LightDAG** | 验证门控的持久化 DAG：租约 + 心跳续期 + epoch fencing，进程退出后可重开续跑 |
| **Agent 运行时** | Inbox 消息队列、Goal 生命周期、可取消后台 Job、权限收窄的子代理、多维预算 |
| **异步内核** | Fiber 协程 + 事件循环：`gather` 并发、`waitFor` 超时竞速、可重入互斥、取消令牌 |

### 🔐 安全与治理

| 模块 | 说明 |
| :--- | :--- |
| **能力体系** | 能力注册表 + 有序 fail-closed 策略引擎 + 只能收窄不能提升的权限集 |
| **人工审批** | `HumanApprovalHook`：approve / edit / respond / reject，审批绑定「工具名 + 确切参数」而非随机 run id |
| **钩子与护栏** | 12 个生命周期钩子；输入 / 工具 / 输出三道护栏；安全相关阶段的钩子失败即 fail-closed |
| **安全上下文** | 不可信调用方的信任链：`SecurityContext` 收窄、审批令牌绑定操作 + 参数摘要 + 策略版本 |
| **预算** | 模型调用 / 工具调用 / token / 秒数多维上限；用量持久化，扛得过会话重开 |

### 🔌 接入与观测

| 模块 | 说明 |
| :--- | :--- |
| **MCP 协议** | stdio / SSE / Streamable HTTP 三种传输；跨源端点校验、凭证脱敏、会话复用 |
| **追踪与评估** | 结构化 trace 事件、用量与成本汇总、回归式评估器（用例 / 断言 / 通过率） |
| **连接器校验** | 离线校验一组「工具 + 技能 + MCP 服务器 + 钩子」声明的完整性；明文凭证判为 error |
| **内置工具** | 受限数据表达式求值、OSS 上传、Python 执行器（**默认在分发层拦截**，见已知限制） |
| **技能系统** | 从 `skills/` 自动发现技能，生成 4 个工具；脚本以 argv 执行（不过 shell），路径不越界 |

> 📖 每个模块的完整用法、构造选项与代码示例见 **[README-V.md](README-V.md)**。

---

## 安装

### 方式一：直接使用（零依赖，推荐）

```bash
git clone <repo-url> LightphpAgent
cd LightphpAgent
```

```php
require '/path/to/LightphpAgent/autoload.php';
```

把整个目录拷到任何 PHP 8.1+ 环境即可，**不需要 Composer**。

### 方式二：Composer 集成

`composer.json` 已就绪（PSR-4 `LightAgent\` → `src/`），可通过 VCS repository 引入。两条安装路径解析出的类集合完全一致。

### 环境要求

| 项 | 必需 | 用途 |
| :--- | :---: | :--- |
| PHP >= 8.1 | ✅ | Fiber、readonly、enum、first-class callable |
| `ext-pdo_sqlite` | ✅ | 会话存储、DAG 存储、RAG FTS5 |
| `ext-curl` | ✅ | OpenAI HTTP 客户端、MCP HTTP 传输、OSS 上传 |
| `ext-mbstring` | ✅ | UTF-8 字符串处理 |
| `ext-ctype` | ✅ | 字符类型检测 |
| `ext-openssl` | 建议 | HTTPS 端点（通常随 PHP 内置） |
| `ext-sockets` | 可选 | 异步信号处理 |

---

## 核心概念

### Agent 主循环

```
用户查询
  → before_run 钩子
  → 输入护栏
  → 记忆上下文注入
  → 思维树（可选）
  → 构建系统提示 + 历史 + 查询
  → before_model_request 钩子 → 预算扣减 → 模型调用
  → 响应含 tool_calls？
      → 权限门控 → 护栏 → 预算 → before_tool_call 钩子 → 分发 → after_tool_result 钩子
      → 工具结果回传模型 → 循环（受 max_tool_iterations / max_tool_calls 限制）
  → 无 tool_calls → 输出护栏 → after_run 钩子
  → 返回（string / RunResult / 流 Generator）
```

### 换掉任意一层

框架把每一层都做成了可替换的契约，不需要 fork 就能改：

```php
// 换检索后端（向量库 / 混合检索 / 重排序）
class MyVectorStore implements \LightAgent\Knowledge\RetrievalProvider { /* ingest / search / remove / … */ }

// 换记忆后端（Qdrant / Chroma / Milvus / mem0）
class MyMemory implements \LightAgent\Protocol\MemoryProtocol {
    public function store(string $data, string $userId): mixed { /* … */ }
    public function retrieve(string $query, string $userId): mixed { /* … */ }
}
```

### 短名别名（可选）

```php
require 'autoload.php';
require 'src/LightAgent.php';

LightAgent\LightAgent::register();  // 幂等

$agent = new \LightAgent([            // = LightAgent\Core\LightAgent
    'model'   => 'gpt-4o-mini',
    'api_key' => getenv('OPENAI_API_KEY'),
]);
$flow  = new \LightFlow();            // = LightAgent\Flow\LightFlow
$pool  = new \SharedMemoryPool();     // = LightAgent\Memory\SharedMemoryPool
```

70+ 短名，见 `src/LightAgent.php` 的 `ALIASES` 常量。

---

## 模型兼容性

框架只使用 OpenAI Chat Completions 协议（`POST {base_url}/chat/completions`），以下服务均已验证兼容：

| 服务 | `base_url` |
| :--- | :--- |
| OpenAI | `https://api.openai.com/v1` |
| DeepSeek | `https://api.deepseek.com/v1` |
| 阿里云 Qwen (DashScope) | `https://dashscope.aliyuncs.com/compatible-mode/v1` |
| vLLM（本地） | `http://localhost:8000/v1` |
| Ollama（本地） | `http://localhost:11434/v1` |
| one-api / new-api 网关 | `http://your-gateway:3000/v1` |
| Azure OpenAI | `https://{resource}.openai.azure.com/openai/deployments/{model}/v1` |
| Claude（经兼容层） | `https://api.anthropic.com/v1/compat` |

> **不兼容**（需要协议转换网关）：Anthropic Messages 原生格式、Gemini 原生 API、Cohere 原生 API。

框架发出的请求体只包含 OpenAI 规范内的字段，**不会夹带任何未知字段**——这一点对严格端点（OpenAI 本身、Azure）很重要：多一个字段就是整个请求 400。

---

## 项目结构

```
LightphpAgent/
├── autoload.php              ← PSR-4 手动加载器（无 Composer 也能用）
├── coroutine_bootstrap.php   ← Composer 路径下的 vendored 前缀映射
├── composer.json             ← Composer 分发配置
├── phpunit.xml               ← 测试配置
├── README.md                 ← 本文件：项目入口与概览
├── README-V.md               ← 详细文档：19 个模块的完整 API 手册
├── src/                      ← 框架主体（175 文件 / 187 类 / 约 2.9 万行）
│   ├── LightAgent.php        ← 顶层 facade（短名别名注册）
│   ├── Core/                 ← LightAgent + LightSwarm
│   ├── Llm/  Mcp/  Flow/  Dag/  Session/  Runtime/
│   ├── Capabilities/  Security/  Review/  Hooks/  Guardrails/
│   ├── Knowledge/  Memory/  Protocol/  Tools/  Skills/
│   ├── Evaluation/  Connectors/  Tracing/  BuiltinTools/
│   └── Async/  Errors/  Logging/  Support/  Result/
├── coroutine/                ← vendored 异步内核（91 个 PHP 文件）
│   ├── amphp/                ← amphp/amp v3（Future / Fiber 协程）
│   ├── revolt/               ← revolt/event-loop（事件循环）
│   ├── sync/                 ← amphp/sync（Mutex / Semaphore / Channel）
│   └── yaml-6.4.48/          ← symfony/yaml（技能 frontmatter）
├── tests/                    ← 58 个测试文件（675 tests / 1798 assertions）
├── examples/                 ← 13 个可运行示例
└── tools/                    ← porting_scan.php（移植陷阱扫描器）
```

---

## 测试

```bash
# 全量运行
php coroutine/phpunit.phar

# 按目录
php coroutine/phpunit.phar tests/Core
php coroutine/phpunit.phar tests/Dag

# 带详细输出
php coroutine/phpunit.phar --testdox
```

**当前状态：675 tests / 1798 assertions 全绿，0 skip。**

包含 MCP stdio 传输的真实子进程端到端测试、Composer 安装路径的真实子进程测试，以及以 Python 原版为只读 oracle 的**差分测试**——把同一份输入喂给 PHP 与 Python 两套实现，逐场景比对，把「PHP 比原版更宽松」判定为失败。差分场景的当前分布：

```
{"total":36,"match":26,"stricter":5,"known_laxer":0,"laxer":0,"divergent":5}
```

`laxer: 0` 意味着：**没有登记在案的、PHP 侧比原版更松的语义漂移。**

> 差分测试需要 Python 3.9+ 与原版树。默认探测工作目录的兄弟目录 `../LightAgent-main/LightAgent`，也可用环境变量 `LIGHTAGENT_PYTHON_ORIGINAL` 指定；原版树不存在时该测试整体 skip，不影响其余测试。

---

## 示例

`examples/` 下 13 个可运行脚本，**全部离线运行**（内置 mock 服务端，无需 API Key）：

```bash
php examples/01_single_agent.php      # 基础对话
php examples/02_tools_agent.php       # 工具调用（流式拼装 → dispatch → 最终回复）
php examples/03_memory.php            # 共享记忆
php examples/04_multi_agent.php       # 多代理 LightSwarm
php examples/05_self_learning.php     # 记忆提升
php examples/06_chat_with_history.php # 多轮对话
php examples/07_use_mcp.php           # MCP 集成（stdio 子进程）
php examples/08_browser_use.php       # 多参数自定义工具
php examples/09_create_tools.php      # 运行时动态注册工具
php examples/10_lightflow.php         # LightFlow 依赖链 + trace 事件流
php examples/11_vector_memory.php     # 自定义 MemoryProtocol 适配器
php examples/12_rag_fts5.php          # 知识库 RAG（FTS5 + 中文 trigram）
php examples/tools_agent.php          # 工具调用分阶段演示
```

换成真实模型，只需把 `base_url` 从 mock 换成实际端点。

---

## 与 Python 原版的对照

LightphpAgent 以 [LightAgent](https://github.com/wxai-space/LightAgent) 为功能蓝本**重构**（不是逐行翻译）。两者对外能力基本对齐，但 PHP 侧在约 35 处语义上做了修正——空权限集的 fail-closed 语义、记忆正则的分隔符语法、DAG 默认存储持久化、流式路径的 token 计费、输出护栏在流式下的补齐、JSON 编码失败可见化、工具注册表与分发器共享同一份数据、**中文检索可用**等等。

逐条对照表（含两侧的 `file:line` 引用）在 **[README-V.md「与 Python LightAgent 的对照」](README-V.md)**。

---

## 已知限制

框架诚实标注自己的边界。以下是几条最需要注意的（完整列表见 [README-V.md「已知限制」](README-V.md)）：

| 限制 | 影响 | 缓解 |
| :--- | :--- | :--- |
| 同一 Agent 内的多个工具调用**串行执行** | 模型一轮发 N 个调用时不会真并发 | 有意为之：并发会写坏回填进消息历史的消息顺序。要真并发需先做 run 级状态隔离 |
| 模型请求只重试**瞬时**故障 | 429 / 5xx / 超时 / DNS / 连接 / TLS 会按 `model_max_retries` 退避重试；400 / 401 / 403 / 404 **不重试** | 需要更激进策略时调 `model_max_retries` / `model_retry_backoff` |
| **阻塞式钩子无法被抢占** | `PolicyHook` 的超时能被检测并上报，但 PHP 无法打断一个正在同步执行的处理器 | 需要硬超时的钩子写成非阻塞形式，或把慢逻辑挪到外部服务 |
| 流式密钥**跨块拼接** | 逐块脱敏只拦得住「完整落在单个 chunk 里」的密钥；跨块拼接要到末尾才被发现，此时前半段已流出 | 消费者侧对流式内容再做一层脱敏 |
| Python 执行沙箱是**静态分析** | 不是操作系统级隔离；且当前**没有受支持的启用路径**（分发层默认拦截） | 需要执行不可信代码时，请自己注册一个隔离的 `SandboxProvider` |
| 记忆与检索是**词面**匹配 | 零词面重叠的同义改写召回不到 | 需要语义召回时对接外部向量库（`RetrievalProvider`）或 mem0 / ClawMem（`MemoryProtocol`） |

---

## 文档索引

| 文档 | 内容 |
| :--- | :--- |
| **[README-V.md](README-V.md)** | 详细手册：19 个功能模块的完整 API、构造选项全表、语义修正对照表、已知限制全表 |
| **[tools/porting_scan.php](tools/porting_scan.php)** | 可重复运行的移植陷阱扫描器（带自检） |

---

## License

[Apache-2.0](https://www.apache.org/licenses/LICENSE-2.0)
