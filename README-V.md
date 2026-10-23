# LightphpAgent

**轻量级 功能齐全的 PHP AI Agent 框架** — 参考设计 [LightAgent](https://github.com/wanxingai/LightAgent)（Python），功能几乎对等实现。无 LangChain、无 LlamaIndex、无外部 Composer 依赖。

---

## 目录

- [项目定位](#项目定位)
- [快速上手](#快速上手)
- [安装](#安装)
- [架构总览](#架构总览)
- [功能模块详解](#功能模块详解)
  - [Agent 核心](#1-agent-核心)
  - [工具系统](#2-工具系统)
  - [流式输出](#3-流式输出)
  - [多代理协作](#4-多代理协作lightswarm)
  - [思维树](#5-思维树tree-of-thought)
  - [MCP 协议](#6-mcp-协议model-context-protocol)
  - [会话管理](#7-会话管理事件溯源)
  - [记忆系统](#8-记忆系统)
  - [知识库 RAG](#9-知识库-ragsqlite-fts5)
  - [LightFlow 工作流](#10-lightflow-工作流)
  - [LightDAG 持久化 DAG](#11-lightdag-持久化-dag)
  - [Agent 运行时](#12-agent-运行时)
  - [能力体系与安全](#13-能力体系与安全)
  - [人工审批](#14-人工审批humanapprovalhook)
  - [钩子与护栏](#15-钩子与护栏)
  - [追踪与评估](#16-追踪与评估)
  - [连接器校验](#17-连接器校验)
  - [内置工具](#18-内置工具)
  - [技能系统](#19-技能系统)
- [模型兼容性](#模型兼容性)
- [异步内核](#异步内核)
- [目录结构](#目录结构)
- [测试](#测试)
- [示例](#示例)
- [与 Python LightAgent 的对照](#与-python-lightagent-的对照)
- [已知限制](#已知限制)
- [License](#license)

---

## 项目定位

LightphpAgent 是一个**独立、健全、可分发的 PHP AI Agent 框架**。它以 LightAgent（Python）为功能蓝本重构，不是逐行翻译——设计目标是让 PHP 开发者获得与 Python 生态 Agent 框架对等的能力，同时保持 PHP 的简洁与部署便利。

**设计原则**：

1. **零外部依赖**——异步内核（Amp/Revolt）、YAML 解析器全部 vendored 进 `coroutine/` 目录，克隆即用
2. **纯 PHP**——所有逻辑（包括 Python 代码安全检查器）均以 PHP 实现，运行时不需要 Python/Node/任何其他解释器
3. **OpenAI 格式唯一**——所有模型交互通过 OpenAI Chat Completions 协议（`/v1/chat/completions`），兼容所有实现了该协议的模型服务
4. **事件溯源**——Session/DAG 全部基于 append-only 事件日志，可重放、可分叉、可断点续跑
5. **安全内建**——护栏、钩子、预算、审批 Hook、fail-closed 策略引擎都是一等公民，随构造器默认可用（默认值即"无约束"：空护栏数组、空钩子数组、无策略）。**能力门控（CapabilityGate）与审批令牌目前只接入 DAG，核心 Agent 的工具路径尚未接入策略引擎**——不过工具路径**已经**会执行运行选项里带上的 `permissions` 权限集（不在 `tool.<name>` 允许列表里的工具被 `[LA-POLICY]` 拦下），子代理的权限收窄因此是有效的；详见「与 Python LightAgent 的对照」

## 快速上手

**要求**：PHP >= 8.1，扩展 `pdo_sqlite` / `curl` / `mbstring` / `ctype`

```bash
# 克隆项目
git clone <repo-url> LightphpAgent
cd LightphpAgent
```

### 最小可用示例

```php
<?php
require 'autoload.php';

use LightAgent\Core\LightAgent;
use LightAgent\Tools\ToolFunction;

// 1. 定义工具（模型可调用的函数）
$weather = new ToolFunction(
    callable: fn (string $city): string => "Query result: {$city} is sunny.",
    toolInfo: [
        'tool_name'        => 'get_weather',
        'tool_description' => 'Get current weather for a city',
        'tool_params'      => [
            ['name' => 'city', 'type' => 'string', 'description' => 'City name', 'required' => true],
        ],
    ],
);

// 2. 创建 Agent
$agent = new LightAgent\Core\LightAgent([
    'model'    => 'gpt-4o-mini',
    'api_key'  => getenv('OPENAI_API_KEY'),
    'base_url' => 'https://api.openai.com/v1',  // 任何 OpenAI 兼容端点
    'auto_discover_skills' => false,
]);
$agent->registerTool($weather);

// 3. 运行——Agent 自动：构建提示 → 调模型 → 解析 tool_calls → 执行工具 → 回传结果 → 生成最终回复
echo $agent->run('What is the weather in Shanghai?');
```

### 使用顶层短名别名

```php
require 'autoload.php';
require 'src/LightAgent.php';

LightAgent\LightAgent::register();  // 注册全部短名别名（幂等）

$agent  = new \LightAgent([...]);        // = LightAgent\Core\LightAgent
$flow   = new \LightFlow();              // = LightAgent\Flow\LightFlow
$pool   = new \SharedMemoryPool();       // = LightAgent\Memory\SharedMemoryPool
$store  = new \SqliteSessionStore($db);  // = LightAgent\Session\SqliteSessionStore
// ... 见 src/LightAgent.php 的 ALIASES 常量（70+ 短名）
```

## 安装

### 方式一：直接使用（推荐——零依赖）

```
LightphpAgent/
├── src/           ← 框架主体（174 个 PHP 文件 / 186 个类，28,540 行）
├── coroutine/     ← vendored 异步内核（Amp + Revolt + Sync + YAML，91 个 PHP 文件）
├── autoload.php   ← 手动 PSR-4 加载器
└── examples/      ← 12 个可运行示例
```

把整个目录拷贝到任何 PHP 8.1+ 环境即可，不需要 Composer：

```php
require '/path/to/LightphpAgent/autoload.php';
```

### 方式二：Composer 集成

`composer.json` 已就绪（PSR-4 `LightAgent\` → `src/`），其他项目可通过 VCS repository 引入。

vendored 的 Amp / Amp\Sync / Revolt / Symfony-Yaml 不是已发布的 Composer 包，无法通过 PSR-4 扫描发现，因此由手写的 `coroutine_bootstrap.php`（列在 `autoload.files` 里）做前缀映射——与零依赖路径的 `autoload.php` 完全一致。两条安装路径解析出的类集合相同。

### 环境要求

| 项              | 必需  | 用途                                       |
| -------------- | --- | ---------------------------------------- |
| PHP >= 8.1     | ✅   | Fiber、readonly、enum、first-class callable |
| ext-pdo_sqlite | ✅   | Session 存储、DAG 存储、RAG FTS5               |
| ext-curl       | ✅   | OpenAI HTTP 客户端、MCP HTTP 传输、OSS 上传       |
| ext-mbstring   | ✅   | UTF-8 字符串处理                              |
| ext-ctype      | ✅   | 字符类型检测                                   |
| ext-openssl    | 建议  | HTTPS 端点（通常随 PHP 内置）                     |
| ext-sockets    | 可选  | 异步信号处理                                   |

## 架构总览

```
┌─────────────────────────────────────────────────────┐
│                   使用者代码                          │
├─────────────────────────────────────────────────────┤
│  LightAgent (Core)          LightSwarm (多代理)       │
│    ├── run() / arun()         ├── registerAgent()    │
│    ├── 工具循环               ├── 意图识别/handoff    │
│    ├── ToT 思维树             └── 代理注册表          │
│    └── 记忆/hook/护栏                                  │
├─────────────────────────────────────────────────────┤
│                 功能子系统                             │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌─────────┐ │
│  │ Llm/     │ │ Mcp/     │ │ Flow/    │ │ Dag/    │ │
│  │ OpenAI   │ │ JSON-RPC │ │ 工作流    │ │ 持久DAG  │ │
│  │ SSE 流式 │ │ 3种传输   │ │ 审批/恢复 │ │ 租约/验证│ │
│  └──────────┘ └──────────┘ └──────────┘ └─────────┘ │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌─────────┐ │
│  │ Session/ │ │ Runtime/ │ │Capabilities│ │Security/│ │
│  │ 事件溯源  │ │Inbox/Job │ │ 权限/策略  │ │ 审批令牌 │ │
│  │ 3种存储   │ │ Goal/... │ │ 适配器    │ │ 门控    │ │
│  └──────────┘ └──────────┘ └──────────┘ └─────────┘ │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌─────────┐ │
│  │ Knowledge│ │ Memory/  │ │Evaluation│ │Review/  │ │
│  │ FTS5 RAG │ │ 共享池    │ │ 回归评估  │ │ 人工审批 │ │
│  └──────────┘ └──────────┘ └──────────┘ └─────────┘ │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐             │
│  │ Hooks/   │ │Guardrails│ │ Tracing/ │             │
│  │ 运行期钩子│ │ 输入护栏  │ │ 事件追踪  │             │
│  └──────────┘ └──────────┘ └──────────┘             │
├─────────────────────────────────────────────────────┤
│           Async/（Amp + Revolt + Fiber 内核）          │
│        async / gather / waitFor / Mutex / Cancel      │
├─────────────────────────────────────────────────────┤
│  coroutine/（vendored: amphp/amp, revolt, sync, yaml） │
└─────────────────────────────────────────────────────┘
```

**代码量**：`src/` 175 个 PHP 文件（187 个类），31,260 行；`coroutine/` 91 个 vendored PHP 文件（另含 `phpunit.phar` 与 YAML 库文档，共 97 个文件）。

## 功能模块详解

### 1. Agent 核心

**入口**：`LightAgent\Core\LightAgent`

Agent 的主循环（core.py 的忠实移植）：

```
用户查询
  → before_run hook
  → 输入护栏（guardrails.checkInput）
  → 记忆上下文注入（addMemoryContext）
  → ToT 思维树（可选，runThought）
  → 构建系统提示 + 历史 + 查询 → chatParams
  → before_model_request hook → 预算扣减 → 模型调用
  → 响应含 tool_calls？
      → 逐个：权限门控（[LA-POLICY]）→ 护栏 → 预算 → before_tool_call hook → dispatch → after_tool_result hook
      → 工具结果回传模型 → 循环（受 max_tool_iterations 限制）
  → 无 tool_calls → 输出护栏（applyOutputGuardrails）→ after_run hook
  → 返回（string / RunResult / 流 Generator / StreamEvent 流）
```

**构造选项**（全部可选，除 model/api_key）：

```php
$agent = new LightAgent([
    // 基础
    'name'           => 'MyAgent',          // 代理名（默认 LightAgent+随机8位数）
    'instructions'   => 'You are...',       // 系统指令
    'role'           => 'assistant',        // 角色描述
    'model'          => 'gpt-4o-mini',      // 模型名
    'api_key'        => 'sk-...',           // API 密钥（或 OPENAI_API_KEY 环境变量）
    'base_url'       => 'https://...',      // OpenAI 兼容端点（或 OPENAI_BASE_URL）
    // 模型请求重试（只重试瞬时故障）
    'model_max_retries' => 2,               // 模型请求最多重试几次（默认 2）
    'model_retry_backoff' => 0.5,           // 指数退避基数（秒）：第 n 次重试等待 base * 2^(n-1)
    // 记忆
    'memory'         => $memoryBackend,     // 实现 MemoryProtocol 的后端
    'memory_policy'  => new MemoryPolicy(namespace: 'tenant-a'),
    'memory_namespace' => 'tenant-a',       // 记忆命名空间快捷方式
    'self_learning'  => true,               // 启用反思记忆（需 promotion）
    // ToT
    'tree_of_thought' => true,              // 启用思维树
    'tot_model'      => 'deepseek-r1',
    // 工具
    'tools'          => [$tool1, $tool2],   // 初始工具
    'builtin_tools'  => true,               // 注册内置 safe_expression（默认开启）
    // 安全
    'input_guardrails'  => [$inputGuardrail],
    'tool_guardrails'   => [$toolGuardrail],
    'output_guardrails' => [$outputGuardrail],
    'hooks'          => [$hook],            // 运行期钩子
    // 持久化
    'session_store'  => $store,             // SessionStore 实现
    'budget_limits'  => new BudgetLimits(modelCalls: 20, tokens: 100000),
    // 能力
    'capability_registry' => $registry,
    'policy_engine'  => $engine,
    // 上下文
    'context_budget' => new ContextBudget(maxTokens: 8192),
    'context_compactor' => new ContextCompactor(),
    // 调试
    'debug'          => true,
    'log_level'      => 'INFO',
    'log_file'       => 'my-agent.log',
]);
```

**运行模式**：

```php
// 非流式（默认）——返回 string
$result = $agent->run('hello');

// 结构化结果——返回 RunResult 对象
$result = $agent->run('hello', ['result_format' => 'object']);
$result->content;       // 回复文本
$result->toolCalls;     // 本次运行的工具调用列表
$result->usage;         // token 用量
$result->traceId;       // 追踪 ID
$result->error;         // 错误（如果有）
$result->success;       // 本次运行是否成功（权威判据，见下）
$result->stage;         // 失败发生的阶段（before_run / input_guardrail / before_model_request / model_request / max_tool_iterations / max_retry / output_guardrail / ...）
$result->trace;         // 结构化追踪事件

// 字典格式（与 object 同构，额外带 success / stage）
$result = $agent->run('hello', ['result_format' => 'dict']);

// 流式——返回 Generator，逐步产出 string | tool_call array | tool_result array
$stream = $agent->run('hello', ['stream' => true]);
foreach ($stream as $chunk) {
    if (is_string($chunk))  echo $chunk;          // 内容增量
    else                    print_r($chunk);       // 工具调用/结果事件
}

// 流式 + 结构化事件
$events = $agent->run('hello', ['stream' => true, 'result_format' => 'event']);
foreach ($events as $event) {
    // StreamEvent: type=content|tool_call|tool_result|error|event
}

// 异步入口（在事件循环内使用）
$result = $agent->arun('hello');          // 等价 run()，在 fiber 内执行
$stream  = $agent->astream('hello');      // 流式 Generator
```

**`success` 是权威判据**：`RunResult::$success`（bool）与 `$stage`（?string）由核心在收尾时写入。**不要只看 `error`**——旧版本里 `error` 只在正文以 `[LA-` 开头时才被填充，于是 `max_tool_iterations` 用尽、`max_retry` 耗尽、人工审批被阻断这些**失败**都会以 `error === null` 的形式交还调用方，与成功无法区分。现在 `$result->success === false` 就是失败，`$result->stage` 指出失败发生的阶段。

**运行选项 `permissions`**：一次运行可以带一个收窄过的权限集（`PermissionSet`），它会被**真正执行**——不在 `tool.<name>` 允许列表里的工具在分发前就被拦下，返回 `[LA-POLICY]`：

```php
$agent->run('hello', ['permissions' => $parent->narrow(allowed: ['tool.read'])]);
```

`SubagentManager` 注册子代理时收窄出的权限集正是通过这个运行选项下发的，所以子代理的权限收窄是**有效**的，而不只是元数据。

**模型请求重试**：`max_retry` / `max_tool_iterations` 管的是**工具循环**；模型请求本身有独立的旋钮。`model_max_retries`（默认 2）控制重试次数，`model_retry_backoff`（默认 0.5 秒）是指数退避的基数。**只有瞬时故障会被重试**——HTTP 408/409/425/429/5xx、超时、DNS/连接/TLS 错误；400/401/403/404 是确定性失败，重试只会白白消耗配额，因此**不重试**。

**工具执行次数上限**：`max_tool_iterations` 只管**轮数**，但模型**一轮**可以并行发 N 个工具调用（实测某模型一轮就发了 10 个），执行数因此是轮数的 N 倍。`max_tool_calls` 是**跨整次运行的实际执行数**上限：触顶时运行失败，`$result->stage === 'max_tool_calls'`，错误码 `LA-TOOL`。

它和 `max_tool_iterations` 一样是**运行选项**（`$agent->run($q, ['max_tool_calls' => 3])`），也可以在**构造时**给出默认上限；两者同时存在时**运行选项优先**。`< 1` 的值直接抛 `InvalidArgumentException`，不会被当成"不限"。

**其他主类方法**：

```php
// 会话管理
$agent->getSession($sessionId?);            // → Session|null
$agent->exportSession($sessionId?);         // → array（完整事件日志）
$agent->replaySession($sessionId?);         // → array（完成/未完成/失败轮次）
$agent->checkpointSession($label?, $meta?); // → array（检查点信息）
$agent->forkSession($throughSeq?, $meta?);  // → Session（分叉副本）
$agent->compactSession($maxMsgs?, $sessionId?); // → array（压缩结果）

// 工具管理
$agent->registerTool($toolFunction);        // 注册单个工具
$agent->loadTools([$tool1, $tool2]);        // 批量注册
$agent->getTool('name');                    // → ToolFunction（未注册抛异常）
$agent->getTools();                         // → OpenAI 格式 schema 列表
$agent->validateTools();                    // → 诊断列表

// 记忆
$agent->listMemoryCandidates();             // → 待提升候选列表
$agent->promoteMemoryCandidate($id);        // 手动提升
$agent->parseToolArguments($jsonStr);       // 宽容参数解析（公开）

// 追踪
$agent->exportTrace();                      // → 事件列表
$agent->summarizeTrace($pricing?);          // → TraceSummary
$agent->exportTraceTo($exporter, ...);      // 导出到外部

// 能力
$agent->invokeCapability('tool.xxx', $args); // 通过 Policy 调用能力

// MCP
$agent->setupMcp($mcpServersConfig);        // 初始化并注册 MCP 工具

// 思维树
[$plan, $tools] = $agent->runThought($query);
```

### 2. 工具系统

**入口**：`LightAgent\Tools\ToolRegistry` / `ToolFunction` / `ToolDispatcher`

工具 = 一个 PHP callable + 元数据（`toolInfo`）。元数据被转换为 OpenAI Function Calling schema 供模型使用。

```php
use LightAgent\Tools\ToolFunction;

$tool = new ToolFunction(
    callable: static fn (string $query, int $limit = 5): array => [...],  // 业务逻辑
    toolInfo: [
        'tool_name'        => 'search_docs',            // 必须匹配 [A-Za-z_][A-Za-z0-9_]*
        'tool_title'       => '文档搜索',                 // 可选标题
        'tool_description' => 'Search documentation',    // 模型看到的描述
        'tool_params'      => [                          // 参数 schema
            ['name' => 'query', 'type' => 'string', 'description' => '...', 'required' => true],
            ['name' => 'limit', 'type' => 'integer', 'description' => '...', 'required' => false],
        ],
    ],
);
```

**自动参数验证**：Dispatcher 在调用前检查必填参数和类型匹配（string/integer/number/boolean/array/object），缺失或类型不匹配返回 `[LA-TOOL]` 格式错误（回传给模型自行修正）。

**工具名强制**：`tool_name` 在**注册时**即校验语法，必须匹配 `[A-Za-z_][A-Za-z0-9_]*`，否则直接抛 `InvalidArgumentException`（不会带着非法名进入发给服务商的 schema）。同名工具**允许重复注册**（后注册的覆盖先注册的），但重复次数会被 `validateTools()` 记为 `error` 级诊断，供接入方自查。

**安全封禁**：三个 Python 执行器在分发层**始终拦截**（返回 `[LA-SANDBOX]`），无论是否注册——这是 Python 原版的安全设计。判定依据是 **callable 的身份**（闭包的 `文件:行` / 可调用数组的 `类::方法`），**不是工具名**——所以把同一个执行器改名成 `run_py` 也照样被拦（此前按名字判定时，改名即可在宿主上真的执行 Python）。

**分发器与注册表是同一份数据**：`ToolDispatcher` 持有**注册表引用**而不是数组副本，因此任何注册（内置的、运行时的、直接注册到公开注册表上的）都**立即可分发**。

**注册即校验**：`registerTool()` 在**改动任何状态之前**先校验 `tool_params` 的形状（参数名、类型、必填标记），不合法直接抛 `InvalidArgumentException`——不会出现"注册了一半"的注册表。

**工具异常不外泄栈**：工具抛异常时，回传给模型的只有分类学格式化后的那一行（`[LA-TOOL] …`），**不含 PHP 堆栈**——堆栈里的绝对服务器路径会进入模型上下文，白白烧掉 token 预算。

### 3. 流式输出

**入口**：`LightAgent\Llm\OpenAiClient` / `SseParser` / `ToolCallAssembler`

- `SseParser`：逐字节容错的 SSE 解析器（跨 feed 的部分行处理、`[DONE]` 检测、keep-alive 跳过、**多行 `data:` 合并为一个事件**）
- `ToolCallAssembler`：跨 chunk 增量拼装 tool_calls（index 索引化，逐字对齐 OpenAI 协议）
- `OpenAiClient`：ext-curl 实现，支持非流式（JSON）和流式（SSE）两种模式

**收尾必须冲刷尾部**：`feed()` 只吐**完整行**，所以连接关闭时缓冲区里还压着流的最后一个事件——对 OpenAI 来说那通常正是 `stream_options.include_usage` 的 usage 分片，也就是整条流的 token 账。传输层在传输结束时调用 `SseParser::finish()`，把没有换行结尾的最后一行也冲出来。

**坏帧不再被静默丢弃**：一个不是合法 JSON 的流帧是协议违规，不是"服务端没话说"。这类帧以前被 `continue` 跳过，**看起来和服务端正常结束一模一样**；现在会被计数在 `OpenAiClient::$malformedStreamFrames` 里，损失变得可观测。

**协议**：仅 OpenAI Chat Completions（`POST /v1/chat/completions`）。通过 `base_url` 兼容所有实现了该协议的服务。

**输出护栏**：流式路径同样受输出护栏约束，且是**两层**：

1. **逐块脱敏**——每个 chunk 在交给消费者之前先过一遍护栏，落在单个 chunk 里的密钥不会流出去；
2. **末尾整段护栏**——流结束后对完整文本再跑一次 `after_model_response` hook + 输出护栏，覆盖**跨块拼接**的密钥。

> 跨块拼接的密钥在第 1 层无法识别（分块时它还不是一个完整的模式），只能在末尾被发现，此时前半段已经流出——需要严格不外泄时请在消费者侧再兜一层。

**Token 计费**：流式请求默认带上 `stream_options: {"include_usage": true}`，服务商会在流末尾追加一个 usage 分片；框架**不会**在收到 `finish_reason` 分片时就收尾，而是继续消费到 usage 分片（或流结束）再统一收尾，因此流式运行同样计入 token 预算。

- 网关若不认这个字段（部分 OpenAI 兼容网关会以 HTTP 400 拒绝未知字段），客户端会**自动去掉该字段重试一次**，代价是该次流没有 usage 分片、无法计费；
- 端点拒绝过一次之后会被记住（`OpenAiClient::$streamOptionsUnsupported`），**后续轮次不再重发**该字段——否则每一个流式轮次都要白付一次往返 + 一次服务商侧的 400；
- 也可以在构造时用 `stream_include_usage => false` 直接关闭（此时请求体里不会出现 `stream_options`）。

### 4. 多代理协作（LightSwarm）

**入口**：`LightAgent\Core\LightSwarm`

```php
$swarm = new LightSwarm();
$swarm->registerAgent($writer, $reviewer, $translator);

// 通过入口代理运行；模型输出 "transfer to Reviewer" 时自动转移
$response = $swarm->run($writer, 'review this draft');

// handoff 流程：意图检测 → on_handoff hook（可拦截/改写）→ 目标代理运行
// 支持嵌套（目标代理也可以转移给第三个代理）
```

### 5. 思维树（Tree of Thought）

**入口**：`LightAgent::runThought()`

启用 `tree_of_thought => true` 后，Agent 在主循环前用 ToT 模型（默认 deepseek-r1）执行三步推理：

1. 生成初始工具使用计划
2. 反思并优化计划（严格限定在已注册工具内）
3. 输出 JSON 工具选择 → `filterTools` 过滤后只暴露相关工具给主循环

### 6. MCP 协议（Model Context Protocol）

**入口**：`LightAgent\Mcp\MCPClientManager` + `JsonRpc` + `StdioTransport` / `HttpTransport`

```
MCPClientManager
├── StdioTransport     — 子进程 JSON-RPC（proc_open + EventLoop::onReadable 非阻塞读）
├── HttpTransport      — Streamable HTTP + 传统 SSE（POST JSON-RPC + endpoint 发现 + 常驻 GET 流）
└── JsonRpc            — 协议帧编解码（initialize / tools/list / tools/call）
```

```php
$manager = new MCPClientManager([
    'mcpServers' => [
        'local'   => ['command' => 'php', 'args' => ['server.php']],  // stdio
        'remote'  => ['url' => 'http://host/sse', 'headers' => [...]], // SSE
        'http'    => ['transport' => 'streamable-http', 'url' => '...'], // Streamable HTTP
    ],
], $toolRegistry);

// 注册所有 MCP 工具到 ToolRegistry（自动 schema 转换、命名空间、冲突检测）
$manager->registerMcpTool();

// 带重连的工具调用
$result = $manager->callTool('tool_name', $arguments, targetServer: 'local');

// 凭证动态注入
$manager = new MCPClientManager($config, $registry,
    credentialProvider: fn ($server, $cfg) => ['Authorization' => 'Bearer ' . getToken($server)],
);
```

**传输层能力**：

- **SSE（传统 2024-11-05 协议）**——端点被**增量读取**（不是一次性读完再解析），服务端发来的 `endpoint` 事件会被解析出来，JSON-RPC 响应则通过**请求 id 在常驻 GET 流上关联**。服务端如果不讲这套协议，现在会得到一条**明确的、可操作的错误**（提示改用 `transport: streamable-http`），而不是沉默的 5 秒超时。
- **SSE 的端点同源校验**——传统 SSE 协议里，**服务端自己声明** JSON-RPC 请求该发到哪里，而那些请求是**带着配置的头**（含 `Authorization: Bearer …`）发出的。框架会校验被声明端点的**同源**（scheme + host + port，缺省端口归一化），跨源即拒绝并给出明确错误（`MCP SSE server announced a cross-origin endpoint …`），**一个字节的凭据都不会发出去**——与官方 MCP SDK 的行为一致。注意这个检查**不能靠抛异常实现**：它发生在 curl 的写回调里，而该回调会吞掉异常，所以违规是被**记录**下来再转成一次失败请求的。
- **Streamable HTTP**——请求不再把整个事件循环**堵死**到响应结束。
- **stdio**——子进程死掉时抛一个干净的异常（以前是一个未捕获的 `UnhandledFutureError` 把**整个进程**带走）；子进程的 **stderr 会被持续抽干**（stderr 洪水曾把服务端永久卡死）；每个请求有**截止时间**；读缓冲**有上限**；配置的 `env` 是**合并**到继承来的环境变量之上，而不是整体替换（否则子进程会拿到 `PATH=false HOME=false`）。

**会话复用**：一次工具调用**不会**重启 MCP 服务端进程——会话被复用，"重连"只在**真的失败**时发生（以前的"重连"是重新 spawn，丢掉服务端状态，并且每次调用都要重付一次 initialize + tools/list 往返）。

**安全特性**：工具**结果**和错误消息都会自动脱敏（Bearer token / sk- 密钥 → `[redacted]`）；失败回执保留诊断信息（`MCP诊断: server::phase -> ErrorType: message`）。服务端给出的工具名会先被**清洗并校验**再进入发给服务商的 schema（一个叫 `foo bar` 的工具名在 OpenAI function schema 里根本无法表达，放行会让整次请求 400）。

### 7. 会话管理（事件溯源）

**入口**：`LightAgent\Session\*`

Session 是 append-only 事件日志，每次 Agent 运行的所有状态变化（消息收发/工具调用/模型请求/错误/策略决策）都记录为带序列号的不可变事件。

```php
// 三种存储后端
$store = new InMemorySessionStore();              // 内存（默认）
$store = new JsonlSessionStore('/path/to/dir');   // JSONL 文件（每 session 一文件，原子写入）
$store = new SqliteSessionStore('/path/to/db');   // SQLite（WAL 模式，事务保存）

// Agent 使用
$agent = new LightAgent([..., 'session_store' => $store]);
$agent->run('hello', ['session_id' => 'my-session']);  // 指定 ID 则恢复，省略则新建

// 事件追加（自动验证序列连续性、去重、schema 版本）
$session->append('custom.event', ['key' => 'value']);

// 回放——按 turn 聚合
$replay = $session->replay();
$replay->completedTurns;    // 正常完成的 turn ID 列表
$replay->incompleteTurns;   // 中断的
$replay->failedTurns;       // 失败的

// 检查点 + 分叉
$checkpoint = $session->checkpoint('before risky step');
$forked = $session->fork(throughSequence: 5);  // 复制前 5 个事件到新 session

// 上下文投影——从事件重建模型消息列表
$messages = (new ContextProjector())->messages($session, turnId: 'turn-1');

// 上下文压缩——超预算时自动裁剪（保留 system + 尾部消息，可选摘要）
$budget = new ContextBudget(maxTokens: 8192, reservedOutputTokens: 1024);
$compactor = new ContextCompactor(summarizer: fn ($msgs) => 'summary...');
$result = $compactor->compactToBudget($messages, $budget);
```

**安全特性**：`api_key`/`token`/`secret`/`password` 等键在事件数据中自动替换为 `[redacted]`。键名先归一化成 snake_case 再比对，所以 **camelCase 拼写同样覆盖**（`accessToken`、`clientSecret`、`dbPassword`、`authToken`…）——这些才是实际出现在载荷里的拼法，只认 `api_key` 的话它们会以**明文**写进会话日志。脱敏在**写入时**执行，不只是在构造时。

**持久化存储拒绝陈旧副本**：`JsonlSessionStore` 与 `SqliteSessionStore` 现在和内存后端一样，**拒绝**写入比本地已存版本更旧的副本（否则两个进程各写各的，事件序列会分叉）。JSONL 存储在 Windows 上重命名失败时**不再静默丢事件**（会明确报出来），SQLite 存储改为**增量 upsert**。

### 8. 记忆系统

**入口**：`LightAgent\Protocol\MemoryProtocol`（接口）+ `LightAgent\Memory\SharedMemoryPool`（内置实现）

```php
// MemoryProtocol 接口——任何实现都可以注入 Agent
interface MemoryProtocol {
    public function store(string $data, string $userId): mixed;
    public function retrieve(string $query, string $userId): mixed;
}

// 内置 SharedMemoryPool——token 重叠排序 + 来源/范围/代理过滤
$pool = new SharedMemoryPool(agentName: 'my-agent', maxResults: 5);

// ⚠️ 必须写入「带命名空间前缀」的用户 id
$scoped = (new MemoryPolicy(namespace: 'tenant-a'))->scopedUserId('alice');  // 'tenant-a:alice'
$pool->store('user preference', $scoped, metadata: ['source' => 'user']);
$pool->retrieve('preference', $scoped);  // {'results': [{memory, metadata, ...}]}

// 注入 Agent
$agent = new LightAgent(['memory' => $pool, 'memory_namespace' => 'tenant-a']);
```

> **为什么必须是 `scopedUserId()`**：Agent 读记忆时用的是 `MemoryPolicy::scopedUserId($userId)`，也就是 `tenant-a:alice`；而直接 `$pool->store(..., 'alice')` 存的是裸 id。两者对不上，**检索永远命中 0**，而且是**静默**的——偏好块会一直是空的。存储和检索必须用**同一个**（带前缀的）id。

**容量上限**：`SharedMemoryPool` 是有界的（`maxRecords`，默认 1000），超出容量时按 **FIFO 淘汰最旧**的记录（最新的才是 Agent 还在推理的那些）。

**停用词查询返回空**：一个只由停用词（`to`、`the`…）组成的查询**不返回任何记录**——返回全部记录是"与相关性无关"的行为，停用词表的存在正是为了避免这种误命中。用 `listRecords()` 枚举已存记忆。

**检索分词**：`SharedMemoryPool` 的 token 重叠打分对文本做小写化、**剥离首尾标点**（`"peanuts."` 能被查询 `peanuts` 命中），并过滤 45 个英文停用词（避免 `"food to avoid"` 因为 `to` 而误命中）。

**中文分词（bigram）**：中文没有词间空格，若只按空白切分，`用户喜欢喝乌龙茶` 会是**一个 token**，只有完全相同的查询才能命中。因此对每一段 CJK 串**额外产出字符 bigram**（`乌龙茶` → `乌龙`、`龙茶`），让较短的查询与较长的记忆共享词元；高频功能词 bigram（`我们`/`这个`/`因为`…）被停用词表过滤。英文路径**完全不变**。

**提问与偏好分区隔离（部分生效）**：Agent 把用户的**提问**写入 `source=user` / **`scope=utterance`** 分区，与「##用户偏好」块使用的 `scope=user` 分区在**写入侧**已经分开。但**召回侧目前没有把 `source`/`scope` 传给存储层**，所以默认配置下历史提问仍会进入偏好块——给 `MemoryPolicy` 传 `allowedScopes: ['user']` 即可真正隔离（Python 原版把两者混在同一分区，见「与 Python LightAgent 的对照」）。

**安全策略**（`MemoryPolicy`）——控制记忆的读写边界：

```php
$policy = new MemoryPolicy(
    namespace: 'tenant-a',              // user_id 前缀隔离
    allowUnattributedResults: false,    // 拒绝无来源的结果
    allowedSources: ['user'],           // 只允许 user 来源
    allowedScopes: ['user'],            // 只允许 user 范围
    minConfidence: 0.8,                 // 最低置信度
    enforceExpiresAt: true,             // 过滤过期记忆
    maxWritesPerRun: 5,                 // 每次运行最多写入 5 条
    rejectDuplicateWrites: true,        // 拒绝重复写入
    minWriteLength: 4,                  // 最短写入长度
    rejectWritePatterns: ['ignore.*instructions'],  // 正则拒绝
);
```

**拒绝规则的写法**：`rejectWritePatterns` 支持两种拼法——

- **分隔符形式** `/body/` 与 `/body/flags`（如 `/etc/passwd/`、`/etc/passwd/i`）：首尾斜杠是分隔符，body 才是正则；body 内部的斜杠会被自动转义，所以 `/etc/passwd/` 能命中 "…/etc/passwd"，`/etc/passwd/i` 也能正常生效。
- **裸形式**（如 `ignore.*instructions`）：整串就是正则。

两种形式都按**不区分大小写**匹配；真正非法的正则（例如 `secret)`）会保持非法并因此 **fail-closed**（拒绝写入），不会静默放行。

**记忆提升**（self_learning）——内部来源（reflection/delegation/agent 等）的记忆默认**不可注入**（防提示注入），需要显式提升：

```php
// Agent 产生 reflection 候选 → listMemoryCandidates() 返回
// 手动或通过策略提升后才能写入共享后端
$agent->promoteMemoryCandidate($candidateId);
// 或通过 memory_promotion_admission 回调自动审批
```

### 9. 知识库 RAG（SQLite FTS5）

**入口**：`LightAgent\Knowledge\SqliteFtsRetrievalProvider`（实现 `LightAgent\Knowledge\RetrievalProvider` 接口）

```php
use LightAgent\Knowledge\RetrievalDocument;
use LightAgent\Knowledge\SqliteFtsRetrievalProvider;

$rag = new SqliteFtsRetrievalProvider('/path/to/knowledge.db', chunkSize: 1200, chunkOverlap: 120);

// 摄取文档（自动分 chunk）
$docId = $rag->ingest(new RetrievalDocument(
    content: 'Long document text...',
    title: 'Document Title',
    source: 'docs/manual',
    scope: 'workspace',
    ownerId: 'alice',
));

// 搜索（FTS5 bm25 排序，降级为 LIKE）
$results = $rag->search('search terms', limit: 5, scope: 'workspace', ownerId: 'alice');
foreach ($results as $r) {
    echo $r->citationId;  // "rag:docId:chunkPos"
    echo $r->content;     // chunk 文本
    echo $r->score;       // 相关性分数
}

// 读回单个 chunk / 删除文档 / 重建索引
$chunk = $rag->readChunk('docId:0');
$rag->remove($docId);
$rag->reindex();
```

**作为 Capability Provider**：注册到 CapabilityRegistry 后，`rag.search` 等能力通过 Policy 引擎调用（权限/审批/审计自动生效）。

> ⚠️ **能力路径必须有身份**：走 capability 路径时，owner/tenant 取自**运行时上下文**（`RuntimeContext` 的 `userId`/`tenantId`，或它携带的 `SecurityContext`），**绝不**取自模型给的 `owner_id`/`tenant_id` 参数。上下文里没有身份时，调用**直接失败**（fail-closed）——"没有 owner"绝不能等于"不受限制"。用 `SqliteFtsRetrievalProvider` 的普通方法（`ingest` / `search` / `remove`）则由调用方自己传 `ownerId` / `tenantId`。

**隔离在 SQL 层完成**：owner/tenant 过滤条件写在 `WHERE` 里，**在 LIMIT 之前**生效（以前是取回 `limit * 10` 行再在 PHP 里过滤，外来的 chunk 会把结果窗口占满，调用方自己的 chunk 被静默挤掉）。存储使用 **WAL** + **busy timeout**，并打开外键强制（否则 `remove()` 的 CASCADE 不会触发）。

**中文检索（trigram 双索引）**：FTS5 默认的 `unicode61` 分词器把一段连续汉字当作**单个 token**（边界只在标点和 ASCII 处），所以 `乌龙茶` 永远匹配不到 `用户喜欢喝乌龙茶`。为此本实现维护**第二套索引** `rag_chunks_cjk`（`tokenize='trigram'`，对每个 3 字符窗口建索引）：

- **含 CJK 的查询**走 trigram 索引 → 任意子串都能命中（`配置方法` 命中 `…配置方法见 config.md…`）；
- **不含 CJK 的查询**仍走原 `unicode61` 索引 → 英文的排序、前缀查询、布尔运算符行为**完全不变**；
- 长度不足 3 字符的 CJK 查询（`茶`）无法用 trigram 表达，退化为 LIKE 子串扫描（同样能命中中文，只是不带排序）；
- 需要 SQLite ≥ 3.34；更老的版本自动降级为 LIKE 子串扫描，**功能不丢**。

**换掉检索实现**：`LightAgent\Knowledge\RetrievalProvider` 是检索后端的契约（`ingest` / `remove` / `listDocuments` / `search` / `readChunk` / `reindex`），与 Python 原版的 `knowledge.py` `RetrievalProvider` Protocol 一一对应。需要向量库、混合检索或重排序时，实现该接口并在 `CapabilityRegistry` 里注册即可（能力名 `rag.*` 保持不变）。

**其他 Provider**（同目录）：

- `SessionSearchProvider`——跨 Session 事件字面搜索
- `SkillProviderAdapter`——SkillManager 能力化
- `McpProviderAdapter`——MCP 工具能力化
- `WorkflowProviderAdapter`——LightFlow 能力化

### 10. LightFlow 工作流

**入口**：`LightAgent\Flow\LightFlow`

```php
use LightAgent\Flow\LightFlow;
use LightAgent\Flow\JsonLightFlowStore;

$flow = (new LightFlow(store: new JsonLightFlowStore('/path/to/checkpoints')))
    ->step('research', agent: $researchAgent, timeout: 30)
    ->step('write', agent: $writerAgent, dependsOn: ['research'],
           query: fn ($ctx) => "Write using: {$ctx['outputs']['research']}")
    ->step('review', agent: $reviewerAgent, dependsOn: ['write'],
           maxRetry: 3, fallbackAgent: $fallbackReviewer,
           fallbackOnTimeout: true,          // 超时也启用 fallback agent（默认 true）
           requiresApproval: true, approvalHandler: $humanApprovalFn)
    ->step('cleanup', agent: $agent, dependsOn: ['write'],
           cancelIf: fn ($ctx) => $ctx['outputs']['write'] === 'SKIP');

// 运行
$result = $flow->run('initial query', ['run_id' => 'my-run', 'trace' => true]);
$result->status;        // success | failed | skipped | waiting_approval
$result->content;       // 最后成功步骤的输出
$result->steps;         // 每步的 LightFlowStepResult
$result->trace;         // 结构化事件

// 审批暂停后恢复
$flow->approve('my-run', 'review', true);   // 人工决策
$result = $flow->resume('my-run');

// 重跑某个步骤（及其所有下游）
$result = $flow->rerunStep('my-run', 'write');

// 取消活动运行
$flow->cancel('my-run', 'user aborted');
```

**特性**：依赖拓扑排序（环检测）、逐依赖输出注入（`Previous step outputs:`）、超时（Future 竞速）、fallback agent、`cancel_if` 条件取消、每步 checkpoint（含 pending 步骤）、`before/after_flow_step` + `on_resume` + `on_rerun` 钩子。

**被 hook 阻断的步骤会连同它的下游一起跳过**：以前只跳过被阻断的那一步，下游步骤照跑——于是一个已被策略拒绝的上游，下游拿着**空的依赖载荷**继续执行，被拒步骤的副作用照样发生。现在阻断是**硬停止**：依赖它的步骤和它们的依赖者全部标记为 skipped，流程停在 `skipped`/`failed`。

**超时与 fallback 是两件事**：`fallbackOnTimeout`（默认 **true**）决定超时后**是否启用** fallback agent；`allowTimeoutOverlap` 决定超时的 agent 是否**允许继续在后台跑**（默认 false）。以前这两者被混为一谈，导致超时路径**永远不启用** fallback。超时的步骤会拿到一个**取消令牌**，可以通知它正在跑的 agent。

**`cancelIf` 闭包**可以是 `fn ($ctx) => …`（一个参数）或 `fn ($ctx, $query) => …`（两个参数）。

**检查点**：写入是**原子**的（先写临时文件再改名），不安全的 run id 会被**拒绝**。

### 11. LightDAG 持久化 DAG

**入口**：`LightAgent\Dag\LightDAG`

LightDAG 是**验证门控的持久化任务图运行时**——比 LightFlow 更底层的通用 DAG 引擎，支持动态分解、worker 池、制品存储、scheduler 租约。

默认的图存储是**制品目录旁的 SQLite 文件**（`<artifactRoot>/graph.sqlite3`，WAL 模式），因此进程退出后 run / task / attempt / 事件日志仍在磁盘上，可重新打开续跑；需要纯内存图时显式传入 `store: new SqliteTaskGraphStore(':memory:')`。

```php
use LightAgent\Dag\{LightDAG, DAGConfig, TaskSpec, CallableVerifier};
use LightAgent\Security\SecurityContext;

$dag = new LightDAG(
    artifactRoot: '/path/to/artifacts',
    config: new DAGConfig(maxConcurrency: 4, failurePolicy: 'continue_independent'),
);

// 注册 worker（任务执行者）和 verifier（验证器）
$dag->registerWorker('solver', function ($ctx) {
    // $ctx: TaskContext（run/task/attempt/dependency_artifacts/security_context/cancellation_token）
    return ['kind' => 'candidate', 'content' => 'result data', 'media_type' => 'text/plain'];
    // 或返回 ['kind' => 'decomposition', 'decomposition' => new DecompositionProposal(...)]
    // 或返回 ['kind' => 'blocked', 'blocker' => 'needs human input']
});
$dag->registerVerifier('solver_verifier', new CallableVerifier('v', fn ($req) => true));

// 创建 run（自动推导根任务）
$context = new SecurityContext(tenantId: 't', projectId: 'p', policyVersion: '1');
$run = $dag->createRun([
    new TaskSpec(goal: 'Root task', workerKey: 'solver', verifierKey: 'solver_verifier', taskId: 'root'),
    new TaskSpec(goal: 'Child task', workerKey: 'solver', verifierKey: 'solver_verifier',
                 taskId: 'child', dependsOn: ['root']),
], context: $context);

// 运行到完成（scheduler 租约 + 并发 claim + 验证门控发布）
$result = $dag->run($run->runId, context: $context);
$result->status;         // succeeded | failed | blocked | paused | cancelled
$result->rootArtifacts;  // 根任务（图的「源」任务）的已发布制品
$result->failedTasks;    // 失败任务列表
$result->blockers;       // 阻塞原因

// 动态分解——worker 返回 decomposition 时自动创建子任务
// 暂停/恢复/取消/解决阻塞/替换任务（后四个可选地接受 expectedGraphRevision 做乐观并发校验）
$dag->pause($runId);
$dag->resume($runId, context: $context);
$dag->cancel($runId, 'reason');
$dag->resolveBlocker($runId, 'taskId', 'resolved by human');
$dag->supersedeTask($runId, 'oldTask', $replacementSpec, expectedGraphRevision: $rev);  // 必填
```

**根任务是「图的源」**：`rootTaskIds`（以及 `$result->rootArtifacts`）指的是**没有任何依赖的任务**，而不是"没人依赖的任务"。以前推导的是后者，于是上面这个例子里 `rootArtifacts` 是空的、报出来的却是最后一条链的末端——与本节自己的示例对不上。

**版本冲突是硬性的**：`supersedeTask()` **必须**传 `expectedGraphRevision`，省略会抛 `VERSION-CONFLICT`（以前省略等于静默关闭这项检查）。`pause` / `resume` / `cancel` / `resolveBlocker` 接受**可选**的 revision，给了就校验。

**取消不抛异常**：被取消的运行**返回一个 `status = cancelled` 的结果**，而不是抛异常。

**核心保证**（SQLite 事务性 CAS + fencing）：

- 每个任务只被 claim 一次（原子 CAS）
- attempt 租约在 worker 运行期间**持续续期**（心跳，间隔 = 租约时长的 1/3）——一个只是**跑得比 `taskLeaseSeconds` 久**的任务不会被重复执行
- 租约过期**消耗重试预算**（`maxAttemptsPerTask`），所以一个任务的执行次数**绝不会超过**配置的上限
- epoch 是**真正的 fencing token**：每次 claim 都会 +1，带着旧 epoch 回来的 worker 会被 `STALE-ATTEMPT` 拒绝
- scheduler 租约防多实例竞争（active lease 不可窃取）
- 制品只有通过验证（VerificationReport PASS）才能发布，**且验证报告必须绑定到被发布的那份字节**（`report.artifactHashes` 必须包含 manifest 的 content hash）——一份写着别的 hash 的报告会被拒绝
- `maxDecompositionDepth` / `verificationConcurrency` / `maxPendingVerifications` 三个配置项现在**真的生效**
- 事件日志完整记录所有状态转移（可分页回放）
- 图验证（环检测/未知依赖/自依赖）在创建和分解时强制
- `LocalArtifactStore::removeOrphans()` **不会**把同目录下的图数据库（`<artifactRoot>/graph.sqlite3` 及其 WAL/SHM 伴生文件）当成孤儿删掉

### 12. Agent 运行时

**入口**：`LightAgent\Runtime\AgentRuntime`

AgentRuntime 聚合了长生命周期运行的全部状态管理：

```php
use LightAgent\Runtime\AgentRuntime;

$runtime = new AgentRuntime(sessionStore: $store, budget: new BudgetManager($limits));
$runtime->openSession('session-id', metadata: [...], agentId: 'agent', userId: 'user');
$runtime->openSession('session-id', securityContext: $securityContext);  // 安全绑定

// Inbox——有序幂等消息队列
$runtime->inbox->enqueue(InboxMessageType::STEERING, 'change direction');
$message = $runtime->inbox->claimNext(safeBoundary: true);  // STEERING 只在安全边界 claim
$runtime->inbox->complete($message->messageId, result: 'done');

// Goals——目标生命周期（pending → active → completed/blocked/cancelled）
$goal = $runtime->goals->create('Ship the port', acceptanceCriteria: ['all tests green']);
$runtime->goals->activate($goal->goalId);
$runtime->goals->block($goal->goalId, 'waiting on dependency');
$runtime->goals->complete($goal->goalId, evidence: ['tests green']);

// Jobs——可取消后台任务（租约 + 幂等键 + epoch fencing）
$job = $runtime->jobs->start('my-job', fn () => compute(), leaseSeconds: 60);
$completed = $runtime->jobs->wait($job->jobId);
$runtime->jobs->renewLease($job->jobId, 60, expectedEpoch: $job->leaseEpoch);
$runtime->jobs->resume($job->jobId, fn () => 'recovered');

// Subagents——深度受限的代理注册表（权限收窄）
$record = $runtime->subagents->register($childAgent, parentAgentId: $parentId,
    allowedCapabilities: ['tool.read'], maxRisk: CapabilityRisk::ISOLATED_WRITE);
$result = $runtime->subagents->run($record->agentId, 'query');

// Budget——多维度消耗限制
$runtime->budget->consume(modelCalls: 1, toolCalls: 1, tokens: 500, seconds: 2.0);

// Progress——停滞检测
$runtime->progress->record($output, tool: 'name', arguments: $args);
if ($runtime->progress->isStalled()) { /* intervene */ }

// 全量快照
$snapshot = $runtime->snapshot();
```

**Inbox 的安全边界**：`claimNext(safeBoundary: true)`（默认）**跳过** STEERING 消息——转向消息会打断正在进行的这一轮，队列本身并不掌握轮次状态，所以它只能在轮次边界被取走。传 `false` 是显式选择不安全的行为。

**子代理的权限收窄是有效的**：`register()` 收窄出来的 `PermissionSet` 会被**下发**给子代理运行（作为 `permissions` 运行选项），所以被收窄掉的工具在分发前就会被 `[LA-POLICY]` 拦下——不只是记录在元数据里。

**预算**：

- 用量会**持久化**为 `budget.consumed` 会话事件，因此预算上限**能扛过一次会话重开**（以前只存在内存里，每次重开都归零，跨会话的上限形同虚设）；
- **负数增量被拒绝**（`consume(modelCalls: -10)` 以前会让剩余额度反而变多）；
- **超预算的消费照样记账**——已经花掉的 token 必须进账，否则超支就是免费的。

### 13. 能力体系与安全

**入口**：`LightAgent\Capabilities\*` + `LightAgent\Security\*`

```php
use LightAgent\Capabilities\*;
use LightAgent\Security\*;

// 能力规格——描述一个可执行操作的权限面
$spec = new CapabilitySpec(
    name: 'file.write',
    description: 'Write a file',
    risk: CapabilityRisk::SENSITIVE,      // L0=read-only → L3=destructive
    write: true,
    network: false,
    execute: true,
    requiresSandbox: false,
    requiresApproval: false,
    timeout: 5.0,
    outputLimit: 10000,
);

// Provider——实现生命周期 + 能力映射
class FileProvider extends BaseCapabilityProvider {
    public function __construct() {
        parent::__construct([$spec]);
        $this->name = 'files';
    }
    public function invoke(string $capability, mixed ...$arguments): mixed { ... }
}

// 注册表——作用域解析（DEFAULT < RUNTIME < SESSION < AGENT，后者优先）
$registry = new CapabilityRegistry(policyEngine: $engine, audit: $auditFn);
$registry->register($fileProvider, scope: CapabilityScope::RUNTIME);
$registry->register($sessionFileProvider, scope: CapabilityScope::SESSION, ownerId: 'sess-1');
$registry->invoke('file.write', ['path' => 'x'], context: $runtimeContext);

// 权限快照——只能收窄（子集 + 降风险），不可提升
$parent = new PermissionSet(allowed: ['file.read', 'file.write'], maxRisk: CapabilityRisk::SENSITIVE);
$child = $parent->narrow(allowed: ['file.read'], maxRisk: CapabilityRisk::READ_ONLY);
// $parent->narrow(allowed: ['shell.exec']);  // ← 抛异常 "cannot add capabilities"

// Policy 引擎——有序 fail-closed 策略评估
$engine = new PolicyEngine([
    fn ($req) => PolicyDecision::allow(['path' => '/safe/' . $req->arguments['path']]),  // 改写参数
    fn ($req) => str_starts_with($req->arguments['path'], '/safe/') ?: PolicyDecision::block('outside /safe'),
], failClosed: true);
$decision = $engine->evaluate(new PolicyRequest(capability: $spec, providerName: 'files', arguments: $args));

// SecurityContext——不可信调用方的信任链
$context = new SecurityContext(
    userId: 'alice', tenantId: 't1', projectId: 'p1',
    permissions: $parent->narrow(...),
    resources: ['workspace:a'],
    networkAllowed: false,
    sandboxRequired: true,
    deadline: '+5 minutes',
);
$child = $context->narrow(taskId: 'task-1', agentId: 'worker');  // 收窄

// CapabilityGate——统一身份/权限/策略/审批/期限检查
$gate = new CapabilityGate($registry);
$decision = $gate->authorize($spec, 'files', $args, $context, approvalToken: $token);
$result = $gate->invoke('file.write', $args, $context, approvalToken: $token);

// ApprovalToken——绑定操作+参数摘要+策略版本的审批凭证
$token = ApprovalToken::issue('file.write', ['path' => '/safe/x'], $context, resource: 'workspace:a');
$token->verify('file.write', ['path' => '/safe/x'], $context, resource: 'workspace:a');  // 通过
// 参数或上下文不匹配 → PermissionError；单次使用后 consumed
```

**`PermissionError`**：`LightAgent\Security\PermissionError`，继承 `LightAgent\Errors\LightAgentError`。`ApprovalToken::verify()` 与能力门控在拒绝时抛的都是它——调用方可以据此把"审批不匹配"和别的运行时故障区分开；它同时带一个稳定的机器可读错误码（`LA-APPROVAL`）。

**审批令牌与策略改写后的参数比对**：令牌是在**策略链改写完参数之后**才校验的（策略把 `path` 改成 `/safe/x`，令牌就必须绑定 `/safe/x`），并且**只有调用真的继续执行时才被消费**——策略直接拒绝时不会白白烧掉一个一次性令牌。

**`SecurityContext` 反序列化是 fail-closed 的**：`fromDict()` 对**当前 schema / 无版本号**的载荷一律**全拒**（包括 `permissions` 数组完全为空的情况——那曾经等于"不受限制"）。只有**旧的 schema-1 载荷**保留"无限制"的旧语义。`unrestricted` 标志用**严格布尔**解析（`"false"` 字符串不再被当作 true）。

**相对 deadline 在构造时解析一次**：`deadline: '+5 minutes'` 被解析成**绝对时刻**并存下来，所以 `narrow()` 不再因为相对 deadline 抛异常，`isExpired()` 也会真的过期。

**能力注册表不会把参数映射当成具名参数传下去**：PHP 8.1+ 会把字符串键的展开数组变成**具名参数**，一个名叫 `capability` 的参数以前会直接触发原生 `Error`。现在参数映射作为**一个 map** 交给 Provider。

**`ToolProviderAdapter` 的能力规格取自**实时**工具注册表**：在 Agent 构造**之后**注册的工具，同样可以按 `tool.<name>` 调用（以前规格在构造时被快照，用户工具永远进不来）。

**`MemoryProviderAdapter` 的用户 id 取自调用上下文**，不是模型给的参数；上下文里没有身份时**直接失败**。

### 14. 人工审批（HumanApprovalHook）

**入口**：`LightAgent\Review\HumanApprovalHook`

```php
use LightAgent\Review\HumanApprovalHook;
use LightAgent\Review\ApprovalDecision;

// 模式 1：无同步审核者——存储 pending 请求并阻断
$hook = new HumanApprovalHook(
    store: new InMemoryReviewStore(),
    tools: ['delete_file', 'send_email'],     // 只审批这些工具
);
$agent = new LightAgent([..., 'hooks' => [$hook]]);

// 第一次运行：被阻断，拿到 request_id
$result = $agent->run('delete the file', ['trace' => true]);
$requestId = /* 从 trace 的 approval_required 事件提取 */;

// 人工审核后，用 approval_id 重新运行
$result = $agent->run('delete the file', ['approval_id' => $requestId]);

// 模式 2：同步审核者——自动决策
$hook = new HumanApprovalHook(
    reviewer: fn (ApprovalRequest $req) => /* approve/reject/edit/respond */,
    timeout: 30.0,
);

// 模式 3：JsonReviewStore——持久化审批状态（服务重启后仍可恢复）
$hook = new HumanApprovalHook(store: new JsonReviewStore('/path/to/reviews.json'));
```

**Hook 决策映射**：approve → continue；edit → replace（工具参数被人工修改）；respond → block（人类直接回复替代执行）；reject/pending → block。

**审批绑定的是「工具名 + 确切参数」，不是那次运行的随机 id**：每次 `run()` 都会重新生成 run id，所以把审批绑在它上面会让**上面这个「模式 1」流程永远走不通**（第二次运行永远匹配不上，工具一直被挡住）。现在审批绑定的是**动作阶段 + 工具名 + 精确参数 + 源/目标代理 + 用户**，run id 只作为**可追溯信息**记录。想要更紧的绑定，可以自己提供一个**稳定的关联 id**（通过运行元数据 `metadata.correlation_id` 传入），带了这个 id 的审批只会在同一关联域内被消费。

**`edit` 决策不带参数时保留被审阅的参数**，而不是把参数清空——否则工具会以"无参数"执行，而不是执行人工看过的那些参数。

### 15. 钩子与护栏

**钩子**（`LightAgent\Hooks\HookManager`）——生命周期回调，可观察/替换/阻断：

```php
// 钩子阶段：before_run / after_run / on_error / before_model_request /
//   after_model_response / before_tool_call / after_tool_result /
//   before_memory_* / after_memory_* / on_handoff / before_flow_* / after_flow_*

$hooks = [
    // 闭包式
    function (HookContext $ctx) {
        if ($ctx->phase === 'before_tool_call') {
            return HookDecision::replace([...$ctx->payload, 'arguments' => $newArgs]);
        }
        return null; // continue
    },
    // PolicyHook——失败时 fail-closed
    new PolicyHook(handler: $riskyHook, phases: ['before_model_request'], failureMode: 'block', timeout: 5.0),
];
```

**护栏**（`LightAgent\Guardrails\GuardrailManager`）——输入/工具/输出三道检查：

```php
use LightAgent\Guardrails\GuardrailFactories;

$agent = new LightAgent([
    'input_guardrails'  => [GuardrailFactories::privacyInput()],          // 阻断隐私数据
    'tool_guardrails'   => [GuardrailFactories::sensitiveToolConfirmation(['delete_file'])],
    'output_guardrails' => [GuardrailFactories::outputRedaction()],       // 输出脱敏
]);
// 自定义：GuardrailFactories::highRiskParameter(['path' => fn ($v) => !str_starts_with($v, '/etc')])
```

**钩子失败时 fail-closed（只针对安全相关阶段）**：`before_run` / `before_model_request` / `after_model_response` / `before_tool_call` 这四个阶段上的钩子一旦抛异常，就**阻断**本次运行——它们守护的是安全决策，坏掉的钩子不能被当成"没有意见"。其他阶段（可观测性钩子）保持原来的"继续"行为。

**钩子返回数组时的 `allowed` 用严格布尔解析**：`['allowed' => 'false']` 以前被当成**允许**（PHP 里 `(bool) 'false'` 是 true），现在会被当作**拒绝**。缺 `allowed` 键仍然表示"没有意见"→ 允许。

**`PolicyHook` 的超时会被检测并上报**——但注意一个 PHP 层面的硬限制：**阻塞式（同步）钩子处理器无法被抢占**。超时只能在处理器**返回之后**被检测到并记录，处理器自己卡住的话，PHP 里没有任何办法把它打断。真正需要硬超时的钩子，应当写成非阻塞形式。

**`highRiskParameter` 接受任何可调用规则**（闭包、可调用对象、`[$obj, 'method']`、函数名——以前只认闭包，其余的**被静默跳过**，等于放行），并且**规则类型不认识时直接阻断**：没被求值的规则绝不能被当成"通过"的规则。

**默认隐私规则覆盖得更全**：除了身份证/银行卡/邮箱/`key: value` 之外，现在还包括**裸 API 密钥**（`sk-`、`ak_`、`pk-`、`ghp_`、`AIza`）、`Authorization: Bearer …` 头和**中国大陆手机号**。

### 16. 追踪与评估

**追踪**（`LightAgent\Tracing\*`）：

```php
$agent->run('hello', ['trace' => true]);

// 导出事件列表
$events = $agent->exportTrace();
// [{type: 'run_start', data: {...}, trace_id, timestamp}, {type: 'model_request', ...}, ...]

// 汇总指标
$summary = $agent->summarizeTrace(pricing: ['input_per_million' => 0.15, 'output_per_million' => 0.60]);
$summary->eventCount;         // 事件数
$summary->modelRequestCount;  // 模型请求数
$summary->modelLatencyMs;     // 模型延迟
$summary->toolCallCount;      // 工具调用数
$summary->toolSuccessRate;    // 工具成功率
$summary->usage;              // token 用量
$summary->estimatedCostUsd;   // 估算成本

// JSONL 导出
$agent->exportTraceTo(new JsonlTraceExporter('/path/to/audit.jsonl'));
```

**评估**（`LightAgent\Evaluation\LightEvaluator`）：

```php
use LightAgent\Evaluation\{LightEvaluator, EvaluationCase};

$evaluator = new LightEvaluator(pricing: [...]);
$report = $evaluator->run($agent, [
    new EvaluationCase(
        name: 'weather query',
        query: 'What is the weather?',
        expectedOutputContains: ['sunny'],
        expectedTools: ['get_weather'],
        forbiddenTools: ['delete_file'],
        expectedTraceEvents: ['model_response'],
        maxLatencyMs: 5000,
        requireRecovery: false,
        checks: [fn ($result, $trace) => strlen($result->content) > 0],
    ),
    // ... 更多用例
]);

echo "pass rate: {$report->passRate()}";   // 0.0 ~ 1.0
$report->toDict();  // 完整报告（含每用例的 summary/failures）
```

**自定义检查必须返回 `true` 才算通过**：`true` 通过、`false` 失败、非空字符串是失败原因、`[false, 原因]` 以该原因失败。**`null` / `0` 现在是失败**——以前它们会悄悄通过，让一个坏掉的检查看起来像一次绿色的评估。

### 17. 连接器校验

**入口**：`LightAgent\Connectors\*`

离线验证一组"连接器"声明（工具 + 技能 + MCP 服务器 + hooks + 记忆适配器）的完整性：

```php
use LightAgent\Connectors\{ConnectorManifest, ConnectorValidator};

$manifest = new ConnectorManifest(
    name: 'my-connector',
    version: '1.0.0',
    description: 'Bundle of tools and skills',
    tools: [$tool1, $tool2],
    skills: ['/path/to/skill-dir'],
    mcpServers: ['remote' => ['url' => 'http://...', 'headers' => ['Authorization' => 'Bearer ${TOKEN}']]],
    docs: ['USAGE.md'],
);
$report = (new ConnectorValidator(basePath: '.'))->validate($manifest);
$report->isValid();   // 无 error 则 true
$report->errors();    // list<ConnectorDiagnostic>：读取属性 $e->field / $e->message / $e->level
$report->warnings();  // 远程文档、缺文档建议
$report->toDict();    // ['connector' => ..., 'valid' => bool, 'diagnostics' => array<...>]
```

**明文凭证是 error，不是 warning**：凭据类键（`api_key` / `token` / `secret` / `password` / `authorization` …）上出现明文值，连接器直接**判为无效**——以前只是 warning，`isValid()` 仍然是 `true`，等于"带着真密钥的连接器照样通过校验"。键名按**词**切分并逐词匹配（`api_key` / `apiKey` / `x-api-key` 都算），所以 `max_tokens` **不会**被误判成凭证。**原始头行**（`'Authorization: Bearer sk-live-…'`）同样会被扫描。

### 18. 内置工具

| 工具                    | 说明                                            | 安全特性                                                                                                                                                                                               |
| --------------------- | --------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `safe_expression`     | 受限数据表达式求值（算术/比较/逻辑/列表），纯 PHP 实现               | 表达式长度上限 8192 字符 / token 上限 2048 / 节点上限 128 / 字符串长度上限 4096；不允许名称/调用/属性访问；实现 Python 的 `and`/`or`（返回操作数本身而非布尔值）、`//` 与链式比较（`1 < 2 < 3`）语义                                                             |
| `execute_python_code` | 通过宿主 Python 解释器执行代码（**当前没有受支持的启用路径**：分发层始终封禁） | 纯 PHP AST 级安全检查（PythonSourceChecker）：11 类封禁规则 + 语法校验（危险模块导入 / 危险内置函数 / `getattr` 动态分发 / 危险属性访问 / 下标调用 / 裸名引用 / 从 builtin 容器取危险内置）；参数清洗（JSON 嵌套/markdown 提取）；**分发层始终封禁**（按 **callable 身份**判定，改名不解除） |
| `upload_file_to_oss`  | 上传文件到阿里云 OSS（S3 兼容 SigV4 签名）                  | 端点/桶/凭证/前缀/ACL **只从 `ALIYUN_OSS_*` 环境变量读取**（模型无法指定，否则一次上传就能被改写到任意主机）；region 由端点推导；ACL 头参与签名；单文件大小上限 32 MB；MD5 重命名、公共读 ACL                                                                          |

### 19. 技能系统

**入口**：`LightAgent\Skills\SkillManager`

从 `skills/` 目录自动发现技能（`SKILL.md` + YAML frontmatter），生成 `list_skills/activate_skill/read_skill_reference/execute_skill_script` 四个工具：

```
skills/
└── my-skill/
    ├── SKILL.md           ← frontmatter: name + description
    ├── scripts/           ← 可执行脚本
    ├── references/        ← 参考文档
    └── assets/            ← 资源文件
```

**脚本执行是 argv，不是命令行字符串**：`execute_skill_script` 的参数以 **argv 数组**传给进程，**不经过 shell**——模型给的参数因此**无法再执行第二条命令**。Windows 上的 `.bat` / `.cmd` 是唯一的例外（操作系统自己会经由 `cmd.exe` 启动），那里的参数会**逐个转义**。`.php` 脚本通过 **`PHP_BINARY`** 启动（以前根本执行不了）。超时是**可移植**的（按单调时钟计算，到点真的会被终止），临时目录会被**清理**。

**参考文档读取不越界**：`read_skill_reference` 用 `realpath()` 解析符号链接/目录联接，再做边界比较——一个指向技能目录之外的链接读不出来。

## 模型兼容性

LightphpAgent **仅使用 OpenAI Chat Completions 协议**（`POST {base_url}/chat/completions`），不实现 Anthropic 原生格式。以下服务均已验证兼容：

| 服务                   | base_url                                                            |
| -------------------- | ------------------------------------------------------------------- |
| OpenAI               | `https://api.openai.com/v1`                                         |
| DeepSeek             | `https://api.deepseek.com/v1`                                       |
| 阿里云 Qwen (DashScope) | `https://dashscope.aliyuncs.com/compatible-mode/v1`                 |
| vLLM (本地)            | `http://localhost:8000/v1`                                          |
| Ollama (本地)          | `http://localhost:11434/v1`                                         |
| one-api / new-api 网关 | `http://your-gateway:3000/v1`                                       |
| Claude（通过兼容层）        | `https://api.anthropic.com/v1/compat`（或经网关）                         |
| Azure OpenAI         | `https://{resource}.openai.azure.com/openai/deployments/{model}/v1` |

**不兼容**：Anthropic Messages API 原生格式、Gemini 原生 API、Cohere 原生 API——这些需要架设协议转换网关（如 one-api）后使用。

**框架发出去的请求长什么样**：请求体只包含 OpenAI Chat Completions 规范里的字段（`model` / `messages` / `tools` / `tool_choice` / `stream` / `stream_options` / `temperature` 等），**不会夹带任何未知的消息字段**。带工具调用回到服务商的那条 assistant 消息里，`reasoning_content` **只有服务商自己返回过推理内容时才会带上**（它是 DeepSeek 风格的扩展，不属于 OpenAI 规范），`tool_calls` 的每一项都被规范化成**恰好** `{id, type, function:{name, arguments}}`。

> **为什么重要**：严格的 OpenAI 兼容端点（OpenAI 本身、Azure OpenAI）会因为一个不认识的字段直接回 HTTP 400 ——`Unrecognized request argument supplied: messages[N]['reasoning_content']`。而这类 400 的破坏性极大：工具**已经执行完了**，第二轮对话却被整体拒绝，模型**永远看不到工具结果**，用户什么都拿不到。网关额外加的键（如某些服务发的 `"index": null`）同理。

## 异步内核

`coroutine/` 目录 vendored 了三个纯 PHP 异步库：

| 库                     | 角色                                           | LightphpAgent 用法                               |
| --------------------- | -------------------------------------------- | ---------------------------------------------- |
| **revolt/event-loop** | 事件循环（定时器/流就绪/Fiber 恢复）                       | 所有非阻塞 I/O 的调度基础                                |
| **amphp/amp**         | Future/Promise + Fiber 协程（async/await/delay） | `AsyncRuntime::gather`（并发工具调度）、`waitFor`（超时竞速） |
| **amphp/sync**        | Mutex/Semaphore/Channel/Parcel               | `AsyncMutex`（跨 await 互斥，可重入）                   |
| **symfony/yaml**      | YAML 解析                                      | SkillManager frontmatter                       |

`src/Async/` 提供语义封装层（对标 Python asyncio 用法）：

```php
use LightAgent\Async\{AsyncRuntime, AsyncMutex, CancellationToken};
use function Amp\async;

// 入口——在事件循环内执行闭包并阻塞等待
$result = AsyncRuntime::run(fn () => $agent->setupMcp($config));

// 并发——同时运行多个闭包，保序返回
$results = AsyncRuntime::gather([$fn1, $fn2, $fn3]);

// 超时竞速——超时抛 AsyncTimeoutException
$result = AsyncRuntime::waitFor(fn () => $blockingCall(), timeoutSeconds: 5.0);

// 非阻塞睡眠
AsyncRuntime::sleep(0.5);

// 可重入互斥锁（跨 await 保护共享状态）
$mutex = new AsyncMutex();
$mutex->synchronized(fn () => $sharedState->mutate());
```

## 目录结构

```
LightphpAgent/
├── autoload.php              ← PSR-4 手动加载器（无 Composer）
├── coroutine_bootstrap.php   ← Composer 路径下的 vendored 依赖前缀映射
├── composer.json             ← Composer 分发配置
├── phpunit.xml               ← 测试配置
├── README.md                 ← 项目入口与概览（本文档的简版入口）
├── README-V.md               ← 本文档：详细 API 手册
├── .github/workflows/ci.yml  ← CI：PHP 8.5（本机验证版本）+ Windows/Linux + 差分 oracle
├── src/                      ← 框架主体（175 文件 / 187 类 / 31,260 行）
│   ├── LightAgent.php        ← 顶层 facade（短名别名注册）
│   ├── Core/                 ← LightAgent + LightSwarm
│   ├── Llm/                  ← OpenAI 客户端 + SSE + ToolCallAssembler
│   ├── Mcp/                  ← MCP 客户端（JSON-RPC + stdio/SSE/HTTP）
│   ├── Session/              ← 事件溯源 + 3 存储 + 投影 + 压缩
│   ├── Flow/                 ← LightFlow 工作流
│   ├── Dag/                  ← LightDAG 持久化 DAG
│   ├── Runtime/              ← AgentRuntime + Inbox/Goal/Job/Subagent
│   ├── Capabilities/         ← 能力注册表 + Policy 引擎 + 适配器
│   ├── Security/             ← SecurityContext + ApprovalToken + Gate
│   ├── Review/               ← 审批请求/决策/Hook/存储
│   ├── Hooks/                ← 运行期钩子
│   ├── Guardrails/           ← 输入/工具/输出护栏
│   ├── Tracing/              ← 事件追踪 + 汇总 + JSONL 导出
│   ├── Tools/                ← 工具注册表 + 分发器
│   ├── Protocol/             ← 记忆协议 + 安全策略
│   ├── Knowledge/            ← SQLite FTS5 RAG + Provider 适配器
│   ├── Memory/               ← 共享记忆池
│   ├── Evaluation/           ← 回归评估器
│   ├── Connectors/           ← 连接器清单校验
│   ├── BuiltinTools/         ← safe_expression / python_executor / OSS
│   ├── Skills/               ← 技能发现 + 执行
│   ├── Async/                ← Amp 语义封装（AsyncRuntime/Mutex/CancellationToken）
│   ├── Errors/               ← 错误分类学
│   ├── Logging/              ← 日志管理器
│   ├── Support/              ← 支撑类（Json / BooleanValue / Timestamp）
│   └── Result/               ← RunResult / StreamEvent
├── coroutine/                ← vendored 异步内核（91 个 PHP 文件 + phpunit.phar）
│   ├── amphp/                ← amphp/amp v3（Future/Fiber 协程）
│   ├── revolt/               ← revolt/event-loop（事件循环驱动）
│   ├── sync/                 ← amphp/sync（Mutex/Semaphore/Channel）
│   └── yaml-6.4.48/          ← symfony/yaml（技能 frontmatter）
├── tests/                    ← 675 测试 / 1798 断言（28 目录）
└── examples/                 ← 13 个可运行示例
    ├── ExampleHelper.php     ← 共享 mock 服务器
    ├── 01_single_agent.php   ← 基础对话
    ├── 02_tools_agent.php    ← 工具调用
    ├── 03_memory.php         ← 共享记忆
    ├── 04_multi_agent.php    ← 多代理
    ├── 05_self_learning.php  ← 记忆提升
    ├── 06_chat_with_history.php ← 多轮对话
    ├── 07_use_mcp.php        ← MCP 集成
    ├── 08_browser_use.php    ← 浏览器工具
    ├── 09_create_tools.php   ← 动态工具
    ├── 10_lightflow.php      ← 工作流
    ├── 11_vector_memory.php  ← 自定义 MemoryProtocol 适配器
    ├── 12_rag_fts5.php       ← 知识库 RAG（FTS5 + 中文 trigram）
    └── tools_agent.php       ← 工具调用分阶段演示（流式拼装 → dispatch → 最终回复）
```

> ℹ️ **审查过程的输入文档已清理**：`REVIEW_REPORT_v3/v4/v5.md`（三轮审查的原始报告）、`COMPARISON_vs_ORIGINAL_v.md`（与 Python 原版的对照报告）以及工作区里的镜像副本目录都已移除——它们是**过程输入**，不是产物。所有结论与逐条修复已收敛进本文档的「与 Python LightAgent 的对照」一节以及对应代码的注释中，不再需要原件。

> CI 的生效位置只有 `.github/workflows/ci.yml` 一个。

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

当前状态：**675 tests / 1798 assertions 全绿，0 skip**（含 MCP stdio 传输的真实子进程端到端测试、Composer 安装路径的真实子进程测试、Python 原版差分测试）。

测试覆盖分布：

| 目录            | 测试数 | 覆盖                                                                                                                        |
| ------------- | --- | ------------------------------------------------------------------------------------------------------------------------- |
| Audit/        | 102 | 历次审计的回归锁定（协议投影/安全收窄/检查器/记忆策略/审批/MCP stdio/HTTP 头/Composer 安装路径/流式错误/布尔判定/沙箱语义/JSON 助手/时间戳精度/流式收尾与计费/租约精度/派生构造字段/分发器与工具身份） |
| BuiltinTools/ | 97  | 安全表达式 + Python 执行器 + 封禁规则 + OSS                                                                                           |
| Session/      | 59  | 事件/3 存储/投影/压缩/脱敏/CJK 预算/陈旧副本拒绝                                                                                            |
| Dag/          | 35  | store + scheduler + graph + provider                                                                                      |
| Protocol/     | 34  | 记忆策略全分支（跨用户/来源/过期/提升/指纹）                                                                                                  |
| Async/        | 26  | 并发/取消/超时/互斥/取消令牌                                                                                                          |
| Errors/       | 23  | 分类学/HTTP 映射/消息启发/传输层新码                                                                                                    |
| Memory/       | 28  | 共享池/排序/过滤/清理/容量淘汰/停用词空查询/CJK bigram 召回                                                                                    |
| Flow/         | 22  | 依赖/重试/超时/审批/checkpoint/resume/hook                                                                                        |
| Knowledge/    | 36  | FTS5 摄取/搜索/chunk/过滤/删除/重建/能力/租户隔离/身份绑定/跨租户覆盖/CJK trigram                                                                  |
| Runtime/      | 20  | Inbox/Goal/Job 租约/Subagent/安全绑定/预算持久化                                                                                     |
| Mcp/          | 20  | manager 逻辑（脱敏/重连/命名空间/凭证/工具名清洗/结果脱敏）+ SSE 同源校验                                                                            |
| Review/       | 17  | HumanApprovalHook 全分支                                                                                                     |
| Connectors/   | 17  | 全部校验分支（明文凭证为 error）                                                                                                       |
| Skills/       | 17  | 发现/激活/冲突/路径安全/脚本 argv/超时/临时目录清理                                                                                           |
| Core/         | 24  | run 循环/流式/错误/工具验证/重试/权限收窄/流式错误不外泄堆栈/工具执行数上限                                                                          |
| Security/     | 16  | SecurityContext 序列化/收窄/门控/审批令牌/相对 deadline                                                                                |
| Capabilities/ | 14  | 生命周期/作用域/权限/策略/审计/具名参数冲突                                                                                                  |
| Evaluation/   | 14  | 评估器全分支（自定义检查必须返回 true）                                                                                                    |
| Llm/          | 14  | SSE 解析/tool_calls 拼装/流式传输/HTTP 集成/尾部冲刷/截断响应识别                                                                             |
| Logging/      | 9   | 日志级别/文件/编码/未知级别回落                                                                                                         |
| Tools/        | 7   | 注册校验/参数 schema/沙箱封禁/异常不外泄栈                                                                                                |
| Guardrails/   | 7   | 三道护栏/高风险参数规则/隐私模式                                                                                                         |
| Hooks/        | 6   | 阶段分派/fail-closed/严格布尔判定                                                                                                   |
| Result/       | 5   | RunResult/StreamEvent 结构与序列化                                                                                              |
| Tracing/      | 4   | 事件记录/用量来源/汇总                                                                                                              |
| Differential/ | 1   | 以 Python 原版为只读 oracle 的差分测试（36 个场景）                                                                                       |
| （根）           | 1   | Version                                                                                                                   |

> `Audit/` 是三轮审计沉淀下来的回归套件（`tests/Audit/AuditRegressionTest.php`），逐条锁定已修缺陷的**新契约**，防止「修一个坏一个」。

### 差分测试（Python 原版作只读 oracle）

`tests/Differential/` 用 **Python 原版作为只读参照**，系统性地捕捉 PHP 移植过程中的**语义漂移**——这是纯回归测试做不到的：回归测试只能证明「被断言过的契约」成立，差分测试能发现「两边本该一致却已经不一致」的地方。

```
tests/Differential/
├── scenarios.json           # 36 个场景的声明（id / 组 / 输入 / 观测类型）
├── php_runner.php           # PHP 侧观测
├── python_runner.py         # Python 侧观测
├── known_divergences.json   # 已登记的差异豁免（必须写明修复编号）
└── DifferentialTest.php     # 比对与分类
```

**工作方式**

1. 把 Python 原版**复制**到临时目录（**从不修改原版**），并置空包 `__init__`（它依赖可选的 `mcp` 包），从副本导入；
2. 两侧 runner 读同一份 `scenarios.json`，各自输出可观测结果（"是否允许写入"、"命中数"、"解析出的 risk/approval"、"action"…）；
3. 逐场景比对并分类：

| 分类             | 含义                                          | 处理            |
| -------------- | ------------------------------------------- | ------------- |
| `MATCH`        | 两边一致                                        | 通过            |
| `PHP_STRICTER` | PHP 更严（拒绝而原版接受）                             | 通过——通常是改进     |
| `PHP_LAXER`    | **PHP 更松**（接受而原版拒绝）                         | **失败**——这是漏洞  |
| `KNOWN_LAXER`  | 已登记在 `known_divergences.json` 的 `PHP_LAXER` | 通过，但计数并在报告中列出 |
| `DIVERGENT`    | 观测值类型不同                                     | 不失败，列出待人工判断   |

4. 豁免清单里的条目如果**不再复现**，测试会失败——保证修复落地后必须清理豁免，不会留下过期的"已知问题"。

**当前状态**（36 场景）：27 `MATCH` · 4 `PHP_STRICTER` · 1 `KNOWN_LAXER` · 4 `DIVERGENT` · **0 未登记的 `PHP_LAXER`**。已登记项是 `subagent_escalation`（子代理提权）。4 个 `DIVERGENT` 是观测值类型不同、需人工判断的条目（`tokenize_punctuation_stripped`、`hook_plain_throws`、`context_budget_chinese`、`fts_owner_filter`）。

**启用条件**：需要 Python 3.9+ 与原版树。默认探测工作目录的兄弟目录 `../LightAgent-main/LightAgent`，也可用环境变量指定：

```bash
# Linux / macOS
LIGHTAGENT_PYTHON_ORIGINAL=/path/to/LightAgent-main/LightAgent php coroutine/phpunit.phar
# PowerShell
$env:LIGHTAGENT_PYTHON_ORIGINAL='E:\work\wrod\agent\LightAgent-main\LightAgent'
php coroutine/phpunit.phar
```

原版树不存在时该测试整体 skip，不影响其余测试；也可用 `LIGHTAGENT_PYTHON_ORIGINAL=off` 显式关闭。

## 示例

`examples/` 下 13 个可运行脚本（对应 Python 版核心场景），**全部离线运行**（内置 mock 提供者，无需 API Key）：

```bash
php examples/01_single_agent.php      # 基础对话（string 返回）
php examples/02_tools_agent.php       # 工具调用（流式拼装 + dispatch + 最终回复）
php examples/03_memory.php            # SharedMemoryPool（排序/过滤/协议兼容）
php examples/04_multi_agent.php       # LightSwarm（注册/工具隔离）
php examples/05_self_learning.php     # 记忆提升（candidate → promoted）
php examples/06_chat_with_history.php # 多轮对话（getHistory + 显式 history）
php examples/07_use_mcp.php           # MCP 集成（stdio 子进程注册+调用）
php examples/08_browser_use.php       # 浏览器工具（多参数 + 校验错误路径）
php examples/09_create_tools.php      # 动态工具（loadTools/getTool/validateTools）
php examples/10_lightflow.php         # LightFlow（依赖链 + trace 事件流）
php examples/11_vector_memory.php     # 自定义 MemoryProtocol 适配器（中文召回可用）
php examples/12_rag_fts5.php          # 知识库 RAG（FTS5 ingest/search/scope + 中文 trigram）
php examples/tools_agent.php          # 工具调用分阶段演示（流式拼装 → dispatch → 最终回复）
```

每个脚本顶部注释说明对应的 Python 场景和预期输出。真实模型使用只需将 `base_url` 从 mock 换为实际端点。

> `11_vector_memory.php` 对应 Python 版的 `11.vector_memory_adapter.py`：**自定义 `MemoryProtocol` 后端**（`store` / `retrieve` 两个方法即可替换成 Qdrant / Chroma / Milvus / FAISS / mem0 / ClawMem）。它自带 CJK bigram 分词——Python 原版示例里的「嵌入」是 `re.findall(r"[a-z0-9]+", …)` 纯 ASCII 正则，中文会得到**空 embedding**，召回恒为 0。知识库侧的演示在 `12_rag_fts5.php`。

## 与 Python LightAgent 的对照

LightphpAgent 以 [LightAgent](https://github.com/wxai-space/LightAgent)（Python）为功能蓝本重构。两者的**对外能力**基本对齐（工具调用、流式、MCP、多代理、ToT、会话事件溯源、记忆、RAG、工作流、DAG、审批、护栏、追踪评估、连接器校验、技能系统），但在以下三类地方**有意不同**。本节所有「Python 原版」的行为描述均在 `LightAgent-main/LightAgent` 上实测确认。

### 语义修正（PHP 侧更正确）

| 场景                              | Python 原版                                                                                                                                                  | LightphpAgent                                                                                                                                                                                                          |
| ------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `SecurityContext` 权限集的「全拒」状态    | `PermissionSet` **没有**对应概念，空 allowlist 恒等于无限制                                                                                                              | 增加显式 `unrestricted` 标志来区分「空=无限制」与「空=全拒」，并用 `schema_version` 判别载荷年代：**schema < 2**（历史载荷）缺该键 → 仍读成**无限制**；**schema ≥ 2 或没有版本号**缺该键 → **全拒**（fail-closed，避免伪造载荷白拿权限）                                                      |
| `MemoryPolicy` 过滤规则的写法          | pattern 直接交给 `re.search(pattern, text, re.IGNORECASE)`——**没有分隔符语义**：`/etc/passwd/` 的两个斜杠是字面量（只匹配带尾斜杠的文本），`/etc/passwd/i` 的 `i` 也是字面量（该规则**永远不生效**）         | 支持 `/body/` 与 `/body/flags` **分隔符语法**（PHP 独有）：剥掉首尾分隔符、只转义 body 内部的 `/`，于是 `/etc/passwd/` 能命中 "…/etc/passwd"，`/etc/passwd/i` 正常生效；非法正则仍然 fail-closed                                                                    |
| LightDAG 默认图存储                  | `dag/scheduler.py:57` → `SqliteTaskGraphStore()`，默认 `:memory:`（进程退出即丢，「持久化 DAG」名不副实）                                                                       | 制品目录旁的 SQLite 文件（`<artifactRoot>/graph.sqlite3`，WAL），退出后可重开续跑                                                                                                                                                          |
| 用户提问的记忆分区                       | `core.py:1457` 提问写进 `source=user` / `scope=user`，与偏好同区 → 「##用户偏好」块被历史提问填满                                                                                  | 提问写 `scope=utterance`（与偏好分区隔离）；**但召回侧尚未把 `source`/`scope` 传给存储层，默认配置下历史提问仍会进入偏好块**（见「已知限制」）                                                                                                                            |
| 会话复制                            | `session.py:554` → `event_id TEXT NOT NULL UNIQUE`（全局唯一）→ 无法把会话复制到新 `session_id`                                                                           | `UNIQUE(session_id, event_id)`；旧库自动重建表迁移                                                                                                                                                                               |
| DAG 租约过期重排队                     | `dag/scheduler.py:157` 的 `expire_attempts()` 在 `while True:` **之前**只调一次 → 运行中过期的 attempt 永不重排队，run 卡在 `blocked`                                            | 每轮循环都调用 → 运行中过期即重排队                                                                                                                                                                                                    |
| Python 沙箱下标取值                   | `vars(__builtins__)["eval"]` 这类**从 builtin 容器取危险内置**的下标取值被放行（原版规则只拦「被调用」的下标）                                                                               | 额外拦截「从 builtin 容器取危险内置」；普通字典读取（`d["eval"]`）仍放行                                                                                                                                                                         |
| 工具名非法                           | `tools.py` 的 `register_tool()` 照收，只在 `validate_tool_info()` 里报诊断                                                                                           | `registerTool()` **直接抛** `InvalidArgumentException`，非法名不会进入发给服务商的 schema                                                                                                                                               |
| 审批 reviewer 返回 `null`           | `review.py:343` → `raw is None` 视为**放行**（approve）                                                                                                          | 视为**拒绝**（fail-closed，与异常路径一致）                                                                                                                                                                                          |
| 模型空响应                           | 内容为空时记为一次「成功但内容为空」的 run（`core.py:2063-2071`）；无 choices 时直接 `IndexError`                                                                                    | 记为失败，错误码 `LA-EMPTY-RESPONSE`                                                                                                                                                                                           |
| 流式路径的 token 预算                  | 流式请求**从不**发送 `stream_options`，服务商也就不会回 usage 分片；`core.py:2186-2201` 在 `finish_reason` 分片就 `return`，随后的 usage 分片根本没被消费 → 流式运行**从不计入** token 预算              | 请求体带上 `stream_options.include_usage`；收到 `finish_reason` 但还没有 usage 时**不立即收尾**，继续消费到 usage 分片或流结束再统一收尾 → 流式运行按实际 token 计费（可用 `stream_include_usage => false` 关闭；网关若以 400 拒绝该字段，客户端会自动去掉后重试一次）                           |
| 流式路径的输出护栏                       | `core.py:2199` 直接把原始 `output` 记进 `model_response` 并结束，**从不调用**输出护栏                                                                                         | 逐块脱敏（`redactStreamChunk`）+ 末尾对完整文本再跑一次护栏与 `after_model_response` hook；被阻断时以 `[LA-GUARDRAIL]` 信封结束                                                                                                                      |
| `after_model_response` hook 被阻断 | 只检查 `[LA-GUARDRAIL]` 前缀（`core.py:2067`）→ hook 阻断产生的 `[LA-HOOK]` 信封被当成**成功答案**返回                                                                            | `[LA-GUARDRAIL]` 与 `[LA-HOOK]` 两种信封都判为失败，`finishRun(success=false)`，并按 `output_guardrail` / `after_model_response` 记录阶段                                                                                                |
| 非流式工具轮次上限                       | `_run_non_stream_logic(response, max_retry)` **不接收** `max_tool_iterations` → 非流式路径没有工具轮次边界，只有 `max_retry` 兜底（超时文案是 "Failed to generate a valid response."） | 两条路径统一按 `max_tool_iterations` 收敛（缺省等于 `max_retry`），停止时报告 `max_tool_iterations_reached` 与 "Max tool iterations(N) reached."                                                                                             |
| 租约/有效期的时间精度                     | `datetime.now(timezone.utc).isoformat()` → **微秒**精度                                                                                                        | 此前用 `DateTimeInterface::ATOM`（**整秒**）→ 0.2s 的租约会落在 0 秒窗口上、创建即过期；改为 `Timestamp`（毫秒精度 `Y-m-d\TH:i:s.vP`）                                                                                                                 |
| 摘要计算的 JSON 失败处理                 | `json.dumps(sort_keys=True)` 遇到 `NaN`/`Infinity` 直接抛错                                                                                                      | PHP 的 `json_encode` 对同类输入返回 `false` → 此前所有不可编码的载荷会塌缩成**同一个**摘要；改为 `Json::canonical()` **抛错**，不可编码的载荷一律不产生摘要                                                                                                            |
| `SecurityContext` 收窄后的字段丢失      | `dataclasses.replace(self, ...)` **保留**所有未被显式修改的字段                                                                                                         | PHP 侧用 `new self(...)` 重建 → `policy_version` / `approval_token_ids` / `schema_version` 被重置为默认值：子上下文声称策略版本 "1"、不携带任何审批，DAG 的 `policy_digest` 也与父级对不上 → 三个字段全部透传（差分框架的 `permission_narrow_*` 两个场景随之由 DIVERGENT 变为 MATCH） |
| DAG 调度器租约的过期时间                  | `(datetime.now(timezone.utc) + timedelta(...)).isoformat()` → **微秒**精度                                                                                     | PHP 侧按毫秒计算却用 `->format(ATOM)`（**整秒**）写出 → 亚秒租约**写进去时就已经过期**，两个调度器可以同时抢到同一个 run → 改用 `Timestamp`（毫秒精度）                                                                                                                  |
| JSON 编码失败被静默吞掉                  | `json.dumps` 失败即抛错，不会产生"空结果"                                                                                                                               | `(string) json_encode(...)` 把失败变成 `''`（工具结果静默丢失）、`=== false ? 'null'` 让所有不可编码输入塌缩成同一个值（摘要碰撞 / 0 字节制品）→ 统一走 `Json::encodeOrError()`（失败可见）与 `Json::canonical()`（摘要/规范化 JSON 抛错）                                          |
| 工具注册表与分发器是**同一份对象**             | 注册表与分发器共享一份映射 → 任何注册立刻可分发                                                                                                                                  | PHP 数组是值类型，分发器此前拿到的是**构造当时的副本** → 构造之后注册的内置 `safe_expression` 与直接注册到公开注册表上的工具**对分发器不可见** → 分发器改为持有**注册表引用**                                                                                                            |
| 危险实现按**函数对象**拦截                 | `inspect.unwrap(tool_call) in (execute_python_code, ...)` → 改名不解除拦截                                                                                        | 只按**工具名**判定 → 同一个 Python 执行器改名为 `run_py` 后**在宿主上真的执行了 Python** → 改为按 **callable 身份**判定（闭包的 `文件:行` / `类::方法`），名字判定退为附加防线                                                                                                |
| 记忆分词的**中文子串**                   | `shared_memory.py` 按空白切分 → 一段连续汉字是**单个 token**，查 `乌龙茶` 匹配不到 `用户喜欢喝乌龙茶`（召回恒为 0）                                                                             | 对每段 CJK 额外产出**字符 bigram**（`乌龙` / `龙茶`），中文子串查询可命中；英文路径逐字不变                                                                                                                                                              |
| 记忆分词的**标点剥离**                   | 只按空白切分，`"peanuts."` 与 `"peanuts"` 是**两个不同的 token**                                                                                                         | 剥掉 token 首尾的标点后再比对，句末标点不再导致漏召回                                                                                                                                                                                         |
| 上下文预算的**中文估算**                  | 按字符数近似 token（`len(text) // 4` 量级）→ 32000 个汉字估成 **8009** token，实际远超此数，预算被严重低估                                                                               | 按 CJK 字符单独计权 → 同样 32000 字估成 **32008** token，预算与真实消耗吻合                                                                                                                                                                  |
| 普通 hook 抛异常时的失败模式               | hook 处理器抛异常 → 记一条日志后**继续跑**（`continue`），下游照常执行                                                                                                             | **fail-closed**：直接阻断该阶段并把运行标记为失败（`[LA-HOOK]` 信封），不让下游在钩子失效的情况下继续                                                                                                                                                       |
| RAG 的 **owner 过滤时机**            | 先取回 `limit * 10` 行，再在 Python 里过滤 owner → 外来的 chunk 会把结果窗口占满，调用方自己的 chunk 被静默挤掉                                                                             | owner/tenant 过滤写在 SQL 的 `WHERE` 里、**在 LIMIT 之前**（差分场景 `fts_owner_filter`：原版 0 命中，PHP 侧 1 命中）                                                                                                                           |
| 子代理的**权限收窄基准**                  | `SubagentManager.register()` 用**调用方传入**的 `parent_permissions` 作为收窄基准 → 调用方可以给子代理一套比父代理实际持有的**更宽**的权限（真的提权）                                                 | 子代理从**父代理的实际权限集**收窄，调用方给的 `parent_permissions` 只能**再收窄**、不能放宽；放宽会直接抛 `InvalidArgumentException`（差分场景判定为 PHP_STRICTER）                                                                                                  |

### 机制差异（PHP 换掉了基础设施）

| Python                         | LightphpAgent                                                                                             |
| ------------------------------ | --------------------------------------------------------------------------------------------------------- |
| `asyncio` 事件循环 + coroutine     | `revolt/event-loop` + `amphp/amp` Fiber（vendored 在 `coroutine/`）                                          |
| `asyncio.gather`               | `AsyncRuntime::gather`                                                                                    |
| `asyncio.wait_for`             | `AsyncRuntime::waitFor`（Future 竞速）                                                                        |
| `threading` 给审批 reviewer 计时    | 内联执行 + `AsyncRuntime::waitFor` 计时                                                                         |
| `await` 策略 / worker / verifier | 同步 callable（能力、策略、worker、验证器都是普通 PHP callable）                                                            |
| `boto3` 上传 OSS                 | 自实现 S3 兼容 SigV4 签名                                                                                        |
| 常驻 `sqlite3` 连接                | 每次操作打开/关闭连接（`SqliteFtsRetrievalProvider` / `SqliteSessionStore`）                                          |
| MCP stdio 传输                   | `mcp` SDK 的 `stdio_client`（anyio 子进程 + 显式 handle 传递） → `proc_open` + 事件循环驱动的非阻塞读（描述符方向：fd0=`r`、fd1/2=`w`） |
| `litellm`                      | 自实现 `OpenAiClient`（ext-curl + SSE 解析）                                                                     |

### PHP 侧独有的能力

- `OpenAiClient` 与 `LightAgent` 构造器都显式暴露 `caBundle`（自签证书 / 拦截代理的 CA）、`proxy`（HTTP(S) 代理）、`extraHeaders`（网关鉴权头，如 `api-key: ...`）。三者**都不会关闭 SSL 校验**；`LightAgent` 的选项名为 `ca_bundle` / `proxy` / `extra_headers`，ToT 客户端另有 `tot_ca_bundle` / `tot_proxy` / `tot_extra_headers`（缺省继承主客户端）。
- 错误分类学新增 `LA-EMPTY-RESPONSE`（原版无此码）。
- **中文检索可用**：原版的 FTS5 与记忆分词对中文**完全失效**（`unicode61` 把连续汉字当单个 token，`str.split()` 同理），中文子串查询命中 0。PHP 侧新增 **trigram 双索引**（RAG）与 **CJK 字符 bigram**（记忆），中文子串查询现已命中，英文路径行为完全不变。
- **`LightAgent\Knowledge\RetrievalProvider` 接口**：与原版 `knowledge.py` 的 `RetrievalProvider` Protocol 一一对应，使"换掉检索后端"这条路径在 PHP 侧同样有明确契约可循。
- 错误分类学新增**传输层**错误码 `LA-CONNECTION`（「The model provider could not be reached.」）与 `LA-TLS`（「The TLS connection to the model provider failed certificate verification.」），并且其余 5xx 归入 `LA-500`——以前 DNS 失败、连接被拒、TLS 握手失败一律报成 `LA-UNKNOWN`「An unexpected LightAgent error occurred」，这是整个分类学里最没有操作价值的一条。**TLS 失败的提示直接指向 `ca_bundle` 选项**（装上服务商的 CA 包，或配置系统信任库；**不要**关掉证书校验）。`LightAgentErrorInfo` 现在还带一个 `category` 字段。
- `MemoryPolicy` 的 `/body/`、`/body/flags` **分隔符语法**（原版没有，见上表）。
- 工具名语法在**注册时**强制（见上表），而不是等到 `validateTools()` 才报诊断。
- 值对象的不可变契约用 `readonly` **属性**表达（`src/` 内 452 处），`TaskSpec`、`SessionCheckpoint` 等一经构造即不可改。
- **Python 沙箱检查器与原版判定一致**：原版用 CPython 真实的 `ast` 解析（看得见 f-string 插值槽位、会解码 `\xNN`、会追踪赋值别名），PHP 侧是手写的子集解析器——这三种语义现已补齐，两边判定逐条对齐（见「已知限制 → Python 执行沙箱」）。
- **`LightAgent\Support\Json` / `BooleanValue` / `Timestamp` 三个支撑类**：统一 JSON 编解码（不可编码时**抛错**而不是塌缩）、统一严格布尔判定（`"false"` 不再被当作允许）、统一毫秒精度时间戳。`json_decode(json_encode($x), true) ?? []` 这种写法**不是防护**——`strict_types` 下 `json_decode(false)` 会先抛 `TypeError`，`??` 根本到不了。
- **`tools/porting_scan.php` 陷阱扫描器**：`php tools/porting_scan.php` 可重复扫描 PHP 侧最容易复发的移植陷阱（带 `--self-test` 自检，11/11 通过）；当前扫描器报出 **34 个候选**（T1/T2 本轮修完后 0 命中；T12 3 个结构提示、T3 22 个静态工厂、T8 9 个非租约时间戳）。

## 已知限制

### 架构与运行时

| 限制                          | 影响                                                                                                                                                                                     | 缓解                                                                                                                                              |
| --------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| 单线程模型                       | CPU 密集工具调用会阻塞事件循环                                                                                                                                                                      | 耗时工具建议用外部服务（HTTP/队列）解耦                                                                                                                          |
| 同一 agent 内的多个工具调用是**串行**执行的 | 模型一轮可以并行发 N 个工具调用，但框架按顺序逐个执行（每个都走 权限门控 → 护栏 → 预算 → hook → dispatch），不会真并发                                                                                                              | 这是**有意为之**：工具结果要按模型给出的顺序回填进同一份 `chatParams['messages']`，并发执行会写坏消息历史。若要真并发，必须先做 **run 级状态隔离**（每次运行独立的 messages 缓冲），不能直接套用 `AsyncRuntime::gather` |
| 模型请求只重试**瞬时**故障             | 429 / 5xx / 超时 / DNS / 连接 / TLS 错误会按 `model_max_retries`（默认 2）指数退避重试；但 400/401/403/404 是确定性失败，**不重试**（重试只会烧配额）                                                                         | 需要更激进的重试策略时用 `model_max_retries` / `model_retry_backoff` 调参，或在 hooks 里自行实现                                                                      |
| **阻塞式钩子无法被抢占**              | `PolicyHook` 的超时能被**检测并上报**，但 PHP 里没有办法打断一个正在同步执行的处理器——它卡住就一直卡住                                                                                                                        | 需要硬超时的钩子写成非阻塞形式；或把慢逻辑挪到外部服务                                                                                                                     |
| Anthropic 原生 API            | 不兼容                                                                                                                                                                                    | 通过 OpenAI 兼容层或网关使用                                                                                                                              |
| 追踪默认关闭                      | `exportTrace()` 默认为空                                                                                                                                                                   | 显式传 `['trace' => true]`                                                                                                                         |
| 审批 reviewer 超时              | PHP 侧 reviewer **内联执行**，长耗时 reviewer 会占用事件循环（原版用线程计时）                                                                                                                                  | 给 reviewer 设短超时，或改成异步工单式审批                                                                                                                      |
| 流式密钥**跨块拼接**                | 逐块脱敏只能拦住「完整落在单个 chunk 里」的密钥；跨块拼接的密钥要到末尾整段检查才会被发现，此时前半段已流出                                                                                                                              | 消费者侧对流式内容再做一层脱敏；或要求服务商把敏感内容放在单个 chunk 内                                                                                                         |
| 流式 token 计费依赖服务商            | `stream_options.include_usage` 不是所有网关都支持；被拒时框架会自动去掉该字段重试，但**这次流就拿不到 usage**、无法计入预算                                                                                                     | 对预算敏感的场景在网关侧确认支持；或用 `stream_include_usage => false` 显式关闭，避免"以为计了费其实没计"的误解                                                                       |
| PHP 版本相关的分支只在 CI 版本上被跑到     | 框架支持 PHP >= 8.1，但测试套件是在 **PHP 8.5** 上验证的；随版本变化的写法（例如 vendored 内核里 PHP 8.3+ 的 `str_increment`、`CapabilityProvider` 契约**刻意不用** PHP 8.4 的 property hook 拼法）在 8.1/8.2 上走的是兼容分支，那两个分支没有回归覆盖 | 生产环境用 PHP 8.3+；或在自己的 PHP 版本上再跑一遍 `php coroutine/phpunit.phar`                                                                                   |

### 检索与记忆

| 限制                     | 影响                                                                                                                                         | 缓解                                                                                       |
| ---------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------- |
| 记忆与检索仍是**词面**匹配，不是语义检索 | 零词面重叠的表述召回不到（如存了 `peanuts`，查 `Any food I should avoid?` 命中 0；中文同理：存了 `用户喜欢喝乌龙茶`，查 `我讨厌什么饮料` 命中 0）。bigram / trigram 解决的是"子串查不到"，**不解决同义改写** | 用词表更接近的表述；需要语义召回时通过 `RetrievalProvider` 接口对接外部向量 DB / `MemoryProtocol` 对接 mem0、ClawMem 等 |
| RAG 无内置向量数据库           | 使用 FTS5 关键词匹配（中文走 trigram 子串匹配），非语义向量搜索                                                                                                    | 通过 `RetrievalProvider` 接口对接外部向量 DB（接口已提供，见「知识库 RAG」）                                     |
| 记忆召回**未按分区过滤**         | 提问虽然写入 `scope=utterance`，但召回时没有把 `source`/`scope` 传给存储层，默认配置下历史提问仍会进入「##用户偏好」块                                                             | 给 `MemoryPolicy` 传 `allowedScopes: ['user']`；或在 `MemoryProtocol` 实现里自行过滤                 |

### Python 执行沙箱

`execute_python_code` 的安全边界来自 `PythonSourceChecker` 的**静态分析**，它不是操作系统级隔离。

**而且它当前没有受支持的启用路径**：`ToolRegistry` 允许注册这些名字（为了兼容性），但分发层对它们**始终封禁**，框架里也没有任何开关能打开——需要真正执行 Python 时必须自己注册一个隔离的 `SandboxProvider`。

**与 Python 原版的一致性**：原版用 CPython 真实的 `ast` 解析，因此看得见 f-string 的插值槽位、会解码 `\xNN`、会追踪 `d = vars(__builtins__)` 这类赋值别名。PHP 侧是手写的子集解析器——**这三种语义现已补齐**（B5 批次），两边的判定完全一致（27 个用例的期望值取自原版实测）。

| 仍然放行的写法                                                     | 说明                                                  | 原版行为 |
| ----------------------------------------------------------- | --------------------------------------------------- | ---- |
| `x = d["eval"]`、`x = a[0]["eval"]`、`y = f()["eval"]`        | 取值来源不是 builtin 容器；判据是「危险**来源**」而非「危险键」，因此普通字典读取不受影响 | 同样放行 |
| `().__class__.__bases__[0].__subclasses__()`                | 经典的 Python 沙箱逃逸链路，静态分析不覆盖                           | 同样放行 |
| `d = {"eval": 1}`、`items = ["eval", "exec"]`                | 字面量键不是下标访问                                          | 同样放行 |
| `('eval')`                                                  | 括号包裹未做归一化                                           | 同样放行 |
| `getattr(__builtins__, chr(101)+chr(118)+chr(97)+chr(108))` | 运行时拼接出来的危险名：静态分析无法求值                                | 同样放行 |

**结论**：需要真正的不信任代码执行时，必须注册隔离的 `SandboxProvider`；静态检查器只能拦住明显的越权写法，不能作为唯一防线。

### LightFlow 语义

| 限制                   | 影响                                                                                                                                                                                                                                                    | 缓解                                                                                          |
| -------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| 超时的**阻塞型 agent 杀不掉** | 步骤超时时会给该步骤的 agent 一个**取消令牌**；但如果那个 agent 正在执行同步的阻塞代码（例如一次同步 HTTP 调用），PHP 里没有办法打断它——它跑完之前一直在占着事件循环                                                                                                                                                     | 让步骤里的 agent 只做**可中断**的 I/O；或用 `fallbackAgent` 顶上，超时的那个结果会被丢弃                                |
| 审批令牌默认一次性            | `ApprovalToken` 默认 `reusable=false`、TTL 300s，且绑定 operation / 参数摘要 / 租户 / 项目 / run / task / attempt / resource，换一项即失效。`HumanApprovalHook` 的审批决策绑定到**动作阶段 + 工具名 + 精确参数 + 源/目标代理 + 用户**（**不再**绑定那次运行的随机 id——绑它会让「挂起 → 批准 → 续跑」流程永远走不通）；run id 只作为可追溯信息记录 | 需要复用时签发时传 `reusable: true` 并按需调整 TTL；不匹配时重新发起审批。需要更紧的绑定时给运行传一个稳定的 `metadata.correlation_id` |

### MCP 传输

| 限制                    | 影响                                                                                                              | 缓解                          |
| --------------------- | --------------------------------------------------------------------------------------------------------------- | --------------------------- |
| **每个服务器只有一个传输实例**     | `MCPClientManager` 里每个服务器名对应**一个**传输实例（一个 stdio 子进程 / 一条常驻 SSE GET 流 / 一个 HTTP 会话）——**没有连接池**，同一服务器的并发调用共用这一个实例 | 需要更高吞吐就配置多个服务器名（各自独立实例）     |
| stdio 请求的截止时间是**固定**的 | 每个 stdio 请求的截止时间由 `mcpServers.<name>.timeout` 决定（默认 60s），超时即失败，**不重试**                                          | 调大 `timeout`，或把慢操作移到服务端异步完成 |
| 响应体有大小上限              | 单个 HTTP 响应体上限 16 MB；stdio 的读缓冲上限 8 MB（超出即判定为**协议违规**并失败，而不是无限增长）                                                | 大结果请分页或走外部存储，不要塞进一次工具调用     |

## License

Apache-2.0
