<?php

declare(strict_types=1);

namespace ShopeePay\Tests\Unit\Service;

use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ShopeePay\Config;
use ShopeePay\Dto\AccountLinking\BindAccountRequest;
use ShopeePay\Dto\AccountLinking\GetAuthCodeRequest;
use ShopeePay\Dto\AccountLinking\InquiryRequest;
use ShopeePay\Dto\AccountLinking\UnbindRequest;
use ShopeePay\Environment;
use ShopeePay\Exception\ApiException;
use ShopeePay\Http\AccessTokenManager;
use ShopeePay\Http\HeaderBuilder;
use ShopeePay\Http\Signer;
use ShopeePay\Http\Transport;
use ShopeePay\Service\AccountLinkingService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

final class AccountLinkingServiceTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures';

    public function testBuildAuthCodeUrlContainsAllRequiredParams(): void
    {
        [$service]   = $this->build();
        $request = new GetAuthCodeRequest(
            redirectUrl:        'https://merchant.example/oauth/callback',
            state:              'abc123',
            partnerReferenceNo: 'BIND-001',
        );

        $url = $service->buildAuthCodeUrl($request);

        $parsed = parse_url($url);
        self::assertIsArray($parsed);
        self::assertSame('https',                                   $parsed['scheme'] ?? null);
        self::assertSame('api.snap.uat.airpay.co.id',               $parsed['host']   ?? null);
        self::assertSame('/v1.0/get-auth-code',                     $parsed['path']   ?? null);

        parse_str($parsed['query'] ?? '', $q);
        self::assertSame('BIND-001',                                  $q['partnerReferenceNo'] ?? null);
        self::assertSame('M1234',                                     $q['merchantId']         ?? null);
        self::assertSame('abc123',                                    $q['state']              ?? null);
        self::assertSame('https://merchant.example/oauth/callback',   $q['redirectUrl']        ?? null);
        // channelId is sent as the CHANNEL-ID header by Transport, not as a
        // query param on /v1.0/get-auth-code.
        self::assertArrayNotHasKey('channelId', $q);
        self::assertArrayNotHasKey('scopes', $q); // not requested → omitted
    }

    public function testBuildAuthCodeUrlRfc3986EncodesRedirectUrl(): void
    {
        // RFC3986 — slashes and colons inside the redirectUrl must be
        // percent-encoded so SNAP BI parses the query right. parse_url + parse_str
        // will reverse this for us in the assertion above; here we check the raw
        // string contains the percent-encoded form, not the literal slashes.
        [$service]   = $this->build();
        $url = $service->buildAuthCodeUrl(new GetAuthCodeRequest(
            redirectUrl:        'https://merchant.example/cb?foo=bar&x=1',
            state:              'st-1',
            partnerReferenceNo: 'BIND-002',
        ));

        self::assertStringContainsString(
            'redirectUrl=https%3A%2F%2Fmerchant.example%2Fcb%3Ffoo%3Dbar%26x%3D1',
            $url,
        );
    }

    public function testBuildAuthCodeUrlIncludesScopesWhenSupplied(): void
    {
        [$service]   = $this->build();
        $url = $service->buildAuthCodeUrl(new GetAuthCodeRequest(
            redirectUrl:        'https://merchant.example/cb',
            state:              'st-1',
            partnerReferenceNo: 'BIND-003',
            scopes:             ['PAYMENT', 'REFUND'],
        ));

        self::assertStringContainsString('scopes=PAYMENT%2CREFUND', $url);
    }

    public function testBuildAuthCodeUrlOmitsPartnerReferenceNoWhenNull(): void
    {
        // SNAP BI does not require partnerReferenceNo on /v1.0/get-auth-code.
        // When the caller omits it, it must NOT appear in the query string.
        [$service] = $this->build();
        $url = $service->buildAuthCodeUrl(new GetAuthCodeRequest(
            redirectUrl: 'https://merchant.example/cb',
            state:       'st-omit',
            scopes:      ['ACCOUNT_BINDING'],
        ));

        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $q);
        self::assertArrayNotHasKey('partnerReferenceNo', $q);
        self::assertSame('ACCOUNT_BINDING', $q['scopes'] ?? null);
    }

    public function testGenerateStateReturns32HexChars(): void
    {
        $a = GetAuthCodeRequest::generateState();
        $b = GetAuthCodeRequest::generateState();

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $a);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $b);
        self::assertNotSame($a, $b);
    }

    public function testBindPostsToCorrectPathAndHydratesResponse(): void
    {
        [$service, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(200, [], (string) json_encode([
            'responseCode'       => '2000700',
            'responseMessage'    => 'Successful',
            'referenceNo'        => 'SP-BIND-1',
            'partnerReferenceNo' => 'BIND-1',
            'accountToken'       => 'tok_live_abc',
        ])));

        $resp = $service->bind(new BindAccountRequest(
            authCode:           'AUTHCODE_XYZ',
            partnerReferenceNo: 'BIND-1',
        ));

        self::assertSame('2000700',           $resp->responseCode);
        self::assertSame('tok_live_abc',      $resp->accountToken);
        self::assertSame('SP-BIND-1',         $resp->referenceNo);
        self::assertSame('BIND-1',            $resp->partnerReferenceNo);

        // Verify the right path was POSTed to with the right body.
        $bindRequest = $http->getRequests()[1];
        self::assertSame('POST',                                              $bindRequest->getMethod());
        self::assertSame('/v1.0/registration-account-binding/bind',           $bindRequest->getUri()->getPath());
        self::assertJsonStringEqualsJsonString(
            '{"authCode":"AUTHCODE_XYZ","partnerReferenceNo":"BIND-1"}',
            (string) $bindRequest->getBody(),
        );
    }

    public function testUnbindPostsToCorrectPath(): void
    {
        [$service, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(200, [], (string) json_encode([
            'responseCode'       => '2000900',
            'responseMessage'    => 'Successful',
            'referenceNo'        => 'SP-UNBIND-1',
            'partnerReferenceNo' => 'UNBIND-1',
        ])));

        $resp = $service->unbind(new UnbindRequest(
            accountToken:       'tok_live_abc',
            partnerReferenceNo: 'UNBIND-1',
        ));

        self::assertSame('2000900',     $resp->responseCode);
        self::assertSame('SP-UNBIND-1', $resp->referenceNo);

        $unbindRequest = $http->getRequests()[1];
        self::assertSame('/v1.0/registration-account-unbinding/unbind', $unbindRequest->getUri()->getPath());
        self::assertJsonStringEqualsJsonString(
            '{"tokenId":"tok_live_abc","partnerReferenceNo":"UNBIND-1"}',
            (string) $unbindRequest->getBody(),
        );
    }

    public function testInquiryReturnsTypedStatus(): void
    {
        [$service, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(200, [], (string) json_encode([
            'responseCode'       => '2000800',
            'responseMessage'    => 'Successful',
            'referenceNo'        => 'SP-INQ-1',
            'partnerReferenceNo' => 'INQ-1',
            'accountStatus'      => 'ACTIVE',
        ])));

        $resp = $service->inquiry(new InquiryRequest(
            accountToken:       'tok_live_abc',
            partnerReferenceNo: 'INQ-1',
        ));

        self::assertSame('ACTIVE', $resp->accountStatus);
        self::assertTrue($resp->isActive());
        self::assertSame(
            '/v1.0/registration-account-inquiry/inquiry-status',
            $http->getRequests()[1]->getUri()->getPath(),
        );
    }

    public function testInquiryIsActiveIsCaseInsensitiveAndStrict(): void
    {
        [$service, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(200, [], (string) json_encode([
            'responseCode'       => '2000800',
            'responseMessage'    => 'Successful',
            'accountStatus'      => 'inactive',
        ])));

        $resp = $service->inquiry(new InquiryRequest('tok_live_abc', 'INQ-2'));

        self::assertFalse($resp->isActive());
    }

    public function testBindPropagatesApiException(): void
    {
        // Expired authCode — SNAP BI returns an HTTP 4xx with a 4030700-class
        // responseCode. Transport surfaces it as ApiException; the service
        // does not swallow it.
        [$service, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(403, [], (string) json_encode([
            'responseCode'    => '4030700',
            'responseMessage' => 'Auth code expired',
        ])));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('4030700: Auth code expired');

        $service->bind(new BindAccountRequest('expired-code', 'BIND-EXP-1'));
    }

    public function testBindRequestRejectsEmptyAuthCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('authCode must not be empty');

        new BindAccountRequest('   ', 'BIND-1');
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    /**
     * @return array{AccountLinkingService, MockClient}
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
        $service       = new AccountLinkingService($config, $transport);

        return [$service, $http];
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
}
