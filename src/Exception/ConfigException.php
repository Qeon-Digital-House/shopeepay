<?php

declare(strict_types=1);

namespace ShopeePay\Exception;

/**
 * The SDK was configured incorrectly or the caller passed malformed inputs.
 * Distinct from ApiException, which is the gateway saying no.
 */
final class ConfigException extends ShopeePayException
{
}
