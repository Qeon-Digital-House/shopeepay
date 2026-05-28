<?php

declare(strict_types=1);

namespace ShopeePay\Tests\Unit\Http;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ShopeePay\Config;
use ShopeePay\Environment;
use ShopeePay\Exception\ApiException;
use ShopeePay\Exception\NetworkException;
use ShopeePay\Http\AccessTokenManager;
use ShopeePay\Http\HeaderBuilder;
use ShopeePay\Http\Signer;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

final class AccessTokenManagerTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures';

    public function testRefreshPostsToAccessTokenEndpointAndCachesResult(): void
    {
        [$mgr, $http, $cache] = $this->build();

        $http->addResponse($this->successResponse('access-tok-1'));

        $token = $mgr->get();

        self::assertSame('access-tok-1', $token);
        self::assertCount(1, $http->getRequests());

        $request = $http->getRequests()[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://api.snap.uat.airpay.co.id/v1.0/access-token/b2b',
            (string) $request->getUri(),
        );
        self::assertSame('client-x', $request->getHeaderLine('X-CLIENT-KEY'));
        self::assertNotEmpty($request->getHeaderLine('X-SIGNATURE'));
        self::assertNotEmpty($request->getHeaderLine('X-TIMESTAMP'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('{"grantType":"client_credentials"}', (string) $request->getBody());

        self::assertSame(
            'access-tok-1',
            $cache->get('shopeepay.access_token.sandbox.client-x'),
        );
    }

    public function testSubsequentCallsHitCacheAndDoNoNetwork(): void
    {
        [$mgr, $http] = $this->build();
        $http->addResponse($this->successResponse('access-tok-2'));

        $first  = $mgr->get();
        $second = $mgr->get();
        $third  = $mgr->get();

        self::assertSame('access-tok-2', $first);
        self::assertSame($first, $second);
        self::assertSame($first, $third);
        self::assertCount(1, $http->getRequests());
    }

    public function testInvalidateForcesARefresh(): void
    {
        [$mgr, $http] = $this->build();
        $http->addResponse($this->successResponse('first-tok'));
        $http->addResponse($this->successResponse('second-tok'));

        self::assertSame('first-tok', $mgr->get());
        $mgr->invalidate();
        self::assertSame('second-tok', $mgr->get());
        self::assertCount(2, $http->getRequests());
    }

    public function testNonSuccessResponseCodeThrowsApiException(): void
    {
        [$mgr, $http] = $this->build();
        $http->addResponse(new Response(401, [], (string) json_encode([
            'responseCode'    => '4017300',
            'responseMessage' => 'Unauthorized client credentials',
        ])));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('4017300');

        $mgr->get();
    }

    public function testMalformedJsonResponseThrowsNetworkException(): void
    {
        [$mgr, $http] = $this->build();
        $http->addResponse(new Response(502, [], '<html>nginx 502</html>'));

        $this->expectException(NetworkException::class);
        $this->expectExceptionMessage('malformed access-token response');

        $mgr->get();
    }

    public function testMissingAccessTokenFieldThrowsApiException(): void
    {
        [$mgr, $http] = $this->build();
        // Gateway returns "success" code but no accessToken — defensive guard.
        $http->addResponse(new Response(200, [], (string) json_encode([
            'responseCode'    => '2007300',
            'responseMessage' => 'Successful',
        ])));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('access-token response missing accessToken');

        $mgr->get();
    }

    public function testCacheKeyIncludesEnvironmentAndClientId(): void
    {
        // Two managers configured for different environments must NOT
        // share token state.
        [$sandboxMgr, $sandboxHttp, $cache] = $this->build();
        $sandboxHttp->addResponse($this->successResponse('sandbox-tok'));
        $sandboxMgr->get();

        // Re-using the same cache, build a production manager for the
        // same clientId — should miss because the key differs.
        $prodMgr = $this->buildWithSharedCache($cache, Environment::PRODUCTION);
        [$mgr, $http] = $prodMgr;
        $http->addResponse($this->successResponse('prod-tok'));

        self::assertSame('prod-tok', $mgr->get());
        self::assertSame('sandbox-tok', $cache->get('shopeepay.access_token.sandbox.client-x'));
        self::assertSame('prod-tok',    $cache->get('shopeepay.access_token.production.client-x'));
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    /**
     * @return array{AccessTokenManager, MockClient, Psr16Cache}
     */
    private function build(): array
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        return $this->buildWithSharedCache($cache, Environment::SANDBOX);
    }

    /**
     * @return array{AccessTokenManager, MockClient, Psr16Cache}
     */
    private function buildWithSharedCache(Psr16Cache $cache, Environment $env): array
    {
        $psr17 = new Psr17Factory();
        $http  = new MockClient($psr17);
        $logger = $this->createMock(LoggerInterface::class);

        $config = new Config(
            clientId:                   'client-x',
            clientSecret:               'secret-x',
            privateKey:                 (string) file_get_contents(self::FIXTURES . '/merchant-private-test.pem'),
            shopeepayPublicKey:         (string) file_get_contents(self::FIXTURES . '/shopeepay-public-test.pem'),
            merchantId:                 'M1234',
            httpClient:                 $http,
            requestFactory:             $psr17,
            streamFactory:              $psr17,
            cache:                      $cache,
            environment:                $env,
            logger:                     $logger,
        );

        $mgr = new AccessTokenManager($config, new HeaderBuilder($config, new Signer()));

        return [$mgr, $http, $cache];
    }

    private function successResponse(string $token, string $expiresIn = '900'): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'responseCode'    => '2007300',
            'responseMessage' => 'Successful',
            'accessToken'     => $token,
            'tokenType'       => 'Bearer',
            'expiresIn'       => $expiresIn,
        ]));
    }
}
