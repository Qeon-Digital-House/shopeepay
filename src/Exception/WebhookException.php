<?php

declare(strict_types=1);

namespace ShopeePay\Exception;

/**
 * Webhook processing failure that is NOT a signature problem: malformed JSON
 * payload, missing required field, unrecognized payload shape. Signature
 * issues raise SignatureException instead.
 */
final class WebhookException extends ShopeePayException
{
}
