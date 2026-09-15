<?php

namespace App\Enums;

enum IpospaysFeedHmacStatus: string
{
    case Verified = 'verified';
    case FeedDisabled = 'feed_disabled';
    case SecretMissing = 'hmac_secret_missing';
    case ProfileUnfinalized = 'hmac_profile_unfinalized';
    case SignatureMissing = 'hmac_signature_missing';
    case InvalidSignature = 'hmac_signature_invalid';
    case VerificationFailed = 'hmac_verification_failed';
}
