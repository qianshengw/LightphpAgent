<?php

declare(strict_types=1);

namespace LightAgent\BuiltinTools;

/**
 * Pure-PHP static safety checker for untrusted Python source.
 *
 * Replaces the bundled python_checker.py: the blocklist rules are ported
 * 1:1 from python_executor.py (_safe_import_check + alias resolution) and
 * evaluated over a Python tokenizer implemented in PHP. No host Python is
 * required for the check step (executing code still needs an interpreter —
 * that is the tool's intrinsic nature, identical to the Python original).
 *
 * Check surface (identical to the Python original):
 *   1. dangerous module imports            -> import os
 *   2. dangerous from-imports              -> from builtins import eval
 *   3. dangerous builtin calls             -> eval("1+1")
 *   4. dangerous attribute calls           -> builtins.eval(...)
 *   5. process attributes on dangerous mods-> subprocess.run(...)
 *   6. getattr/attrgetter dynamic dispatch -> getattr(os, "system")
 *   7. subscript access of dangerous names -> d["eval"]
 *   8. Name-load references to dangerous b.-> x = eval
 *   9. alias tracking (import as / from / string constants, 3 rounds)
 *  10. syntax errors                       -> fail closed
 */
final class PythonSourceChecker
{
    // token types
    private const T_NAME = 'name';
    private const T_STRING = 'string';
    private const T_NUMBER = 'number';
    private const T_OP = 'op';
    private const T_KEYWORD = 'keyword';

    /** @var list<string> */
    private const KEYWORDS = [
        'import', 'from', 'as', 'def', 'return', 'if', 'elif', 'else', 'for',
        'while', 'in', 'not', 'and', 'or', 'is', 'None', 'True', 'False',
        'class', 'with', 'try', 'except', 'finally', 'raise', 'lambda', 'pass',
        'break', 'continue', 'del', 'global', 'nonlocal', 'assert', 'yield',
        'async', 'await', 'print', // print is a builtin-name, not keyword; kept as name
    ];

    /** @var list<string> */
    private const DANGEROUS_MODULES = [
        '__builtins__', 'ctypes', 'glob', 'importlib', 'os', 'pickle', 'pty',
        'shelve', 'shutil', 'socket', 'subprocess', 'sys',
    ];

    /** @var list<string> */
    private const DANGEROUS_BUILTINS = [
        '__import__', 'compile', 'eval', 'exec', 'input', 'open', 'raw_input',
    ];

    /** @var list<string> */
    private const DANGEROUS_ATTRIBUTES = ['compile', 'eval', 'exec', 'popen', 'system'];

    /** @var list<string> */
    private const PROCESS_ATTRIBUTES = ['Popen', 'call', 'check_call', 'check_output', 'run'];

    /**
     * Subscript keys that are dangerous even OUTSIDE a call expression (A4):
     * only the classic escape hatches. Common dictionary keys like `run`,
     * `call`, `system`, `input` stay allowed for plain data access —
     * Python's original rule only blocked *called* subscripts, and these
     * names are ubiquitous as dict keys.
     */
    private const DANGEROUS_SUBSCRIPT_KEYS = ['eval', 'exec', 'compile', '__import__', 'open'];

    /**
     * Expressions that can hand out a builtin object.
     *
     * A dangerous key read through one of these is an escape hatch:
     * `vars(__builtins__)["eval"]` yields the real builtin, while the same
     * key read from an ordinary local dict (`d = {'eval': 1}`) is harmless
     * data access. Keying the rule on the SOURCE rather than on the key
     * keeps the common dictionary case safe (A4) while still closing the
     * realistic escapes (H4).
     *
     * @var list<string>
     */
    private const DANGEROUS_SUBSCRIPT_SOURCES = [
        '__builtins__', 'builtins', 'globals', 'vars', 'getattr', '__import__',
    ];

    /** @var list<string> */
    private const GETATTR_DISPATCHERS = ['getattr', 'attrgetter'];

    /**
     * @return array{0: bool, 1: string}
     */
    public static function check(string $code): array
    {
        try {
            $tokens = self::tokenize($code);
            self::validateStructure($tokens);
        } catch (PythonSyntaxError $e) {
            return [false, "语法错误：{$e->getMessage()}"];
        }

        $aliases = self::collectStaticBindings($tokens);
        $constants = self::collectStringConstants($tokens);

        $n = count($tokens);

        // Plain subscript reads that name an executable builtin are rejected
        // before the main scan, so an enclosing call cannot swallow the token
        // (H4). `x = d["eval"]` binds the builtin without ever forming a call
        // on the dangerous name itself.
        $plainExecutable = self::matchPlainExecutableSubscript($tokens, $n, $constants, $aliases);
        if ($plainExecutable !== null) {
            return [false, "禁止通过下标访问危险内置函数 '{$plainExecutable}'"];
        }

        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];

            // --- f-string interpolations (T16) ---
            // The code inside `f"{...}"` is real code. Python's ast exposes
            // it, so the original checker inspects it; the PHP port used to
            // treat the whole f-string as one opaque string, which hid
            // `f"{__import__('os').system('id')}"` completely.
            if ($tok['type'] === self::T_STRING && !empty($tok['is_fstring'])) {
                foreach (($tok['interpolations'] ?? []) as $slot) {
                    if (trim((string) $slot) === '') {
                        continue;
                    }
                    [$slotAllowed, $slotReason] = self::check((string) $slot);
                    if (!$slotAllowed) {
                        return [false, "禁止在 f-string 插值中使用危险代码：{$slotReason}"];
                    }
                }
            }

