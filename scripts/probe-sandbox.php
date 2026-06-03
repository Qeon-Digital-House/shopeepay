<?php

declare(strict_types=1);

/**
 * scripts/probe-sandbox.php — empirical probe against the ShopeePay sandbox.
 *
 * Walks the real onboarding + first-debit flow end-to-end so design-doc
 * questions can be answered with live gateway data instead of guesses:
 *
 *   1. **Access-token TTL.** The SDK currently assumes 14 minutes
 *      (`Config::$tokenTtlSeconds = 840`). The gateway returns its own
 *      `expiresIn` field on /access-token/b2b responses — the probe
 *      reads that and prints it. Treat THAT value as ground truth and
 *      update the Config default if it differs.
 *
 *   2. **Flow probe.** Five steps, each feeding the next:
 *        a. get-auth-code                   — GET the consent URL, verify
 *                                             the endpoint responds
 *        b. registration-account-binding    — exchange authCode → accountToken
 *        c. debit/payment-host-to-host      — debit using accountToken
 *        d. debit/status                    — query the debit's status
 *        e. registration-account-unbinding  — revoke accountToken (cleanup)
 *
 *      Each path is classified `success`, `looks-valid-path`,
 *      `may-be-wrong-path`, or `indeterminate-*`. When the env var for a
 *      step is missing the probe sends a deliberately-invalid value so
 *      the path can still be checked.
 *
 *   3. **channelId acceptance.** The SDK uses `95221` (SNAP BI e-money
 *      default). If the access-token probe succeeds, that value is
 *      accepted; the probe surfaces this implicitly.
 *
 * Safety:
 *   - Defaults to SANDBOX. Pass `--production` to run against prod
 *     (gated behind an explicit confirmation prompt).
 *   - With no SHOPEEPAY_AUTH_CODE / SHOPEEPAY_ACCOUNT_TOKEN, sends only
 *     deliberately-invalid values that the gateway rejects with a
 *     validation error before any state mutation.
 *   - With real env values, this DOES exercise real state: bind creates
 *     a real account-token binding, debit creates a real (small)
 *     payment intent. Use sandbox creds.
 *
 * Usage:
 *   php scripts/probe-sandbox.php
 *   php scripts/probe-sandbox.php --production       # asks for confirmation
 *   php scripts/probe-sandbox.php --json             # report as JSON
 *   php scripts/probe-sandbox.php --only=bind        # run ONE flow step
 *       (steps: auth-code | bind | debit | status | unbind | token; the
 *        access-token probe always runs. via Make: make probe ARGS=--only=bind)
 *
 * Env vars (same convention as .env.example / examples/_bootstrap.php):
 *   SHOPEEPAY_CLIENT_ID, SHOPEEPAY_SECRET_KEY, SHOPEEPAY_SUBS_MERCHANT_ID,
 *   and ONE of each key pair — SHOPEEPAY_PRIVATE_KEY (PEM string) or
 *   SHOPEEPAY_PRIVATE_KEY_PATH (file path); SHOPEEPAY_PUBLIC_KEY or
 *   SHOPEEPAY_PUBLIC_KEY_PATH.
 *
 *   Optional (enable richer flow-probe chaining):
 *     SHOPEEPAY_SUBS_STORE_ID       — outlet id, multi-outlet merchants
 *     SHOPEEPAY_AUTH_CODE          — authCode from a completed consent
 *                                    flow (otherwise bind probes with a
 *                                    dummy and only validates the path)
 *     SHOPEEPAY_ACCOUNT_TOKEN      — fallback accountToken for debit if
 *                                    bind didn't yield one
 */

require __DIR__ . '/../vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use ShopeePay\Config;
use ShopeePay\Dto\AccountLinking\GetAuthCodeRequest;
use ShopeePay\Environment;
use ShopeePay\Exception\ApiException;
use ShopeePay\Exception\NetworkException;
use ShopeePay\Http\AccessTokenManager;
use ShopeePay\Http\HeaderBuilder;
use ShopeePay\Http\Signer;
use ShopeePay\Http\Transport;
use ShopeePay\Service\AccountLinkingService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

// ── arg parsing ────────────────────────────────────────────────────────
$args        = array_slice($argv, 1);
$production  = in_array('--production', $args, true);
$jsonOutput  = in_array('--json',       $args, true);

