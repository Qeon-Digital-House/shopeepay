<?php

declare(strict_types=1);

namespace ShopeePay\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use ShopeePay\Http\LogScrubber;

final class LogScrubberTest extends TestCase
{
    public function testRedactsAccessTokenAndSignature(): void
    {
        $scrubbed = (new LogScrubber())->scrub([
            'method'       => 'POST',
            'accessToken'  => 'eyJhbGciOiJIUzI1NiJ9.payload.sig',
            'X-SIGNATURE'  => 'base64-blob==',
        ]);

        self::assertSame('POST', $scrubbed['method']);
        self::assertSame('[REDACTED:32]', $scrubbed['accessToken']);
        self::assertSame('[REDACTED:13]', $scrubbed['X-SIGNATURE']);
    }

    public function testRedactsByLowercasedKey(): void
    {
        $scrubbed = (new LogScrubber())->scrub([
            'accountToken'  => 'acct-abc',
            'AccountToken'  => 'acct-def',
            'ACCOUNT_TOKEN' => 'acct-ghi',
        ]);

        self::assertSame('[REDACTED:8]', $scrubbed['accountToken']);
        self::assertSame('[REDACTED:8]', $scrubbed['AccountToken']);
        self::assertSame('[REDACTED:8]', $scrubbed['ACCOUNT_TOKEN']);
    }

    public function testRedactsPemRegardlessOfFieldName(): void
    {
        $pem = "-----BEGIN PRIVATE KEY-----\nMIIEvQI...\n-----END PRIVATE KEY-----";

        $scrubbed = (new LogScrubber())->scrub([
            'note' => $pem,
        ]);

        self::assertStringStartsWith('[REDACTED:', $scrubbed['note']);
    }

    public function testRecursesIntoNestedArrays(): void
    {
        $scrubbed = (new LogScrubber())->scrub([
            'headers' => [
                'X-Signature'  => 'abc',
                'Content-Type' => 'application/json',
            ],
            'body'    => [
                'mobileNumber' => '081234567890',
                'amount'       => 150000,
            ],
        ]);

        self::assertSame('[REDACTED:3]', $scrubbed['headers']['X-Signature']);
        self::assertSame('application/json', $scrubbed['headers']['Content-Type']);
        self::assertSame('[REDACTED:12]', $scrubbed['body']['mobileNumber']);
        self::assertSame(150000, $scrubbed['body']['amount']);
    }

    public function testLeavesUnrelatedKeysUntouched(): void
    {
        $scrubbed = (new LogScrubber())->scrub([
            'partnerReferenceNo' => 'ORDER-42',
            'responseCode'       => '2005400',
        ]);

        self::assertSame('ORDER-42', $scrubbed['partnerReferenceNo']);
        self::assertSame('2005400', $scrubbed['responseCode']);
    }
}
