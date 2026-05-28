<?php

declare(strict_types=1);

namespace ShopeePay\Service;

use ShopeePay\Dto\AuthCapture\AuthorizeRequest;
use ShopeePay\Dto\AuthCapture\AuthorizeResponse;
use ShopeePay\Dto\AuthCapture\CaptureRequest;
use ShopeePay\Dto\AuthCapture\CaptureResponse;
use ShopeePay\Dto\AuthCapture\QueryAuthRequest;
use ShopeePay\Dto\AuthCapture\QueryAuthResponse;
use ShopeePay\Dto\AuthCapture\QueryCaptureRequest;
use ShopeePay\Dto\AuthCapture\QueryCaptureResponse;
use ShopeePay\Dto\AuthCapture\QueryVoidRequest;
use ShopeePay\Dto\AuthCapture\QueryVoidResponse;
use ShopeePay\Dto\AuthCapture\RefundRequest;
use ShopeePay\Dto\AuthCapture\RefundResponse;
use ShopeePay\Dto\AuthCapture\VoidRequest;
use ShopeePay\Dto\AuthCapture\VoidResponse;
use ShopeePay\Http\Transport;

/**
 * Auth & Capture: the four-stage card-on-file pattern. Authorize reserves
 * funds (svc 63); capture settles them (svc 65); void releases an
 * un-captured authorization (svc 67); refund reverses a settled capture
 * (svc 69). Three matching query ops (svc 64/66/68) let callers poll
 * status out-of-band of the notify webhook.
 *
 * State-machine constraints from the design doc (line 492 onward):
 *   - Capture must occur before the auth's `validUpTo` expires (default 24h,
 *     max 14 days).
 *   - **One partial capture per authorization** — unreserved balance is
 *     released to the customer.
 *   - Void must occur before capture.
 *   - Refund must occur after a successful capture.
 *
 * These constraints are NOT enforced client-side in v1. The gateway is
 * the source of truth and surfaces violations as `ApiException`.
 *
 * Endpoint paths follow SNAP BI convention. Only `/v1.0/auth/refund` is
 * explicitly pinned by the approved design doc (line 230); the others
 * are inferred and confirmed by build-order step 11's sandbox probe.
 */
final class AuthCaptureService
{
    private const PATH_AUTHORIZE      = '/v1.0/auth/payment-host-to-host';
    private const PATH_CAPTURE        = '/v1.0/auth/capture';
    private const PATH_VOID           = '/v1.0/auth/void';
    private const PATH_REFUND         = '/v1.0/auth/refund';
    private const PATH_QUERY_AUTH     = '/v1.0/auth/status';
    private const PATH_QUERY_CAPTURE  = '/v1.0/auth/capture/status';
    private const PATH_QUERY_VOID     = '/v1.0/auth/void/status';

    public function __construct(
        private readonly Transport $transport,
    ) {
    }

    public function authorize(AuthorizeRequest $request): AuthorizeResponse
    {
        $payload = $this->transport->send(
            method: 'POST',
            path:   self::PATH_AUTHORIZE,
            body:   $request->toArray(),
        );
        return AuthorizeResponse::fromArray($payload);
    }

    public function capture(CaptureRequest $request): CaptureResponse
    {
        $payload = $this->transport->send(
            method: 'POST',
            path:   self::PATH_CAPTURE,
            body:   $request->toArray(),
        );
        return CaptureResponse::fromArray($payload);
    }

    public function void(VoidRequest $request): VoidResponse
    {
        $payload = $this->transport->send(
            method: 'POST',
            path:   self::PATH_VOID,
            body:   $request->toArray(),
        );
        return VoidResponse::fromArray($payload);
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

    public function queryAuth(QueryAuthRequest $request): QueryAuthResponse
    {
        $payload = $this->transport->send(
            method: 'POST',
            path:   self::PATH_QUERY_AUTH,
            body:   $request->toArray(),
        );
        return QueryAuthResponse::fromArray($payload);
    }

    public function queryCapture(QueryCaptureRequest $request): QueryCaptureResponse
    {
        $payload = $this->transport->send(
            method: 'POST',
            path:   self::PATH_QUERY_CAPTURE,
            body:   $request->toArray(),
        );
        return QueryCaptureResponse::fromArray($payload);
    }

    public function queryVoid(QueryVoidRequest $request): QueryVoidResponse
    {
        $payload = $this->transport->send(
            method: 'POST',
            path:   self::PATH_QUERY_VOID,
            body:   $request->toArray(),
        );
        return QueryVoidResponse::fromArray($payload);
    }
}
