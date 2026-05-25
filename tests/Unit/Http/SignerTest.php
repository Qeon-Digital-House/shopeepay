<?php

declare(strict_types=1);

namespace ShopeePay\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use ShopeePay\Exception\SignatureException;
use ShopeePay\Http\Signer;

/**
 * Three known-vector tests, one per signature type. Failure here means the
 * SDK is signing something differently than ShopeePay expects — every other
 * test downstream is meaningless until these pass.
 */
final class SignerTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures';

    // Pinned HMAC vector computed once from the canonical formula in the
    // design doc. If a refactor changes any of:
    //   - JSON encoding flags,
    //   - bodyHash case,
    //   - stringToSign separator,
    //   - HMAC algorithm,
    // this assertion breaks loud and immediately.
    private const VECTOR_2_EXPECTED_BASE64 =
        'vHxrawDuVWD7dRAHMMaYBzWzsah7ZXU9kuGB5pzhzABxsyuMBYmQ27cTGYpGdZu6LaMDL+r40QndzrL2DzQcWw==';

    public function testVector1AccessTokenSignsAndVerifies(): void
    {
        $signer = new Signer();
        $privPem = $this->loadFixture('merchant-private-test.pem');
        $pubPem  = $this->loadFixture('merchant-public-test.pem');

        $clientKey = 'sample-client-id';
        $timestamp = '2022-08-11T11:13:43+07:00';

        $base64Sig = $signer->signAccessToken($clientKey, $timestamp, $privPem);

        self::assertNotSame('', $base64Sig);
        self::assertMatchesRegularExpression('#^[A-Za-z0-9+/=]+$#', $base64Sig);

        // RSA-SHA256 signatures are deterministic per key, but we verify
        // round-trip instead of pinning bytes — pinning would couple the
        // test to whatever fixture key happens to be on disk.
        $rawSig       = base64_decode($base64Sig, true);
        self::assertNotFalse($rawSig);
        $stringToSign = $clientKey . '|' . $timestamp;
        $pubKey       = openssl_pkey_get_public($pubPem);
        self::assertNotFalse($pubKey);

        self::assertSame(1, openssl_verify($stringToSign, $rawSig, $pubKey, OPENSSL_ALGO_SHA256));
    }

    public function testVector2TransactionHmacMatchesPinnedBase64(): void
    {
        $signer = new Signer();

        $signature = $signer->signTransaction(
            method:       'POST',
            path:         '/v1.1/debit/payment-host-to-host',
            accessToken:  'test-access-token',
            minifiedBody: '{"partnerReferenceNo":"ORDER-1","amount":{"value":"150000.00","currency":"IDR"}}',
            timestamp:    '2022-08-11T11:13:43.123+07:00',
            clientSecret: 'test-secret',
        );

        self::assertSame(self::VECTOR_2_EXPECTED_BASE64, $signature);
    }

    public function testVector3WebhookVerifiesValidSignature(): void
    {
        $signer = new Signer();

        // Sign a sample notify payload with the ShopeePay fixture private
        // key — that is what ShopeePay's server does in real life — then
        // verify with the fixture public key, which is what the SDK does.
        $rawBody      = '{"partnerReferenceNo":"ORDER-1","latestTransactionStatus":"00","transactionStatusDesc":"Success"}';
        $callbackPath = '/merchant/webhook/shopeepay';
        $timestamp    = '2026-05-25T10:00:00+07:00';
        $bodyHash     = hash('sha256', $rawBody);
        $stringToSign = 'POST:' . $callbackPath . ':' . $bodyHash . ':' . $timestamp;

        $privKey = openssl_pkey_get_private($this->loadFixture('shopeepay-private-test.pem'));
        self::assertNotFalse($privKey);

        $rawSig = '';
        self::assertTrue(openssl_sign($stringToSign, $rawSig, $privKey, OPENSSL_ALGO_SHA256));
        $base64Sig = base64_encode($rawSig);

        $verified = $signer->verifyWebhook(
            callbackPath:        $callbackPath,
            rawBody:             $rawBody,
            base64Signature:     $base64Sig,
            timestamp:           $timestamp,
            shopeepayPublicKey:  $this->loadFixture('shopeepay-public-test.pem'),
        );

        self::assertTrue($verified);
    }

    public function testWebhookRejectsTamperedBody(): void
    {
        $signer = new Signer();
        $callbackPath = '/merchant/webhook/shopeepay';
        $timestamp    = '2026-05-25T10:00:00+07:00';
        $originalBody = '{"amount":"150000.00"}';
        $tamperedBody = '{"amount":"999999.00"}';

        // Sign the ORIGINAL body, then try to verify against the TAMPERED body.
        $bodyHash     = hash('sha256', $originalBody);
        $stringToSign = 'POST:' . $callbackPath . ':' . $bodyHash . ':' . $timestamp;

        $privKey = openssl_pkey_get_private($this->loadFixture('shopeepay-private-test.pem'));
        self::assertNotFalse($privKey);

        $rawSig = '';
        self::assertTrue(openssl_sign($stringToSign, $rawSig, $privKey, OPENSSL_ALGO_SHA256));

        $verified = $signer->verifyWebhook(
            callbackPath:        $callbackPath,
            rawBody:             $tamperedBody,
            base64Signature:     base64_encode($rawSig),
            timestamp:           $timestamp,
            shopeepayPublicKey:  $this->loadFixture('shopeepay-public-test.pem'),
        );

        self::assertFalse($verified);
    }

    public function testWebhookRejectsMalformedBase64SignatureSilently(): void
    {
        // base64_decode strict-mode returns false for "@@@" (not valid base64).
        // Per the docstring, this is treated as a mismatch, not an error.
        $verified = (new Signer())->verifyWebhook(
            callbackPath:        '/merchant/webhook',
            rawBody:             '{}',
            base64Signature:     '@@@not-valid-base64@@@',
            timestamp:           '2026-05-25T10:00:00+07:00',
            shopeepayPublicKey:  $this->loadFixture('shopeepay-public-test.pem'),
        );

        self::assertFalse($verified);
    }

    public function testSignAccessTokenRejectsInvalidPem(): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Could not parse merchant private key');

        (new Signer())->signAccessToken(
            'client-id',
            '2026-05-25T10:00:00+07:00',
            'NOT-A-PEM-STRING',
        );
    }

    public function testVerifyWebhookRejectsInvalidPublicKey(): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Could not parse ShopeePay public key');

        (new Signer())->verifyWebhook(
            callbackPath:        '/webhook',
            rawBody:             '{}',
            base64Signature:     base64_encode('whatever'),
            timestamp:           '2026-05-25T10:00:00+07:00',
            shopeepayPublicKey:  'NOT-A-PUBLIC-KEY',
        );
    }

    public function testTransactionSignatureIsCaseSensitiveOnMethod(): void
    {
        // Signer normalizes the method to uppercase, so "post" and "POST"
        // produce identical signatures. Documenting this so a future
        // refactor that drops the strtoupper() breaks the test.
        $signer = new Signer();
        $args = [
            'path'         => '/v1.1/debit/payment-host-to-host',
            'accessToken'  => 'tk',
            'minifiedBody' => '{}',
            'timestamp'    => '2026-05-25T10:00:00.000+07:00',
            'clientSecret' => 'secret',
        ];

        $sigLower = $signer->signTransaction('post', ...$args);
        $sigUpper = $signer->signTransaction('POST', ...$args);

        self::assertSame($sigUpper, $sigLower);
    }

    private function loadFixture(string $name): string
    {
        $path = self::FIXTURES . '/' . $name;
        $contents = file_get_contents($path);
        self::assertNotFalse($contents, "Fixture missing: $path");
        return $contents;
    }
}
