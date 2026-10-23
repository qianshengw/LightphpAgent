<?php

declare(strict_types=1);

namespace LightAgent\BuiltinTools;
use LightAgent\Support\Json;
use LightAgent\Tools\ToolFunction;


/**
 * Bounded data-only expression evaluator (port of builtin_tools/safe_expression.py).
 *
 * The Python original walks an ast.parse tree; the PHP port implements a
 * small recursive-descent parser over the same safe subset:
 *   literals (int/float/string/bool/null), lists (JSON array style),
 *   arithmetic (+ - * / // %), unary (+ - not ~), comparisons
 *   (== != < <= > >= in/not in/is/is not), and/or chains, and parentheses.
 */
final class SafeExpressionTool
{
    public const MAX_NODES = 128;
    public const MAX_COLLECTION_ITEMS = 64;
    public const MAX_STRING_LENGTH = 4096;

    /**
     * Maximum length of the expression source (V4-02).
     *
     * The evaluator is fed straight from the model. Without a length
     * limit a single expression could allocate an unbounded amount of
     * memory (the tokenizer builds one entry per token) and kill the
     * agent process with an OOM fatal that CANNOT be caught. The limit
     * is checked before anything is allocated.
     */
    public const MAX_EXPRESSION_LENGTH = 8192;

    /**
     * Maximum number of tokens (V4-02).
     *
     * A second, tighter bound on the tokenizer's output: an expression
     * made of many short tokens stays inside MAX_EXPRESSION_LENGTH but
     * would still build a large token array.
     */
    public const MAX_TOKENS = 2048;

    private int $nodeCount = 0;

    private function __construct()
    {
    }

    public static function create(): ToolFunction
    {
        return new ToolFunction(
            callable: static fn (string $expression): string => self::safeExpression($expression),
            toolInfo: [
                'tool_name' => 'safe_expression',
                'tool_title' => '安全表达式计算',
                'tool_description' => '计算不访问文件、网络、进程或运行时的受限数据表达式',
                'tool_params' => [
                    [
                        'name' => 'expression',
                        'description' => '受限表达式，例如 45 * 9827 或 [1, 2, 3]',
                        'type' => 'string',
                        'required' => true,
                    ],
                ],
            ],
        );
    }

    public static function safeExpression(string $expression): string
    {
        try {
            $value = self::evaluate($expression);
        } catch (SafeExpressionError $error) {
            return "安全表达式错误：{$error->getMessage()}";
        }
        return self::repr($value);
    }

    /**
     * @return mixed
     */
    public static function evaluate(string $expression): mixed
    {
        if (trim($expression) === '') {
            throw new SafeExpressionError('expression must be a non-empty string');
        }
        // V4-02: the length is checked BEFORE the tokenizer runs — a huge
        // expression used to exhaust the memory limit with an uncatchable
        // OOM fatal.
        if (strlen($expression) > self::MAX_EXPRESSION_LENGTH) {
            throw new SafeExpressionError(
                'expression exceeds the safe expression limit (' . self::MAX_EXPRESSION_LENGTH . ' characters)',
            );
        }
        $parser = new self();
        $tokens = $parser->tokenize($expression);
        $parser->tokens = $tokens;
        $value = $parser->parseOr();
        if ($parser->pos < count($tokens)) {
            throw new SafeExpressionError('unexpected trailing tokens');
        }
        if ($parser->nodeCount > self::MAX_NODES) {
            throw new SafeExpressionError('expression contains too many nodes');
        }
        return $value;
    }

    private static function repr(mixed $value): string
    {
        if ($value === null) {
            return 'None';
        }
        if ($value === true) {
            return 'True';
        }
        if ($value === false) {
            return 'False';
        }
        if (is_array($value)) {
            // M58: json_encode() returns FALSE for invalid UTF-8, which
            // violates the `string` return type and raised a TypeError
            // outside the tool's own error handling. The fallback
            // substitutes the invalid bytes instead of failing.
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
            return $encoded === false
                ? Json::encodeOrError($value, JSON_UNESCAPED_UNICODE)
                : $encoded;
        }
        if (is_float($value)) {
            return (string) $value;
        }
        return (string) $value;
    }

    // ------------------------------------------------------------------
    // Tokenizer
    // ------------------------------------------------------------------

    /** @var list<array{type: string, value: mixed}> */
    private array $tokens = [];
    private int $pos = 0;

