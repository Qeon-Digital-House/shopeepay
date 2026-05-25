<?php

declare(strict_types=1);

namespace ShopeePay\Webhook\Event;

/**
 * Link & Pay payment did NOT succeed.
 * Notify service code 56, latestTransactionStatus != "00".
 */
final class PaymentFailed extends Event
{
}
