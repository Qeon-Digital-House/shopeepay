<?php

declare(strict_types=1);

namespace ShopeePay\Tests\Unit\Dto\Common;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShopeePay\Dto\Common\Money;

final class MoneyTest extends TestCase
{
    public function testHappyPath(): void
    {
        $money = new Money('150000.00', 'IDR');

        self::assertSame('150000.00', $money->value);
        self::assertSame('IDR', $money->currency);
        self::assertSame(['value' => '150000.00', 'currency' => 'IDR'], $money->toArray());
    }

    public function testCurrencyDefaultsToIdr(): void
    {
        $money = new Money('1.00');

        self::assertSame('IDR', $money->currency);
    }

    public function testZeroIsAllowed(): void
    {
        // Zero-amount refunds and queries are real shapes.
        $money = new Money('0.00');

        self::assertSame('0.00', $money->value);
    }

    #[DataProvider('invalidValues')]
    public function testRejectsValueShapes(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Money value must be a string with exactly 2 decimal places');

        new Money($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidValues(): iterable
    {
        yield 'no decimals'         => ['150000'];
        yield 'one decimal place'   => ['150000.0'];
        yield 'three decimal places'=> ['150000.000'];
        yield 'comma separator'     => ['150,000.00'];
        yield 'negative'            => ['-150000.00'];
        yield 'scientific notation' => ['1.5e5'];
        yield 'plain decimal'       => ['.00'];
        yield 'empty'               => [''];
        yield 'whitespace'          => [' 150000.00'];
        yield 'non-numeric'         => ['abc'];
    }

    public function testRejectsNonIdrCurrency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only IDR is supported');

        new Money('1.00', 'USD');
    }

    public function testRejectsLowercaseIdr(): void
    {
        // SNAP BI codes are case-sensitive uppercase; we mirror that.
        $this->expectException(InvalidArgumentException::class);

        new Money('1.00', 'idr');
    }
}
