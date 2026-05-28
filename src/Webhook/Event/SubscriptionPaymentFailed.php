<?php

declare(strict_types=1);

namespace ShopeePay\Webhook\Event;

/**
 * Subscription payment did NOT succeed.
 * Notify service code 52, latestTransactionStatus != "00".
 */
final class SubscriptionPaymentFailed extends Event
{
}
