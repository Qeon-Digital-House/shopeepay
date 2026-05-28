<?php

declare(strict_types=1);

namespace ShopeePay\Tests\Unit\Http;

use DateTimeImmutable;
use DateTimeZone;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\SimpleCache\CacheInterface;
use ShopeePay\Config;
use ShopeePay\Environment;
use ShopeePay\Http\HeaderBuilder;
use ShopeePay\Http\Signer;

final class HeaderBuilderTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures';

    public function testAccessTokenHeadersUseSecondPrecisionInJakarta(): void
    {
        $builder = $this->builder();
        // Provide UTC time — the builder must convert to Asia/Jakarta (+07:00).
        $now = new DateTimeImmutable('2026-05-25T04:13:43.999000+00:00');

        $headers = $builder->accessTokenHeaders($now);

        self::assertSame('client-x', $headers['X-CLIENT-KEY']);
        // 04:13:43 UTC → 11:13:43 +07:00, NO milliseconds.
        self::assertSame('2026-05-25T11:13:43+07:00', $headers['X-TIMESTAMP']);
        self::assertSame('application/json', $headers['Content-Type']);
        self::assertNotEmpty($headers['X-SIGNATURE']);
    }

    public function testTransactionHeadersUseMillisInJakartaAndAutoGenerateExternalId(): void
    {
        $builder = $this->builder();
        $now = new DateTimeImmutable('2026-05-25T04:13:43.123456+00:00');

        $result = $builder->transactionHeaders(
            method:       'POST',
            path:         '/v1.1/debit/payment-host-to-host',
            accessToken:  'tk',
            minifiedBody: '{}',
            now:          $now,
        );

        $h = $result['headers'];
        self::assertSame('client-x', $h['X-PARTNER-ID']);
        // 04:13:43.123 UTC → 11:13:43.123 +07:00 (millis preserved).
        self::assertSame('2026-05-25T11:13:43.123+07:00', $h['X-TIMESTAMP']);
        self::assertSame('Bearer tk', $h['Authorization']);
        self::assertSame('95221', $h['CHANNEL-ID']);
        self::assertSame('application/json', $h['Content-Type']);
        self::assertNotEmpty($h['X-SIGNATURE']);

        // Auto-generated external ID is 32 lowercase hex chars.
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $h['X-EXTERNAL-ID']);
        self::assertSame($h['X-EXTERNAL-ID'], $result['externalId']);
    }

    public function testTransactionHeadersAcceptCallerSuppliedExternalId(): void
    {
        $builder = $this->builder();

        $result = $builder->transactionHeaders(
            method:       'POST',
            path:         '/v1.1/debit/payment-host-to-host',
            accessToken:  'tk',
            minifiedBody: '{}',
            externalId:   'order-2026-abc-001',
            now:          new DateTimeImmutable('2026-05-25T11:13:43.123', new DateTimeZone('Asia/Jakarta')),
        );

        self::assertSame('order-2026-abc-001', $result['headers']['X-EXTERNAL-ID']);
    }

    public function testGenerateExternalIdIsAlwaysUnique(): void
    {
        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $ids[] = HeaderBuilder::generateExternalId();
        }
        self::assertCount(100, array_unique($ids));
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    private function builder(): HeaderBuilder
    {
        $psr17 = new Psr17Factory();
        $httpClient = $this->createMock(ClientInterface::class);
        $cache      = $this->createMock(CacheInterface::class);

        $config = new Config(
            clientId:                   'client-x',
            clientSecret:               'secret-x',
            privateKey:                 (string) file_get_contents(self::FIXTURES . '/merchant-private-test.pem'),
            shopeepayPublicKey:         (string) file_get_contents(self::FIXTURES . '/shopeepay-public-test.pem'),
            merchantId:                 'M1234',
            httpClient:                 $httpClient,
            requestFactory:             $psr17,
            streamFactory:              $psr17,
            cache:                      $cache,
            environment:                Environment::SANDBOX,
        );

        return new HeaderBuilder($config, new Signer());
    }
}