            // --- import statements ---
            if ($tok['type'] === self::T_KEYWORD && $tok['value'] === 'import') {
                // import a.b as c, d.e
                $j = $i + 1;
                while ($j < $n) {
                    $dotted = self::readDottedName($tokens, $j, $n);
                    if ($dotted === null) {
                        break;
                    }
                    $j += $dotted['consumed'];
                    $boundAs = $dotted['first'];
                    // optional "as name"
                    if ($j < $n && $tokens[$j]['type'] === self::T_KEYWORD && $tokens[$j]['value'] === 'as') {
                        $j++;
                        if ($j < $n && $tokens[$j]['type'] === self::T_NAME) {
                            $boundAs = $tokens[$j]['value'];
                            $j++;
                        }
                    }
                    $root = explode('.', $dotted['first'])[0];
                    if (in_array($root, self::DANGEROUS_MODULES, true)) {
                        return [false, "禁止导入危险模块 '{$dotted['first']}'"];
                    }
                    // side effect handled in collectStaticBindings; here only blocking
                    if ($j < $n && $tokens[$j]['type'] === self::T_OP && $tokens[$j]['value'] === ',') {
                        $j++;
                        continue;
                    }
                    break;
                }
                continue;
            }

            // --- from X import a, b ---
            if ($tok['type'] === self::T_KEYWORD && $tok['value'] === 'from') {
                $j = $i + 1;
                $dotted = self::readDottedName($tokens, $j, $n, allowDots: true);
                if ($dotted === null) {
                    continue;
                }
                $j += $dotted['consumed'];
                if ($j < $n && $tokens[$j]['type'] === self::T_KEYWORD && $tokens[$j]['value'] === 'import') {
                    $j++;
                    $names = self::readNameList($tokens, $j, $n);
                    $root = explode('.', $dotted['first'])[0];
                    if (in_array($root, self::DANGEROUS_MODULES, true)) {
                        return [false, "禁止从危险模块 '{$dotted['first']}' 导入"];
                    }
                    if (in_array($dotted['first'], ['builtins', '__builtins__'], true)) {
                        foreach ($names as $imported) {
                            if (in_array($imported, self::DANGEROUS_BUILTINS, true)) {
                                return [false, "禁止使用内置函数 '{$imported}'"];
                            }
                        }
                    }
                }
                continue;
            }