// --only=<step> runs a single flow step instead of the full chain, so a
// single endpoint can be iterated on in isolation (e.g. --only=bind).
// The access-token probe always runs — every flow step needs a token.
// Friendly aliases map onto the canonical $flow keys.
$stepAliases = [
    'auth-code'    => 'getAuthCode', 'authcode' => 'getAuthCode',
    'getauthcode'  => 'getAuthCode', 'get-auth-code' => 'getAuthCode',
    'bind'         => 'bind',
    'debit'        => 'debit',
    'status'       => 'debitStatus', 'debit-status' => 'debitStatus',
    'unbind'       => 'unbind', 'unlink' => 'unbind', 'unbinding' => 'unbind',
    'token'        => 'token',  // access-token probe only, no flow step
];
$only = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--only=')) {
        $val = strtolower(substr($arg, strlen('--only=')));
        if (!isset($stepAliases[$val])) {
            fwrite(STDERR, "Unknown --only step '$val'. Valid: "
                . implode(', ', array_keys($stepAliases)) . "\n");
            exit(1);
        }
        $only = $stepAliases[$val];
    }
}
// Which flow steps to run: all four by default, or just the selected one.
$wantStep = static fn (string $key): bool => $only === null || $only === $key;

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
$required = ['SHOPEEPAY_CLIENT_ID', 'SHOPEEPAY_SECRET_KEY', 'SHOPEEPAY_SUBS_MERCHANT_ID'];
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
$storeId     = ((string) getenv('SHOPEEPAY_SUBS_STORE_ID')) ?: null;

$config = new Config(
    clientId:           (string) getenv('SHOPEEPAY_CLIENT_ID'),
    clientSecret:       (string) getenv('SHOPEEPAY_SECRET_KEY'),
    privateKey:         $privateKey,
    shopeepayPublicKey: $publicKey,
    merchantId:         (string) getenv('SHOPEEPAY_SUBS_MERCHANT_ID'),
    httpClient:         $httpClient,
    requestFactory:     $psr17,
    streamFactory:      $psr17,
    cache:              $cache,
    environment:        $envFromFlag ? Environment::PRODUCTION : Environment::SANDBOX,
    storeId:            $storeId,
);

$signer         = new Signer();
$headerBuilder  = new HeaderBuilder($config, $signer);
$atm            = new AccessTokenManager($config, $headerBuilder);
$transport      = new Transport($config, $headerBuilder, $atm);
$accountLinking = new AccountLinkingService($config, $transport);

// ── probe: access-token TTL ────────────────────────────────────────────
// We re-issue the /access-token/b2b request directly (bypassing the
// cache) so we can read the gateway's expiresIn verbatim.
$tokenProbe = probeAccessToken($config, $headerBuilder);

// ── probe: flow (get-auth-code → bind → debit → status → unbind) ───────
// Each step chains into the next: bind's accountToken feeds debit's
// body, debit's partnerReferenceNo feeds the status query, and bind's
// accountToken also feeds the unbind cleanup.
//
// When the user-provided env var for a step is empty, a placeholder
// value is sent so the gateway still validates the path; classification
// surfaces whether the path itself is real.
// Steps not selected by --only stay null and are skipped in the report.
$flow = [
    'getAuthCode' => null,
    'bind'        => null,
    'debit'       => null,
    'debitStatus' => null,
    'unbind'      => null,
];

// 1. get-auth-code: signed server-to-server GET (NOT a pure browser URL —
//    the gateway requires the full SNAP-BI signed header set on this
//    endpoint, surfacing as 4001002 "Invalid Mandatory Field" otherwise).
if ($wantStep('getAuthCode')) {
    $flow['getAuthCode'] = probeGetAuthCode($accountLinking, $config, $headerBuilder, $atm);
}

// 2. registration-account-binding
//    authCode comes from get-auth-code's response when it succeeds; an
//    explicit SHOPEEPAY_AUTH_CODE env wins (e.g. a code captured from a
//    real browser-consent redirect), and probeBind falls back to a
//    placeholder when neither is available. When this step runs in
//    isolation (--only=bind) the get-auth-code chain is skipped, so the
//    env var is the only source of a real authCode.
if ($wantStep('bind')) {
    $authCode = (string) (getenv('SHOPEEPAY_AUTH_CODE') ?: '')
        ?: ($flow['getAuthCode']['authCode'] ?? '');
    $flow['bind'] = probeBind($transport, $config->merchantId, $authCode);
}

