<?php

declare(strict_types=1);

/**
 * scripts/probe-sandbox.php — empirical probe against the ShopeePay sandbox.
 *
 * Build-order step 11. Answers the open questions the design doc left
 * tentative:
 *
 *   1. **Access-token TTL.** The SDK currently assumes 14 minutes
 *      (`Config::$tokenTtlSeconds = 840`). The gateway returns its own
 *      `expiresIn` field on /access-token responses — the probe reads
 *      that and prints it. Treat THAT value as ground truth and update
 *      the Config default if it differs.
 *
 *   2. **AuthCapture paths.** Only `/v1.0/auth/refund` is design-doc
 *      pinned. The other six (`/v1.0/auth/payment-host-to-host`,
 *      `/auth/capture`, `/auth/void`, `/auth/status`,
 *      `/auth/capture/status`, `/auth/void/status`) are SNAP-BI guesses.
 *      The probe POSTs a minimal body to each and classifies the response:
 *
 *        - "looks valid"     — the gateway returned a domain-aware error
 *                              (missing partnerReferenceNo, invalid amount,
 *                              etc.) → path is real, body just incomplete
 *        - "may be wrong"    — the gateway returned a 404-shape responseCode
 *                              or "service not found" message → path needs
 *                              correcting
 *        - "indeterminate"   — transport error or unexpected shape
 *
 *   3. **channelId acceptance.** The SDK uses `95221` (SNAP BI e-money
 *      default). If the access-token probe succeeds, that value is
 *      accepted; the probe surfaces this implicitly.
 *
 *   4. **Refund window.** Not directly probable without a real settled
 *      capture to refund against. Documented as a manual-followup item.
 *
 * Safety:
 *   - Defaults to SANDBOX. Pass `--production` to run against prod
 *     (gated behind an explicit confirmation prompt).
 *   - Sends only deliberately-empty/probe bodies that the gateway will
 *     reject with a validation error before any money movement.
 *   - No state mutation. Read-only probe.
 *
 * Usage:
 *   php scripts/probe-sandbox.php
 *   php scripts/probe-sandbox.php --production       # asks for confirmation
 *   php scripts/probe-sandbox.php --json             # report as JSON
 *
 * Env vars (same convention as .env.example / examples/_bootstrap.php):
 *   SHOPEEPAY_CLIENT_ID, SHOPEEPAY_SECRET_KEY, SHOPEEPAY_CWS_MERCHANT_ID,
 *   and ONE of each key pair — SHOPEEPAY_PRIVATE_KEY (PEM string) or
 *   SHOPEEPAY_PRIVATE_KEY_PATH (file path); SHOPEEPAY_PUBLIC_KEY or
 *   SHOPEEPAY_PUBLIC_KEY_PATH.
 *   Optional: SHOPEEPAY_CWS_STORE_ID.
 */

require __DIR__ . '/../vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use ShopeePay\Config;
use ShopeePay\Environment;
use ShopeePay\Exception\ApiException;
use ShopeePay\Exception\NetworkException;
use ShopeePay\Http\AccessTokenManager;
use ShopeePay\Http\HeaderBuilder;
use ShopeePay\Http\Signer;
use ShopeePay\Http\Transport;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

// ── arg parsing ────────────────────────────────────────────────────────
$args        = array_slice($argv, 1);
$production  = in_array('--production', $args, true);
$jsonOutput  = in_array('--json',       $args, true);

if ($production) {
    fwrite(STDERR, "⚠️  Production probe requested. The probe is read-only but\n");
    fwrite(STDERR, "   sends real requests against the live ShopeePay API.\n");
    fwrite(STDERR, "   Type 'yes' to continue: ");
    $confirm = trim((string) fgets(STDIN));
    if (strtolower($confirm) !== 'yes') {
        fwrite(STDERR, "Aborted.\n");
        exit(1);
    }
}

// ── env loading ────────────────────────────────────────────────────────
$required = ['SHOPEEPAY_CLIENT_ID', 'SHOPEEPAY_SECRET_KEY', 'SHOPEEPAY_CWS_MERCHANT_ID'];
foreach ($required as $var) {
    if (getenv($var) === false || getenv($var) === '') {
        fwrite(STDERR, "Missing env var: $var. See .env.example for the full list.\n");
        exit(1);
    }
}
$privateKey = probe_load_pem('SHOPEEPAY_PRIVATE_KEY', 'SHOPEEPAY_PRIVATE_KEY_PATH');
$publicKey  = probe_load_pem('SHOPEEPAY_PUBLIC_KEY',  'SHOPEEPAY_PUBLIC_KEY_PATH');

