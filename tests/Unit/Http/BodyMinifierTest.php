<?php

declare(strict_types=1);

namespace ShopeePay\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use ShopeePay\Http\BodyMinifier;

final class BodyMinifierTest extends TestCase
{
    public function testEncodesSlashesLiterally(): void
    {
        $body = [
            'partnerReferenceNo' => 'ORDER-1',
            'redirectUrl'        => 'https://merchant.example/return',
        ];

        $json = (new BodyMinifier())->encode($body);

        self::assertStringContainsString('https://merchant.example/return', $json);
        self::assertStringNotContainsString('https:\/\/', $json);
    }

    public function testEncodesUnicodeLiterally(): void
    {
        $body = ['storeName' => 'Toko Setia Budi — Jakarta'];

        $json = (new BodyMinifier())->encode($body);

        // Em-dash should stay as literal UTF-8 in the output (3 bytes, E2 80 94),
        // NOT as the ASCII escape sequence — that default json_encode emits.
        self::assertSame('{"storeName":"Toko Setia Budi — Jakarta"}', $json);
        self::assertStringNotContainsString('\\u2014', $json);
    }

    public function testEncodesMatchesKnownVectorForHmacStringToSign(): void
    {
        // The body shape from the design doc's Vector 2.
        $body = [
            'partnerReferenceNo' => 'ORDER-1',
            'amount' => [
                'value'    => '150000.00',
                'currency' => 'IDR',
            ],
        ];

        $expected = '{"partnerReferenceNo":"ORDER-1","amount":{"value":"150000.00","currency":"IDR"}}';

        self::assertSame($expected, (new BodyMinifier())->encode($body));
    }

    public function testBodyHashIsLowercaseHexSha256(): void
    {
        $minifier = new BodyMinifier();
        $minified = $minifier->encode(['x' => 1]);

        $hash = $minifier->bodyHash($minified);

        self::assertSame(64, strlen($hash));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        self::assertSame(hash('sha256', '{"x":1}'), $hash);
    }
}
