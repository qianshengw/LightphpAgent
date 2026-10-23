<?php

declare(strict_types=1);

namespace LightAgent\Review;

/**
 * Human approval primitives (review.py constants).
 */
final class ApprovalConstants
{
    public const PENDING = 'pending';
    public const APPROVE = 'approve';
    public const REJECT = 'reject';
    public const EDIT = 'edit';
    public const RESPOND = 'respond';

    public const ACTIONS = [
        self::PENDING, self::APPROVE, self::REJECT, self::EDIT, self::RESPOND,
    ];

    private function __construct()
    {
    }

    public static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
    }
}
