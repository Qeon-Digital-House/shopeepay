<?php

declare(strict_types=1);

namespace ShopeePay\Tests\Unit\Service;

use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ShopeePay\Config;
use ShopeePay\Dto\AuthCapture\AuthorizeRequest;
use ShopeePay\Dto\AuthCapture\CaptureRequest;
use ShopeePay\Dto\AuthCapture\QueryAuthRequest;
use ShopeePay\Dto\AuthCapture\QueryAuthResponse;
use ShopeePay\Dto\AuthCapture\QueryCaptureRequest;
use ShopeePay\Dto\AuthCapture\QueryVoidRequest;
use ShopeePay\Dto\AuthCapture\RefundRequest;
use ShopeePay\Dto\AuthCapture\VoidRequest;
use ShopeePay\Dto\Common\Money;
use ShopeePay\Environment;
use ShopeePay\Exception\ApiException;
use ShopeePay\Http\AccessTokenManager;
use ShopeePay\Http\HeaderBuilder;
use ShopeePay\Http\Signer;
use ShopeePay\Http\Transport;
use ShopeePay\Service\AuthCaptureService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

final class AuthCaptureServiceTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures';

    public function testAuthorizePostsAuthHostToHostPathWithAllFields(): void
    {
        [$service, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(200, [], (string) json_encode([
            'responseCode'       => '2006300',
            'responseMessage'    => 'Successful',
            'referenceNo'        => 'SP-AUTH-001',
            'partnerReferenceNo' => 'AUTH-2026-0001',
            'webRedirectUrl'     => 'https://shopee.example/auth/abc',
        ])));

        $resp = $service->authorize(new AuthorizeRequest(
            partnerReferenceNo: 'AUTH-2026-0001',
            amount:             new Money('250000.00'),
            accountToken:       'tok_live_xyz',
            validUpTo:          '2026-06-08T10:00:00.000+07:00',
            additionalInfo:     ['note' => 'hotel deposit'],
        ));

        self::assertSame('SP-AUTH-001',                            $resp->referenceNo);
        self::assertSame('https://shopee.example/auth/abc',        $resp->webRedirectUrl);

        $req = $http->getRequests()[1];
        self::assertSame('POST',                                   $req->getMethod());
        // Path is one of the SNAP-BI-convention guesses (the design doc only
        // pins /v1.0/auth/refund). Lock here so sandbox probe in step 11
        // surfaces a mismatch loudly rather than silently.
        self::assertSame('/v1.0/auth/payment-host-to-host',        $req->getUri()->getPath());

        $body = (array) json_decode((string) $req->getBody(), true);
        self::assertSame('AUTH-2026-0001',                         $body['partnerReferenceNo']);
        self::assertSame(['value' => '250000.00', 'currency' => 'IDR'], $body['amount']);
        self::assertSame('tok_live_xyz',                           $body['accountToken']);
        self::assertSame('2026-06-08T10:00:00.000+07:00',          $body['validUpTo']);
        self::assertSame(['note' => 'hotel deposit'],              $body['additionalInfo']);
    }

    public function testAuthorizeOmitsValidUpToAndAdditionalInfoWhenNotSupplied(): void
    {
        [$service, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(200, [], (string) json_encode([
            'responseCode' => '2006300',
            'referenceNo'  => 'SP-AUTH-002',
        ])));

        $service->authorize(new AuthorizeRequest(
            partnerReferenceNo: 'AUTH-2',
            amount:             new Money('10000.00'),
            accountToken:       'tok_live',
        ));

        $body = (array) json_decode((string) $http->getRequests()[1]->getBody(), true);
        self::assertArrayNotHasKey('validUpTo',      $body);
        self::assertArrayNotHasKey('additionalInfo', $body);
    }

    public function testAuthorizeRejectsEmptyPartnerReferenceNo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('partnerReferenceNo must not be empty');

        new AuthorizeRequest(
            partnerReferenceNo: '   ',
            amount:             new Money('10000.00'),
            accountToken:       'tok',
        );
    }

    public function testCapturePostsToCapturePathWithCaptureAmount(): void
    {
        [$service, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(200, [], (string) json_encode([
            'responseCode'              => '2006500',
            'responseMessage'           => 'Successful',
            'referenceNo'               => 'SP-CAP-001',
            'partnerReferenceNo'        => 'CAP-2026-1',
            'originalReferenceNo'       => 'SP-AUTH-001',
            'capturedAmount'            => ['value' => '180000.00', 'currency' => 'IDR'],
        ])));

        // Partial capture of an earlier auth — only 180k of the 250k held.
        $resp = $service->capture(new CaptureRequest(
            captureAmount:       new Money('180000.00'),
            partnerReferenceNo:  'CAP-2026-1',
            originalReferenceNo: 'SP-AUTH-001',
        ));

        self::assertSame('SP-CAP-001', $resp->referenceNo);
        self::assertNotNull($resp->capturedAmount);
        self::assertSame('180000.00', $resp->capturedAmount->value);

        $req = $http->getRequests()[1];
        self::assertSame('/v1.0/auth/capture', $req->getUri()->getPath());
        $body = (array) json_decode((string) $req->getBody(), true);
        self::assertSame(['value' => '180000.00', 'currency' => 'IDR'], $body['captureAmount']);
        self::assertSame('SP-AUTH-001', $body['originalReferenceNo']);
        self::assertArrayNotHasKey('originalPartnerReferenceNo', $body);
    }

    public function testCaptureRequiresAtLeastOneOriginalReference(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one of originalReferenceNo');

        new CaptureRequest(
            captureAmount:      new Money('10000.00'),
            partnerReferenceNo: 'CAP-X',
        );
    }

    public function testVoidPostsToVoidPathAndIncludesReason(): void
    {
        [$service, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(200, [], (string) json_encode([
            'responseCode'              => '2006700',
            'responseMessage'           => 'Successful',
            'referenceNo'               => 'SP-VOID-001',
            'partnerReferenceNo'        => 'VOID-2026-1',
            'originalReferenceNo'       => 'SP-AUTH-001',
        ])));

        $resp = $service->void(new VoidRequest(
            partnerReferenceNo:  'VOID-2026-1',
            originalReferenceNo: 'SP-AUTH-001',
            reason:              'customer cancelled before capture',
        ));

        self::assertSame('SP-VOID-001', $resp->referenceNo);

        $req = $http->getRequests()[1];
        self::assertSame('/v1.0/auth/void', $req->getUri()->getPath());
        $body = (array) json_decode((string) $req->getBody(), true);
        self::assertSame('customer cancelled before capture', $body['reason']);
        self::assertArrayNotHasKey('additionalInfo', $body);
    }

    public function testVoidRequestRejectsEmptyPartnerReferenceNo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('partnerReferenceNo must not be empty');

        new VoidRequest(
            partnerReferenceNo:  '',
            originalReferenceNo: 'SP-AUTH-1',
        );
    }

    public function testRefundPostsToAuthRefundPathNotDebitRefund(): void
    {
        [$service, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(200, [], (string) json_encode([
            'responseCode'    => '2006900',
            'responseMessage' => 'Successful',
            'refundNo'        => 'SP-AUTH-REFUND-1',
            'partnerRefundNo' => 'AC-REFUND-1',
            'refundAmount'    => ['value' => '180000.00', 'currency' => 'IDR'],
        ])));

        $resp = $service->refund(new RefundRequest(
            refundAmount:        new Money('180000.00'),
            partnerRefundNo:     'AC-REFUND-1',
            originalReferenceNo: 'SP-CAP-001',
            reason:              'customer service goodwill',
        ));

        self::assertSame('SP-AUTH-REFUND-1', $resp->refundNo);
        self::assertNotNull($resp->refundAmount);
        self::assertSame('180000.00', $resp->refundAmount->value);

        $req = $http->getRequests()[1];
        // CRITICAL: auth refund and debit refund live on different paths
        // (design doc, line 230). Regressing this would silently route
        // auth-flow refunds into the debit subsystem.
        self::assertSame('/v1.0/auth/refund', $req->getUri()->getPath());
    }

    public function testRefundResponseHydratesNullMoneyOnMalformedGatewayValue(): void
    {
        [$service, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(200, [], (string) json_encode([
            'responseCode' => '2006900',
            'refundNo'     => 'SP-AUTH-REFUND-2',
            'refundAmount' => ['value' => 'NOT-A-NUMBER', 'currency' => 'IDR'],
        ])));

        $resp = $service->refund(new RefundRequest(
            refundAmount:        new Money('10000.00'),
            partnerRefundNo:     'AC-REFUND-2',
            originalReferenceNo: 'SP-CAP-001',
        ));

        self::assertNull($resp->refundAmount);
        // Caller can still see what was on the wire via $raw.
        self::assertSame('NOT-A-NUMBER', $resp->raw['refundAmount']['value']);
    }

    public function testQueryAuthPostsToStatusPathWithServiceCode63(): void
    {
        [$service, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(200, [], (string) json_encode([
            'responseCode'            => '2006400',
            'latestTransactionStatus' => '00',
            'transactionStatusDesc'   => 'Authorized',
            'originalReferenceNo'     => 'SP-AUTH-001',
        ])));

        $resp = $service->queryAuth(new QueryAuthRequest(originalReferenceNo: 'SP-AUTH-001'));

        self::assertTrue($resp->isSuccess());
        self::assertTrue($resp->isTerminal());
        self::assertSame(QueryAuthResponse::STATUS_SUCCESS, $resp->latestTransactionStatus);

        $req = $http->getRequests()[1];
        self::assertSame('/v1.0/auth/status', $req->getUri()->getPath());
        $body = (array) json_decode((string) $req->getBody(), true);
        self::assertSame('63', $body['serviceCode']);
    }

    public function testQueryAuthIsTerminalForExpiredAndCancelled(): void
    {
        // Both `expired` and `cancelled` are auth-specific terminal states
        // not present in the LinkAndPay status taxonomy. Polling callers
        // need to break out of the loop in these cases too.
        [$service, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(200, [], (string) json_encode([
            'responseCode'            => '2006400',
            'latestTransactionStatus' => QueryAuthResponse::STATUS_EXPIRED,
            'transactionStatusDesc'   => 'Expired',
        ])));

        $resp = $service->queryAuth(new QueryAuthRequest(originalPartnerReferenceNo: 'AUTH-7'));

        self::assertFalse($resp->isSuccess());
        self::assertTrue($resp->isTerminal());
    }

    public function testQueryCaptureUsesCaptureStatusPath(): void
    {
        [$service, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(200, [], (string) json_encode([
            'responseCode'            => '2006600',
            'latestTransactionStatus' => '00',
            'transactionStatusDesc'   => 'Captured',
        ])));

        $resp = $service->queryCapture(new QueryCaptureRequest(originalReferenceNo: 'SP-CAP-001'));

        self::assertTrue($resp->isSuccess());
        $req = $http->getRequests()[1];
        self::assertSame('/v1.0/auth/capture/status', $req->getUri()->getPath());
        $body = (array) json_decode((string) $req->getBody(), true);
        self::assertSame('65', $body['serviceCode']);
    }

    public function testQueryVoidUsesVoidStatusPath(): void
    {
        [$service, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(200, [], (string) json_encode([
            'responseCode'            => '2006800',
            'latestTransactionStatus' => '00',
            'transactionStatusDesc'   => 'Voided',
        ])));

        $resp = $service->queryVoid(new QueryVoidRequest(originalReferenceNo: 'SP-VOID-001'));

        self::assertTrue($resp->isSuccess());
        self::assertTrue($resp->isTerminal());
        $req = $http->getRequests()[1];
        self::assertSame('/v1.0/auth/void/status', $req->getUri()->getPath());
        $body = (array) json_decode((string) $req->getBody(), true);
        self::assertSame('67', $body['serviceCode']);
    }

    public function testCapturePropagatesApiExceptionOnStateMachineViolation(): void
    {
        // Capturing an already-captured auth (one-partial-capture rule)
        // surfaces from the gateway as an ApiException — v1 doesn't try
        // to detect this client-side. Lock the propagation path so a
        // future "helpful" client-side check doesn't swallow the error.
        [$service, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(409, [], (string) json_encode([
            'responseCode'    => '4096501',
            'responseMessage' => 'Authorization already captured',
        ])));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('4096501: Authorization already captured');

        $service->capture(new CaptureRequest(
            captureAmount:       new Money('10000.00'),
            partnerReferenceNo:  'CAP-DUP',
            originalReferenceNo: 'SP-AUTH-001',
        ));
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    /**
     * @return array{AuthCaptureService, MockClient}
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
        $service       = new AuthCaptureService($transport);

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
