<?php

declare(strict_types=1);

namespace ShopeePay\Dto\Common;

use InvalidArgumentException;

/**
 * The "amount" object that goes into every transaction request.
 *
 *   { "value": "150000.00", "currency": "IDR" }
 *
 * Value is a STRING, not a float — float arithmetic on currency is a well
 * documented foot-gun, and SNAP BI rejects payloads where the value comes
 * across the wire as a number. Two decimal places are mandatory; "150000",
 * "150000.0", and "150000.000" all raise InvalidArgumentException.
 *
 * Only IDR is supported in v1. Multi-currency will be additive — the right
 * place to extend this is a Currency enum, not a string check.
 */
final class Money
{
    private const VALID_VALUE_PATTERN = '/^\d+\.\d{2}$/';
    private const SUPPORTED_CURRENCY  = 'IDR';

    public readonly string $value;
    public readonly string $currency;

    public function __construct(string $value, string $currency = self::SUPPORTED_CURRENCY)
    {
        if (!preg_match(self::VALID_VALUE_PATTERN, $value)) {
            throw new InvalidArgumentException(sprintf(
                'Money value must be a string with exactly 2 decimal places (e.g. "150000.00"), got %s',
                json_encode($value),
            ));
        }

        if ($currency !== self::SUPPORTED_CURRENCY) {
            throw new InvalidArgumentException(sprintf(
                'Only IDR is supported in v1, got %s',
                json_encode($currency),
            ));
        }

        $this->value    = $value;
        $this->currency = $currency;
    }

    /**
     * @return array{value: string, currency: string}
     */
    public function toArray(): array
    {
        return [
            'value'    => $this->value,
            'currency' => $this->currency,
        ];
    }
}
