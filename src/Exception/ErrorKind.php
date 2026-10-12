<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Exception;

/**
 * A provider failure, normalised across providers.
 */
enum ErrorKind: string
{
    case InvalidRequest = 'invalid_request';
    case Authentication = 'authentication';
    case Permission = 'permission';
    case NotFound = 'not_found';
    case RequestTooLarge = 'request_too_large';
    case Billing = 'billing';
    case RateLimited = 'rate_limited';
    case Overloaded = 'overloaded';
    case Timeout = 'timeout';
    case Server = 'server';
    case InvalidResponse = 'invalid_response';
    case Unknown = 'unknown';
}