// ── bootstrap ──────────────────────────────────────────────────────────
$psr17      = new Psr17Factory();
$httpClient = \Http\Discovery\Psr18ClientDiscovery::find();
$cache      = new Psr16Cache(new ArrayAdapter());

$envFromFlag = $production
    || strtolower((string) getenv('SHOPEEPAY_IS_PRODUCTION')) === 'true';
$storeId     = ((string) getenv('SHOPEEPAY_CWS_STORE_ID')) ?: null;

$config = new Config(
    clientId:           (string) getenv('SHOPEEPAY_CLIENT_ID'),
    clientSecret:       (string) getenv('SHOPEEPAY_SECRET_KEY'),
    privateKey:         $privateKey,
    shopeepayPublicKey: $publicKey,
    merchantId:         (string) getenv('SHOPEEPAY_CWS_MERCHANT_ID'),
    httpClient:         $httpClient,
    requestFactory:     $psr17,
    streamFactory:      $psr17,
    cache:              $cache,
    environment:        $envFromFlag ? Environment::PRODUCTION : Environment::SANDBOX,
    storeId:            $storeId,
);

$signer        = new Signer();
$headerBuilder = new HeaderBuilder($config, $signer);
$atm           = new AccessTokenManager($config, $headerBuilder);
$transport     = new Transport($config, $headerBuilder, $atm);

// ── probe: access-token TTL ────────────────────────────────────────────
// We re-issue the /access-token request directly (bypassing the cache)
// so we can read the gateway's expiresIn verbatim.
$tokenProbe = probeAccessToken($config, $headerBuilder);

// ── probe: each AuthCapture path ───────────────────────────────────────
// Use a fresh access token via the normal Transport path so the body-hash
// + signature dance is exactly what the SDK produces in production.
$probePaths = [
    'authorize'      => '/v1.0/auth/payment-host-to-host',
    'capture'        => '/v1.0/auth/capture',
    'void'           => '/v1.0/auth/void',
    'refund'         => '/v1.0/auth/refund',          // ← design-doc pinned
    'queryAuth'      => '/v1.0/auth/status',
    'queryCapture'   => '/v1.0/auth/capture/status',
    'queryVoid'      => '/v1.0/auth/void/status',
];

$pathResults = [];
foreach ($probePaths as $label => $path) {
    $pathResults[$label] = probePath($transport, $label, $path);
}

// ── probe: cross-check known-good paths ────────────────────────────────
// Confirms the probe itself works — these paths are already verified by
// the unit test suite via mocked transports, but if the sandbox is having
// a bad day we want to know before reading too much into the AuthCapture
// results.
$controlPaths = [
    'lap-create'   => '/v1.1/debit/payment-host-to-host',
    'lap-status'   => '/v1.0/debit/status',
    'lap-refund'   => '/v1.0/debit/refund',
];
$controlResults = [];
foreach ($controlPaths as $label => $path) {
    $controlResults[$label] = probePath($transport, $label, $path);
}

// ── report ─────────────────────────────────────────────────────────────
$report = [
    'environment'    => $config->environment->value,
    'baseUrl'        => $config->baseUrl(),
    'channelId'      => $config->channelId,
    'configTokenTtl' => $config->tokenTtlSeconds,
    'tokenProbe'     => $tokenProbe,
    'authPaths'      => $pathResults,
    'controlPaths'   => $controlResults,
    'notes'          => [
        'refundWindow' => 'Not directly probable — needs a real settled capture. '
                        . 'If you have one in your sandbox, attempt refund() at varying ages '
                        . 'and pin the smallest age that surfaces a "too old" responseCode.',
    ],
];

if ($jsonOutput) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

printReport($report);
exit(0);

// ────────────────────────────────────────────────────────────────────────
// helpers
// ────────────────────────────────────────────────────────────────────────

/**
 * @return array{httpStatus: int, responseCode: string, responseMessage: string,
 *               accessTokenPresent: bool, expiresInSeconds: ?int, raw: array<string, mixed>|string}
 */
