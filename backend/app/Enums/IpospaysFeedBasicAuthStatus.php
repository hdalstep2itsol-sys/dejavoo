<?php

namespace App\Enums;

enum IpospaysFeedBasicAuthStatus: string
{
    case Verified = 'basic_auth_verified';
    case FeedDisabled = 'feed_disabled';
    case CredentialsMissing = 'basic_auth_config_missing';
    case Missing = 'basic_auth_missing';
    case Malformed = 'basic_auth_malformed';
    case Invalid = 'basic_auth_invalid';
}
