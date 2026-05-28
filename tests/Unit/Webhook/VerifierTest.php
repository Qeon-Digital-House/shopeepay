<?php

declare(strict_types=1);

namespace ShopeePay\Tests\Unit\Webhook;

use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use ShopeePay\Config;
use ShopeePay\Environment;
use ShopeePay\Exception\ConfigException;
use ShopeePay\Exception\SignatureException;
use ShopeePay\Exception\WebhookException;
use ShopeePay\Http\Signer;
use ShopeePay\Webhook\Event\PaymentCompleted;
use ShopeePay\Webhook\Event\RefundCompleted;
use ShopeePay\Webhook\Event\UnknownEvent;
use ShopeePay\Webhook\EventFactory;
use ShopeePay\Webhook\Verifier;

/**
 * End-to-end-ish tests for Verifier: real RSA signing with the fixture
 * ShopeePay private key, real verification against the fixture public key,
 * then dispatch via the real EventFactory. The only thing mocked is the
 * caller's PSR-3 logger / PSR-16 cache (held inside Config but unused here).
 */
final class VerifierTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures';

    public function testParsesValidWebhookIntoTypedEvent(): void
    {
        $rawBody      = '{"originalPartnerReferenceNo":"ORDER-9","serviceCode":"56","latestTransactionStatus":"00","transactionStatusDesc":"Success","originalReferenceNo":"SP-X"}';
        $callbackPath = '/merchant/webhook/shopeepay';
        $timestamp    = '2026-05-25T10:00:00+07:00';

        $verifier = $this->buildVerifier();
        $sig      = $this->signWebhook($callbackPath, $rawBody, $timestamp);

        $event = $verifier->parse(
            method:          'POST',
            callbackPath:    $callbackPath,
            rawBody:         $rawBody,
            base64Signature: $sig,
            timestamp:       $timestamp,
            now:             new DateTimeImmutable($timestamp),
        );

        self::assertInstanceOf(PaymentCompleted::class, $event);
        self::assertSame('SP-X', $event->originalReferenceNo);
        self::assertSame('ORDER-9', $event->originalPartnerReferenceNo);
    }

    public function testParseRoutesRefundShapedNotifyToRefundCompleted(): void
    {
        $rawBody      = '{"serviceCode":"56","latestTransactionStatus":"00","refundReferenceNo":"SP-REFUND-1"}';
        $callbackPath = '/merchant/webhook/shopeepay';
        $timestamp    = '2026-05-25T10:00:00+07:00';

        $verifier = $this->buildVerifier();
        $sig      = $this->signWebhook($callbackPath, $rawBody, $timestamp);

        $event = $verifier->parse(
            method:          'POST',
            callbackPath:    $callbackPath,
            rawBody:         $rawBody,
            base64Signature: $sig,
            timestamp:       $timestamp,
            now:             new DateTimeImmutable($timestamp),
        );

        self::assertInstanceOf(RefundCompleted::class, $event);
    }

    public function testRejectsTimestampOutsideReplayWindow(): void
    {
        $rawBody      = '{"serviceCode":"56","latestTransactionStatus":"00"}';
        $callbackPath = '/merchant/webhook/shopeepay';
        $timestamp    = '2026-05-25T10:00:00+07:00';

        $verifier = $this->buildVerifier();
        $sig      = $this->signWebhook($callbackPath, $rawBody, $timestamp);

        // 'now' is 10 minutes after the signing timestamp — outside the 300s
        // default replay window. No signature work happens.
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('outside the 300-second replay window');

        $verifier->parse(
            method:          'POST',
            callbackPath:    $callbackPath,
            rawBody:         $rawBody,
            base64Signature: $sig,
            timestamp:       $timestamp,
            now:             new DateTimeImmutable('2026-05-25T10:10:00+07:00'),
        );
    }

    public function testRejectsBadSignature(): void
    {
        $rawBody      = '{"serviceCode":"56","latestTransactionStatus":"00"}';
        $callbackPath = '/merchant/webhook/shopeepay';
        $timestamp    = '2026-05-25T10:00:00+07:00';

        $verifier = $this->buildVerifier();
        // Sign a DIFFERENT body, then submit the original — the body-hash
        // inside stringToSign won't match.
        $otherBody = '{"serviceCode":"56","latestTransactionStatus":"00","tampered":true}';
        $sig       = $this->signWebhook($callbackPath, $otherBody, $timestamp);

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('signature did not verify');

        $verifier->parse(
            method:          'POST',
            callbackPath:    $callbackPath,
            rawBody:         $rawBody,
            base64Signature: $sig,
            timestamp:       $timestamp,
            now:             new DateTimeImmutable($timestamp),
        );
    }

    public function testRejectsNonPostMethod(): void
    {
        $verifier = $this->buildVerifier();

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('webhook method must be POST');

        $verifier->parse(
            method:          'GET',
            callbackPath:    '/merchant/webhook/shopeepay',
            rawBody:         '{}',
            base64Signature: '',
            timestamp:       '2026-05-25T10:00:00+07:00',
            now:             new DateTimeImmutable('2026-05-25T10:00:00+07:00'),
        );
    }

    public function testRejectsMalformedJsonAfterValidSignature(): void
    {
        // Sign a malformed body — the signature is still valid, but JSON
        // decoding fails. Maps to WebhookException, not SignatureException,
        // because the cryptographic guarantee held; ShopeePay sent us garbage.
        $rawBody      = '{not valid json';
        $callbackPath = '/merchant/webhook/shopeepay';
        $timestamp    = '2026-05-25T10:00:00+07:00';

        $verifier = $this->buildVerifier();
        $sig      = $this->signWebhook($callbackPath, $rawBody, $timestamp);

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('could not be JSON-decoded');

        $verifier->parse(
            method:          'POST',
            callbackPath:    $callbackPath,
            rawBody:         $rawBody,
            base64Signature: $sig,
            timestamp:       $timestamp,
            now:             new DateTimeImmutable($timestamp),
        );
    }

    public function testRejectsNonObjectJsonBody(): void
    {
        $rawBody      = '"a string, not an object"';
        $callbackPath = '/merchant/webhook/shopeepay';
        $timestamp    = '2026-05-25T10:00:00+07:00';

        $verifier = $this->buildVerifier();
        $sig      = $this->signWebhook($callbackPath, $rawBody, $timestamp);

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('not a JSON object');

        $verifier->parse(
            method:          'POST',
            callbackPath:    $callbackPath,
            rawBody:         $rawBody,
            base64Signature: $sig,
            timestamp:       $timestamp,
            now:             new DateTimeImmutable($timestamp),
        );
    }

    public function testConstructorRejectsUnparseablePublicKey(): void
    {
        // Bypass Config's own PEM check by stubbing the property.
        // Config validates `shopeepayPublicKey` at boot, so the only way for
        // Verifier to see a broken key is if Config was bypassed (e.g. tests).
        // Verifier still defends in depth.
        $config = $this->buildConfig();
        $brokenConfig = (new \ReflectionClass($config))->newInstanceWithoutConstructor();
        foreach (get_object_vars($config) as $name => $value) {
            $prop = new \ReflectionProperty($config, $name);
            $prop->setValue($brokenConfig, $name === 'shopeepayPublicKey' ? 'not a key' : $value);
        }

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('webhook verification will never succeed');

        new Verifier($brokenConfig, new Signer(), new EventFactory());
    }

    public function testParseAcceptsMillisecondTimestampFormat(): void
    {
        // SNAP BI transaction timestamps include millis (`Y-m-d\TH:i:s.vP`).
        // ATOM::createFromFormat doesn't accept fractional seconds, so the
        // verifier falls back to the constructor parser — covered here.
        $rawBody      = '{"serviceCode":"56","latestTransactionStatus":"00"}';
        $callbackPath = '/merchant/webhook/shopeepay';
        $timestamp    = '2026-05-25T10:00:00.123+07:00';

        $verifier = $this->buildVerifier();
        $sig      = $this->signWebhook($callbackPath, $rawBody, $timestamp);

        $event = $verifier->parse(
            method:          'POST',
            callbackPath:    $callbackPath,
            rawBody:         $rawBody,
            base64Signature: $sig,
            timestamp:       $timestamp,
            now:             new DateTimeImmutable('2026-05-25T10:00:00+07:00'),
        );

        self::assertInstanceOf(PaymentCompleted::class, $event);
    }

    public function testUnknownServiceCodeStillProducesUnknownEvent(): void
    {
        // The signature is valid, the body parses, but the service code is
        // not one we recognize. The Verifier's job ends at "parse a typed
        // event"; the EventFactory decides this is UnknownEvent.
        $rawBody      = '{"serviceCode":"77","latestTransactionStatus":"00"}';
        $callbackPath = '/merchant/webhook/shopeepay';
        $timestamp    = '2026-05-25T10:00:00+07:00';

        $verifier = $this->buildVerifier();
        $sig      = $this->signWebhook($callbackPath, $rawBody, $timestamp);

        $event = $verifier->parse(
            method:          'POST',
            callbackPath:    $callbackPath,
            rawBody:         $rawBody,
            base64Signature: $sig,
            timestamp:       $timestamp,
            now:             new DateTimeImmutable($timestamp),
        );

        self::assertInstanceOf(UnknownEvent::class, $event);
        self::assertSame('77', $event->serviceCode);
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    private function buildVerifier(): Verifier
    {
        return new Verifier($this->buildConfig(), new Signer(), new EventFactory());
    }

    private function buildConfig(int $webhookReplayWindowSeconds = 300): Config
    {
        $psr17 = new Psr17Factory();

        return new Config(
            clientId:                   'client-x',
            clientSecret:               'secret-x',
            privateKey:                 (string) file_get_contents(self::FIXTURES . '/merchant-private-test.pem'),
            shopeepayPublicKey:         (string) file_get_contents(self::FIXTURES . '/shopeepay-public-test.pem'),
            merchantId:                 'M1234',
            httpClient:                 $this->createMock(ClientInterface::class),
            requestFactory:             $psr17,
            streamFactory:              $psr17,
            cache:                      $this->createMock(CacheInterface::class),
            environment:                Environment::SANDBOX,
            webhookReplayWindowSeconds: $webhookReplayWindowSeconds,
            logger:                     $this->createMock(LoggerInterface::class),
        );
    }

    private function signWebhook(string $callbackPath, string $rawBody, string $timestamp): string
    {
        $stringToSign = sprintf(
            'POST:%s:%s:%s',
            $callbackPath,
            hash('sha256', $rawBody),
            $timestamp,
        );

        $privKey = openssl_pkey_get_private(
            (string) file_get_contents(self::FIXTURES . '/shopeepay-private-test.pem'),
        );
        self::assertNotFalse($privKey);

        $rawSig = '';
        self::assertTrue(openssl_sign($stringToSign, $rawSig, $privKey, OPENSSL_ALGO_SHA256));

        return base64_encode($rawSig);
    }
}
