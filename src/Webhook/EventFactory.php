<?php

declare(strict_types=1);

namespace ShopeePay\Webhook;

use ShopeePay\Webhook\Event\AuthCaptured;
use ShopeePay\Webhook\Event\Event;
use ShopeePay\Webhook\Event\PaymentCompleted;
use ShopeePay\Webhook\Event\PaymentFailed;
use ShopeePay\Webhook\Event\RefundCompleted;
use ShopeePay\Webhook\Event\SubscriptionPaymentCompleted;
use ShopeePay\Webhook\Event\SubscriptionPaymentFailed;
use ShopeePay\Webhook\Event\UnknownEvent;

/**
 * Turns a decoded notify payload into a typed Event.
 *
 * Dispatch axes:
 *   1. serviceCode (top-level field; fallback to additionalInfo.serviceCode
 *      since SNAP BI is inconsistent about where it puts it)
 *   2. presence of a refund-specific reference → RefundCompleted
 *   3. latestTransactionStatus == "00" → Completed variant, else Failed
 *
 * Unknown serviceCodes return UnknownEvent so the SDK is forward-compatible
 * with new ShopeePay notifications. Callers MUST handle UnknownEvent in
 * their match (see the README quickstart) or events will silently drop.
 *
 * Service codes recognized in v1:
 *   56 → Link & Pay  (PaymentCompleted / PaymentFailed / RefundCompleted)
 *   52 → Subscription (SubscriptionPaymentCompleted / Failed / RefundCompleted)
 *   65 → Auth & Capture (AuthCaptured / RefundCompleted on a 65 refund)
 *   69 → Auth & Capture refund (always RefundCompleted)
 */
final class EventFactory
{
    private const SVC_LINK_AND_PAY      = '56';
    private const SVC_SUBSCRIPTION      = '52';
    private const SVC_AUTH_CAPTURE      = '65';
    private const SVC_AUTH_REFUND       = '69';

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): Event
    {
        $serviceCode = self::extractServiceCode($payload);
        $status      = self::asString($payload['latestTransactionStatus'] ?? null);
        $statusDesc  = self::asString($payload['transactionStatusDesc']   ?? null);
        $origRef     = self::asNullableString($payload['originalReferenceNo']        ?? null);
        $origPartner = self::asNullableString($payload['originalPartnerReferenceNo'] ?? null);
        $isRefund    = self::looksLikeRefund($payload);
        $isSuccess   = $status === Event::STATUS_SUCCESS;

        $ctor = [
            'serviceCode'                => $serviceCode,
            'latestTransactionStatus'    => $status,
            'transactionStatusDesc'      => $statusDesc,
            'originalReferenceNo'        => $origRef,
            'originalPartnerReferenceNo' => $origPartner,
            'raw'                        => $payload,
        ];

        // Auth-refund (svc 69) is always a refund notification.
        if ($serviceCode === self::SVC_AUTH_REFUND) {
            return new RefundCompleted(...$ctor);
        }

        // For payment notifications, a refund signal on the same svc rerou-
        // tes to RefundCompleted regardless of payment success status.
        if ($isRefund && in_array($serviceCode, [self::SVC_LINK_AND_PAY, self::SVC_SUBSCRIPTION, self::SVC_AUTH_CAPTURE], true)) {
            return new RefundCompleted(...$ctor);
        }

        return match ($serviceCode) {
            self::SVC_LINK_AND_PAY => $isSuccess
                ? new PaymentCompleted(...$ctor)
                : new PaymentFailed(...$ctor),
            self::SVC_SUBSCRIPTION => $isSuccess
                ? new SubscriptionPaymentCompleted(...$ctor)
                : new SubscriptionPaymentFailed(...$ctor),
            self::SVC_AUTH_CAPTURE => new AuthCaptured(...$ctor),
            default                => new UnknownEvent(...$ctor),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function extractServiceCode(array $payload): string
    {
        $top = self::asNullableString($payload['serviceCode'] ?? null);
        if ($top !== null && $top !== '') {
            return $top;
        }
        $additional = $payload['additionalInfo'] ?? null;
        if (is_array($additional)) {
            $nested = self::asNullableString($additional['serviceCode'] ?? null);
            if ($nested !== null && $nested !== '') {
                return $nested;
            }
        }
        return '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function looksLikeRefund(array $payload): bool
    {
        // Heuristics — any of these signal a refund-shaped notify:
        //   - top-level refundReferenceNo
        //   - top-level refundAmount object
        //   - additionalInfo.refundReferenceNo
        //   - additionalInfo.refundAmount
        if (isset($payload['refundReferenceNo']) || isset($payload['refundAmount'])) {
            return true;
        }
        $additional = $payload['additionalInfo'] ?? null;
        if (is_array($additional) && (
            isset($additional['refundReferenceNo']) || isset($additional['refundAmount'])
        )) {
            return true;
        }
        return false;
    }

    private static function asString(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function asNullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
