<?php

declare(strict_types=1);

namespace ShopeePay\Service;

use ShopeePay\Dto\LinkAndPay\CheckStatusRequest;
use ShopeePay\Dto\LinkAndPay\CheckStatusResponse;
use ShopeePay\Dto\LinkAndPay\CreatePaymentRequest;
use ShopeePay\Dto\LinkAndPay\CreatePaymentResponse;
use ShopeePay\Dto\LinkAndPay\RefundRequest;
use ShopeePay\Dto\LinkAndPay\RefundResponse;
use ShopeePay\Http\Transport;

/**
 * The three Link & Pay operations: create a payment (svc 54), poll its
 * status (svc 55), and refund a completed one (svc 58).
 *
 * All three are signed POSTs routed through `Transport`, which handles
 * the access-token cache and the retry-once-on-auth-failure dance.
 *
 * Polling is the CALLER's job — SDK does not auto-poll. See
 * `examples/02-link-and-pay.php` for the suggested 5s × 20 then 5m × 6
 * cadence (open to be tuned once `Notify Transaction Status` arrives;
 * many integrations stop polling once the webhook lands).
 *
 * The create endpoint path is pinned by the design doc as
 * `/v1.1/debit/payment-host-to-host` (note the v1.1, not v1.0). Status
 * and refund are v1.0 per SNAP BI convention; sandbox probe (build-order
 * step 11) confirms.
 */
final class LinkAndPayService
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
