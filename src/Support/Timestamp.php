<?php

declare(strict_types=1);

namespace LightAgent\Support;

/**
 * UTC timestamps with millisecond precision.
 *
 * `DateTimeInterface::ATOM` renders `Y-m-d\TH:i:sP` — **no sub-second
 * component**. Storing a lease or an expiry with it truncates the value to
 * whole seconds, so a 0.2s lease could land on a window of 0 seconds and
 * expire the instant it was created (T8).
 */
final class Timestamp
{
    public const FORMAT = 'Y-m-d\TH:i:s.vP';

    private function __construct()
    {
    }

    public static function utcNow(): string
    {
        return self::format(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    public static function format(\DateTimeInterface $when): string
    {
        return $when->format(self::FORMAT);
    }

    /**
     * A timestamp `seconds` in the future, with millisecond precision.
     */
    public static function inSeconds(float $seconds): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $micros = (int) round($seconds * 1_000_000);
        return self::format($now->modify('+' . intdiv($micros, 1_000_000) . ' seconds')
            ->modify('+' . ($micros % 1_000_000) . ' microseconds'));
    }

    /**
     * @throws \InvalidArgumentException when the value is not a timestamp
     */
    public static function parse(string $value): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable(str_replace('Z', '+00:00', $value));
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("invalid UTC timestamp: {$value}");
        }
    }
}