// 3. debit/payment-host-to-host
$debitRef = 'PROBE-DEBIT-' . bin2hex(random_bytes(4));
if ($wantStep('debit')) {
    $accountToken = ($flow['bind']['accountToken'] ?? '')
        ?: ((string) (getenv('SHOPEEPAY_ACCOUNT_TOKEN') ?: ''));
    $flow['debit'] = probeDebit(
        $transport,
        $config->merchantId,
        (string) ($config->storeId ?? ''),
        $debitRef,
        $accountToken,
    );
}

// 4. debit/status — query the debit just attempted (or the dummy ref
//    when the debit didn't actually create a row).
if ($wantStep('debitStatus')) {
    $statusRef = ($flow['debit']['responseReferenceNo'] ?? '')
        ?: ((string) (getenv('SHOPEEPAY_ORIGINAL_REF') ?: '') ?: $debitRef);
    $flow['debitStatus'] = probeDebitStatus(
        $transport,
        $config->merchantId,
        (string) ($config->storeId ?? ''),
        $statusRef,
    );
}

// 5. registration-account-unbinding — revoke the accountToken (cleanup).
//    Runs last in the full chain so it tears down the binding created in
//    step 2. When no accountToken is available it falls back to a
//    partnerReferenceNo so the endpoint shape is still validated.
if ($wantStep('unbind')) {
    $accountToken = ($flow['bind']['accountToken'] ?? '')
        ?: ((string) (getenv('SHOPEEPAY_ACCOUNT_TOKEN') ?: ''));
    $flow['unbind'] = probeUnbind($transport, $config->merchantId, $accountToken);
}

// ── report ─────────────────────────────────────────────────────────────
$report = [
    'environment'    => $config->environment->value,
    'baseUrl'        => $config->baseUrl(),
    'channelId'      => $config->channelId,
    'configTokenTtl' => $config->tokenTtlSeconds,
    'tokenProbe'     => $tokenProbe,
    'flow'           => $flow,
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
        ->createRequest('POST', $config->baseUrl() . '/v1.0/access-token/b2b')
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
 * Build the consent URL and GET it as a signed SNAP-BI request.
 *
 * Empirically (4001002 "invalid header: X-PARTNER-ID=[]"), the
 * /v1.0/get-auth-code endpoint requires the full SNAP-BI transaction
 * header set: X-PARTNER-ID, X-EXTERNAL-ID, X-TIMESTAMP, X-SIGNATURE,
 * CHANNEL-ID, Authorization Bearer. So this is NOT a plain
 * browser-redirect target — the merchant's server calls it, and the
 * response (or response body) indicates where to redirect the user.
 *
 * Body for the signature is the empty string (GET has no body); the
 * path-with-query goes into stringToSign.
 *
 * @return array{path: string, url: string, classification: string,
 *               httpStatus: ?int, contentType: string, authCode: string, raw: string}
 */
function probeGetAuthCode(
    AccountLinkingService $svc,
    Config $config,
    HeaderBuilder $headerBuilder,
    AccessTokenManager $atm,
): array {
    $request = new GetAuthCodeRequest(
        redirectUrl: 'https://localhost/probe-callback',
        state:       GetAuthCodeRequest::generateState(),
        scopes:      ['ACCOUNT_BINDING'],
    );
    $url = $svc->buildAuthCodeUrl($request);

    // SNAP-BI signs the path-with-query for GET, body is "" (no body).
    //
    // Quirk: ShopeePay's gateway URL-decodes the query string and then
    // re-encodes the WHOLE thing as a single value before folding it
    // into stringToSign — so every "=" becomes "%3D" and every "&"
    // becomes "%26" (and ":" "/" inside redirectUrl become "%3A" "%2F").
    // Verified by reading section "Get Sign Url Result" of the
    // gateway's /show_openapi_sign_debug_info debug page.
    $parsed = parse_url($url) ?: [];
    $path   = $parsed['path'] ?? '/v1.0/get-auth-code';
    $relativeUrl = isset($parsed['query']) && $parsed['query'] !== ''
        ? $path . '?' . rawurlencode(urldecode($parsed['query']))
        : $path;

    try {
        $accessToken = $atm->get();
    } catch (\Throwable $e) {
        return [
            'path'           => '/v1.0/get-auth-code',
            'url'            => $url,
            'classification' => 'indeterminate-network',
            'httpStatus'     => null,
            'contentType'    => '',
            'authCode'       => '',
            'raw'            => 'access-token unavailable: ' . $e->getMessage(),
        ];
    }

    $built = $headerBuilder->transactionHeaders(
        method:       'GET',
        path:         $relativeUrl,
        accessToken:  $accessToken,
        minifiedBody: '',
    );

    $httpReq = $config->requestFactory->createRequest('GET', $url);
    foreach ($built['headers'] as $h => $v) {
        $httpReq = $httpReq->withHeader($h, $v);
    }

    try {
        $response = $config->httpClient->sendRequest($httpReq);
    } catch (\Psr\Http\Client\ClientExceptionInterface $e) {
        return [
            'path'           => '/v1.0/get-auth-code',
            'url'            => $url,
            'classification' => 'indeterminate-network',
            'httpStatus'     => null,
            'contentType'    => '',
            'authCode'       => '',
            'raw'            => $e->getMessage(),
        ];
    }

    $status = $response->getStatusCode();
    $ctype  = $response->getHeaderLine('Content-Type');
    $body   = (string) $response->getBody();

    // For a JSON-shaped response, peek at responseCode to classify and
    // pull the authCode the gateway hands back. get-auth-code returns the
    // authCode directly in its body (responseCode 2001000); that same code
    // is what registration-account-binding consumes, so we chain it forward.
    $rcode    = '';
    $authCode = '';
    if (str_starts_with($ctype, 'application/json')) {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            if (is_string($decoded['responseCode'] ?? null)) {
                $rcode = $decoded['responseCode'];
            }
            if (is_string($decoded['authCode'] ?? null)) {
                $authCode = $decoded['authCode'];
            }
        }
    }

    $classification = match (true) {
        $status >= 200 && $status < 400                                 => 'success',
        $status === 404 && str_starts_with($ctype, 'text/plain')        => 'may-be-wrong-path',
        $rcode !== '' && preg_match('/^\d{3}00\d{2}$/', $rcode) === 1   => 'may-be-wrong-path',
        $status >= 400                                                  => 'looks-valid-path',
        default                                                         => 'indeterminate',
    };

    return [
        'path'           => '/v1.0/get-auth-code',
        'url'            => $url,
        'classification' => $classification,
        'httpStatus'     => $status,
        'contentType'    => $ctype,
        'authCode'       => $authCode,
        'raw'            => substr($body, 0, 400),
    ];
}

