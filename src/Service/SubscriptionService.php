<?php

declare(strict_types=1);

namespace ShopeePay\Service;

use ShopeePay\Dto\Subscription\CheckStatusRequest;
use ShopeePay\Dto\Subscription\CheckStatusResponse;
use ShopeePay\Dto\Subscription\CreatePaymentRequest;
use ShopeePay\Dto\Subscription\CreatePaymentResponse;
use ShopeePay\Dto\Subscription\RefundRequest;
use ShopeePay\Dto\Subscription\RefundResponse;
use ShopeePay\Http\Transport;

/**
 * Subscription recurring-debit operations: create (svc 54), checkStatus
 * (svc 55), refund (svc 58). Notify on completion arrives as svc 52 —
 * see `Webhook\EventFactory` for the dispatch.
 *
 * The create endpoint shares its path with `LinkAndPayService::create`
 * per the approved design (service-code map, line 120). Disambiguation
 * happens via the `subscriptionId` field which is required on
 * `CreatePaymentRequest` and absent from the Link & Pay equivalent.
 *
 * Endpoint paths follow the design doc + SNAP BI convention. Sandbox
 * probing (build-order step 11) confirms before v0.1.0 ships.
 */
final class SubscriptionService
{
    private const PATH_CREATE        = '/v1.1/debit/payment-host-to-host';
    private const PATH_CHECK_STATUS  = '/v1.0/debit/status';
    private const PATH_REFUND        = '/v1.0/debit/refund';

    public function __construct(
        private readonly Transport $transport,
    ) {
    }

    public function create(CreatePaymentRequest $request): CreatePaymentResponse
    {
        $payload = $this->transport->send(
            method: 'POST',
            path:   self::PATH_CREATE,
            body:   $request->toArray(),
        );
        return CreatePaymentResponse::fromArray($payload);
    }

    public function checkStatus(CheckStatusRequest $request): CheckStatusResponse
    {
        $payload = $this->transport->send(
            method: 'POST',
            path:   self::PATH_CHECK_STATUS,
            body:   $request->toArray(),
        );
        return CheckStatusResponse::fromArray($payload);
    }

    public function refund(RefundRequest $request): RefundResponse
    {
        $payload = $this->transport->send(
            method: 'POST',
            path:   self::PATH_REFUND,
            body:   $request->toArray(),
        );
        return RefundResponse::fromArray($payload);
    }
}
