<?php

declare(strict_types=1);

namespace ShopeePay\Tests\Unit\Service;

use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ShopeePay\Config;
use ShopeePay\Dto\Common\Money;
use ShopeePay\Dto\LinkAndPay\CheckStatusRequest;
use ShopeePay\Dto\LinkAndPay\CheckStatusResponse;
use ShopeePay\Dto\LinkAndPay\CreatePaymentRequest;
use ShopeePay\Dto\LinkAndPay\RefundRequest;
use ShopeePay\Environment;
use ShopeePay\Exception\ApiException;
use ShopeePay\Http\AccessTokenManager;
use ShopeePay\Http\HeaderBuilder;
use ShopeePay\Http\Signer;
use ShopeePay\Http\Transport;
use ShopeePay\Service\LinkAndPayService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

final class LinkAndPayServiceTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures';

    public function testCreatePostsToV11PathAndReturnsWebRedirectUrl(): void
    {
        [$service, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(200, [], (string) json_encode([
            'responseCode'       => '2005400',
            'responseMessage'    => 'Successful',
            'referenceNo'        => 'SP-2026-001',
            'partnerReferenceNo' => 'ORDER-2026-0042',
            'webRedirectUrl'     => 'https://shopee.example/redirect/abc',
        ])));

        $resp = $service->create(new CreatePaymentRequest(
            partnerReferenceNo: 'ORDER-2026-0042',
            amount:             new Money('150000.00'),
            accountToken:       'tok_live_abc',
        ));

        self::assertSame('https://shopee.example/redirect/abc', $resp->webRedirectUrl);
        self::assertSame('SP-2026-001',                         $resp->referenceNo);
        self::assertSame('ORDER-2026-0042',                     $resp->partnerReferenceNo);

        // Path is v1.1 per the design doc — explicit assertion to lock it.
        $req = $http->getRequests()[1];
        self::assertSame('POST',                                   $req->getMethod());
        self::assertSame('/v1.1/debit/payment-host-to-host',       $req->getUri()->getPath());

        $body = (array) json_decode((string) $req->getBody(), true);
        self::assertSame('ORDER-2026-0042', $body['partnerReferenceNo']);
        self::assertSame(['value' => '150000.00', 'currency' => 'IDR'], $body['amount']);
        self::assertSame('tok_live_abc', $body['accountToken']);
        self::assertArrayNotHasKey('additionalInfo', $body); // omitted when empty
    }

    public function testCreateSerializesAdditionalInfoWhenPresent(): void
    {
        [$service, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(200, [], (string) json_encode([
            'responseCode'   => '2005400',
            'webRedirectUrl' => 'https://shopee.example/redirect/abc',
        ])));

        $service->create(new CreatePaymentRequest(
            partnerReferenceNo: 'ORDER-1',
            amount:             new Money('150000.00'),
            accountToken:       'tok_live_abc',
            additionalInfo:     ['productId' => 'PROD-7', 'note' => 'Promo cashback'],
        ));

        $body = (array) json_decode((string) $http->getRequests()[1]->getBody(), true);
        self::assertSame(
            ['productId' => 'PROD-7', 'note' => 'Promo cashback'],
            $body['additionalInfo'],
        );
    }

    public function testCheckStatusReturnsTypedStatusAndDispatchesByServiceCode(): void
    {
        [$service, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(200, [], (string) json_encode([
            'responseCode'              => '2005500',
            'responseMessage'           => 'Successful',
            'latestTransactionStatus'   => '00',
            'transactionStatusDesc'     => 'Success',
            'originalReferenceNo'       => 'SP-2026-001',
            'originalPartnerReferenceNo'=> 'ORDER-2026-0042',
        ])));

        $resp = $service->checkStatus(new CheckStatusRequest(
            originalReferenceNo: 'SP-2026-001',
        ));

        self::assertTrue($resp->isSuccess());
        self::assertTrue($resp->isTerminal());
        self::assertSame(CheckStatusResponse::STATUS_SUCCESS, $resp->latestTransactionStatus);

        $req = $http->getRequests()[1];
        self::assertSame('/v1.0/debit/status', $req->getUri()->getPath());
        $body = (array) json_decode((string) $req->getBody(), true);
        self::assertSame('54',          $body['serviceCode']); // default to create
        self::assertSame('SP-2026-001', $body['originalReferenceNo']);
        self::assertArrayNotHasKey('originalPartnerReferenceNo', $body); // not supplied
    }

    public function testCheckStatusIsTerminalFalseForPaying(): void
    {
        [$service, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(200, [], (string) json_encode([
            'responseCode'            => '2005500',
            'latestTransactionStatus' => CheckStatusResponse::STATUS_PAYING,
            'transactionStatusDesc'   => 'Paying',
        ])));

        $resp = $service->checkStatus(new CheckStatusRequest(originalPartnerReferenceNo: 'ORDER-7'));

        self::assertFalse($resp->isSuccess());
        self::assertFalse($resp->isTerminal());
    }

    public function testRefundPostsToCorrectPathAndHydratesMoney(): void
    {
        [$service, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(200, [], (string) json_encode([
            'responseCode'              => '2005800',
            'responseMessage'           => 'Successful',
            'refundNo'                  => 'SP-REFUND-001',
            'partnerRefundNo'           => 'REFUND-7',
            'refundAmount'              => ['value' => '50000.00', 'currency' => 'IDR'],
            'originalReferenceNo'       => 'SP-2026-001',
            'originalPartnerReferenceNo'=> 'ORDER-2026-0042',
        ])));

        $resp = $service->refund(new RefundRequest(
            refundAmount:        new Money('50000.00'),
            partnerRefundNo:     'REFUND-7',
            originalReferenceNo: 'SP-2026-001',
            reason:              'duplicate charge',
        ));

        self::assertSame('SP-REFUND-001', $resp->refundNo);
        self::assertSame('REFUND-7',      $resp->partnerRefundNo);
        self::assertNotNull($resp->refundAmount);
        self::assertSame('50000.00', $resp->refundAmount->value);
        self::assertSame('IDR',      $resp->refundAmount->currency);

        $req = $http->getRequests()[1];
        self::assertSame('/v1.0/debit/refund', $req->getUri()->getPath());
        $body = (array) json_decode((string) $req->getBody(), true);
        self::assertSame('REFUND-7',         $body['partnerRefundNo']);
        self::assertSame('duplicate charge', $body['reason']);
        self::assertSame(['value' => '50000.00', 'currency' => 'IDR'], $body['refundAmount']);
    }

    public function testRefundPropagatesApiExceptionOnGatewayFailure(): void
    {
        [$service, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(400, [], (string) json_encode([
            'responseCode'    => '4045801',
            'responseMessage' => 'Transaction not found',
        ])));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('4045801: Transaction not found');

        $service->refund(new RefundRequest(
            refundAmount:        new Money('50000.00'),
            partnerRefundNo:     'REFUND-MISSING',
            originalReferenceNo: 'SP-DOES-NOT-EXIST',
        ));
    }

    public function testCheckStatusRequestRejectsEmptyReferences(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one of originalReferenceNo');

        new CheckStatusRequest();
    }

    public function testRefundRequestRejectsEmptyReferences(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one of originalReferenceNo');

        new RefundRequest(
            refundAmount:    new Money('50000.00'),
            partnerRefundNo: 'REFUND-1',
        );
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    /**
     * @return array{LinkAndPayService, MockClient}
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
        $service       = new LinkAndPayService($transport);

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
