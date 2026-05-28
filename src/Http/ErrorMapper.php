<?php

declare(strict_types=1);

namespace ShopeePay\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use ShopeePay\Exception\ApiException;
use ShopeePay\Exception\ConfigException;
use ShopeePay\Exception\NetworkException;
use ShopeePay\Exception\ShopeePayException;

/**
 * Converts low-level transport failures and gateway error responses into the
 * SDK's exception hierarchy.
 *
 * Two mapping surfaces:
 *
 *   PSR-18 throws → SDK exception
 *   ───────────────────────────────────────────────────
 *   NetworkExceptionInterface     → NetworkException
 *   RequestExceptionInterface     → ConfigException     (signals SDK bug or
 *                                                        caller-malformed
 *                                                        request)
 *   ClientExceptionInterface base → NetworkException    (catch-all transport
 *                                                        failure)
 *
 *   PSR-7 ResponseInterface → ApiException OR NetworkException
 *   ───────────────────────────────────────────────────
 *   2xx with parseable body  → null  (success — let the caller hydrate the
 *                                     DTO)
 *   2xx with body that has a non-2xx responseCode → ApiException
 *                                     (SNAP BI sometimes returns HTTP 200
 *                                      with an in-body 4011xxx)
 *   non-2xx with parseable body → ApiException
 *   non-2xx with unparseable body → NetworkException
 *                                     ("malformed gateway response")
 */
final class ErrorMapper
{
    public static function fromClientException(ClientExceptionInterface $e): ShopeePayException
    {
        return match (true) {
            $e instanceof NetworkExceptionInterface => new NetworkException(
                'transport failure: ' . $e->getMessage(),
                previous: $e,
            ),
            $e instanceof RequestExceptionInterface => new ConfigException(
                'malformed request rejected before sending: ' . $e->getMessage(),
                previous: $e,
            ),
            default => new NetworkException(
                'transport failure: ' . $e->getMessage(),
                previous: $e,
            ),
        };
    }

    /**
     * Inspect a PSR-7 response and return null if it is a success, otherwise
     * the ApiException carrying the parsed fields. Throws NetworkException if
     * the body cannot be JSON-decoded.
     *
     * @throws NetworkException
     */
    public static function fromResponse(ResponseInterface $response): ?ApiException
    {
        $bodyString = (string) $response->getBody();
        // Rewind so a downstream consumer can still read the body if needed.
        if ($response->getBody()->isSeekable()) {
            $response->getBody()->rewind();
        }

        $decoded = self::tryDecode($bodyString);
        if ($decoded === null) {
            throw new NetworkException(sprintf(
                'malformed gateway response: HTTP %d, body could not be JSON-decoded (%d bytes)',
                $response->getStatusCode(),
                strlen($bodyString),
            ));
        }

        $responseCode    = is_string($decoded['responseCode'] ?? null) ? $decoded['responseCode']    : (string) $response->getStatusCode() . '00000';
        $responseMessage = is_string($decoded['responseMessage'] ?? null) ? $decoded['responseMessage'] : '';
        $referenceNo     = is_string($decoded['referenceNo'] ?? null) ? $decoded['referenceNo']     : null;
        $partnerRef      = is_string($decoded['partnerReferenceNo'] ?? null) ? $decoded['partnerReferenceNo'] : null;

        $http = $response->getStatusCode();
        $isHttpSuccess  = $http >= 200 && $http < 300;
        // The body's responseCode wins over HTTP status when present — SNAP BI
        // sometimes returns HTTP 200 with an in-body 4011000 to signal token
        // expiry, and we MUST surface that as a failure (so AccessTokenManager
        // can retry once with a fresh token).
        $bodySaysSuccess = self::responseCodeIsSuccess($responseCode);

        if ($isHttpSuccess && $bodySaysSuccess) {
            return null;
        }

        return new ApiException(
            responseCode:       $responseCode,
            responseMessage:    $responseMessage,
            referenceNo:        $referenceNo,
            partnerReferenceNo: $partnerRef,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function tryDecode(string $body): ?array
    {
        if ($body === '') {
            return null;
        }
        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }

    private static function responseCodeIsSuccess(string $code): bool
    {
        // Anything 2xxxxxx in the 7-digit SNAP BI scheme is a success. Be
        // defensive about non-7-digit values — treat them as failures so
        // unexpected gateway responses surface to the caller.
        if (!preg_match('/^\d{7}$/', $code)) {
            return false;
        }
        return $code[0] === '2';
    }
}
