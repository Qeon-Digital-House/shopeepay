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
 * Required env vars (set before running):
 *   SHOPEEPAY_CLIENT_ID         — your client id (sandbox creds work)
 *   SHOPEEPAY_CLIENT_SECRET     — your client secret
 *   SHOPEEPAY_MERCHANT_ID       — your merchant id
 *   SHOPEEPAY_PRIVATE_KEY_PATH  — path to your merchant private RSA PEM
 *   SHOPEEPAY_PUBLIC_KEY_PATH   — path to the ShopeePay public RSA PEM
 *
 * Optional:
 *   SHOPEEPAY_ENV               — "sandbox" (default) | "production"
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
    $required = ['SHOPEEPAY_CLIENT_ID', 'SHOPEEPAY_CLIENT_SECRET', 'SHOPEEPAY_MERCHANT_ID',
                 'SHOPEEPAY_PRIVATE_KEY_PATH', 'SHOPEEPAY_PUBLIC_KEY_PATH'];
    foreach ($required as $var) {
        if (getenv($var) === false || getenv($var) === '') {
            fwrite(STDERR, sprintf(
                "Skipping: %s is not set. See examples/_bootstrap.php for the full list.\n",
                $var,
            ));
            exit(0);
        }
    }

    $privateKey = file_get_contents((string) getenv('SHOPEEPAY_PRIVATE_KEY_PATH'));
    $publicKey  = file_get_contents((string) getenv('SHOPEEPAY_PUBLIC_KEY_PATH'));
    if ($privateKey === false || $publicKey === false) {
        fwrite(STDERR, "Could not read one of the key files. Check the paths.\n");
        exit(1);
    }

    // PSR-18 client discovery. Requires that a PSR-18 implementation be
    // installed in the project (`composer require symfony/http-client` is
    // the typical choice; guzzle, kriswallsmith/buzz, etc. also work).
    $httpClient = \Http\Discovery\Psr18ClientDiscovery::find();
    $psr17      = new Psr17Factory();

    // ArrayAdapter — the access-token cache is in-process only. Real apps
    // should use a shared Redis/APCu PSR-16 cache so concurrent workers
    // share the token instead of each fetching their own.
    $cache = new Psr16Cache(new ArrayAdapter());

    $envName     = getenv('SHOPEEPAY_ENV') ?: 'sandbox';
    $environment = $envName === 'production' ? Environment::PRODUCTION : Environment::SANDBOX;

    $config = new Config(
        clientId:           (string) getenv('SHOPEEPAY_CLIENT_ID'),
        clientSecret:       (string) getenv('SHOPEEPAY_CLIENT_SECRET'),
        privateKey:         $privateKey,
        shopeepayPublicKey: $publicKey,
        merchantId:         (string) getenv('SHOPEEPAY_MERCHANT_ID'),
        httpClient:         $httpClient,
        requestFactory:     $psr17,
        streamFactory:      $psr17,
        cache:              $cache,
        environment:        $environment,
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
