<?php

declare(strict_types=1);

/**
 * Shared bootstrap for the runnable examples. Returns a `Config` plus the
 * four service instances, wired against the sandbox by default.
 *
 * Examples DO actually hit the network when SHOPEEPAY_CLIENT_ID is set —
 * keep that in mind before running 02-link-and-pay.php against production.
 * With the env var unset, the examples short-circuit before any network
 * call so they remain copy-paste-runnable for API exploration.
 *
 * Env var names follow .env.example (the canonical SDK convention).
 *
 * Required:
 *   SHOPEEPAY_CLIENT_ID         — your client id (sandbox creds work)
 *   SHOPEEPAY_SECRET_KEY        — your client secret
 *   SHOPEEPAY_SUBS_MERCHANT_ID   — your merchant id
 *
 * Keys — provide ONE of each pair (PEM-string form preferred; the *_PATH
 * forms are shell-friendly fallbacks read from disk):
 *   SHOPEEPAY_PRIVATE_KEY       — merchant private RSA PEM (string)
 *   SHOPEEPAY_PRIVATE_KEY_PATH  —   ...or path to the same PEM
 *   SHOPEEPAY_PUBLIC_KEY        — ShopeePay public RSA PEM (string)
 *   SHOPEEPAY_PUBLIC_KEY_PATH   —   ...or path to the same PEM
 *
 * Optional:
 *   SHOPEEPAY_SUBS_STORE_ID      — outlet id for multi-outlet merchants
 *   SHOPEEPAY_IS_PRODUCTION     — "true" → production, anything else → sandbox
 *   SHOPEEPAY_ACCOUNT_TOKEN     — required by examples 02/03/04 (a token
 *                                 from a prior successful bind())
 *
 * To run an example: `composer require symfony/http-client` (any PSR-18
 * client works), then `php examples/02-link-and-pay.php`.
 */

require __DIR__ . '/../vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use ShopeePay\Config;
use ShopeePay\Environment;
use ShopeePay\Http\AccessTokenManager;
use ShopeePay\Http\HeaderBuilder;
use ShopeePay\Http\Signer;
use ShopeePay\Http\Transport;
use ShopeePay\Service\AccountLinkingService;
use ShopeePay\Service\AuthCaptureService;
use ShopeePay\Service\LinkAndPayService;
use ShopeePay\Service\SubscriptionService;
use ShopeePay\Webhook\EventFactory;
use ShopeePay\Webhook\Verifier;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

function shopeepay_example_bootstrap(): array
{
    $required = ['SHOPEEPAY_CLIENT_ID', 'SHOPEEPAY_SECRET_KEY', 'SHOPEEPAY_SUBS_MERCHANT_ID'];
    foreach ($required as $var) {
        if (getenv($var) === false || getenv($var) === '') {
            fwrite(STDERR, sprintf(
                "Skipping: %s is not set. See examples/_bootstrap.php for the full list.\n",
                $var,
            ));
            exit(0);
        }
    }

    $privateKey = shopeepay_load_pem('SHOPEEPAY_PRIVATE_KEY', 'SHOPEEPAY_PRIVATE_KEY_PATH');
    $publicKey  = shopeepay_load_pem('SHOPEEPAY_PUBLIC_KEY',  'SHOPEEPAY_PUBLIC_KEY_PATH');

    // PSR-18 client discovery. Requires that a PSR-18 implementation be
    // installed in the project (`composer require symfony/http-client` is
    // the typical choice; guzzle, kriswallsmith/buzz, etc. also work).
    $httpClient = \Http\Discovery\Psr18ClientDiscovery::find();
    $psr17      = new Psr17Factory();

    // ArrayAdapter — the access-token cache is in-process only. Real apps
    // should use a shared Redis/APCu PSR-16 cache so concurrent workers
    // share the token instead of each fetching their own.
    $cache = new Psr16Cache(new ArrayAdapter());

    $environment = strtolower((string) getenv('SHOPEEPAY_IS_PRODUCTION')) === 'true'
        ? Environment::PRODUCTION
        : Environment::SANDBOX;
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
        environment:        $environment,
        storeId:            $storeId,
    );

    $signer        = new Signer();
    $headerBuilder = new HeaderBuilder($config, $signer);
    $atm           = new AccessTokenManager($config, $headerBuilder);
    $transport     = new Transport($config, $headerBuilder, $atm);

    return [
        'config'         => $config,
        'accountLinking' => new AccountLinkingService($config, $transport),
        'linkAndPay'     => new LinkAndPayService($transport),
        'subscription'   => new SubscriptionService($transport),
        'authCapture'    => new AuthCaptureService($transport),
        'webhooks'       => new Verifier($config, $signer, new EventFactory()),
    ];
}

/**
 * Resolve a PEM from either a direct env var (the value IS the PEM) or a
 * `_PATH`-suffixed env var pointing to a file on disk. Exits the process
 * with a useful message if neither is usable.
 */
function shopeepay_load_pem(string $pemVar, string $pathVar): string
{
    $direct = (string) getenv($pemVar);
    if (str_contains($direct, '-----BEGIN')) {
        return $direct;
    }

    $path = (string) getenv($pathVar);
    if ($path === '') {
        fwrite(STDERR, sprintf(
            "Set %s (PEM string) or %s (file path).\n",
            $pemVar,
            $pathVar,
        ));
        exit(1);
    }

    $contents = @file_get_contents($path);
    if ($contents === false) {
        fwrite(STDERR, "Could not read $pathVar at $path\n");
        exit(1);
    }
    return $contents;
}
