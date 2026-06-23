<?php

declare(strict_types=1);

namespace ShopeePay\Service;

use ShopeePay\Config;
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
        private readonly Config $config,
        private readonly Transport $transport,
    ) {
    }

    public function create(CreatePaymentRequest $request): CreatePaymentResponse
    {
        $payload = $this->transport->send(
            method: 'POST',
            path:   self::PATH_CREATE,
            body:   array_merge($request->toArray(), $this->merchantFields()),
        );
        return CreatePaymentResponse::fromArray($payload);
    }

    /**
     * Top-level merchantId/externalStoreId that every debit endpoint requires
     * (verified against the sandbox probe, see CLAUDE.md). externalStoreId is
     * omitted when storeId is not configured rather than sent empty.
     *
     * @return array<string, string>
     */
    private function merchantFields(): array
    {
        $fields = ['merchantId' => $this->config->merchantId];
        if ($this->config->storeId !== null && trim($this->config->storeId) !== '') {
            $fields['externalStoreId'] = $this->config->storeId;
        }
        return $fields;
    }

    public function checkStatus(CheckStatusRequest $request): CheckStatusResponse
    {
        $payload = $this->transport->send(
            method: 'POST',
            path:   self::PATH_CHECK_STATUS,
            body:   array_merge($request->toArray(), $this->merchantFields()),
        );
        return CheckStatusResponse::fromArray($payload);
    }

    public function refund(RefundRequest $request): RefundResponse
    {
        $payload = $this->transport->send(
            method: 'POST',
            path:   self::PATH_REFUND,
            body:   array_merge($request->toArray(), $this->merchantFields()),
        );
        return RefundResponse::fromArray($payload);
    }
}
