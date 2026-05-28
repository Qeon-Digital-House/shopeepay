<?php

declare(strict_types=1);

namespace ShopeePay\Webhook\Event;

/**
 * Link & Pay payment succeeded.
 * Notify service code 56, latestTransactionStatus "00".
 */
final class PaymentCompleted extends Event
{
}