/**
 * @return array{path: string, classification: string, httpStatus: ?int,
 *               responseCode: string, responseMessage: string,
 *               accountToken: string, authCodeProvided: bool, raw: mixed}
 */
function probeBind(Transport $transport, string $merchantId, string $authCode): array
{
    // merchantId is a top-level mandatory field on this endpoint — the
    // gateway rejects its absence with "4000702 Invalid Mandatory Field
    // {merchantId}". authCode and partnerReferenceNo are mutually exclusive:
    // the spec says EITHER one (not both). Sending both also trips 4000702
    // ("Invalid Mandatory Field {authCode} or {partnerReferenceNo}"), so we
    // send exactly one — the real authCode when we have it, otherwise a
    // partnerReferenceNo to at least exercise the endpoint shape.
    $body = ['merchantId' => $merchantId];
    if ($authCode !== '') {
        $body['authCode'] = $authCode;
    } else {
        $body['partnerReferenceNo'] = 'PROBE-BIND-' . bin2hex(random_bytes(4));
    }
    $r = probeRequest($transport, '/v1.0/registration-account-binding', $body);

    $accountToken = '';
    if (is_array($r['raw']) && is_string($r['raw']['accountToken'] ?? null)) {
        $accountToken = $r['raw']['accountToken'];
    }
    $r['accountToken']     = $accountToken;
    $r['authCodeProvided'] = $authCode !== '';
    return $r;
}

/**
 * @return array{path: string, classification: string, httpStatus: ?int,
 *               responseCode: string, responseMessage: string,
 *               responseReferenceNo: string, accountTokenProvided: bool, raw: mixed}
 */
