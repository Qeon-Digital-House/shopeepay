<?php

declare(strict_types=1);

namespace ShopeePay\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Smoke test that proves the PHPUnit + autoload wiring is healthy.
 * Delete this once a real test class exists in tests/Unit.
 */
final class ScaffoldSmokeTest extends TestCase
{
    public function testPhpUnitIsWired(): void
    {
        self::assertTrue(true);
    }

    public function testFixtureKeyPairIsReadable(): void
    {
        $merchantPriv = file_get_contents(__DIR__ . '/../fixtures/merchant-private-test.pem');
        $merchantPub  = file_get_contents(__DIR__ . '/../fixtures/merchant-public-test.pem');

        self::assertNotFalse($merchantPriv);
        self::assertNotFalse($merchantPub);
        self::assertStringContainsString('BEGIN PRIVATE KEY', $merchantPriv);
        self::assertStringContainsString('BEGIN PUBLIC KEY', $merchantPub);
    }

    public function testFixtureKeyPairSignsAndVerifies(): void
    {
        $priv = openssl_pkey_get_private(
            (string) file_get_contents(__DIR__ . '/../fixtures/merchant-private-test.pem')
        );
        $pub = openssl_pkey_get_public(
            (string) file_get_contents(__DIR__ . '/../fixtures/merchant-public-test.pem')
        );

        self::assertNotFalse($priv, 'merchant private key must parse');
        self::assertNotFalse($pub, 'merchant public key must parse');

        $message = 'sample-client-id|2022-08-11T11:13:43+07:00';
        $signature = '';
        self::assertTrue(openssl_sign($message, $signature, $priv, OPENSSL_ALGO_SHA256));
        self::assertSame(1, openssl_verify($message, $signature, $pub, OPENSSL_ALGO_SHA256));
    }
}