    /**
     * @return list<array{type: string, value: mixed}>
     */
    private function tokenize(string $input): array
    {
        $tokens = [];
        $len = strlen($input);
        $i = 0;
        while ($i < $len) {
            // V4-02: the token array is the biggest allocation of the
            // evaluator — stop before it can grow without bound.
            if (count($tokens) >= self::MAX_TOKENS) {
                throw new SafeExpressionError('expression contains too many tokens');
            }
            $c = $input[$i];
            if (ctype_space($c)) {
                $i++;
                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
                $j = $i + 1;
                $str = '';
                while ($j < $len && $input[$j] !== $quote) {
                    if ($input[$j] === '\\' && $j + 1 < $len) {
                        $next = $input[$j + 1];
                        $str .= match ($next) {
                            'n' => "\n",
                            't' => "\t",
                            'r' => "\r",
                            default => $next,
                        };
                        $j += 2;
                        continue;
                    }
                    $str .= $input[$j];
                    $j++;
                }
                if ($j >= $len) {
                    throw new SafeExpressionError('unterminated string literal');
                }
                if (strlen($str) > self::MAX_STRING_LENGTH) {
                    throw new SafeExpressionError('string literal exceeds the safe expression limit');
                }
                $tokens[] = ['type' => 'string', 'value' => $str];
                $i = $j + 1;
                continue;
            }
            if (ctype_digit($c) || ($c === '.' && $i + 1 < $len && ctype_digit($input[$i + 1]))) {
                $j = $i;
                $hasDot = false;
                while ($j < $len && (ctype_digit($input[$j]) || $input[$j] === '.')) {
                    if ($input[$j] === '.') {
                        if ($hasDot) {
                            break;
                        }
                        $hasDot = true;
                    }
                    $j++;
                }
                $text = substr($input, $i, $j - $i);
                if (strlen($text) > 64) {
                    throw new SafeExpressionError('integer exceeds the safe expression limit');
                }
                $tokens[] = ['type' => 'number', 'value' => $hasDot ? (float) $text : (int) $text];
                $i = $j;
                continue;
            }
            if (ctype_alpha($c) || $c === '_') {
                $j = $i;
                while ($j < $len && (ctype_alnum($input[$j]) || $input[$j] === '_')) {
                    $j++;
                }
                $word = substr($input, $i, $j - $i);
                $tokens[] = ['type' => 'word', 'value' => $word];
                $i = $j;
                continue;
            }
            // Operators & punctuation (longest match first)
            $two = substr($input, $i, 2);
            if (in_array($two, ['==', '!=', '<=', '>=', '//'], true)) {
                $tokens[] = ['type' => 'op', 'value' => $two];
                $i += 2;
                continue;
            }
            if (in_array($c, ['+', '-', '*', '/', '%', '<', '>', '(', ')', '[', ']', ',', '~'], true)) {
                $tokens[] = ['type' => 'op', 'value' => $c];
                $i++;
                continue;
            }
            throw new SafeExpressionError("unexpected character '{$c}'");
        }
        return $tokens;
    }

    private function next(): ?array
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private function eat(string $type, ?string $value = null): ?array
    {
        $tok = $this->next();
        if ($tok === null || $tok['type'] !== $type || ($value !== null && $tok['value'] !== $value)) {
            return null;
        }
        $this->pos++;
        return $tok;
    }

    private function tick(): void
    {
        $this->nodeCount++;
        if ($this->nodeCount > self::MAX_NODES) {
            throw new SafeExpressionError('expression contains too many nodes');
        }
    }

    // ------------------------------------------------------------------
    // Parser: or → and → not → comparison → arith → term → unary → atom
    // ------------------------------------------------------------------

    private function parseOr(): mixed
    {
        $this->tick();
        $result = $this->parseAnd();
        while ($this->eat('word', 'or') !== null) {
            $rhs = $this->parseAnd();
            // Python `or` returns the FIRST truthy operand, otherwise the
            // last one: `1 or ""` is 1, `"" or 0` is 0.
            if (self::isTruthy($result)) {
                continue; // keep the truthy left operand
            }
            $result = $rhs;
        }
        return $result;
    }

    private function parseAnd(): mixed
    {
        $this->tick();
        $result = $this->parseNot();
        while ($this->eat('word', 'and') !== null) {
            $rhs = $this->parseNot();
            // Python `and` returns the FIRST falsy operand, otherwise the
            // last one: `0 and ""` is 0, `None and 0` is None.
            if (!self::isTruthy($result)) {
                continue; // keep the falsy left operand
            }
            $result = $rhs;
        }
        return $result;
    }

