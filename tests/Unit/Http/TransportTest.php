<?php

declare(strict_types=1);

namespace ShopeePay\Tests\Unit\Http;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
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

final class TransportTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures';

    public function testHappyPathReturnsDecodedBody(): void
    {
        [$transport, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse($this->paymentSuccessResponse());

        $decoded = $transport->send('POST', '/v1.1/debit/payment-host-to-host', [
            'partnerReferenceNo' => 'ORDER-1',
            'amount' => ['value' => '150000.00', 'currency' => 'IDR'],
        ]);

        self::assertSame('2005400', $decoded['responseCode']);
        self::assertSame('https://shopee.example/redirect/abc', $decoded['webRedirectUrl']);
        self::assertCount(2, $http->getRequests()); // 1 access-token + 1 transaction
    }

    public function testTransactionRequestCarriesSignedHeaders(): void
    {
        [$transport, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse($this->paymentSuccessResponse());

        $transport->send('POST', '/v1.1/debit/payment-host-to-host', ['x' => 1]);

        $txRequest = $http->getRequests()[1];
        self::assertSame('POST', $txRequest->getMethod());
        self::assertSame('client-x',         $txRequest->getHeaderLine('X-PARTNER-ID'));
        self::assertSame('Bearer tk-1',      $txRequest->getHeaderLine('Authorization'));
        self::assertSame('95221',            $txRequest->getHeaderLine('CHANNEL-ID'));
        self::assertSame('application/json', $txRequest->getHeaderLine('Content-Type'));
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{32}$/',
            $txRequest->getHeaderLine('X-EXTERNAL-ID'),
        );
        self::assertNotEmpty($txRequest->getHeaderLine('X-SIGNATURE'));
        // Body is the minified JSON.
        self::assertSame('{"x":1}', (string) $txRequest->getBody());
    }

    public function testRetriesOnHttp401AndSucceedsOnSecondAttempt(): void
    {
        [$transport, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-stale'));
        $http->addResponse(new Response(401, [], (string) json_encode([
            'responseCode'    => '4011000',
            'responseMessage' => 'Token expired',
        ])));
        $http->addResponse($this->tokenResponse('tk-fresh'));
        $http->addResponse($this->paymentSuccessResponse());

        $decoded = $transport->send('POST', '/v1.1/debit/payment-host-to-host', ['x' => 1]);

        self::assertSame('2005400', $decoded['responseCode']);
        self::assertCount(4, $http->getRequests());

        // Second transaction call should carry the fresh token.
        self::assertSame('Bearer tk-fresh', $http->getRequests()[3]->getHeaderLine('Authorization'));
    }

    public function testRetriesOnInBody4011AndSucceeds(): void
    {
        // HTTP 200 wrapper with an in-body 4011000 — real SNAP BI behavior.
        [$transport, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-stale'));
        $http->addResponse(new Response(200, [], (string) json_encode([
            'responseCode'    => '4011500',
            'responseMessage' => 'Token expired (in body)',
        ])));
        $http->addResponse($this->tokenResponse('tk-fresh'));
        $http->addResponse($this->paymentSuccessResponse());

        $transport->send('POST', '/v1.1/debit/payment-host-to-host', ['x' => 1]);

        self::assertCount(4, $http->getRequests());
        self::assertSame('Bearer tk-fresh', $http->getRequests()[3]->getHeaderLine('Authorization'));
    }

    public function testTwoConsecutiveAuthFailuresThrowApiExceptionNoThirdAttempt(): void
    {
        [$transport, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(401, [], (string) json_encode([
            'responseCode'    => '4011000',
            'responseMessage' => 'Token expired',
        ])));
        $http->addResponse($this->tokenResponse('tk-2'));
        $http->addResponse(new Response(401, [], (string) json_encode([
            'responseCode'    => '4011000',
            'responseMessage' => 'Token expired again',
        ])));

        try {
            $transport->send('POST', '/v1.1/debit/payment-host-to-host', ['x' => 1]);
            self::fail('Expected ApiException after two consecutive auth failures');
        } catch (ApiException $e) {
            self::assertSame('4011000', $e->responseCode);
            self::assertSame('Token expired again', $e->responseMessage);
        }

        // Exactly 4 requests: two token fetches + two transactions. No third
        // transaction attempt — the retry budget is one.
        self::assertCount(4, $http->getRequests());
    }

    public function testNonAuthFailureDoesNotRetry(): void
    {
        // 4xx that is NOT a token problem must propagate immediately —
        // the side-effect may have already happened.
        [$transport, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(400, [], (string) json_encode([
            'responseCode'       => '4005401',
            'responseMessage'    => 'Invalid amount',
            'partnerReferenceNo' => 'ORDER-1',
        ])));

        try {
            $transport->send('POST', '/v1.1/debit/payment-host-to-host', ['amount' => 'bad']);
            self::fail('Expected ApiException for non-auth 4xx');
        } catch (ApiException $e) {
            self::assertSame('4005401', $e->responseCode);
            self::assertSame('ORDER-1', $e->partnerReferenceNo);
        }

        self::assertCount(2, $http->getRequests()); // token + 1 tx, no retry
    }

    public function testNetworkFailureMapsToNetworkException(): void
    {
        [$transport, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));

        $http->addException(new class extends \RuntimeException implements NetworkExceptionInterface {
            public function getRequest(): RequestInterface
            {
                return (new Psr17Factory())->createRequest('POST', 'https://example/');
            }
        });

        $this->expectException(NetworkException::class);
        $transport->send('POST', '/v1.1/debit/payment-host-to-host', ['x' => 1]);
    }

    public function testMalformedJsonInTransactionResponseThrowsNetworkException(): void
    {
        [$transport, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(502, [], '<html>nginx 502</html>'));

        $this->expectException(NetworkException::class);
        $this->expectExceptionMessage('malformed gateway response');

        $transport->send('POST', '/v1.1/debit/payment-host-to-host', ['x' => 1]);
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    /**
     * @return array{Transport, MockClient}
     */
    private function build(): array
    {
        $psr17 = new Psr17Factory();
        $http  = new MockClient($psr17);
        $cache = new Psr16Cache(new ArrayAdapter());

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
            environment:                Environment::SANDBOX,
        );

        $headerBuilder = new HeaderBuilder($config, new Signer());
        $atm           = new AccessTokenManager($config, $headerBuilder);
        $transport     = new Transport($config, $headerBuilder, $atm);

        return [$transport, $http];
    }

    private function tokenResponse(string $token): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'responseCode'    => '2007300',
            'responseMessage' => 'Successful',
            'accessToken'     => $token,
            'tokenType'       => 'Bearer',
            'expiresIn'       => '900',
        ]));
    }

    private function paymentSuccessResponse(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'responseCode'    => '2005400',
            'responseMessage' => 'Successful',
            'referenceNo'     => 'SP-2026-001',
            'webRedirectUrl'  => 'https://shopee.example/redirect/abc',
        ]));
    }
}
