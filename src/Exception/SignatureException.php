<?php

declare(strict_types=1);

namespace ShopeePay\Exception;

/**
 * Cryptographic failure: cannot sign, cannot verify, or signature mismatch.
 * Also covers webhook replay-window violations.
 */
final class SignatureException extends ShopeePayException
{
}
