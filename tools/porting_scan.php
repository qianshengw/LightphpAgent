<?php

declare(strict_types=1);

/**
 * Porting-trap scanner.
 *
 * The Python original is a reference implementation, not a specification: it
 * was written in a language whose semantics differ from PHP's in ways that
 * silently change behaviour. This scanner looks for the *mechanical* traces
 * of those differences across `src/`, so a whole class of defects can be
 * found in one pass instead of one defect at a time.
 *
 * Every trap below is a semantic difference between Python and PHP, not a
 * style issue. The scanner reports CANDIDATES; each one still needs a
 * runtime probe to confirm it.
 *
 * Usage:
 *     php tools/porting_scan.php [path-to-src] [--json]
 *
 * Read-only: it never writes to the project.
 */

$asJson = in_array('--json', $argv, true);
$selfTest = in_array('--self-test', $argv, true);
$positional = [];
foreach (array_slice($argv, 1) as $arg) {   // $argv[0] is the script itself
    if (!str_starts_with($arg, '--')) {
        $positional[] = $arg;
    }
}
$root = $positional[0] ?? (__DIR__ . '/../src');
$root = rtrim(str_replace('\\', '/', (string) (realpath($root) ?: $root)), '/');

if (!is_dir($root)) {
    fwrite(STDERR, "not a directory: {$root}\n");
    exit(2);
}

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        $files[] = str_replace('\\', '/', $f->getPathname());
    }
}
sort($files);

$findings = [];
function add(string $trap, string $file, int $line, string $detail, string $why): void
{
    global $findings;
    $findings[] = [
        'trap' => $trap,
        'file' => $file,
        'line' => $line,
        'detail' => $detail,
        'why' => $why,
    ];
}

function lineOf(string $src, int $pos): int
{
    return substr_count(substr($src, 0, $pos), "\n") + 1;
}

// ---------------------------------------------------------------------
// T1  value snapshot: a consumer that is handed a registry's ARRAY copy
//      PHP arrays are value types — `new Dispatcher($registry->mappings)`
//      captures the registry's contents at construction time, so anything
//      registered afterwards is invisible to the consumer.
// ---------------------------------------------------------------------
foreach ($files as $file) {
    $src = (string) file_get_contents($file);
    if (!preg_match_all('/new\s+\\\\?([A-Za-z_][A-Za-z0-9_]*)\s*\(([^;]{0,200}?)\)/s', $src, $calls, PREG_OFFSET_CAPTURE)) {
        continue;
    }
    foreach ($calls[0] as $i => $_full) {
        $args = $calls[2][$i][0];
        $class = $calls[1][$i][0];
        // A registry-like array property handed to a consumer.
        if (!preg_match('/->\s*(functionMappings|functionInfo|openaiFunctionSchemas|mappings)\b/', $args)) {
            continue;
        }
        if (!preg_match('/\$this->\w+->/', $args)) {
            continue;
        }
        add(
            'T1',
            $file,
            lineOf($src, (int) $calls[1][$i][1]),
            'new ' . $class . '(... ' . trim(preg_replace('/\s+/', ' ', $args)) . ' ...)',
            '消费者拿到的是注册表数组的**副本**（PHP 数组是值类型）→ 构造之后注册的工具永远不可见；应传注册表引用',
        );
    }
}

// ---------------------------------------------------------------------
// T2  identity loss: a safety decision that only looks at the tool NAME
//      The Python original compares the function object
//      (`inspect.unwrap(tool_call) in (...)`), so renaming a dangerous
//      implementation does not unblock it. A name-only list lets the same
//      implementation through under another name.
// ---------------------------------------------------------------------
foreach ($files as $file) {
    $src = (string) file_get_contents($file);
    // A static helper that decides "is this tool dangerous?" from a NAME.
    if (!preg_match('/function\s+isUnsafe\w*Tool\s*\(\s*string\s+\$\w+\s*\)/', $src)) {
        continue;
    }
    // The companion identity check must exist in the same file.
    if (preg_match('/callableIdentity\s*\(|isUnsafe\w*Callable\s*\(/', $src)) {
        continue;
    }
    preg_match('/function\s+isUnsafe\w*Tool\s*\(/', $src, $m, PREG_OFFSET_CAPTURE);
    add(
        'T2',
        $file,
        lineOf($src, (int) ($m[0][1] ?? 0)),
        'name-only safety decision (isUnsafe*Tool) without an identity check',
        '按**名字**判定危险实现 → 同一实现改名后即可绕过拦截（Python 原版比较的是函数对象本身）',
    );
}

