<?php

declare(strict_types=1);

namespace LightAgent\Dag;

use LightAgent\Security\SecurityUtils;
use LightAgent\Support\Json;

/**
 * Shared DAG constants and helpers (dag/models.py header).
 */
final class DAGConstants
{
    public const SCHEMA_VERSION = 1;

    private function __construct()
    {
    }

    public static function utcNow(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
    }

    /**
     * Python json.dumps(sort_keys=True, separators=(",", ":")) canonical form.
     *
     * Delegates to Json::canonical(), which THROWS when the value cannot be
     * encoded. Returning '' here would give every unencodable DAG state the
     * same canonical form — and therefore the same digest (T5b).
     *
     * @param mixed $value
     */
    public static function canonicalJson(mixed $value): string
    {
        return Json::canonical($value);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    public static function deepSort(mixed $value): mixed
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as $k => $v) {
                $value[$k] = self::deepSort($v);
            }
        }
        return $value;
    }

    /**
     * @param mixed $value
     */
    public static function canonicalDigest(mixed $value): string
    {
        return hash('sha256', self::canonicalJson($value));
    }
}