            // --- call expressions ---
            if ($tok['type'] === self::T_NAME || $tok['type'] === self::T_STRING) {
                // Subscript policy (Python parity, A4/A-then-H4): PLAIN reads
                // like d["eval"] stay allowed (a dict may legitimately use
                // such keys); only CALLED subscripts f["run"]() are checked —
                // see the calledSub rule inside the call branch below.

                $call = self::matchCall($tokens, $i, $n, $aliases);
                if ($call !== null) {
                    [$resolvedCall, $next] = $call;
                    $callParts = $resolvedCall === '' ? [] : explode('.', $resolvedCall);
                    $rootName = $callParts[0] ?? '';
                    $finalName = $callParts[count($callParts) - 1] ?? '';

                    // dynamic dispatch: getattr(obj, "attr") / attrgetter("attr")(obj)
                    $dispatch = self::matchDynamicDispatch($tokens, $i, $n, $aliases, $constants);
                    if ($dispatch !== null && in_array($dispatch[1], self::DANGEROUS_DYNAMIC(), true)) {
                        return [false, "禁止通过 {$dispatch[0]} 访问危险函数 '{$dispatch[1]}'"];
                    }

                    // direct name call: eval(...) / aliasedEval(...)
                    if ($tok['type'] === self::T_NAME) {
                        if (in_array($finalName, self::DANGEROUS_BUILTINS, true)) {
                            return [false, "禁止使用 '{$finalName}' 函数"];
                        }
                        $dispatchName = explode('.', $aliases[$tok['value']] ?? $tok['value']);
                        $tail = $dispatchName[count($dispatchName) - 1];
                        if (in_array($tail, self::GETATTR_DISPATCHERS, true)) {
                            // getattr(os, "system")(...)  — attr is inside the parens
                            $attr = self::readGetattrLiteral($tokens, $i, $n, $constants);
                            if ($attr !== null && in_array($attr, self::DANGEROUS_DYNAMIC(), true)) {
                                return [false, "禁止通过 {$tail} 访问危险函数 '{$attr}'"];
                            }
                        }
                    }

                    if (in_array($finalName, self::DANGEROUS_BUILTINS, true)
                        || in_array($finalName, self::DANGEROUS_ATTRIBUTES, true)) {
                        return [false, "禁止使用危险函数 '{$finalName}'"];
                    }
                    if (in_array($finalName, self::PROCESS_ATTRIBUTES, true)
                        && in_array($rootName, self::DANGEROUS_MODULES, true)) {
                        return [false, "禁止通过危险模块调用 '{$resolvedCall}'"];
                    }

                    // CALLED subscript (Python ast.Subscript semantics): the
                    // full danger list applies when the subscript result is
                    // invoked — f["run"]() / f["eval"]() — while plain data
                    // access config["run"] stays allowed (A4).
                    $calledSub = self::matchSubscriptAccess($tokens, $i, $n, $constants);
                    if ($calledSub !== null && in_array($calledSub, self::DANGEROUS_DYNAMIC(), true)) {
                        return [false, "禁止通过下标调用危险函数 '{$calledSub}'"];
                    }

                    $i = $next - 1;
                    continue;
                }

                // Name-load reference (not a call): x = eval
                if ($tok['type'] === self::T_NAME) {
                    $resolved = $aliases[$tok['value']] ?? $tok['value'];
                    $tail = explode('.', $resolved);
                    $tail = $tail[count($tail) - 1];
                    if (in_array($tail, self::DANGEROUS_BUILTINS, true)) {
                        // Only flag when not the callee of a definition and not
                        // an attribute segment — Python flags bare Name loads.
                        $prev = $i > 0 ? $tokens[$i - 1] : null;
                        $isAttrSegment = $prev !== null
                            && $prev['type'] === self::T_OP
                            && $prev['value'] === '.';
                        $next = $i + 1 < $n ? $tokens[$i + 1] : null;
                        $isCall = $next !== null
                            && $next['type'] === self::T_OP
                            && $next['value'] === '(';
                        // A name on the LEFT of an assignment is a Store, not a
                        // Load: Python's ast distinguishes the two and only
                        // checks loads. Without this, `x = d['input']` would
                        // be rejected while `x = d['input']; print(x)` is
                        // (which is the original's behaviour) — the
                        // assignment alone must stay allowed.
                        $isStoreTarget = $next !== null
                            && $next['type'] === self::T_OP
                            && $next['value'] === '=';
                        if (!$isAttrSegment && !$isCall && !$isStoreTarget) {
                            return [false, "禁止引用危险内置函数 '{$tail}'"];
                        }
                    }
                }
            }
        }

        return [true, ''];
    }

    /**
     * @return list<string>
     */
    private static function DANGEROUS_DYNAMIC(): array
    {
        return array_merge(
            self::DANGEROUS_BUILTINS,
            self::DANGEROUS_ATTRIBUTES,
            self::PROCESS_ATTRIBUTES,
        );
    }

    /**
     * Structural sanity checks that catch common syntax errors at token level:
     * unbalanced brackets, empty parameter lists followed by ':', and
     * assignment to literals.
     *
     * @param list<array{type: string, value: string, line: int}> $tokens
     */
    private static function validateStructure(array $tokens): void
    {
        $stack = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if ($tok['type'] !== self::T_OP) {
                continue;
            }
            switch ($tok['value']) {
                case '(': case '[': case '{':
                    // `def broken(:` — illegal ':' immediately after '('
                    if ($tok['value'] === '(') {
                        $next = $tokens[$i + 1] ?? null;
                        if ($next !== null && $next['type'] === self::T_OP && $next['value'] === ':') {
                            throw new PythonSyntaxError('invalid syntax in parameter list');
                        }
                    }
                    $stack[] = $tok['value'];
                    break;
                case ')': case ']': case '}':
                    $expected = match ($tok['value']) {
                        ')' => '(',
                        ']' => '[',
                        default => '{',
                    };
                    $open = array_pop($stack);
                    if ($open !== $expected) {
                        throw new PythonSyntaxError("unmatched '{$tok['value']}'");
                    }
                    // `def broken(:` — a parameter list that closes with ':' inside parens
                    if ($expected === '(') {
                        $prev = $tokens[$i - 1] ?? null;
                        if ($prev !== null && $prev['type'] === self::T_OP && $prev['value'] === ':') {
                            throw new PythonSyntaxError('invalid syntax in parameter list');
                        }
                    }
                    break;
                case ':':
                    // `f(:` with no opening handled above via ')' — also catch
                    // a ':' directly after '(' via lookahead from '(' branch
                    break;
            }
        }
        if ($stack !== []) {
            throw new PythonSyntaxError("unclosed '" . $stack[count($stack) - 1] . "'");
        }
    }

    // ------------------------------------------------------------------
    // Tokenizer (Python lexical subset sufficient for the check surface)
    // ------------------------------------------------------------------

    /**
     * @return list<array{type: string, value: string, line: int}>
     */
    private static function tokenize(string $code): array
    {
        $tokens = [];
        $len = strlen($code);
        $i = 0;
        $line = 1;

        while ($i < $len) {
            $c = $code[$i];

            // newline tracking
            if ($c === "\n") {
                $line++;
                $i++;
                continue;
            }
            // whitespace
            if ($c === ' ' || $c === "\t" || $c === "\r") {
                $i++;
                continue;
            }
            // line comment
            if ($c === '#') {
                while ($i < $len && $code[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            // strings (with prefixes)
            if ($c === '"' || $c === "'" || self::isStringPrefix($code, $i)) {
                $result = self::readString($code, $i, $line);
                // The prefix matters: `f"..."` interpolates arbitrary
                // expressions, so the checker must look INSIDE the braces
                // (T16). `b"..."` is bytes and `r"..."` keeps its backslashes.
                $tokens[] = [
                    'type' => self::T_STRING,
                    'value' => $result['value'],
                    'line' => $line,
                    'prefix' => $result['prefix'] ?? '',
                    'is_fstring' => str_contains($result['prefix'] ?? '', 'f'),
                    'interpolations' => self::extractFStringSlots($code, $i, $line, $result),
                ];
                $line += $result['newlines'];
                $i = $result['next'];
                continue;
            }
            // numbers
            if (ctype_digit($c)) {
                $j = $i;
                while ($j < $len && (ctype_alnum($code[$j]) || $code[$j] === '.' || $code[$j] === '_')) {
                    $j++;
                }
                $tokens[] = ['type' => self::T_NUMBER, 'value' => substr($code, $i, $j - $i), 'line' => $line];
                $i = $j;
                continue;
            }
            // names / keywords
            if (ctype_alpha($c) || $c === '_') {
                $j = $i;
                while ($j < $len && (ctype_alnum($code[$j]) || $code[$j] === '_')) {
                    $j++;
                }
                $value = substr($code, $i, $j - $i);
                $type = in_array($value, self::KEYWORDS, true) ? self::T_KEYWORD : self::T_NAME;
                // 'print' is a builtin name in py3, not keyword
                if ($value === 'print') {
                    $type = self::T_NAME;
                }
                $tokens[] = ['type' => $type, 'value' => $value, 'line' => $line];
                $i = $j;
                continue;
            }
            // operators & punctuation (multi-char first)
            $three = substr($code, $i, 3);
            if (in_array($three, ['**=', '//=', '>>=', '<<=', '...'], true)) {
                $tokens[] = ['type' => self::T_OP, 'value' => $three, 'line' => $line];
                $i += 3;
                continue;
            }
            $two = substr($code, $i, 2);
            if (in_array($two, ['==', '!=', '<=', '>=', '//', '->', '+=', '-=', '*=', '/=', '%=', '**', ':='], true)) {
                $tokens[] = ['type' => self::T_OP, 'value' => $two, 'line' => $line];
                $i += 2;
                continue;
            }
            $tokens[] = ['type' => self::T_OP, 'value' => $c, 'line' => $line];
            $i++;
        }

        return $tokens;
    }

    private static function isStringPrefix(string $code, int $i): bool
    {
        $len = strlen($code);
        // r, b, f, u, rb, br, rf, fr combinations followed by a quote
        $j = $i;
        $prefix = '';
        while ($j < $len && $j - $i < 2 && (ctype_alpha($code[$j]))) {
            $prefix .= strtolower($code[$j]);
            $j++;
        }
        if ($j < $len && ($code[$j] === '"' || $code[$j] === "'")) {
            $allowed = ['r', 'b', 'f', 'u', 'rb', 'br', 'rf', 'fr'];
            return in_array($prefix, $allowed, true);
        }
        return false;
    }

    /**
     * @return array{value: string, next: int, newlines: int}
     */
    private static function readString(string $code, int $i, int $line): array
    {
        $len = strlen($code);
        $prefix = '';
        // R10 fix: bound the prefix scan by PREFIX LENGTH (valid: r/b/f/rb/br/rf/fr, max 2
        // letters), not by $i - $line ? the old condition mixed an absolute char offset with a
        // line number, so on any line after the second the prefix scan never ran and the prefix
        // letter was mistaken for the quote (f"..." ? 'unterminated string literal').
        $scanned = 0;
        while ($scanned < 2 && ctype_alpha($code[$i])) {
            $prefix .= strtolower($code[$i]);
            $i++;
            $scanned++;
            if ($code[$i] === '"' || $code[$i] === "'") {
                break;
            }
        }
        $quote = $code[$i];
        $triple = substr($code, $i, 3) === str_repeat($quote, 3);
        $i += $triple ? 3 : 1;
        $bodyStart = $i;
        // A raw string (r"..." / rf"...") does NOT decode escapes: Python keeps
        // the backslash. Everything else follows Python's escape table.
        $raw = str_contains($prefix, 'r');
        $value = '';
        $newlines = 0;
        while ($i < $len) {
            $c = $code[$i];
            if ($c === '\\' && $i + 1 < $len) {
                $next = $code[$i + 1];
                if ($raw) {
                    // Raw string: the backslash is kept verbatim.
                    $value .= '\\' . $next;
                    $i += 2;
                    continue;
                }
                // Python's escape table. Anything unknown KEEPS the backslash
                // (`\q` is two characters) — the previous implementation dropped
                // it, so "\x61" was read as "x" and the dangerous name was
                // never recognised (T16).
                $simple = match ($next) {
                    '\\' => '\\',
                    '"' => '"',
                    "'" => "'",
                    'a' => "\x07",
                    'b' => "\x08",
                    'f' => "\x0c",
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'v' => "\x0b",
                    default => null,
                };
                if ($simple !== null) {
                    $value .= $simple;
                    if ($next === "\n") {
                        $newlines++;
                    }
                    $i += 2;
                    continue;
                }
                if ($next === "\n") {
                    // Explicit line continuation: the newline disappears.
                    $i += 2;
                    continue;
                }
                // \xHH — exactly two hex digits.
                if ($next === 'x' && preg_match('/^[0-9A-Fa-f]{2}/', substr($code, $i + 2, 2), $m) === 1) {
                    $value .= chr((int) hexdec($m[0]));
                    $i += 4;
                    continue;
                }
                // \OOO — one to three octal digits.
                if ($next >= '0' && $next <= '7' && preg_match('/^[0-7]{1,3}/', substr($code, $i + 1, 3), $m) === 1) {
                    $value .= chr((int) octdec($m[0]));
                    $i += 1 + strlen($m[0]);
                    continue;
                }
                // \uXXXX — four hex digits.
                if ($next === 'u' && preg_match('/^[0-9A-Fa-f]{4}/', substr($code, $i + 2, 4), $m) === 1) {
                    $value .= mb_chr((int) hexdec($m[0]), 'UTF-8') ?: '';
                    $i += 6;
                    continue;
                }
                // \UXXXXXXXX — eight hex digits.
                if ($next === 'U' && preg_match('/^[0-9A-Fa-f]{8}/', substr($code, $i + 2, 8), $m) === 1) {
                    $value .= mb_chr((int) hexdec($m[0]), 'UTF-8') ?: '';
                    $i += 10;
                    continue;
                }
                // Unknown escape: Python keeps the backslash.
                $value .= '\\' . $next;
                $i += 2;
                continue;
            }
            if (!$triple && $c === "\n") {
                throw new PythonSyntaxError('unterminated string literal');
            }
            if ($triple && substr($code, $i, 3) === str_repeat($quote, 3)) {
                $i += 3;
                return [
                    'value' => $value, 'next' => $i, 'newlines' => $newlines,
                    'prefix' => $prefix, 'body_start' => $bodyStart, 'body_end' => $i - 3,
                ];
            }
            if (!$triple && $c === $quote) {
                $i++;
                return [
                    'value' => $value, 'next' => $i, 'newlines' => $newlines,
                    'prefix' => $prefix, 'body_start' => $bodyStart, 'body_end' => $i - 1,
                ];
            }
            if ($c === "\n") {
                $newlines++;
            }
            $value .= $c;
            $i++;
        }
        throw new PythonSyntaxError('unterminated string literal');
    }

    // ------------------------------------------------------------------
    // f-string interpolation (T16)
    // ------------------------------------------------------------------

    /**
     * Extract the `{...}` interpolation slots of an f-string.
     *
     * Python's `ast` exposes every expression inside an f-string as a real
     * node, so the original checker sees `f"{eval('6*7')}"` as a call to
     * `eval`. The PHP port tokenised the whole f-string as ONE string
     * literal, which made the interpolated code completely invisible — a
     * real arbitrary-command-execution hole.
     *
     * Handles `{{` / `}}` escapes, nested braces, and the `!r`/`!s`/`!a`
     * conversion plus the `:spec` format suffix.
     *
     * @return list<string> the source text of each slot
     */
    private static function extractFStringSlots(string $code, int $start, int $line, array $result): array
    {
        $prefix = (string) ($result['prefix'] ?? '');
        if (!str_contains($prefix, 'f')) {
            return [];
        }
        $bodyStart = (int) ($result['body_start'] ?? 0);
        $bodyEnd = (int) ($result['body_end'] ?? 0);
        if ($bodyEnd <= $bodyStart) {
            return [];
        }
        $body = substr($code, $bodyStart, $bodyEnd - $bodyStart);
        $len = strlen($body);
        $slots = [];
        $i = 0;
        while ($i < $len) {
            $c = $body[$i];
            if ($c === '{') {
                if ($i + 1 < $len && $body[$i + 1] === '{') {
                    $i += 2;      // escaped literal brace
                    continue;
                }
                // Find the matching close brace, tracking nesting and quotes.
                $depth = 0;
                $j = $i;
                $quote = null;
                while ($j < $len) {
                    $ch = $body[$j];
                    if ($quote !== null) {
                        if ($ch === '\\') {
                            $j += 2;
                            continue;
                        }
                        if ($ch === $quote) {
                            $quote = null;
                        }
                        $j++;
                        continue;
                    }
                    if ($ch === '"' || $ch === "'") {
                        $quote = $ch;
                        $j++;
                        continue;
                    }
                    if ($ch === '{') {
                        $depth++;
                    } elseif ($ch === '}') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    }
                    $j++;
                }
                if ($j >= $len) {
                    break;      // unterminated slot
                }
                $slot = substr($body, $i + 1, $j - $i - 1);
                $slots[] = self::stripFStringSlotSuffix($slot);
                $i = $j + 1;
                continue;
            }
            if ($c === '}' && $i + 1 < $len && $body[$i + 1] === '}') {
                $i += 2;          // escaped literal brace
                continue;
            }
            $i++;
        }
        return $slots;
    }

    /**
     * Drop the `!r` / `!s` / `!a` conversion and the `:format` suffix from
     * an f-string slot, leaving the expression itself.
     */
    private static function stripFStringSlotSuffix(string $slot): string
    {
        $depth = 0;
        $len = strlen($slot);
        for ($i = 0; $i < $len; $i++) {
            $c = $slot[$i];
            if ($c === '(' || $c === '[' || $c === '{') {
                $depth++;
                continue;
            }
            if ($c === ')' || $c === ']' || $c === '}') {
                $depth--;
                continue;
            }
            if ($depth > 0) {
                continue;
            }
            if ($c === '!' && $i + 1 < $len && in_array($slot[$i + 1], ['r', 's', 'a'], true)) {
                $slot = substr($slot, 0, $i) . substr($slot, $i + 2);
                $len = strlen($slot);
                $i = max(-1, $i - 1);
                continue;
            }
            if ($c === ':') {
                $slot = substr($slot, 0, $i);
                break;
            }
            if ($c === '=' && $i + 1 < $len && $slot[$i + 1] === '=') {
                // `f"{x==}"` is not a self-documenting slot; keep scanning.
                continue;
            }
            if ($c === '=') {
                // Self-documenting slot `f"{x=}"` — the expression is `x`.
                $slot = substr($slot, 0, $i);
                break;
            }
        }
        return trim($slot);
    }

    // ------------------------------------------------------------------
    // Alias resolution (python_executor.py _collect_static_bindings)
    // ------------------------------------------------------------------

    /**
     * @param list<array{type: string, value: string, line: int}> $tokens
     * @return array<string, string> local name -> fully-resolved dotted path
     */
    private static function collectStaticBindings(array $tokens): array
    {
        $aliases = [];
        $n = count($tokens);

        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];

            if ($tok['type'] === self::T_KEYWORD && $tok['value'] === 'import') {
                $j = $i + 1;
                while ($j < $n) {
                    $dotted = self::readDottedName($tokens, $j, $n);
                    if ($dotted === null) {
                        break;
                    }
                    $j += $dotted['consumed'];
                    $boundAs = explode('.', $dotted['first'])[0];
                    if ($j < $n && $tokens[$j]['type'] === self::T_KEYWORD && $tokens[$j]['value'] === 'as') {
                        $j++;
                        if ($j < $n && $tokens[$j]['type'] === self::T_NAME) {
                            $boundAs = $tokens[$j]['value'];
                            $j++;
                        }
                    }
                    $aliases[$boundAs] = $dotted['first'];
                    if ($j < $n && $tokens[$j]['type'] === self::T_OP && $tokens[$j]['value'] === ',') {
                        $j++;
                        continue;
                    }
                    break;
                }
            } elseif ($tok['type'] === self::T_KEYWORD && $tok['value'] === 'from') {
                $j = $i + 1;
                $dotted = self::readDottedName($tokens, $j, $n, allowDots: true);
                if ($dotted === null) {
                    continue;
                }
                $j += $dotted['consumed'];
                if ($j < $n && $tokens[$j]['type'] === self::T_KEYWORD && $tokens[$j]['value'] === 'import') {
                    $j++;
                    foreach (self::readNameList($tokens, $j, $n, withAs: true) as $entry) {
                        $aliases[$entry['bound']] = "{$dotted['first']}.{$entry['imported']}";
                    }
                }
            }
        }

        // 3 rounds: resolve name assignments whose RHS resolves to a dotted path
        $constants = self::collectStringConstants($tokens);
        for ($round = 0; $round < 3; $round++) {
            $changed = false;
            $n = count($tokens);
            for ($i = 0; $i < $n; $i++) {
                // pattern: NAME = <resolvable>
                if ($tokens[$i]['type'] !== self::T_NAME) {
                    continue;
                }
                if ($i + 1 >= $n || $tokens[$i + 1]['type'] !== self::T_OP || $tokens[$i + 1]['value'] !== '=') {
                    continue;
                }
                // avoid == (already excluded by tokenizer) and augmented forms
                $resolved = self::resolveNameExpression($tokens, $i + 2, $n, $aliases, $constants);
                if ($resolved !== null && $resolved !== $tokens[$i]['value']) {
                    $key = $tokens[$i]['value'];
                    if (($aliases[$key] ?? null) !== $resolved) {
                        $aliases[$key] = $resolved;
                        $changed = true;
                    }
                }
            }
            if (!$changed) {
                break;
            }
        }

        return $aliases;
    }

    /**
     * Collect string constants assigned to simple names (attr = "system").
     *
     * @param list<array{type: string, value: string, line: int}> $tokens
     * @return array<string, string>
     */
    private static function collectStringConstants(array $tokens): array
    {
        $constants = [];
        $n = count($tokens);
        for ($i = 0; $i < $n - 2; $i++) {
            if (
                $tokens[$i]['type'] === self::T_NAME
                && $tokens[$i + 1]['type'] === self::T_OP && $tokens[$i + 1]['value'] === '='
                && $tokens[$i + 2]['type'] === self::T_STRING
            ) {
                $constants[$tokens[$i]['value']] = $tokens[$i + 2]['value'];
            }
        }
        return $constants;
    }

    /**
     * Resolve `NAME = <expr>` where expr is a dotted name chain, optionally
     * parenthesized, optionally built from string concatenation.
     *
     * @param list<array{type: string, value: string, line: int}> $tokens
     */
    private static function resolveNameExpression(array $tokens, int $start, int $n, array $aliases, array $constants): ?string
    {
        $i = $start;
        if ($i < $n && $tokens[$i]['type'] === self::T_OP && $tokens[$i]['value'] === '(') {
            $i++;
        }

        // string-literal chain: "a" + "b" [+ "c" ...]
        if ($i < $n && $tokens[$i]['type'] === self::T_STRING) {
            $buffer = $tokens[$i]['value'];
            $j = $i + 1;
            while (
                $j + 1 < $n
                && $tokens[$j]['type'] === self::T_OP && $tokens[$j]['value'] === '+'
                && $tokens[$j + 1]['type'] === self::T_STRING
            ) {
                $buffer .= $tokens[$j + 1]['value'];
                $j += 2;
            }
            return $buffer;
        }

        // dotted name chain: os . system
        if ($i < $n && $tokens[$i]['type'] === self::T_NAME) {
            $first = $aliases[$tokens[$i]['value']] ?? $tokens[$i]['value'];
            $parts = [$first];
            $j = $i + 1;
            while (
                $j + 1 < $n
                && $tokens[$j]['type'] === self::T_OP && $tokens[$j]['value'] === '.'
                && $tokens[$j + 1]['type'] === self::T_NAME
            ) {
                $parts[] = $tokens[$j + 1]['value'];
                $j += 2;
            }
            if ($j < $n && $tokens[$j]['type'] === self::T_OP && $tokens[$j]['value'] === ')') {
                $j++;
            }
            // Subscript form: `obj["key"]` resolves to `obj.key`.
            // The Python original builds this chain in _resolved_name()
            // (ast.Subscript) and stores it in the alias table, which is how
            // `x = d["eval"]; x("6*7")` ends up being seen as `d.eval` and
            // hitting the dangerous-name list (T16).
            if ($j < $n && $tokens[$j]['type'] === self::T_OP && $tokens[$j]['value'] === '[') {
                $k = $j + 1;
                $key = null;
                if ($k < $n && $tokens[$k]['type'] === self::T_STRING) {
                    $key = $tokens[$k]['value'];
                    $k++;
                } elseif ($k < $n && $tokens[$k]['type'] === self::T_NAME && isset($constants[$tokens[$k]['value']])) {
                    $key = $constants[$tokens[$k]['value']];
                    $k++;
                }
                if ($key !== null && $k < $n && $tokens[$k]['type'] === self::T_OP && $tokens[$k]['value'] === ']') {
                    return $first . '.' . $key;
                }
            }
            // only consume the expression up to a natural boundary (newline-ish
            // tokens are not emitted; boundary = end or another statement start)
            return implode('.', $parts);
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Token-walking helpers
    // ------------------------------------------------------------------

    /**
     * Read a (possibly dotted) module path starting at $start.
     *
     * @param list<array{type: string, value: string, line: int}> $tokens
     * @return array{first: string, consumed: int, last: string}|null
     */
    private static function readDottedName(array $tokens, int $start, int $n, bool $allowDots = false): ?array
    {
        $i = $start;
        if ($allowDots) {
            // relative import dots
            while ($i < $n && $tokens[$i]['type'] === self::T_OP && $tokens[$i]['value'] === '.') {
                $i++;
            }
        }
        if ($i >= $n || $tokens[$i]['type'] !== self::T_NAME) {
            return null;
        }
        $parts = [$tokens[$i]['value']];
        $i++;
        while (
            $i + 1 < $n
            && $tokens[$i]['type'] === self::T_OP && $tokens[$i]['value'] === '.'
            && $tokens[$i + 1]['type'] === self::T_NAME
        ) {
            $parts[] = $tokens[$i + 1]['value'];
            $i += 2;
        }
        return [
            'first' => $parts[0],
            'last' => $parts[count($parts) - 1],
            'consumed' => $i - $start,
        ];
    }

    /**
     * Read a comma-separated name list after `import` / `from X import`.
     *
     * @param list<array{type: string, value: string, line: int}> $tokens
     * @return list<string>|list<array{imported: string, bound: string}>
     */
    private static function readNameList(array $tokens, int &$j, int $n, bool $withAs = false): array
    {
        $names = [];
        while ($j < $n) {
            if ($tokens[$j]['type'] === self::T_OP && $tokens[$j]['value'] === '(') {
                $j++;
                continue;
            }
            if ($tokens[$j]['type'] === self::T_OP && $tokens[$j]['value'] === ')') {
                $j++;
                break;
            }
            if ($tokens[$j]['type'] !== self::T_NAME) {
                break;
            }
            $imported = $tokens[$j]['value'];
            $j++;
            $bound = $imported;
            if ($withAs && $j < $n && $tokens[$j]['type'] === self::T_KEYWORD && $tokens[$j]['value'] === 'as') {
                $j++;
                if ($j < $n && $tokens[$j]['type'] === self::T_NAME) {
                    $bound = $tokens[$j]['value'];
                    $j++;
                }
            }
            $names[] = $withAs ? ['imported' => $imported, 'bound' => $bound] : $imported;
            if ($j < $n && $tokens[$j]['type'] === self::T_OP && $tokens[$j]['value'] === ',') {
                $j++;
                continue;
            }
            break;
        }
        return $names;
    }

    /**
     * If tokens at $i begin a call expression `resolved.name(args...)`, return
     * the resolved dotted call path and the index after the call.
     *
     * @param list<array{type: string, value: string, line: int}> $tokens
     * @return array{0: string, 1: int}|null
     */
    private static function matchCall(array $tokens, int $i, int $n, array $aliases = []): ?array
    {
        // Walk a dotted chain backwards-compatible: name(.name)* immediately
        // followed by '(' — with subscript segments allowed in the chain.
        $parts = [];
        $j = $i;
        if ($tokens[$j]['type'] === self::T_NAME || $tokens[$j]['type'] === self::T_STRING) {
            // Resolve the head through the alias table, so `x = d["eval"]`
            // followed by `x(...)` is seen as `d.eval` — the same chain the
            // Python original builds through ast + _collect_static_bindings
            // (T16).
            $head = $tokens[$j]['value'];
            $parts[] = $aliases[$head] ?? $head;
            $j++;
        } else {
            return null;
        }
        while ($j + 1 < $n && $tokens[$j]['type'] === self::T_OP && $tokens[$j]['value'] === '.') {
            $parts[] = $tokens[$j + 1]['value'];
            $j += 2;
        }
        // optional subscripts in the chain: ["system"]
        while ($j < $n && $tokens[$j]['type'] === self::T_OP && $tokens[$j]['value'] === '[') {
            $depth = 1;
            $j++;
            while ($j < $n && $depth > 0) {
                if ($tokens[$j]['type'] === self::T_OP && $tokens[$j]['value'] === '[') {
                    $depth++;
                } elseif ($tokens[$j]['type'] === self::T_OP && $tokens[$j]['value'] === ']') {
                    $depth--;
                }
                $j++;
            }
            if ($j + 1 < $n && $tokens[$j]['type'] === self::T_OP && $tokens[$j]['value'] === '.') {
                $parts[] = $tokens[$j + 1]['value'];
                $j += 2;
            }
        }
        if ($j >= $n || $tokens[$j]['type'] !== self::T_OP || $tokens[$j]['value'] !== '(') {
            return null;
        }
        // R9: stop AT the open paren. The caller previously skipped the whole
        // argument list, which meant nested calls like print(eval(...)) were
        // never inspected (Python's ast.walk visits every node). Returning
        // the open-paren index lets the main loop advance by one and scan
        // the arguments normally.
        return [implode('.', $parts), $j];
    }

    /**
     * Detect getattr(obj, "attr") / attrgetter("attr") calls at token index $i
     * (resolving the dispatcher name through aliases).
     *
     * @param list<array{type: string, value: string, line: int}> $tokens
     * @return array{0: string, 1: string}|null [dispatcher, attribute]
     */
    private static function matchDynamicDispatch(
        array $tokens,
        int $i,
        int $n,
        array $aliases,
        array $constants,
    ): ?array {
        if ($tokens[$i]['type'] !== self::T_NAME) {
            return null;
        }
        $base = explode('.', $aliases[$tokens[$i]['value']] ?? $tokens[$i]['value']);
        $tail = $base[count($base) - 1];
        if (!in_array($tail, self::GETATTR_DISPATCHERS, true)) {
            return null;
        }
        $attr = self::readGetattrLiteral($tokens, $i, $n, $constants);
        if ($attr === null) {
            return null;
        }
        return [$tail, $attr];
    }

    /**
     * Read the attribute literal from a getattr/attrgetter call's argument
     * list: getattr(obj, "attr") — index 1 for getattr, 0 for attrgetter.
     *
     * @param list<array{type: string, value: string, line: int}> $tokens
     */
    private static function readGetattrLiteral(
        array $tokens,
        int $i,
        int $n,
        array $constants,
    ): ?string {
        if ($i + 1 >= $n || $tokens[$i + 1]['type'] !== self::T_OP || $tokens[$i + 1]['value'] !== '(') {
            return null;
        }
        $j = $i + 2;
        $depth = 1;
        $argIndex = 0;
        $argTokens = [];
        while ($j < $n && $depth > 0) {
            $t = $tokens[$j];
            if ($t['type'] === self::T_OP) {
                if ($t['value'] === '(' || $t['value'] === '[') {
                    $depth++;
                } elseif ($t['value'] === ')' || $t['value'] === ']') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                } elseif ($t['value'] === ',' && $depth === 1) {
                    $argIndex++;
                    $j++;
                    continue;
                }
            }
            $argTokens[$argIndex][] = $t;
            $j++;
        }
        // getattr: attribute is arg 1; attrgetter: attribute is arg 0
        $attrTokens = $argTokens[1] ?? ($argTokens[0] ?? null);
        if ($attrTokens === null) {
            return null;
        }
        return self::literalFromTokens($attrTokens, $constants);
    }

    /**
     * Extract a literal string from simple token runs: plain string or
     * string-concat chain, with constant-name substitution.
     *
     * @param list<array{type: string, value: string, line: int}> $tokens
     */
    private static function literalFromTokens(array $tokens, array $constants): ?string
    {
        $buffer = null;
        $i = 0;
        $count = count($tokens);
        while ($i < $count) {
            $t = $tokens[$i];
            $piece = null;
            if ($t['type'] === self::T_STRING) {
                $piece = $t['value'];
            } elseif ($t['type'] === self::T_NAME && isset($constants[$t['value']])) {
                $piece = $constants[$t['value']];
            } elseif ($t['type'] === self::T_OP && $t['value'] === '+') {
                $i++;
                continue;
            } else {
                return null;
            }
            $buffer = $buffer === null ? $piece : $buffer . $piece;
            $i++;
        }
        return $buffer;
    }

    /**
     * Detect `x = vars(__builtins__)["eval"]` — a subscript that pulls an
     * executable builtin out of a builtin container.
     *
     * Such a binding reaches the builtin without ever forming a call on the
     * dangerous name itself, so the *called* subscript rule cannot see it.
     * This runs as its own pass over the token stream so an enclosing call
     * cannot swallow the token (H4).
     *
     * The key must be dangerous AND the indexed expression must be a
     * builtin container. Reading the same key from an ordinary dict
     * (`d = {'eval': 1}`) stays allowed (A4).
     *
     * A list or dict literal key (`["eval"]`, `{"eval": 1}`) is not an index
     * and is ignored; only a `[` that indexes a value expression counts.
     *
     * M60: the indexed expression is resolved down to its ROOT expression,
     * so a nested chain such as `g["__builtins__"]["eval"]` is seen as a
     * read from `g` — and `g` is resolved through the alias table, which
     * is what makes `g = globals()` (and friends) blockable.
     *
     * @param list<array{type: string, value: string, line: int}> $tokens
     * @param array<string, string> $aliases
     */
    private static function matchPlainExecutableSubscript(array $tokens, int $n, array $constants, array $aliases = []): ?string
    {
        for ($i = 1; $i < $n; $i++) {
            if ($tokens[$i]['type'] !== self::T_STRING) {
                continue;
            }
            $open = $tokens[$i - 1];
            if ($open['type'] !== self::T_OP || $open['value'] !== '[') {
                continue;
            }
            $before = $tokens[$i - 2] ?? null;
            if ($before === null) {
                continue; // bare list literal
            }
            $indexesValue = $before['type'] === self::T_NAME
                || ($before['type'] === self::T_OP && in_array($before['value'], [')', ']'], true));
            if (!$indexesValue) {
                continue; // list / dict literal
            }
            $key = self::literalFromTokens([$tokens[$i]], $constants);
            if ($key === null || !in_array(strtolower($key), self::DANGEROUS_SUBSCRIPT_KEYS, true)) {
                continue;
            }
            $base = self::resolveSubscriptBase($tokens, $i - 1, $aliases);
            if ($base !== null && in_array($base, self::DANGEROUS_SUBSCRIPT_SOURCES, true)) {
                return $key;
            }
        }
        return null;
    }

    /**
     * Describe the expression a subscript indexes, e.g. `vars(__builtins__)`
     * -> "vars", `globals()["__builtins__"]` -> "globals", `d` -> "d".
     *
     * A nested subscript chain is walked down to its ROOT expression, so
     * `g["__builtins__"]["eval"]` resolves to `g` (M60).
     *
     * @param list<array{type: string, value: string, line: int}> $tokens
     * @param array<string, string> $aliases
     */
    private static function resolveSubscriptBase(array $tokens, int $openIdx, array $aliases = []): ?string
    {
        $i = $openIdx - 1;
        if ($i < 0) {
            return null;
        }
        $tok = $tokens[$i];

        if ($tok['type'] === self::T_NAME) {
            $name = strtolower($tok['value']);
            // M60: the base may be an ALIAS of a builtin container
            // (`g = globals()`); without the alias table the escape
            // `g["__builtins__"]["eval"]` slipped through.
            $resolved = $aliases[$tok['value']] ?? null;
            if (is_string($resolved) && $resolved !== '') {
                $segments = explode('.', $resolved);
                $name = strtolower((string) end($segments));
            }
            return $name;
        }

        // nested subscript: the base is whatever the inner one indexes
        if ($tok['type'] === self::T_OP && $tok['value'] === ']') {
            $depth = 0;
            for ($j = $i; $j >= 0; $j--) {
                $t = $tokens[$j];
                if ($t['type'] !== self::T_OP) {
                    continue;
                }
                if ($t['value'] === ']') {
                    $depth++;
                } elseif ($t['value'] === '[') {
                    $depth--;
                    if ($depth === 0) {
                        return self::resolveSubscriptBase($tokens, $j, $aliases);
                    }
                }
            }
            return null;
        }

        // call expression: the base is the callee name
        if ($tok['type'] === self::T_OP && $tok['value'] === ')') {
            $depth = 0;
            for ($j = $i; $j >= 0; $j--) {
                $t = $tokens[$j];
                if ($t['type'] !== self::T_OP) {
                    continue;
                }
                if ($t['value'] === ')') {
                    $depth++;
                } elseif ($t['value'] === '(') {
                    $depth--;
                    if ($depth === 0) {
                        $callee = $tokens[$j - 1] ?? null;
                        if ($callee !== null
                            && ($callee['type'] === self::T_NAME || $callee['type'] === self::T_STRING)) {
                            $calleeName = strtolower($callee['value']);
                            $resolved = $aliases[$callee['value']] ?? null;
                            if (is_string($resolved) && $resolved !== '') {
                                $segments = explode('.', $resolved);
                                $calleeName = strtolower((string) end($segments));
                            }
                            return $calleeName;
                        }
                        return null;
                    }
                }
            }
            return null;
        }

        return null;
    }

    /**
     * If tokens at $i are a subscript read `expr["key"]`, return the literal key.
     *
     * @param list<array{type: string, value: string, line: int}> $tokens
     */
    private static function matchSubscriptAccess(array $tokens, int $i, int $n, array $constants): ?string
    {
        // find a '[' after the dotted expression
        $j = $i;
        while ($j < $n && !($tokens[$j]['type'] === self::T_OP && $tokens[$j]['value'] === '[')) {
            if ($tokens[$j]['type'] === self::T_OP && $tokens[$j]['value'] === '(') {
                return null; // call handled elsewhere
            }
            $j++;
        }
        if ($j >= $n) {
            return null;
        }
        $j++;
        // single string-literal key (with constant-name support)
        $literalTokens = [];
        $depth = 1;
        while ($j < $n && $depth > 0) {
            $t = $tokens[$j];
            if ($t['type'] === self::T_OP) {
                if ($t['value'] === '[') {
                    $depth++;
                } elseif ($t['value'] === ']') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                } elseif ($t['value'] === '(') {
                    $depth++;
                } elseif ($t['value'] === ')') {
                    $depth--;
                }
            }
            $literalTokens[] = $t;
            $j++;
        }
        if ($literalTokens === []) {
            return null;
        }
        return self::literalFromTokens($literalTokens, $constants);
    }
}

/**
 * Raised for Python source that cannot be tokenized (fail closed).
 */
final class PythonSyntaxError extends \InvalidArgumentException
{
}
