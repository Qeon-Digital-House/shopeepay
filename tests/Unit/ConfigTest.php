<?php

declare(strict_types=1);

namespace ShopeePay\Tests\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use ShopeePay\Config;
use ShopeePay\Environment;
use ShopeePay\Exception\ConfigException;

final class ConfigTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures';

    public function testValidConfigSandboxDefaults(): void
    {
        $config = $this->buildConfig();

        self::assertSame('client-x', $config->clientId);
        self::assertSame('M1234', $config->merchantId);
        self::assertNull($config->storeId);
        self::assertSame('95221', $config->channelId);
        self::assertSame(Environment::SANDBOX, $config->environment);
        self::assertSame('https://api.snap.uat.airpay.co.id', $config->baseUrl());
        self::assertSame(840, $config->tokenTtlSeconds);
        self::assertSame(300, $config->webhookReplayWindowSeconds);
    }

    public function testProductionEnvironmentSwitchesBaseUrl(): void
    {
        $config = $this->buildConfig(environment: Environment::PRODUCTION);
        self::assertSame('https://api.snap.airpay.co.id', $config->baseUrl());
    }

    public function testStoreIdAndCustomTtlsArePropagated(): void
    {
        $config = $this->buildConfig(
            storeId: 'STORE-7',
            tokenTtlSeconds: 600,
            webhookReplayWindowSeconds: 60,
        );

        self::assertSame('STORE-7', $config->storeId);
        self::assertSame(600, $config->tokenTtlSeconds);
        self::assertSame(60, $config->webhookReplayWindowSeconds);
    }

    public function testRejectsEmptyClientId(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('clientId must not be empty');
        $this->buildConfig(clientId: '');
    }

    public function testRejectsBlankWhitespaceMerchantId(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('merchantId must not be empty');
        $this->buildConfig(merchantId: '   ');
    }

    public function testRejectsPrivateKeyThatLooksLikeAFilePath(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('must be a PEM string');
        $this->buildConfig(privateKey: '/path/to/merchant-private.pem');
    }

    public function testRejectsUnparseablePrivateKey(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('could not be parsed by openssl');
        $this->buildConfig(
            privateKey: "-----BEGIN PRIVATE KEY-----\ngarbage\n-----END PRIVATE KEY-----",
        );
    }

    public function testRejectsUnparseablePublicKey(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('shopeepayPublicKey could not be parsed');
        $this->buildConfig(
            shopeepayPublicKey: "-----BEGIN PUBLIC KEY-----\ngarbage\n-----END PUBLIC KEY-----",
        );
    }

    public function testRejectsTooLowTokenTtl(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('tokenTtlSeconds must be at least 60');
        $this->buildConfig(tokenTtlSeconds: 30);
    }

    public function testRejectsZeroReplayWindow(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('webhookReplayWindowSeconds must be positive');
        $this->buildConfig(webhookReplayWindowSeconds: 0);
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    private function buildConfig(
        string $clientId = 'client-x',
        string $clientSecret = 'secret-x',
        ?string $privateKey = null,
        ?string $shopeepayPublicKey = null,
        string $merchantId = 'M1234',
        ?string $storeId = null,
        string $channelId = '95221',
        Environment $environment = Environment::SANDBOX,
        int $tokenTtlSeconds = 840,
        int $webhookReplayWindowSeconds = 300,
    ): Config {
        $psr17       = new Psr17Factory();
        $httpClient  = $this->createMock(ClientInterface::class);
        $cache       = $this->createMock(CacheInterface::class);
        $logger      = $this->createMock(LoggerInterface::class);

        return new Config(
            clientId:                   $clientId,
            clientSecret:               $clientSecret,
            privateKey:                 $privateKey         ?? (string) file_get_contents(self::FIXTURES . '/merchant-private-test.pem'),
            shopeepayPublicKey:         $shopeepayPublicKey ?? (string) file_get_contents(self::FIXTURES . '/shopeepay-public-test.pem'),
            merchantId:                 $merchantId,
            httpClient:                 $httpClient,
            requestFactory:             $psr17,
            streamFactory:              $psr17,
            cache:                      $cache,
            environment:                $environment,
            storeId:                    $storeId,
            channelId:                  $channelId,
            tokenTtlSeconds:            $tokenTtlSeconds,
            webhookReplayWindowSeconds: $webhookReplayWindowSeconds,
            logger:                     $logger,
        );
    }
}