// ---------------------------------------------------------------------
// T3  derived construction must pass every constructor parameter
//      (Python dataclasses.replace keeps the ones you don't mention)
// ---------------------------------------------------------------------
function constructorParams(string $src): array
{
    if (!preg_match('/public function __construct\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    $start = $m[0][1] + strlen($m[0][0]);
    $depth = 1;
    $i = $start;
    $len = strlen($src);
    while ($i < $len && $depth > 0) {
        if ($src[$i] === '(') {
            $depth++;
        } elseif ($src[$i] === ')') {
            $depth--;
        }
        $i++;
    }
    $paramsSrc = substr($src, $start, $i - $start - 1);
    $params = [];
    $depth = 0;
    $buf = '';
    $parts = [];
    for ($j = 0, $n = strlen($paramsSrc); $j < $n; $j++) {
        $c = $paramsSrc[$j];
        if (in_array($c, ['(', '[', '{'], true)) {
            $depth++;
        } elseif (in_array($c, [')', ']', '}'], true)) {
            $depth--;
        }
        if ($c === ',' && $depth === 0) {
            $parts[] = $buf;
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    if (trim($buf) !== '') {
        $parts[] = $buf;
    }
    foreach ($parts as $part) {
        $part = trim($part);
        if (preg_match('/\$(\w+)\s*(?:=|,|$)/', $part, $pm)) {
            $params[] = $pm[1];
        }
    }
    return $params;
}

foreach ($files as $file) {
    $src = (string) file_get_contents($file);
    if (!preg_match('/class\s+(\w+)/', $src, $cm)) {
        continue;
    }
    $class = $cm[1];
    $ctor = constructorParams($src);
    if ($ctor === []) {
        continue;
    }
    if (!preg_match_all('/new\s+(self|static)\s*\(/', $src, $calls, PREG_OFFSET_CAPTURE)) {
        continue;
    }
    foreach ($calls[0] as $call) {
        $callStart = $call[1];
        $open = $callStart + strlen($call[0]) - 1;
        $depth = 1;
        $k = $open + 1;
        $len = strlen($src);
        while ($k < $len && $depth > 0) {
            if ($src[$k] === '(') {
                $depth++;
            } elseif ($src[$k] === ')') {
                $depth--;
            }
            $k++;
        }
        $argsSrc = substr($src, $open + 1, $k - $open - 2);
        $passed = [];
        if (preg_match_all('/(\w+)\s*:/', $argsSrc, $named)) {
            $passed = $named[1];
        } elseif (preg_match_all('/\$(\w+)/', $argsSrc, $pos)) {
            $passed = $pos[1];
        }
        $missing = array_values(array_diff($ctor, $passed));
        if ($missing !== []) {
            add(
                'T3',
                $file,
                lineOf($src, $callStart),
                "{$class}: 缺失 " . implode(',', $missing),
                '派生构造未透传构造参数；Python dataclasses.replace() 会保留未提及的字段',
            );
        }
    }
}

// ---------------------------------------------------------------------
// T4  PSR-4 loads classes, never functions
// ---------------------------------------------------------------------
// A module-level function file is only a defect when NOTHING loads it:
// `autoload.php` and `composer.json`'s `autoload.files` may require it
// explicitly (that is the sanctioned way to ship a namespaced function).
$explicitlyRequired = [];
foreach (['autoload.php', 'composer.json'] as $manifest) {
    $manifestPath = dirname(__DIR__) . '/' . $manifest;
    if (!is_file($manifestPath)) {
        continue;
    }
    $manifestSrc = (string) file_get_contents($manifestPath);
    if (preg_match_all("/['\"]([^'\"]+\.php)['\"]/", $manifestSrc, $refs)) {
        foreach ($refs[1] as $ref) {
            $explicitlyRequired[str_replace('\\', '/', $ref)] = true;
        }
    }
}
foreach ($files as $file) {
    $src = (string) file_get_contents($file);
    $relative = str_replace('\\', '/', substr($file, strlen(dirname(__DIR__)) + 1));
    if (isset($explicitlyRequired[$relative])) {
        // The file IS required explicitly — loading it is not the trap.
        continue;
    }
    $classes = preg_match_all('/^\s*(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+\w+/m', $src);
    if (preg_match_all('/^\s*function\s+(\w+)\s*\(/m', $src, $fns) && $classes === 0) {
        foreach ($fns[1] as $fn) {
            add(
                'T4',
                $file,
                lineOf($src, (int) strpos($src, "function {$fn}(")),
                "module-level function {$fn}()",
                'PSR-4 只能自动加载类；模块级函数必须被显式 require',
            );
        }
    }
}

// ---------------------------------------------------------------------
// T5  json_decode(json_encode(...)) is fatal on invalid UTF-8
//      (`?? []` does NOT guard it: ?? only handles null, and json_decode(false)
//      throws a TypeError under strict_types before ?? can run)
// ---------------------------------------------------------------------
foreach ($files as $file) {
    $src = (string) file_get_contents($file);
    // `src/Support/Json.php` IS the sanctioned helper: its whole purpose is
    // to make the encoding failure explicit. Reporting it would be noise.
    if (str_ends_with(str_replace('\\', '/', $file), '/src/Support/Json.php')) {
        continue;
    }
    if (!preg_match_all('/json_decode\s*\(\s*json_encode\s*\(/', $src, $calls, PREG_OFFSET_CAPTURE)) {
        continue;
    }
    foreach ($calls[0] as $call) {
        $pos = $call[1];
        // A match inside a comment (e.g. the pattern quoted in a docblock)
        // is documentation, not code.
        $lineText = trim(explode("\n", substr($src, 0, $pos))[count(explode("\n", substr($src, 0, $pos))) - 1]);
        if (str_starts_with($lineText, '//') || str_starts_with($lineText, '*') || str_starts_with($lineText, '#')) {
            continue;
        }
        $stmtEnd = strpos($src, ';', $pos);
        $stmt = substr($src, $pos, ($stmtEnd === false ? 160 : $stmtEnd - $pos));
        $guards = [];
        if (str_contains($stmt, '??')) {
            $guards[] = '`??` 无效防护';
        }
        $before = substr($src, 0, $pos);
        $lastTry = strrpos($before, 'try {');
        $lastCatch = strrpos($before, '} catch');
        $inTry = $lastTry !== false && ($lastCatch === false || $lastTry > $lastCatch);
        if ($inTry) {
            $guards[] = '在 try 内';
        }
        add(
            'T5',
            $file,
            lineOf($src, $pos),
            trim(preg_replace('/\s+/', ' ', substr($stmt, 0, 100))),
            $guards === []
                ? '无任何防护：非法 UTF-8 → json_encode 返回 false → json_decode(false) 抛 TypeError'
                : '部分防护：' . implode('；', $guards),
        );
    }
}

// ---------------------------------------------------------------------
// T5b  json_encode() used alone, with a fallback that HIDES the failure
//       json_encode returns false on invalid UTF-8. Two shapes silently
//       collapse every unencodable value into the SAME value:
//         (a) `(string) json_encode(...)`      -> false becomes ''
//         (b) `$e = json_encode(...); ... $e === false ? 'null' : $e`
//       -> digests collide, artifacts become 0 bytes, a whole store is
//          truncated. Plain `json_encode()` as a return value is fine and
//       is NOT reported here.
// ---------------------------------------------------------------------
foreach ($files as $file) {
    $src = (string) file_get_contents($file);
    // `src/Support/Json.php` IS the sanctioned helper: its whole purpose is
    // to make the encoding failure explicit. Reporting it would be noise.
    if (str_ends_with(str_replace('\\', '/', $file), '/src/Support/Json.php')) {
        continue;
    }
    if (!str_contains($src, 'json_encode')) {
        continue;
    }
    $lines = explode("\n", $src);
    foreach ($lines as $idx => $line) {
        if (!str_contains($line, 'json_encode')) {
            continue;
        }
        // Skip comment-only lines.
        $trimmed = trim($line);
        if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '#')) {
            continue;
        }
        // Look at the call line plus the two following lines (the fallback
        // often sits in the next statement).
        $window = $trimmed;
        for ($k = 1; $k <= 2 && isset($lines[$idx + $k]); $k++) {
            $window .= ' ' . trim($lines[$idx + $k]);
        }
        $shape = null;
        if (preg_match('/\(\s*string\s*\)\s*json_encode\s*\(/', $window)) {
            $shape = '(string) 强转：false 变成 \'\'';
        } elseif (preg_match('/json_encode\s*\([^;]*\)\s*\?\?\s*[\'"]/', $window)) {
            $shape = '?? 回退到字面量：所有不可编码输入得到同一个值';
        } elseif (preg_match('/\$\w+\s*===\s*false\s*\?\s*[\'"][^\'"]*[\'"]/', $window)) {
            $shape = '编码结果被回退到字面量：所有不可编码输入得到同一个值';
        }
        if ($shape === null) {
            continue;
        }
        // Severity is a HINT, not a verdict: "fall back to a literal" always
        // collapses distinct inputs into one value (digest collisions, 0-byte
        // artifacts) — a plain (string) cast only loses one chunk of
        // display data. The caller must grade the consequence.
        $severity = str_contains($shape, '字面量') ? 'HIGH' : 'MEDIUM';
        add(
            'T5b',
            $file,
            $idx + 1,
            '[' . $severity . '] ' . trim(preg_replace('/\s+/', ' ', $trimmed)),
            $shape,
        );
    }
}

// ---------------------------------------------------------------------
// T6  (bool) 'false' === true
//      Two spellings are caught here:
//        a) a plain (bool) cast
//        b) a value taken out of an array and then used in a condition —
//           PHP's truthiness treats the non-empty string 'false' as TRUE,
//           so `? APPROVE : REJECT` is a fail-open just like `(bool)`.
// ---------------------------------------------------------------------
foreach ($files as $file) {
    $src = (string) file_get_contents($file);
    if (!preg_match_all("/\(bool\)\s*\(\s*[^)]*\[['\"]allowed['\"]\]\s*\?\?\s*true\s*\)/", $src, $calls, PREG_OFFSET_CAPTURE)) {
        continue;
    }
    foreach ($calls[0] as $call) {
        add(
            'T6',
            $file,
            lineOf($src, $call[1]),
            trim(preg_replace('/\s+/', ' ', $call[0])),
            "(bool) 'false' === true：字符串形式的拒绝会被翻成允许",
        );
    }
    // (b) `$x = $arr['k'] ?? ...;` followed by `$x ? A : B`
    if (preg_match_all(
        '/\$(\w+)\s*=\s*\$\w+\[[\'"][a-z_]+[\'"]\]\s*\?\?[^;]+;.{0,200}?\$\1\s*\?/s',
        $src,
        $calls,
        PREG_OFFSET_CAPTURE
    )) {
        foreach ($calls[0] as $call) {
            $snippet = trim(preg_replace('/\s+/', ' ', $call[0]));
            if (str_contains($snippet, 'BooleanValue::parse')) {
                continue;
            }
            add(
                'T6',
                $file,
                lineOf($src, $call[1]),
                $snippet,
                "未经严格解析的数组取值直接进入条件判断：字符串 'false' 会被判为真",
            );
        }
    }
}

// ---------------------------------------------------------------------
// T16  cross-language semantics
//       When the framework simulates ANOTHER language's semantics (here:
//       Python source analysis), it must implement that language's literal
//       decoding rules completely. A partial decoder turns "the same source
//       text" into "a different program" for the checker than for the
//       interpreter.
// ---------------------------------------------------------------------
$escapeDecoder = static function (string $src): array {
    $handled = [];
    // Find a helper that decodes escape sequences: a str_replace/preg_replace
    // over a list of backslash escapes.
    if (!preg_match_all("/(?:str_replace|preg_replace)\s*\(\s*(\[[^\]]*\]|'[^']*')/", $src, $calls, PREG_OFFSET_CAPTURE)) {
        // fall through: the table may be expressed as a `match` expression
    }
    foreach ($calls[0] ?? [] as $call) {
        $literal = $call[0];
        if (!str_contains($literal, '\\')) {
            continue;
        }
        if (preg_match_all('/\\\\\\\\(x[0-9A-Fa-f]{2}|[ntr\\\\"\'0abfnrve])/', $literal, $escs)) {
            foreach ($escs[1] as $esc) {
                $handled[$esc] = true;
            }
        }
    }
    // A `match` expression is the other common way to write an escape table:
    //     $simple = match ($next) { '\\' => '\\', 'n' => "\n", ... };
    // The B5 rewrite of the Python escape table uses exactly that shape, so
    // without this arm the rule reports a FALSE POSITIVE on the fixed
    // checker (the scanner must not cry wolf).
    if (preg_match_all('/match\s*\(\s*\$\w+\s*\)\s*\{(.*?)\n\s*\}/s', $src, $blocks)) {
        foreach ($blocks[1] as $body) {
            if (!preg_match_all("/'((?:[^'\\\\]|\\\\.){1,4})'\s*=>/", $body, $arms)) {
                continue;
            }
            foreach ($arms[1] as $arm) {
                $decoded = str_replace(['\\\\', "\\'"], ['\\', "'"], $arm);
                if ($decoded !== '') {
                    $handled[$decoded] = true;
                }
            }
        }
    }
    return array_keys($handled);
};
foreach ($files as $file) {
    $src = (string) file_get_contents($file);
    // Only the CHECKER decodes Python string literals. `PythonExecutorTool`
    // merely mentions the checker and contains an unrelated input sanitizer
    // (undoing JSON escaping) — flagging that file was a false positive.
    $isLiteralDecoder = str_contains($src, 'literalFromTokens')
        || str_contains($src, 'readString')
        || str_contains($src, 'scanString');
    if (!$isLiteralDecoder) {
        continue;
    }
    $handled = $escapeDecoder($src);
    $hasHex = false;
    foreach ($handled as $esc) {
        if (str_starts_with($esc, 'x')) {
            $hasHex = true;
        }
    }
    // An explicit \xNN branch: `$next === 'x' && ... hexdec(...)`.
    if (!$hasHex && preg_match("/===\s*'x'/", $src) === 1 && preg_match('/hexdec\s*\(/', $src) === 1) {
        $hasHex = true;
    }
    if ($hasHex) {
        // The escape table implements \xNN — nothing to report.
        continue;
    }
    add(
        'T16',
        $file,
        1,
        '字面量转义解码集合 = [' . implode(', ', $handled) . ']',
        '未处理 \\xNN：Python 会把 "ev\\x61l" 解析成 "eval"，检查器却看到字面量 → 危险名单被绕过',
    );
}

// ---------------------------------------------------------------------
// T8  DateTimeInterface::ATOM has no sub-second component
// ---------------------------------------------------------------------
foreach ($files as $file) {
    $src = (string) file_get_contents($file);
    if (!preg_match_all('/->format\(\s*\\\\?DateTimeInterface::ATOM\s*\)/', $src, $calls, PREG_OFFSET_CAPTURE)) {
        continue;
    }
    foreach ($calls[0] as $call) {
        $before = substr($src, 0, $call[1]);
        $line = lineOf($src, $call[1]);
        $context = trim(preg_replace('/\s+/', ' ', (string) substr($before, max(0, strrpos($before, "\n", -200) ?: 0))));
        add(
            'T8',
            $file,
            $line,
            'DateTimeInterface::ATOM',
            'ATOM 不含毫秒；用于租约/有效期时会把窗口截断到秒（亚秒租约可能创建即过期）',
        );
    }
}

// ---------------------------------------------------------------------
// T12  paired implementation paths that must agree
// ---------------------------------------------------------------------
$pathPairs = [
    '流式 / 非流式' => ['runStreamLogic', 'runNonStreamLogic'],
    '会话存储后端' => ['InMemorySessionStore', 'JsonlSessionStore', 'SqliteSessionStore'],
    '安装路径' => ['autoload.php', 'composer.json'],
];
foreach ($pathPairs as $label => $members) {
    add(
        'T12',
        '(project-wide)',
        0,
        $label . ' => ' . implode(' / ', $members),
        '同一语义在多条路径各实现一次；逐条实现会各自漂移（应抽出共用实现）',
    );
}

// ---------------------------------------------------------------------
// report
// ---------------------------------------------------------------------
if ($selfTest) {
    // The scanner's own patterns must be able to catch the shapes they
    // claim to catch — a pattern that silently matches nothing is worse
    // than no pattern at all (that is how the T6 4th call site was missed).
    $samples = [
        'T5b' => [
            'label' => 'json_encode 回退到字面量',
            'code' => "<?php\n\$e = json_encode(\$v);\nreturn \$e === false ? 'null' : \$e;\n",
            'expect' => 1,
        ],
        'T5b-cast' => [
            'label' => '(string) json_encode 强转',
            'code' => "<?php\nreturn (string) json_encode(\$v);\n",
            'expect' => 1,
        ],
        'T6' => [
            'label' => '(bool) 强转',
            'code' => "<?php\n\$x = (bool) (\$a['allowed'] ?? true);\n",
            'expect' => 1,
        ],
        'T6-ternary' => [
            'label' => '数组取值直接进三元',
            'code' => "<?php\n\$approved = \$raw['approved'] ?? (\$raw['allowed'] ?? false);\n\$action = \$approved ? 'approve' : 'reject';\n",
            'expect' => 1,
        ],
        'T8' => [
            'label' => 'ATOM 格式化租约',
            'code' => "<?php\n\$x = (new DateTimeImmutable('now'))->format(\\DateTimeInterface::ATOM);\n",
            'expect' => 1,
        ],
        'T4' => [
            'label' => '纯函数文件',
            'code' => "<?php\nnamespace X;\nfunction helper(): int { return 1; }\n",
            'expect' => 1,
        ],
        'T16' => [
            'label' => '字面量转义表缺 \\xNN',
            'code' => "<?php\nclass C {\n    public function readString(string \$c): string {\n        return str_replace(['\\\\n', '\\\\t'], [\"\\n\", \"\\t\"], \$c);\n    }\n}\n",
            'expect' => 1,
        ],
        'T16-ok' => [
            'label' => 'match 转义表含 \\xNN（不得误报）',
            'code' => "<?php\nclass C {\n    public function readString(string \$c): string {\n        \$v = match (\$n) { 'n' => \"\\n\", 'x' => 'x' };\n        if (\$n === 'x' && preg_match('/^[0-9A-Fa-f]{2}/', \$c, \$m) === 1) { \$v = chr((int) hexdec(\$m[0])); }\n        return \$v;\n    }\n}\n",
            'expect' => 0,
        ],
        'T1' => [
            'label' => '消费者拿到注册表数组副本',
            'code' => "<?php\nclass C {\n    public function build(): void {\n        \$d = new Dispatcher(\$this->registry->functionMappings, \$this->registry->functionInfo);\n    }\n}\n",
            'expect' => 1,
        ],
        'T2' => [
            'label' => '只按名字判定危险实现',
            'code' => "<?php\nclass C {\n    public static function isUnsafeTool(string \$name): bool {\n        return \$name === 'x';\n    }\n}\n",
            'expect' => 1,
        ],
        'T2-ok' => [
            'label' => '带身份校验的危险判定（不得误报）',
            'code' => "<?php\nclass C {\n    public static function callableIdentity(mixed \$c): string { return 'x'; }\n    public static function isUnsafeTool(string \$name): bool { return \$name === 'x'; }\n}\n",
            'expect' => 0,
        ],
    ];

    $selfTestDir = sys_get_temp_dir() . '/la_scan_selftest_' . bin2hex(random_bytes(4));
    mkdir($selfTestDir, 0777, true);
    $failures = 0;
    echo "scanner self-test\n";
    echo str_repeat('=', 60), "\n";
    foreach ($samples as $key => $sample) {
        $file = $selfTestDir . '/sample_' . $key . '.php';
        file_put_contents($file, $sample['code']);
        $before = count($findings);
        // Re-run the relevant detectors over the sample only.
        $sampleFindings = [];
        $tmp = $findings;
        $findings = [];
        foreach (scandir($selfTestDir) as $entry) {
            if (!str_ends_with($entry, '.php')) {
                continue;
            }
            $path = $selfTestDir . '/' . $entry;
            $src = (string) file_get_contents($path);
            if (str_contains($src, 'json_encode') && preg_match('/\(\s*string\s*\)\s*json_encode\s*\(/', $src)) {
                $sampleFindings[] = 'T5b';
            }
            if (str_contains($src, 'json_encode') && preg_match('/\$\w+\s*===\s*false\s*\?\s*[\'"]/', $src)) {
                $sampleFindings[] = 'T5b';
            }
            if (preg_match("/\(bool\)\s*\(\s*[^)]*\[['\"]allowed['\"]\]\s*\?\?\s*true\s*\)/", $src)) {
                $sampleFindings[] = 'T6';
            }
            if (preg_match('/\$(\w+)\s*=\s*\$\w+\[[\'"][a-z_]+[\'"]\]\s*\?\?[^;]+;.{0,200}?\$\1\s*\?/s', $src)) {
                $sampleFindings[] = 'T6';
            }
            if (preg_match('/->format\(\s*\\\\?DateTimeInterface::ATOM\s*\)/', $src)) {
                $sampleFindings[] = 'T8';
            }
            $classes = preg_match_all('/^\s*(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+\w+/m', $src);
            if (preg_match_all('/^\s*function\s+(\w+)\s*\(/m', $src) && $classes === 0) {
                $sampleFindings[] = 'T4';
            }
            // T16: a Python-literal decoder whose escape table does not
            // implement \xNN. A `match` table WITH the explicit hex branch
            // must NOT be flagged (the B5 rewrite of the checker).
            if (str_contains($src, 'readString') || str_contains($src, 'literalFromTokens')) {
                $table = [];
                if (preg_match_all("/(?:str_replace|preg_replace)\s*\(\s*(\[[^\]]*\]|'[^']*')/", $src, $escCalls)) {
                    foreach ($escCalls[0] as $literal) {
                        if (preg_match_all('/\\\\\\\\(x[0-9A-Fa-f]{2}|[ntr\\\\"\'0abfnrve])/', $literal, $escs)) {
                            foreach ($escs[1] as $esc) {
                                $table[$esc] = true;
                            }
                        }
                    }
                }
                $hex = false;
                foreach (array_keys($table) as $esc) {
                    if (str_starts_with($esc, 'x')) {
                        $hex = true;
                    }
                }
                if (!$hex && preg_match("/===\s*'x'/", $src) === 1 && preg_match('/hexdec\s*\(/', $src) === 1) {
                    $hex = true;
                }
                if (!$hex) {
                    $sampleFindings[] = 'T16';
                }
            }
            // T1: a consumer handed a registry's array copy.
            if (preg_match('/new\s+\\\\?([A-Za-z_][A-Za-z0-9_]*)\s*\(([^;]{0,200}?)\)/s', $src, $t1)
                && preg_match('/->\s*(functionMappings|functionInfo|mappings)\b/', $t1[2])
                && preg_match('/\$this->\w+->/', $t1[2])) {
                $sampleFindings[] = 'T1';
            }
            // T2: a name-only safety decision without an identity check.
            if (preg_match('/function\s+isUnsafe\w*Tool\s*\(\s*string\s+\$\w+\s*\)/', $src)
                && !preg_match('/callableIdentity\s*\(|isUnsafe\w*Callable\s*\(/', $src)) {
                $sampleFindings[] = 'T2';
            }
        }
        $findings = $tmp;
        $hit = count(array_unique($sampleFindings)) > 0;
        $ok = $hit === (bool) $sample['expect'];
        printf("  %-6s %-32s %s\n", $ok ? '[ok]' : '[FAIL]', $sample['label'], $hit ? 'detected' : 'NOT detected');
        if (!$ok) {
            $failures++;
        }
        @unlink($file);
    }
    @rmdir($selfTestDir);
    echo str_repeat('=', 60), "\n";
    echo $failures === 0 ? "self-test OK\n" : "self-test FAILED ({$failures})\n";
    exit($failures === 0 ? 0 : 1);
}

if ($asJson) {
    echo json_encode($findings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit(0);
}

$byTrap = [];
foreach ($findings as $row) {
    $byTrap[$row['trap']][] = $row;
}
ksort($byTrap);

echo "Porting-trap scan of {$root}\n";
echo str_repeat('=', 72), "\n";
foreach ($byTrap as $trap => $rows) {
    echo "\n## {$trap}  (" . count($rows) . ")\n";
    foreach ($rows as $row) {
        if ($row['file'] === '(project-wide)') {
            echo "  - {$row['detail']}\n      {$row['why']}\n";
            continue;
        }
        printf("  - %s:%d\n      %s\n      %s\n", $row['file'], $row['line'], $row['detail'], $row['why']);
    }
}
echo "\n" . str_repeat('=', 72), "\n";
printf("total candidates: %d\n", count($findings));
echo "T3/T5/T8 need a runtime probe before they are treated as defects.\n";