function probeAccessToken(Config $config, HeaderBuilder $headerBuilder): array
{
    $body    = '{"grantType":"client_credentials"}';
    $request = $config->requestFactory
        ->createRequest('POST', $config->baseUrl() . '/v1.0/access-token')
        ->withBody($config->streamFactory->createStream($body));
    foreach ($headerBuilder->accessTokenHeaders() as $h => $v) {
        $request = $request->withHeader($h, $v);
    }

    try {
        $response = $config->httpClient->sendRequest($request);
    } catch (\Psr\Http\Client\ClientExceptionInterface $e) {
        return [
            'httpStatus'         => 0,
            'responseCode'       => '',
            'responseMessage'    => 'transport error: ' . $e->getMessage(),
            'accessTokenPresent' => false,
            'expiresInSeconds'   => null,
            'raw'                => $e->getMessage(),
        ];
    }

    $bodyString = (string) $response->getBody();
    $decoded    = json_decode($bodyString, true);
    $isArray    = is_array($decoded);

    $expiresIn = null;
    if ($isArray) {
        // The SNAP BI spec field is `expiresIn` (string seconds). Some
        // gateways spell it `expires_in` — accept both, prefer the spec form.
        $val = $decoded['expiresIn'] ?? $decoded['expires_in'] ?? null;
        if (is_string($val) && ctype_digit($val)) {
            $expiresIn = (int) $val;
        } elseif (is_int($val)) {
            $expiresIn = $val;
        }
    }

    return [
        'httpStatus'         => $response->getStatusCode(),
        'responseCode'       => $isArray && is_string($decoded['responseCode'] ?? null)
                                    ? $decoded['responseCode'] : '',
        'responseMessage'    => $isArray && is_string($decoded['responseMessage'] ?? null)
                                    ? $decoded['responseMessage'] : '',
        'accessTokenPresent' => $isArray && is_string($decoded['accessToken'] ?? null)
                                    && $decoded['accessToken'] !== '',
        'expiresInSeconds'   => $expiresIn,
        'raw'                => $isArray ? $decoded : $bodyString,
    ];
}

/**
 * @return array{path: string, classification: string, httpStatus: ?int,
 *               responseCode: string, responseMessage: string, raw: mixed}
 */
function probePath(Transport $transport, string $label, string $path): array
{
    // Send a body that's syntactically minimal but semantically incomplete.
    // The gateway should respond with a validation-shaped error if the path
    // is real, or a 404/not-found-shaped error if the path is wrong.
    $body = [
        'partnerReferenceNo' => 'PROBE-' . bin2hex(random_bytes(4)),
    ];

    try {
        $decoded = $transport->send(method: 'POST', path: $path, body: $body);

        // Unexpected success — the gateway accepted a minimal body. Log it.
        return [
            'path'            => $path,
            'classification'  => 'unexpected-success',
            'httpStatus'      => 200,
            'responseCode'    => is_string($decoded['responseCode'] ?? null) ? $decoded['responseCode'] : '',
            'responseMessage' => is_string($decoded['responseMessage'] ?? null) ? $decoded['responseMessage'] : '',
            'raw'             => $decoded,
        ];
    } catch (ApiException $e) {
        return [
            'path'            => $path,
            'classification'  => classifyApiException($e),
            'httpStatus'      => extractHttpStatus($e->responseCode),
            'responseCode'    => $e->responseCode,
            'responseMessage' => $e->responseMessage,
            'raw'             => $e->responseCode . ': ' . $e->responseMessage,
        ];
    } catch (NetworkException $e) {
        return [
            'path'            => $path,
            'classification'  => 'indeterminate-network',
            'httpStatus'      => null,
            'responseCode'    => '',
            'responseMessage' => $e->getMessage(),
            'raw'             => $e->getMessage(),
        ];
    } catch (\Throwable $e) {
        return [
            'path'            => $path,
            'classification'  => 'indeterminate-throwable',
            'httpStatus'      => null,
            'responseCode'    => '',
            'responseMessage' => $e::class . ': ' . $e->getMessage(),
            'raw'             => $e->getMessage(),
        ];
    }
}

