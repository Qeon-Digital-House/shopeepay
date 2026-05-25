<?php

declare(strict_types=1);

namespace ShopeePay;

enum Environment: string
{
    case SANDBOX    = 'sandbox';
    case PRODUCTION = 'production';

    public function baseUrl(): string
    {
        return match ($this) {
            self::SANDBOX    => 'https://api.snap.uat.airpay.co.id',
            self::PRODUCTION => 'https://api.snap.airpay.co.id',
        };
    }
}
