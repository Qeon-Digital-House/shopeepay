<?php

declare(strict_types=1);

namespace ShopeePay\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShopeePay\Exception\ConfigException;
use ShopeePay\Http\StatusCode;

final class StatusCodeTest extends TestCase
{
    public function testParsesSuccessFromGetAuthCode(): void
    {
        $sc = StatusCode::parse('2001000');

        self::assertSame(200, $sc->http);
        self::assertSame(10, $sc->service);
        self::assertSame(0, $sc->sub);
        self::assertTrue($sc->isSuccess());
        self::assertFalse($sc->isAuthFailure());
    }

    public function testParsesCreatePaymentSuccess(): void
    {
        $sc = StatusCode::parse('2005400');

        self::assertSame(200, $sc->http);
        self::assertSame(54, $sc->service);
        self::assertTrue($sc->isSuccess());
    }

    public function testRecognizesAuthFailure(): void
    {
        $sc = StatusCode::parse('4011000');

        self::assertSame(401, $sc->http);
        self::assertSame(10, $sc->service);
        self::assertFalse($sc->isSuccess());
        self::assertTrue($sc->isAuthFailure());
    }

    public function testNonAuthFailureIsNotMistakenForOne(): void
    {
        // Generic 400 on Create Payment — not a token problem.
        $sc = StatusCode::parse('4005400');

        self::assertFalse($sc->isSuccess());
        self::assertFalse($sc->isAuthFailure());
    }

    #[DataProvider('malformedCodes')]
    public function testRejectsMalformedCodes(string $code): void
    {
        $this->expectException(ConfigException::class);
        StatusCode::parse($code);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedCodes(): iterable
    {
        yield 'empty'        => [''];
        yield 'too short'    => ['200100'];
        yield 'too long'     => ['20010000'];
        yield 'non-digit'    => ['200100A'];
        yield 'with spaces'  => ['200 1000'];
    }
}