function probeDebit(
    Transport $transport,
    string $merchantId,
    string $externalStoreId,
    string $partnerReferenceNo,
    string $accountToken,
): array {
    // /v1.1/debit/payment-host-to-host mandatory fields (subscription spec):
    //   top-level: partnerReferenceNo, merchantId, externalStoreId, amount,
    //              urlParams[] (each needs url + type=PAY_RETURN + isDeepLink)
    //   additionalInfo: accountToken  ← NOT top-level
    // Omitting any of these trips 4000702 "Invalid Mandatory Field {…}".
    $body = [
        'partnerReferenceNo' => $partnerReferenceNo,
        'merchantId'         => $merchantId,
        'externalStoreId'    => $externalStoreId !== '' ? $externalStoreId : 'PROBE-STORE-ID',
        'amount'             => ['value' => '10000.00', 'currency' => 'IDR'],
        'urlParams'          => [
            [
                'url'        => 'https://localhost/probe-pay-return',
                'type'       => 'PAY_RETURN',
                'isDeepLink' => 'N',
            ],
        ],
        'additionalInfo'     => [
            'accountToken' => $accountToken !== '' ? $accountToken : 'PROBE-INVALID-ACCOUNT-TOKEN',
        ],
    ];
    $r = probeRequest($transport, '/v1.1/debit/payment-host-to-host', $body);

    $refNo = '';
    if (is_array($r['raw']) && is_string($r['raw']['partnerReferenceNo'] ?? null)) {
        $refNo = $r['raw']['partnerReferenceNo'];
    }
    $r['responseReferenceNo']   = $refNo;
    $r['accountTokenProvided']  = $accountToken !== '';
    return $r;
}

/**
 * @return array{path: string, classification: string, httpStatus: ?int,
 *               responseCode: string, responseMessage: string, raw: mixed}
 */
function probeDebitStatus(
    Transport $transport,
    string $merchantId,
    string $externalStoreId,
    string $partnerReferenceNo,
): array {
    // /v1.0/debit/status (svc 55) mandatory fields: originalPartnerReferenceNo,
    // merchantId, externalStoreId, serviceCode (the queried txn's service —
    // 54 for the debit), and an amount object. Omitting amount.value trips
    // 4005502 "Invalid mandatory field {value}".
    $body = [
        'originalPartnerReferenceNo' => $partnerReferenceNo,
        'merchantId'                 => $merchantId,
        'externalStoreId'            => $externalStoreId !== '' ? $externalStoreId : 'PROBE-STORE-ID',
        'serviceCode'                => '54',
        'amount'                     => ['value' => '10000.00', 'currency' => 'IDR'],
    ];
    return probeRequest($transport, '/v1.0/debit/status', $body);
}

/**
 * @return array{path: string, classification: string, httpStatus: ?int,
 *               responseCode: string, responseMessage: string,
 *               accountTokenProvided: bool, raw: mixed}
 */
function probeUnbind(Transport $transport, string $merchantId, string $accountToken): array
{
    // /v1.0/registration-account-unbinding (NO /unbind suffix). merchantId
    // is top-level mandatory; the binding to revoke is identified by EITHER
    // additionalInfo.accountToken OR a top-level partnerReferenceNo (at
    // least one — unlike bind, both may be sent together). We send the
    // accountToken when we have one, else a partnerReferenceNo so the
    // endpoint shape is still exercised.
    $body = ['merchantId' => $merchantId];
    if ($accountToken !== '') {
        $body['additionalInfo'] = ['accountToken' => $accountToken];
    } else {
        $body['partnerReferenceNo'] = 'PROBE-UNBIND-' . bin2hex(random_bytes(4));
    }
    $r = probeRequest($transport, '/v1.0/registration-account-unbinding', $body);
    $r['accountTokenProvided'] = $accountToken !== '';
    return $r;
}

/**
 * Generic POST-and-classify helper. The caller controls the body so
 * each step can send the shape the gateway expects.
 *
 * @param array<string, mixed> $body
 * @return array{path: string, classification: string, httpStatus: ?int,
 *               responseCode: string, responseMessage: string, raw: mixed}
 */
