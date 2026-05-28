<?php

declare(strict_types=1);

namespace ShopeePay\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use ShopeePay\Config;
use ShopeePay\Exception\ApiException;
use ShopeePay\Exception\NetworkException;

/**
 * Sends signed transaction requests and handles the auth-retry dance.
 *
 * Retry policy (eng-review decision #2):
 *   - First attempt fails with HTTP 401 OR an in-body responseCode matching
 *     4011xxx → invalidate the cached token, refresh once, retry exactly
 *     once.
 *   - Second attempt failing the same way → throw ApiException. No loop.
 *   - Anything else (4xx that isn't auth, 5xx, transport failure) → propagate
 *     immediately. We do not retry on non-auth failures because the request
 *     side-effect may already have happened.
 *
 * Returns the decoded JSON body on success. Services convert that array
 * into their typed response DTOs.
 *
 * @phpstan-type DecodedBody array<string, mixed>
 */
final class Transport
{
    private const MAX_ATTEMPTS = 2;

    public function __construct(
        private readonly Config $config,
        private readonly HeaderBuilder $headerBuilder,
        private readonly AccessTokenManager $accessTokenManager,
        private readonly BodyMinifier $bodyMinifier = new BodyMinifier(),
    ) {
    }

    /**
     * @param  array<string, mixed> $body
     * @return DecodedBody
     *
     * @throws ApiException     when the gateway returns a non-success response
     * @throws NetworkException for transport failures or unparseable responses
     */
    public function send(
        string $method,
        string $path,
        array $body,
        ?string $externalId = null,
    ): array {
        $minifiedBody = $this->bodyMinifier->encode($body);
        $attempt      = 1;

        while (true) {
            $accessToken = $this->accessTokenManager->get();
            $built       = $this->headerBuilder->transactionHeaders(
                method:       $method,
                path:         $path,
                accessToken:  $accessToken,
                minifiedBody: $minifiedBody,
                externalId:   $externalId,
            );

            $response = $this->dispatch($method, $path, $minifiedBody, $built['headers']);
            $decoded  = $this->decodeBody($response);

            $responseCode = is_string($decoded['responseCode'] ?? null) ? $decoded['responseCode'] : '';
            if (self::isSuccess($response->getStatusCode(), $responseCode)) {
                return $decoded;
            }

            $apiException = $this->buildApiException($response, $decoded);

            // Retry exactly once on an auth failure; everything else
            // propagates immediately (the side-effect may have happened).
            $canRetry = $attempt < self::MAX_ATTEMPTS
                && self::isAuthFailure($response->getStatusCode(), $responseCode);

            if (!$canRetry) {
                throw $apiException;
            }

            $this->accessTokenManager->invalidate();
            $attempt++;
        }
    }

    /**
     * @param  array<string, string> $headers
     */
    private function dispatch(
        string $method,
        string $path,
        string $minifiedBody,
        array $headers,
    ): ResponseInterface {
        $request = $this->config->requestFactory->createRequest(
            $method,
            $this->config->baseUrl() . $path,
        );
        $request = $request->withBody(
            $this->config->streamFactory->createStream($minifiedBody),
        );
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            return $this->config->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw ErrorMapper::fromClientException($e);
        }
    }

    /**
     * @return DecodedBody
     */
    private function decodeBody(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        if ($response->getBody()->isSeekable()) {
            $response->getBody()->rewind();
        }
        if ($body === '') {
            throw new NetworkException(sprintf(
                'malformed gateway response: HTTP %d, empty body',
                $response->getStatusCode(),
            ));
        }
        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new NetworkException(sprintf(
                'malformed gateway response: HTTP %d, body could not be JSON-decoded (%s)',
                $response->getStatusCode(),
                $e->getMessage(),
            ));
        }
        if (!is_array($decoded)) {
            throw new NetworkException('gateway response body was not a JSON object');
        }
        return $decoded;
    }

    /**
     * @param DecodedBody $decoded
     */
    private function buildApiException(ResponseInterface $response, array $decoded): ApiException
    {
        $code = is_string($decoded['responseCode'] ?? null) ? $decoded['responseCode'] : (string) $response->getStatusCode() . '00000';
        $msg  = is_string($decoded['responseMessage'] ?? null) ? $decoded['responseMessage'] : '';
        $ref  = is_string($decoded['referenceNo'] ?? null) ? $decoded['referenceNo'] : null;
        $par  = is_string($decoded['partnerReferenceNo'] ?? null) ? $decoded['partnerReferenceNo'] : null;

        return new ApiException(
            responseCode:       $code,
            responseMessage:    $msg,
            referenceNo:        $ref,
            partnerReferenceNo: $par,
        );
    }

    private static function isSuccess(int $httpStatus, string $responseCode): bool
    {
        if ($httpStatus < 200 || $httpStatus >= 300) {
            return false;
        }
        // Empty responseCode → defer to HTTP status only (best-effort).
        if ($responseCode === '') {
            return true;
        }
        return preg_match('/^2\d{6}$/', $responseCode) === 1;
    }

    private static function isAuthFailure(int $httpStatus, string $responseCode): bool
    {
        if ($httpStatus === 401) {
            return true;
        }
        // SNAP BI returns HTTP 200 with an in-body 4011xxx for token expiry.
        return preg_match('/^4011\d{3}$/', $responseCode) === 1;
    }
}
