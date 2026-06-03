<?php

declare(strict_types=1);

namespace ShopeePay\Service;

use ShopeePay\Config;
use ShopeePay\Dto\AccountLinking\BindAccountRequest;
use ShopeePay\Dto\AccountLinking\BindAccountResponse;
use ShopeePay\Dto\AccountLinking\GetAuthCodeRequest;
use ShopeePay\Dto\AccountLinking\GetAuthCodeResponse;
use ShopeePay\Dto\AccountLinking\InquiryRequest;
use ShopeePay\Dto\AccountLinking\InquiryResponse;
use ShopeePay\Dto\AccountLinking\UnbindRequest;
use ShopeePay\Dto\AccountLinking\UnbindResponse;
use ShopeePay\Http\Transport;

/**
 * The four Account-Linking operations. Service codes 10/07/09/08
 * (get-auth-code / bind / unbind / inquiry).
 *
 * `buildAuthCodeUrl` is a pure URL builder — it does not hit the gateway.
 * The caller redirects the user's browser to the returned URL; ShopeePay
 * runs the consent flow and redirects back to `redirectUrl` with `authCode`
 * and `state` in the query string. The caller then verifies `state` and
 * passes `authCode` to `bind()`.
 *
 * The three POST endpoints route through `Transport`, which handles signing,
 * the access-token cache, and the retry-once-on-auth-failure dance.
 *
 * Endpoint paths follow SNAP BI convention. They are not stamped in the
 * approved design — sandbox probing (build-order step 11) confirms them
 * empirically before v0.1.0 ships.
 */
final class AccountLinkingService
{
    private const PATH_AUTH_CODE = '/v1.0/get-auth-code';
    private const PATH_BIND      = '/v1.0/registration-account-binding/bind';
    private const PATH_UNBIND    = '/v1.0/registration-account-unbinding/unbind';
    private const PATH_INQUIRY   = '/v1.0/registration-account-inquiry/inquiry-status';

    public function __construct(
        private readonly Config $config,
        private readonly Transport $transport,
    ) {
    }

    /**
     * Build the URL the user's browser must be redirected to for the
     * get-auth-code consent flow (service code 10).
     *
     * The returned URL includes `merchantId` (defaulted from Config if the
     * request leaves it null), `partnerReferenceNo`, `state`, `redirectUrl`,
     * `channelId`, and `scopes` (comma-joined) when supplied. Values are
     * urlencoded — slashes in `redirectUrl` survive, ampersands inside
     * `state` would not (but `state` is hex per `generateState()`).
     */
    public function buildAuthCodeUrl(GetAuthCodeRequest $request): string
    {
        return $this->config->baseUrl()
            . self::PATH_AUTH_CODE
            . '?'
            . http_build_query($this->authCodeParams($request), '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Server-to-server get-auth-code (svc 10): a signed GET that returns the
     * `authCode` directly in its body (responseCode 2001000). Per the SNAP BI
     * flow the caller then appends that `authCode` to the static consent URL
     * for the user's browser; after consent ShopeePay redirects back to
     * `redirectUrl` with the `authCode`, which is exchanged via `bind()`.
     *
     * Use this when you drive the linking flow from the backend. If you only
     * need the browser-redirect URL, use `buildAuthCodeUrl()` (no HTTP call).
     */
    public function getAuthCode(GetAuthCodeRequest $request): GetAuthCodeResponse
    {
        $payload = $this->transport->get(
            path:  self::PATH_AUTH_CODE,
            query: $this->authCodeParams($request),
        );
        return GetAuthCodeResponse::fromArray($payload);
    }

    /**
     * Query params shared by buildAuthCodeUrl() and getAuthCode().
     *
     * channelId is sent as the CHANNEL-ID header, not as a query param.
     * partnerReferenceNo is optional on this endpoint — included only when
     * the caller explicitly supplied one. merchantId defaults from Config.
     *
     * @return array<string, string>
     */
    private function authCodeParams(GetAuthCodeRequest $request): array
    {
        $params = [
            'merchantId'  => $request->merchantId ?? $this->config->merchantId,
            'state'       => $request->state,
            'redirectUrl' => $request->redirectUrl,
        ];
        if ($request->partnerReferenceNo !== null) {
            $params['partnerReferenceNo'] = $request->partnerReferenceNo;
        }
        if ($request->scopes !== []) {
            $params['scopes'] = implode(',', $request->scopes);
        }

        return $params;
    }

    public function bind(BindAccountRequest $request): BindAccountResponse
    {
        $payload = $this->transport->send(
            method: 'POST',
            path:   self::PATH_BIND,
            body:   $request->toArray(),
        );
        return BindAccountResponse::fromArray($payload);
    }

    public function unbind(UnbindRequest $request): UnbindResponse
    {
        $payload = $this->transport->send(
            method: 'POST',
            path:   self::PATH_UNBIND,
            body:   $request->toArray(),
        );
        return UnbindResponse::fromArray($payload);
    }

    public function inquiry(InquiryRequest $request): InquiryResponse
    {
        $payload = $this->transport->send(
            method: 'POST',
            path:   self::PATH_INQUIRY,
            body:   $request->toArray(),
        );
        return InquiryResponse::fromArray($payload);
    }
}