function probeRequest(Transport $transport, string $path, array $body): array
{
    try {
        $decoded = $transport->send(method: 'POST', path: $path, body: $body);

        return [
            'path'            => $path,
            'classification'  => 'success',
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
 * Dump a probe step's raw response verbatim. Arrays (decoded JSON
 * bodies) are pretty-printed; strings (error text, truncated bodies)
 * are echoed as-is. Empty values print a placeholder so every step
 * shows *something* for its response.
 *
 * @param array<string, mixed>|string|null $raw
 */
function printRawResponse(mixed $raw): void
{
    echo "   Raw response:\n";
    if (is_array($raw)) {
        $str = json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } else {
        $str = (string) $raw;
    }
    if (trim($str) === '') {
        echo "     (empty)\n";
        return;
    }
    echo "     " . str_replace("\n", "\n     ", $str) . "\n";
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
        echo "✗ No access token in response. Flow probes below will all\n";
        echo "  fail until creds/signature/keys are correct.\n";
        echo "\n  Raw response body (first 400 chars):\n";
        $raw = $tp['raw'];
        $rawStr = is_array($raw)
            ? json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : (string) $raw;
        echo "    " . str_replace("\n", "\n    ", substr($rawStr, 0, 400));
        if (strlen($rawStr) > 400) {
            echo "\n    [...truncated, " . (strlen($rawStr) - 400) . " more chars]";
        }
        echo "\n";
    }
    echo "\n";

    // Only steps that actually ran are present (others are null when a
    // single step was selected with --only).
    $ga = $report['flow']['getAuthCode'];
    $bp = $report['flow']['bind'];
    $dp = $report['flow']['debit'];
    $sp = $report['flow']['debitStatus'];
    $up = $report['flow']['unbind'];

    if ($ga !== null || $bp !== null || $dp !== null || $sp !== null || $up !== null) {
        echo "--- Flow Probe ---\n";
    }

    if ($ga !== null) {
        echo sprintf("1. get-auth-code           %s  (HTTP %s)\n",
            $ga['classification'], $ga['httpStatus'] ?? '?');
        echo "   URL: " . $ga['url'] . "\n";
        echo "   Content-Type: " . ($ga['contentType'] ?: '(none)') . "\n";
        printRawResponse($ga['raw']);
    }

    if ($bp !== null) {
        echo sprintf("\n2. registration-account-binding   %s  (HTTP %s)\n",
            $bp['classification'], $bp['httpStatus'] ?? '?');
        echo "   path: {$bp['path']}\n";
        echo "   " . ($bp['responseCode'] ?: '?') . ' ' . trim((string) $bp['responseMessage']) . "\n";
        echo "   authCode supplied via env: " . ($bp['authCodeProvided'] ? 'yes' : 'no — used dummy') . "\n";
        if ($bp['accountToken'] !== '') {
            echo "   ✓ accountToken received → chained into debit\n";
        }
        printRawResponse($bp['raw']);
    }

    if ($dp !== null) {
        echo sprintf("\n3. debit/payment-host-to-host    %s  (HTTP %s)\n",
            $dp['classification'], $dp['httpStatus'] ?? '?');
        echo "   path: {$dp['path']}\n";
        echo "   " . ($dp['responseCode'] ?: '?') . ' ' . trim((string) $dp['responseMessage']) . "\n";
        echo "   accountToken supplied: " . ($dp['accountTokenProvided'] ? 'yes' : 'no — used dummy') . "\n";
        printRawResponse($dp['raw']);
    }

    if ($sp !== null) {
        echo sprintf("\n4. debit/status                  %s  (HTTP %s)\n",
            $sp['classification'], $sp['httpStatus'] ?? '?');
        echo "   path: {$sp['path']}\n";
        echo "   " . ($sp['responseCode'] ?: '?') . ' ' . trim((string) $sp['responseMessage']) . "\n";
        printRawResponse($sp['raw']);
    }

    if ($up !== null) {
        echo sprintf("\n5. registration-account-unbinding %s  (HTTP %s)\n",
            $up['classification'], $up['httpStatus'] ?? '?');
        echo "   path: {$up['path']}\n";
        echo "   " . ($up['responseCode'] ?: '?') . ' ' . trim((string) $up['responseMessage']) . "\n";
        echo "   accountToken supplied: " . ($up['accountTokenProvided'] ? 'yes' : 'no — used partnerReferenceNo') . "\n";
        printRawResponse($up['raw']);
    }

    echo "\n";
    echo "=== End of report ===\n";
    echo "\nLegend: success | looks-valid-path | may-be-wrong-path | indeterminate-*\n";
    echo "Anything classified 'may-be-wrong-path' indicates the corresponding\n";
    echo "PATH_* const in src/Service/*.php should be updated.\n";
}
