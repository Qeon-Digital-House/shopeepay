<?php

declare(strict_types=1);

namespace ShopeePay\Exception;

/**
 * Transport-layer failure: DNS, TCP, TLS, read timeout, malformed gateway
 * response that cannot be JSON-decoded. Wraps PSR-18 ClientExceptionInterface.
 */
final class NetworkException extends ShopeePayException
{
}
