<?php

declare(strict_types=1);

namespace ShopeePay\Exception;

use RuntimeException;

/**
 * Base class for every exception thrown by the SDK. Callers can catch this to
 * blanket-handle any SDK-originated failure.
 */
class ShopeePayException extends RuntimeException
{
}
