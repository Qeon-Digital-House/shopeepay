<?php

declare(strict_types=1);

namespace ShopeePay\Webhook;

use DateTimeImmutable;
use OpenSSLAsymmetricKey;
use ShopeePay\Config;
use ShopeePay\Exception\ConfigException;
use ShopeePay\Exception\SignatureException;
use ShopeePay\Exception\WebhookException;
use ShopeePay\Http\Signer;
use ShopeePay\Webhook\Event\Event;

/**
 * Verifies an incoming ShopeePay webhook and turns it into a typed Event.
 *
 * Lifecycle:
 *   1. Constructor parses the ShopeePay public key ONCE and holds the
 *      OpenSSLAsymmetricKey (eng-review decision #7 — saves ~0.1ms × N on
 *      bursty webhook delivery and turns a bad-key error into a boot-time
 *      ConfigException instead of a per-request SignatureException).
 *   2. parse() validates the replay window, verifies the RSA-SHA256
 *      signature via Signer, JSON-decodes the body, and dispatches via
 *      EventFactory.
 *
 * Caller responsibility — the RAW body string MUST be passed in. PSR-7
 * stream-based middleware that consumes the body before this is called
 * will break verification because the body-hash inside the stringToSign
 * is computed from the exact bytes ShopeePay sent.
 */
final class Verifier
{
    private readonly OpenSSLAsymmetricKey $publicKey;

    public function __construct(
        private readonly Config $config,
        private readonly Signer $signer,
        private readonly EventFactory $factory,
    ) {
        $key = openssl_pkey_get_public($config->shopeepayPublicKey);
        if ($key === false) {
            throw new ConfigException(
                'shopeepayPublicKey could not be parsed at boot — webhook ' .
                'verification will never succeed with this config.',
            );
        }
        $this->publicKey = $key;
    }

    /**
     * @throws SignatureException replay-window violation, signature mismatch,
     *                            or openssl error
     * @throws WebhookException   malformed body or unsupported method
     */
    public function parse(
        string $method,
        string $callbackPath,
        string $rawBody,
        string $base64Signature,
        string $timestamp,
        ?DateTimeImmutable $now = null,
    ): Event {
        if (strtoupper($method) !== 'POST') {
            throw new WebhookException(sprintf(
                'webhook method must be POST, got %s',
                json_encode($method),
            ));
        }

        $this->requireTimestampWithinReplayWindow($timestamp, $now);

        $verified = $this->signer->verifyWebhook(
            callbackPath:        $callbackPath,
            rawBody:             $rawBody,
            base64Signature:     $base64Signature,
            timestamp:           $timestamp,
            shopeepayPublicKey:  $this->publicKey,
        );

        if (!$verified) {
            throw new SignatureException(
                'webhook signature did not verify against the configured ShopeePay public key',
            );
        }

        try {
            $payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new WebhookException(
                'webhook body could not be JSON-decoded: ' . $e->getMessage(),
            );
        }
        if (!is_array($payload)) {
            throw new WebhookException('webhook body was not a JSON object');
        }

        return $this->factory->create($payload);
    }

    private function requireTimestampWithinReplayWindow(
        string $timestamp,
        ?DateTimeImmutable $now,
    ): void {
        $parsed = DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $timestamp);
        if ($parsed === false) {
            // Fall back to the constructor-style parser for the millisecond
            // variant — ATOM does not accept fractional seconds.
            try {
                $parsed = new DateTimeImmutable($timestamp);
            } catch (\Exception) {
                throw new SignatureException(sprintf(
                    'webhook timestamp could not be parsed: %s',
                    json_encode($timestamp),
                ));
            }
        }

        $now = $now ?? new DateTimeImmutable('now');
        $diff = abs($now->getTimestamp() - $parsed->getTimestamp());

        if ($diff > $this->config->webhookReplayWindowSeconds) {
            throw new SignatureException(sprintf(
                'webhook timestamp is outside the %d-second replay window (drift: %ds)',
                $this->config->webhookReplayWindowSeconds,
                $diff,
            ));
        }
    }
}
