<?php

namespace App\Enums;

enum IpospaysFeedEventStatus: string
{
    case Received = 'received';
    case Authenticated = 'authenticated';
    case PendingProviderMapping = 'pending_provider_mapping';
    case Processed = 'processed';
    case Duplicate = 'duplicate';
    case Unsupported = 'unsupported';
    case TerminalUnknown = 'terminal_unknown';
    case TerminalInactive = 'terminal_inactive';
    case LocationInactive = 'location_inactive';
    case Conflict = 'conflict';
    case Failed = 'failed';
}
