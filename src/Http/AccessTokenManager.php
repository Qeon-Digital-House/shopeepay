<?php

declare(strict_types=1);

namespace ShopeePay\Http;

use Psr\SimpleCache\InvalidArgumentException as CacheInvalidArgumentException;
use ShopeePay\Config;
use ShopeePay\Exception\ApiException;
use ShopeePay\Exception\ConfigException;
use ShopeePay\Exception\NetworkException;

/**
 * Caches the merchant's SNAP BI access token in PSR-16 and refreshes it
 * on miss. Lifecycle:
 *
 *   1. caller asks for a token
 *   2. cache hit → return cached value, no network
 *   3. cache miss → POST /v1.0/access-token, cache result with TTL from
 *      Config (default 840s = 14 min; the design notes this is tentative
 *      until a sandbox probe confirms the real ShopeePay token lifetime)
 *   4. on a 401 or in-body 4011xxx during a transaction, Transport calls
 *      invalidate() and tries one fresh refresh, then bubbles ApiException
 *      if THAT also fails
 *
 * Concurrent-refresh race is accepted for v1: two callers can both miss
 * the cache at the same instant and both refresh. Both refreshes are
 * idempotent on ShopeePay's side and the last write wins. Revisit if
 * production traffic surfaces rate-limiting on /access-token.
 *
 * Note: this class does not own Signer or HeaderBuilder by composition;
 * they are injected so tests can swap them.
 */
final class AccessTokenManager
{
    private const PATH                 = '/v1.0/access-token';
    private const GRANT_TYPE_BODY      = '{"grantType":"client_credentials"}';
    private const CACHE_KEY_PREFIX     = 'shopeepay.access_token';

    public function __construct(
        private readonly Config $config,
        private readonly HeaderBuilder $headerBuilder,
    ) {
    }

    /**
     * Returns a usable access token, fetching from cache or refreshing as
     * needed.
     *
     * @throws ApiException     when the gateway rejects the refresh
     * @throws NetworkException when the transport itself fails
     * @throws ConfigException  when the cache itself blows up (rare)
     */
    public function get(): string
    {
        $cached = $this->cacheGet();
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->refresh();
    }

    public function invalidate(): void
    {
        try {
            $this->config->cache->delete($this->cacheKey());
        } catch (CacheInvalidArgumentException $e) {
            // Cache key is fixed and known-valid; this would only fire on
            // a broken cache implementation. Don't let it mask the real
            // failure (the auth retry that triggered the invalidate).
            $this->config->logger?->warning(
                'shopeepay: cache delete failed during invalidate(): ' . $e->getMessage(),
            );
        }
    }

    public function refresh(): string
    {
        $request = $this->config->requestFactory
            ->createRequest('POST', $this->config->baseUrl() . self::PATH)
            ->withBody($this->config->streamFactory->createStream(self::GRANT_TYPE_BODY));

        foreach ($this->headerBuilder->accessTokenHeaders() as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            $response = $this->config->httpClient->sendRequest($request);
        } catch (\Psr\Http\Client\ClientExceptionInterface $e) {
            throw ErrorMapper::fromClientException($e);
        }

        $bodyString = (string) $response->getBody();
        try {
            $decoded = json_decode($bodyString, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new NetworkException(
                'malformed access-token response: ' . $e->getMessage(),
            );
        }

        if (!is_array($decoded)) {
            throw new NetworkException('access-token response was not a JSON object');
        }

        // SNAP BI's /access-token uses responseCode 2007300 for success.
        $responseCode    = is_string($decoded['responseCode'] ?? null) ? $decoded['responseCode']    : '';
        $responseMessage = is_string($decoded['responseMessage'] ?? null) ? $decoded['responseMessage'] : '';
        $accessToken     = is_string($decoded['accessToken'] ?? null) ? $decoded['accessToken']     : null;

        $codeOk  = self::isSuccessResponseCode($responseCode);
        $tokenOk = $accessToken !== null && $accessToken !== '';

        if (!$codeOk) {
            // Real gateway rejection — surface the gateway's own code/message.
            throw new ApiException(
                responseCode:    $responseCode === '' ? (string) $response->getStatusCode() . '00000' : $responseCode,
                responseMessage: $responseMessage !== '' ? $responseMessage : 'access-token request failed',
            );
        }

        if (!$tokenOk) {
            // Gateway said success but did not return a usable token. Defensive
            // guard — should be impossible in practice; if it fires, something
            // upstream changed silently.
            throw new ApiException(
                responseCode:    $responseCode,
                responseMessage: 'access-token response missing accessToken',
            );
        }

        $this->cachePut($accessToken);

        return $accessToken;
    }

    private function cacheKey(): string
    {
        return sprintf(
            '%s.%s.%s',
            self::CACHE_KEY_PREFIX,
            $this->config->environment->value,
            $this->config->clientId,
        );
    }

    private function cacheGet(): ?string
    {
        try {
            $value = $this->config->cache->get($this->cacheKey());
        } catch (CacheInvalidArgumentException) {
            return null;
        }
        return is_string($value) ? $value : null;
    }

    private function cachePut(string $token): void
    {
        try {
            $this->config->cache->set(
                $this->cacheKey(),
                $token,
                $this->config->tokenTtlSeconds,
            );
        } catch (CacheInvalidArgumentException $e) {
            // Same rationale as invalidate(): never let a cache wobble
            // mask the actual auth result. Log + continue; next call
            // will just refresh again.
            $this->config->logger?->warning(
                'shopeepay: cache write failed during refresh(): ' . $e->getMessage(),
            );
        }
    }

    private static function isSuccessResponseCode(string $code): bool
    {
        return $code !== '' && preg_match('/^2\d{6}$/', $code) === 1;
    }
}
