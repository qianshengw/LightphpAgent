<?php

declare(strict_types=1);

namespace LightAgent\Runtime;

/**
 * Inbox enums (runtime.py InboxMessageType / InboxMessageStatus).
 */
enum InboxMessageType: string
{
    case FOLLOWUP = 'followup';
    case STEERING = 'steering';
    case CONTEXT = 'context';
    case APPROVAL = 'approval';
}

enum InboxMessageStatus: string
{
    case PENDING = 'pending';
    case CLAIMED = 'claimed';
    case COMPLETED = 'completed';
    case REJECTED = 'rejected';
}
