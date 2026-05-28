<?php

declare(strict_types=1);

namespace ShopeePay\Webhook\Event;

/**
 * An Auth & Capture authorization was successfully captured (async notify).
 * Notify service code 65 (capture completion).
 */
final class AuthCaptured extends Event
{
}
