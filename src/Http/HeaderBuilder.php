<?php

declare(strict_types=1);

namespace ShopeePay\Http;

use DateTimeImmutable;
use DateTimeZone;
use ShopeePay\Config;

/**
 * Builds the two distinct header sets SNAP BI requires. Keeping them in one
 * class makes the timezone and CHANNEL-ID rules visible in one file.
 *
 *   Access token (POST /v1.0/access-token):
 *     X-CLIENT-KEY  : merchant clientId
 *     X-TIMESTAMP   : Y-m-d\TH:i:sP                 in Asia/Jakarta (seconds)
 *     X-SIGNATURE   : RSA-SHA256(clientKey|ts)      base64
 *     Content-Type  : application/json
 *
 *   Transaction:
 *     X-PARTNER-ID  : merchant clientId
 *     X-TIMESTAMP   : Y-m-d\TH:i:s.vP               in Asia/Jakarta (millis)
 *     X-SIGNATURE   : HMAC-SHA512 of stringToSign    base64
 *     X-EXTERNAL-ID : 32 hex chars (caller-supplied or auto)
 *     CHANNEL-ID    : 95221 (SNAP BI e-money default)
 *     Authorization : Bearer {accessToken}
 *     Content-Type  : application/json
 *
 * Time is injectable to keep the unit tests deterministic.
 */
final class HeaderBuilder
{
    private const TS_FORMAT_ACCESS_TOKEN = 'Y-m-d\TH:i:sP';
    private const TS_FORMAT_TRANSACTION  = 'Y-m-d\TH:i:s.vP';
    private const TIMEZONE               = 'Asia/Jakarta';

    public function __construct(
        private readonly Config $config,
        private readonly Signer $signer,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function accessTokenHeaders(?DateTimeImmutable $now = null): array
    {
        $timestamp = $this->formatTimestamp($now, self::TS_FORMAT_ACCESS_TOKEN);
        $signature = $this->signer->signAccessToken(
            $this->config->clientId,
            $timestamp,
            $this->config->privateKey,
        );

        return [
            'X-CLIENT-KEY' => $this->config->clientId,
            'X-TIMESTAMP'  => $timestamp,
            'X-SIGNATURE'  => $signature,
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * @return array{headers: array<string, string>, timestamp: string, externalId: string}
     */
    public function transactionHeaders(
        string $method,
        string $path,
        string $accessToken,
        string $minifiedBody,
        ?string $externalId = null,
        ?DateTimeImmutable $now = null,
    ): array {
        $timestamp  = $this->formatTimestamp($now, self::TS_FORMAT_TRANSACTION);
        $externalId = $externalId ?? self::generateExternalId();

        $signature = $this->signer->signTransaction(
            method:       $method,
            path:         $path,
            accessToken:  $accessToken,
            minifiedBody: $minifiedBody,
            timestamp:    $timestamp,
            clientSecret: $this->config->clientSecret,
        );

        return [
            'headers' => [
                'X-PARTNER-ID'  => $this->config->clientId,
                'X-TIMESTAMP'   => $timestamp,
                'X-SIGNATURE'   => $signature,
                'X-EXTERNAL-ID' => $externalId,
                'CHANNEL-ID'    => $this->config->channelId,
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type'  => 'application/json',
            ],
            'timestamp'  => $timestamp,
            'externalId' => $externalId,
        ];
    }

    /**
     * 32 lowercase hex chars. Comfortably under the SNAP BI 36-char ceiling
     * and unique-per-call with overwhelming probability.
     */
    public static function generateExternalId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function formatTimestamp(?DateTimeImmutable $now, string $format): string
    {
        $now = $now ?? new DateTimeImmutable('now');
        return $now->setTimezone(new DateTimeZone(self::TIMEZONE))->format($format);
    }
}