    private function parseNot(): mixed
    {
        $this->tick();
        if ($this->eat('word', 'not') !== null) {
            $operand = $this->parseNot();
            return !self::isTruthy($operand);
        }
        return $this->parseComparison();
    }

    /**
     * Python truthiness (M59).
     *
     * False, None, 0, 0.0, "" and empty collections are falsy. Note that
     * the string "0" is TRUTHY in Python, while PHP treats it as falsy.
     */
    private static function isTruthy(mixed $value): bool
    {
        if ($value === null || $value === false) {
            return false;
        }
        if ($value === true) {
            return true;
        }
        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }
        if (is_string($value)) {
            return $value !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }
        return true;
    }

    private function parseComparison(): mixed
    {
        $this->tick();
        $left = $this->parseArith();
        // Chained comparisons (`1 < 2 < 3`) compare the MIDDLE operand
        // with both neighbours: Python evaluates `a < b < c` as
        // `a < b and b < c` and always yields a bool. The previous
        // operand is therefore kept aside instead of being overwritten
        // by the boolean result of the comparison.
        $previous = $left;
        $result = null;
        $compared = false;
        while (true) {
            $op = null;
            $tok = $this->next();
            if ($tok !== null && $tok['type'] === 'op' && in_array($tok['value'], ['==', '!=', '<', '<=', '>', '>='], true)) {
                $op = $tok['value'];
                $this->pos++;
            } elseif ($tok !== null && $tok['type'] === 'word' && in_array($tok['value'], ['in', 'is'], true)) {
                $word = $tok['value'];
                $this->pos++;
                // "not in" / "is not"
                $nxt = $this->next();
                if ($nxt !== null && $nxt['type'] === 'word' && $nxt['value'] === 'not') {
                    $this->pos++;
                    $op = $word === 'in' ? 'not in' : 'is not';
                } else {
                    $op = $word === 'in' ? 'in' : 'is';
                }
            }
            if ($op === null) {
                break;
            }
            $right = $this->parseArith();
            $ok = $this->applyComparison($op, $previous, $right);
            $result = $compared ? ($result && $ok) : $ok;
            $compared = true;
            $previous = $right;
        }
        return $compared ? $result : $left;
    }

    private function applyComparison(string $op, mixed $left, mixed $right): bool
    {
        return match ($op) {
            '==' => $left === $right,
            '!=' => $left !== $right,
            '<' => $left < $right,
            '<=' => $left <= $right,
            '>' => $left > $right,
            '>=' => $left >= $right,
            'in' => is_array($right) ? in_array($left, $right, true) : (is_string($right) && is_string($left) && str_contains($right, $left)),
            'not in' => !(is_array($right) ? in_array($left, $right, true) : (is_string($right) && is_string($left) && str_contains($right, $left))),
            'is' => $left === $right,
            'is not' => $left !== $right,
            default => throw new SafeExpressionError('comparison operator is not allowed'),
        };
    }

    private function parseArith(): mixed
    {
        $this->tick();
        $left = $this->parseTerm();
        while (true) {
            $tok = $this->next();
            if ($tok !== null && $tok['type'] === 'op' && in_array($tok['value'], ['+', '-'], true)) {
                $this->pos++;
                $right = $this->parseTerm();
                $left = $this->validateValue(
                    match ($tok['value']) {
                        '+' => $this->arithAdd($left, $right),
                        '-' => $this->arithNumeric($left, $right, '-'),
                    },
                );
            } else {
                break;
            }
        }
        return $left;
    }

    private function parseTerm(): mixed
    {
        $this->tick();
        $left = $this->parseUnary();
        while (true) {
            $tok = $this->next();
            if ($tok !== null && $tok['type'] === 'op' && in_array($tok['value'], ['*', '/', '//', '%'], true)) {
                $this->pos++;
                $right = $this->parseUnary();
                $left = $this->validateValue($this->arithNumeric($left, $right, $tok['value']));
            } else {
                break;
            }
        }
        return $left;
    }

    private function parseUnary(): mixed
    {
        $this->tick();
        $tok = $this->next();
        if ($tok !== null && $tok['type'] === 'op' && in_array($tok['value'], ['+', '-', '~'], true)) {
            $this->pos++;
            $operand = $this->parseUnary();
            $this->validateValue($operand);
            return match ($tok['value']) {
                '+' => $operand,
                '-' => - (is_int($operand) || is_float($operand) ? $operand : throw new SafeExpressionError('unary operator is not allowed')),
                '~' => is_int($operand) ? ~$operand : throw new SafeExpressionError('unary operator is not allowed'),
            };
        }
        return $this->parseAtom();
    }

    private function parseAtom(): mixed
    {
        $this->tick();
        $tok = $this->next();
        if ($tok === null) {
            throw new SafeExpressionError('unexpected end of expression');
        }

        if ($tok['type'] === 'number' || $tok['type'] === 'string') {
            $this->pos++;
            return $this->validateValue($tok['value']);
        }

        if ($tok['type'] === 'word') {
            $this->pos++;
            return match ($tok['value']) {
                'True' => true,
                'False' => false,
                'None' => null,
                default => throw new SafeExpressionError("name '{$tok['value']}' is not allowed"),
            };
        }

        if ($tok['type'] === 'op' && $tok['value'] === '(') {
            $this->pos++;
            $value = $this->parseOr();
            if ($this->eat('op', ')') === null) {
                throw new SafeExpressionError('expected closing parenthesis');
            }
            return $value;
        }

        if ($tok['type'] === 'op' && $tok['value'] === '[') {
            $this->pos++;
            $items = [];
            while (true) {
                if ($this->eat('op', ']') !== null) {
                    break;
                }
                if (count($items) >= self::MAX_COLLECTION_ITEMS) {
                    throw new SafeExpressionError('collection exceeds the safe expression limit');
                }
                $items[] = $this->parseOr();
                if ($this->eat('op', ',') === null) {
                    if ($this->eat('op', ']') === null) {
                        throw new SafeExpressionError('expected , or ] in list');
                    }
                    break;
                }
            }
            return $this->validateValue($items);
        }

        throw new SafeExpressionError("token '{$tok['value']}' is not allowed");
    }

    private function arithAdd(mixed $left, mixed $right): mixed
    {
        if (is_string($left) && is_string($right)) {
            if (strlen($left) + strlen($right) > self::MAX_STRING_LENGTH) {
                throw new SafeExpressionError('string literal exceeds the safe expression limit');
            }
            return $left . $right;
        }
        if (is_array($left) && is_array($right)) {
            return array_merge($left, $right);
        }
        return $this->arithNumeric($left, $right, '+');
    }

    private function arithNumeric(mixed $left, mixed $right, string $op): mixed
    {
        if ((!is_int($left) && !is_float($left)) || (!is_int($right) && !is_float($right))) {
            throw new SafeExpressionError('expression evaluation failed: TypeError');
        }
        try {
            return match ($op) {
                '+' => $left + $right,
                '-' => $left - $right,
                '*' => $left * $right,
                '/' => $right == 0 ? throw new SafeExpressionError('expression evaluation failed: ZeroDivisionError') : $left / $right,
                '//' => $right == 0 ? throw new SafeExpressionError('expression evaluation failed: ZeroDivisionError') : self::floorDivide($left, $right),
                '%' => $right == 0
                    ? throw new SafeExpressionError('expression evaluation failed: ZeroDivisionError')
                    : (is_int($left) && is_int($right) ? ($left - intdiv($left, $right) * $right) : fmod($left, $right)),
                default => throw new SafeExpressionError('binary operator is not allowed'),
            };
        } catch (\ArithmeticError | \DivisionByZeroError $e) {
            throw new SafeExpressionError('expression evaluation failed: ' . $e::class);
        }
    }

    /**
     * Python floor division (M59).
     *
     * Python rounds towards negative infinity, so -7 // 2 is -4 while
     * intdiv() (which truncates towards zero) returns -3.
     */
    private static function floorDivide(mixed $left, mixed $right): int|float
    {
        if (is_int($left) && is_int($right)) {
            $quotient = intdiv($left, $right);
            // intdiv() truncates towards zero: adjust when there is a
            // remainder and the operands have different signs.
            if (($left % $right !== 0) && (($left < 0) !== ($right < 0))) {
                $quotient--;
            }
            return $quotient;
        }
        return (float) floor($left / $right);
    }

    /**
     * @return mixed
     */
    private function validateValue(mixed $value): mixed
    {
        if (is_array($value) && count($value) > self::MAX_COLLECTION_ITEMS) {
            throw new SafeExpressionError('collection exceeds the safe expression limit');
        }
        return $value;
    }
}

final class SafeExpressionError extends \InvalidArgumentException
{
}
