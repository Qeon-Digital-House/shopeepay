<?php

declare(strict_types=1);

namespace ShopeePay;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use ShopeePay\Exception\ConfigException;

/**
 * Per-merchant configuration for the SDK. Everything the kernel needs to
 * sign, send, cache, and log — wired via PSR interfaces so the SDK does not
 * pin a transport, cache, or logger implementation.
 *
 * Keys are PEM strings, not file paths. Containerised deploys can read them
 * from secret-manager env vars without materialising files on disk; callers
 * who DO have files should `file_get_contents()` themselves.
 *
 * Token TTL and the webhook replay window are tunable. Defaults match the
 * design doc:
 *   - tokenTtlSeconds:           840  (14 min — tentative; sandbox probe confirms)
 *   - webhookReplayWindowSeconds: 300  (5 min — generous for clock drift, tight
 *                                       enough to deny replay)
 */
final class Config
{
    public readonly string $clientId;
    public readonly string $clientSecret;
    public readonly string $privateKey;
    public readonly string $shopeepayPublicKey;
    public readonly string $merchantId;
    public readonly ?string $storeId;
    public readonly string $channelId;
    public readonly Environment $environment;
    public readonly int $tokenTtlSeconds;
    public readonly int $webhookReplayWindowSeconds;
    public readonly ClientInterface $httpClient;
    public readonly RequestFactoryInterface $requestFactory;
    public readonly StreamFactoryInterface $streamFactory;
    public readonly CacheInterface $cache;
    public readonly ?LoggerInterface $logger;

    public function __construct(
        string $clientId,
        string $clientSecret,
        string $privateKey,
        string $shopeepayPublicKey,
        string $merchantId,
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        CacheInterface $cache,
        Environment $environment = Environment::SANDBOX,
        ?string $storeId = null,
        string $channelId = '95221',
        int $tokenTtlSeconds = 840,
        int $webhookReplayWindowSeconds = 300,
        ?LoggerInterface $logger = null,
    ) {
        $this->requireNonEmpty($clientId, 'clientId');
        $this->requireNonEmpty($clientSecret, 'clientSecret');
        $this->requireNonEmpty($merchantId, 'merchantId');
        $this->requireNonEmpty($channelId, 'channelId');

        $this->requireValidPrivateKey($privateKey);
        $this->requireValidPublicKey($shopeepayPublicKey);

        if ($tokenTtlSeconds < 60) {
            throw new ConfigException(sprintf(
                'tokenTtlSeconds must be at least 60, got %d',
                $tokenTtlSeconds,
            ));
        }
        if ($webhookReplayWindowSeconds < 1) {
            throw new ConfigException(sprintf(
                'webhookReplayWindowSeconds must be positive, got %d',
                $webhookReplayWindowSeconds,
            ));
        }

        $this->clientId                   = $clientId;
        $this->clientSecret               = $clientSecret;
        $this->privateKey                 = $privateKey;
        $this->shopeepayPublicKey         = $shopeepayPublicKey;
        $this->merchantId                 = $merchantId;
        $this->storeId                    = $storeId;
        $this->channelId                  = $channelId;
        $this->environment                = $environment;
        $this->tokenTtlSeconds            = $tokenTtlSeconds;
        $this->webhookReplayWindowSeconds = $webhookReplayWindowSeconds;
        $this->httpClient                 = $httpClient;
        $this->requestFactory             = $requestFactory;
        $this->streamFactory              = $streamFactory;
        $this->cache                      = $cache;
        $this->logger                     = $logger;
    }

    public function baseUrl(): string
    {
        return $this->environment->baseUrl();
    }

    private function requireNonEmpty(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new ConfigException("$field must not be empty");
        }
    }

    private function requireValidPrivateKey(string $pem): void
    {
        if (!str_contains($pem, '-----BEGIN')) {
            throw new ConfigException(
                'privateKey must be a PEM string (-----BEGIN ...-----), not a file path',
            );
        }
        if (openssl_pkey_get_private($pem) === false) {
            throw new ConfigException(
                'privateKey could not be parsed by openssl: ' . $this->drainOpenSslErrors(),
            );
        }
    }

    private function requireValidPublicKey(string $pem): void
    {
        if (!str_contains($pem, '-----BEGIN')) {
            throw new ConfigException(
                'shopeepayPublicKey must be a PEM string (-----BEGIN ...-----), not a file path',
            );
        }
        if (openssl_pkey_get_public($pem) === false) {
            throw new ConfigException(
                'shopeepayPublicKey could not be parsed by openssl: ' . $this->drainOpenSslErrors(),
            );
        }
    }

    private function drainOpenSslErrors(): string
    {
        $msgs = [];
        while (($err = openssl_error_string()) !== false) {
            $msgs[] = $err;
        }
        return $msgs === [] ? '(no openssl error reported)' : implode('; ', $msgs);
    }
}