function classifyApiException(ApiException $e): string
{
    $code    = $e->responseCode;
    $message = strtolower($e->responseMessage);

    // A SNAP BI responseCode is 7 digits: HTTP(3) + svc(2) + sub(2).
    // 4040000-class with svc "00" means the gateway-level router didn't
    // recognize the path. Anything with a non-zero svc means the path was
    // dispatched into a service handler that then complained — that
    // strongly suggests the path is valid.
    if (preg_match('/^(\d{3})(\d{2})\d{2}$/', $code, $matches) === 1) {
        $http = (int) $matches[1];
        $svc  = $matches[2];

        if ($http === 404 && $svc === '00') {
            return 'may-be-wrong-path';
        }
        if ($svc !== '00') {
            return 'looks-valid-path';
        }
    }

    // Fall back to message inspection.
    if (str_contains($message, 'not found') || str_contains($message, 'no service')
        || str_contains($message, 'invalid path')) {
        return 'may-be-wrong-path';
    }

    return 'looks-valid-path';
}

function extractHttpStatus(string $responseCode): ?int
{
    if (preg_match('/^(\d{3})\d{4}$/', $responseCode, $m) === 1) {
        return (int) $m[1];
    }
    return null;
}

/**
 * Resolve a PEM from either a direct env var (the value IS the PEM) or a
 * `_PATH`-suffixed env var pointing to a file on disk.
 */
function probe_load_pem(string $pemVar, string $pathVar): string
{
    $direct = (string) getenv($pemVar);
    if (str_contains($direct, '-----BEGIN')) {
        return $direct;
    }

    $path = (string) getenv($pathVar);
    if ($path === '') {
        fwrite(STDERR, "Set $pemVar (PEM string) or $pathVar (file path).\n");
        exit(1);
    }

    $contents = @file_get_contents($path);
    if ($contents === false) {
        fwrite(STDERR, "Could not read $pathVar at $path\n");
        exit(1);
    }
    return $contents;
}

/**
 * @param array<string, mixed> $report
 */
function printReport(array $report): void
{
    echo "\n=== ShopeePay Sandbox Probe Report ===\n\n";
    echo "Environment: {$report['environment']}\n";
    echo "Base URL:    {$report['baseUrl']}\n";
    echo "channelId:   {$report['channelId']}\n";
    echo "Config TTL:  {$report['configTokenTtl']}s (" . round($report['configTokenTtl'] / 60) . " min)\n";
    echo "\n";

    echo "--- Access Token Probe ---\n";
    $tp = $report['tokenProbe'];
    echo sprintf("HTTP %d  responseCode=%s\n", $tp['httpStatus'], $tp['responseCode'] ?: '(none)');
    echo "Message: " . ($tp['responseMessage'] ?: '(none)') . "\n";
    if ($tp['accessTokenPresent']) {
        echo "✓ Access token issued\n";
        if ($tp['expiresInSeconds'] !== null) {
            echo sprintf(
                "⏱  Gateway expiresIn = %ds (%d min)%s\n",
                $tp['expiresInSeconds'],
                round($tp['expiresInSeconds'] / 60),
                $tp['expiresInSeconds'] === $report['configTokenTtl']
                    ? '  — matches Config default'
                    : "  — Config default is {$report['configTokenTtl']}s; UPDATE.",
            );
        } else {
            echo "⚠  Gateway did not return expiresIn — keeping {$report['configTokenTtl']}s default.\n";
        }
    } else {
        echo "✗ No access token in response. channelId acceptance and path probes\n";
        echo "  below will all fail until creds/signature/keys are correct.\n";
    }
    echo "\n";

    echo "--- AuthCapture Path Probe (6 guessed + 1 pinned) ---\n";
    foreach ($report['authPaths'] as $label => $r) {
        echo sprintf(
            "  %-15s %-32s  %-22s  %s\n",
            $label,
            $r['path'],
            $r['classification'],
            ($r['responseCode'] ?: '?') . ' ' . trim((string) $r['responseMessage']),
        );
    }
    echo "\n";

    echo "--- Control: known-good Link & Pay paths ---\n";
    foreach ($report['controlPaths'] as $label => $r) {
        echo sprintf(
            "  %-15s %-40s  %s\n",
            $label,
            $r['path'],
            $r['classification'],
        );
    }
    echo "\n";

    echo "--- Notes ---\n";
    foreach ($report['notes'] as $k => $v) {
        echo "  • $k: $v\n";
    }
    echo "\n";

    echo "=== End of report ===\n";
    echo "\nNext: if any AuthCapture path classifies as 'may-be-wrong-path',\n";
    echo "update the corresponding PATH_* const in src/Service/AuthCaptureService.php.\n";
}
