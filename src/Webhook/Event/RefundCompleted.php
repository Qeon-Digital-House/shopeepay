<?php

declare(strict_types=1);

namespace ShopeePay\Webhook\Event;

/**
 * A refund finished (either a Link & Pay/Subscription debit refund — svc 58,
 * notify via parent's svc — or an Auth & Capture refund, svc 69). Identified
 * by the presence of a refund-specific reference in the payload (e.g.
 * `refundReferenceNo` or `originalReferenceNo` with a refund-shaped
 * additionalInfo).
 */
final class RefundCompleted extends Event
{
}
