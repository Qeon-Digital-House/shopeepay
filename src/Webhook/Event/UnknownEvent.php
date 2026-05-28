<?php

declare(strict_types=1);

namespace ShopeePay\Webhook\Event;

/**
 * Forward-compat fallback. Returned by EventFactory when the service code is
 * not one of the recognized notifications in v1.
 *
 * ⚠️  Callers MUST handle this — a typical pattern is a `default` arm in a
 *     `match`:
 *
 *     match (true) {
 *         $event instanceof PaymentCompleted => ...,
 *         default                            => $logger->warning('unknown shopeepay event', ['svc' => $event->serviceCode]),
 *     }
 *
 * Without a `default`, unrecognized service codes (which WILL happen as
 * ShopeePay adds flows) are silently dropped. The README quickstart shows
 * the correct shape.
 */
final class UnknownEvent extends Event
{
}
