<?php

declare(strict_types=1);

namespace ShopeePay\Webhook\Event;

/**
 * Subscription payment succeeded.
 * Notify service code 52, latestTransactionStatus "00".
 */
final class SubscriptionPaymentCompleted extends Event
{
}
