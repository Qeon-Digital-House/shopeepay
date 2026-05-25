<?php

declare(strict_types=1);

namespace ShopeePay\Http;

use ShopeePay\Exception\SignatureException;

/**
 * SNAP BI signing primitives. Three operations, three formats — keep them
 * separate so callers can't accidentally mix string-to-sign shapes.
 *
 * Access token (asymmetric, RSA-SHA256):
 *   stringToSign = "{clientKey}|{timestamp}"             // timestamp: Y-m-d\TH:i:sP
 *   signature    = base64(openssl_sign(stringToSign, merchantPrivateKey, SHA256))
 *
 * Transaction (symmetric, HMAC-SHA512):
 *   bodyHash     = lowercase hex SHA-256 of the minified JSON body
 *   stringToSign = "{METHOD}:{path}:{accessToken}:{bodyHash}:{timestamp}"
 *                  // timestamp: Y-m-d\TH:i:s.vP (millis required)
 *   signature    = base64(hash_hmac('sha512', stringToSign, clientSecret, true))
 *
 * Webhook (asymmetric, RSA-SHA256, verify-only):
 *   stringToSign = "POST:{callbackPath}:{Hex(SHA256(rawBody))}:{timestamp}"
 *   verify       = openssl_verify(stringToSign, base64_decode(sig),
 *                                 shopeepayPublicKey, SHA256)
 *
 * openssl_verify returns 1 = valid, 0 = mismatch, -1 = openssl error.
 * All three are handled explicitly — -1 raises SignatureException with the
 * openssl error chain, 0 returns false, 1 returns true.
 */
final class Signer
{
    public function signAccessToken(
        string $clientKey,
        string $timestamp,
        string $merchantPrivateKeyPem,
    ): string {
        $key = openssl_pkey_get_private($merchantPrivateKeyPem);
        if ($key === false) {
            throw new SignatureException(
                'Could not parse merchant private key: ' . $this->drainOpenSslErrors(),
            );
        }

        $stringToSign = sprintf('%s|%s', $clientKey, $timestamp);

        $rawSig = '';
        if (!openssl_sign($stringToSign, $rawSig, $key, OPENSSL_ALGO_SHA256)) {
            throw new SignatureException(
                'openssl_sign failed for access token: ' . $this->drainOpenSslErrors(),
            );
        }

        return base64_encode($rawSig);
    }

    public function signTransaction(
        string $method,
        string $path,
        string $accessToken,
        string $minifiedBody,
        string $timestamp,
        string $clientSecret,
    ): string {
        $bodyHash     = hash('sha256', $minifiedBody);
        $stringToSign = sprintf(
            '%s:%s:%s:%s:%s',
            strtoupper($method),
            $path,
            $accessToken,
            $bodyHash,
            $timestamp,
        );

        $rawSig = hash_hmac('sha512', $stringToSign, $clientSecret, true);

        return base64_encode($rawSig);
    }

    /**
     * Verifies the X-SIGNATURE header on an incoming ShopeePay webhook.
     *
     * @param  \OpenSSLAsymmetricKey|string $shopeepayPublicKey Either a PEM
     *                                                          string or a
     *                                                          pre-parsed key
     *                                                          resource. Webhook
     *                                                          verifiers should
     *                                                          parse once and
     *                                                          reuse.
     * @throws SignatureException when openssl reports an error (-1) or when
     *                            the public-key argument cannot be parsed.
     */
    public function verifyWebhook(
        string $callbackPath,
        string $rawBody,
        string $base64Signature,
        string $timestamp,
        \OpenSSLAsymmetricKey|string $shopeepayPublicKey,
    ): bool {
        $key = $shopeepayPublicKey instanceof \OpenSSLAsymmetricKey
            ? $shopeepayPublicKey
            : openssl_pkey_get_public($shopeepayPublicKey);

        if ($key === false) {
            throw new SignatureException(
                'Could not parse ShopeePay public key: ' . $this->drainOpenSslErrors(),
            );
        }

        $bodyHash     = hash('sha256', $rawBody);
        $stringToSign = sprintf('POST:%s:%s:%s', $callbackPath, $bodyHash, $timestamp);

        $rawSig = base64_decode($base64Signature, true);
        if ($rawSig === false) {
            // Malformed signature is treated as a mismatch, not an error —
            // attackers should not be able to distinguish "bad base64" from
            // "valid base64 but wrong content".
            return false;
        }

        $result = openssl_verify($stringToSign, $rawSig, $key, OPENSSL_ALGO_SHA256);

        return match ($result) {
            1       => true,
            0       => false,
            default => throw new SignatureException(
                'openssl_verify failed: ' . $this->drainOpenSslErrors(),
            ),
        };
    }

    /**
     * Collect every queued openssl error into a single string so the exception
     * message tells you which specific failure happened.
     */
    private function drainOpenSslErrors(): string
    {
        $msgs = [];
        while (($err = openssl_error_string()) !== false) {
            $msgs[] = $err;
        }
        return $msgs === [] ? '(no openssl error reported)' : implode('; ', $msgs);
    }
}
