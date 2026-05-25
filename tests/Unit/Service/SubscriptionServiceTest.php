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
use ShopeePay\Dto\Subscription\CheckStatusRequest;
use ShopeePay\Dto\Subscription\CheckStatusResponse;
use ShopeePay\Dto\Subscription\CreatePaymentRequest;
use ShopeePay\Dto\Subscription\RefundRequest;
use ShopeePay\Environment;
use ShopeePay\Exception\ApiException;
use ShopeePay\Http\AccessTokenManager;
use ShopeePay\Http\HeaderBuilder;
use ShopeePay\Http\Signer;
use ShopeePay\Http\Transport;
use ShopeePay\Service\SubscriptionService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

final class SubscriptionServiceTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures';

    public function testCreateSendsSubscriptionIdAndSharesLinkAndPayPath(): void
    {
        [$service, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(200, [], (string) json_encode([
            'responseCode'       => '2005400',
            'responseMessage'    => 'Successful',
            'referenceNo'        => 'SP-SUB-001',
            'partnerReferenceNo' => 'SUB-CHARGE-001',
        ])));

        $resp = $service->create(new CreatePaymentRequest(
            partnerReferenceNo: 'SUB-CHARGE-001',
            amount:             new Money('99000.00'),
            accountToken:       'tok_live_abc',
            subscriptionId:     'SUB-2026-7',
        ));

        self::assertSame('SP-SUB-001', $resp->referenceNo);
        // Subscription debits often don't return a webRedirectUrl — assert
        // we tolerate the empty value rather than choking.
        self::assertSame('', $resp->webRedirectUrl);

        $req = $http->getRequests()[1];
        // Path is shared with LinkAndPay per design doc — disambiguation
        // happens via the subscriptionId field in the body.
        self::assertSame('/v1.1/debit/payment-host-to-host', $req->getUri()->getPath());
        $body = (array) json_decode((string) $req->getBody(), true);
        self::assertSame('SUB-2026-7',  $body['subscriptionId']);
        self::assertSame('tok_live_abc', $body['accountToken']);
        self::assertSame(['value' => '99000.00', 'currency' => 'IDR'], $body['amount']);
    }

    public function testCheckStatusReturnsTypedStatus(): void
    {
        [$service, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(200, [], (string) json_encode([
            'responseCode'            => '2005500',
            'responseMessage'         => 'Successful',
            'latestTransactionStatus' => '00',
            'transactionStatusDesc'   => 'Success',
            'originalReferenceNo'     => 'SP-SUB-001',
        ])));

        $resp = $service->checkStatus(new CheckStatusRequest(
            originalReferenceNo: 'SP-SUB-001',
        ));

        self::assertTrue($resp->isSuccess());
        self::assertTrue($resp->isTerminal());
        self::assertSame(CheckStatusResponse::STATUS_SUCCESS, $resp->latestTransactionStatus);

        self::assertSame('/v1.0/debit/status', $http->getRequests()[1]->getUri()->getPath());
    }

    public function testRefundSendsSubscriptionIdInBodyWhenSupplied(): void
    {
        [$service, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(200, [], (string) json_encode([
            'responseCode'    => '2005800',
            'responseMessage' => 'Successful',
            'refundNo'        => 'SP-REFUND-SUB-1',
            'partnerRefundNo' => 'SUB-REFUND-1',
            'refundAmount'    => ['value' => '99000.00', 'currency' => 'IDR'],
        ])));

        $resp = $service->refund(new RefundRequest(
            refundAmount:        new Money('99000.00'),
            partnerRefundNo:     'SUB-REFUND-1',
            originalReferenceNo: 'SP-SUB-001',
            subscriptionId:      'SUB-2026-7',
            reason:              'service downgrade',
        ));

        self::assertSame('SP-REFUND-SUB-1', $resp->refundNo);
        self::assertNotNull($resp->refundAmount);
        self::assertSame('99000.00', $resp->refundAmount->value);

        $req = $http->getRequests()[1];
        self::assertSame('/v1.0/debit/refund', $req->getUri()->getPath());
        $body = (array) json_decode((string) $req->getBody(), true);
        self::assertSame('SUB-2026-7',         $body['subscriptionId']);
        self::assertSame('service downgrade',  $body['reason']);
    }

    public function testRefundOmitsSubscriptionIdWhenNull(): void
    {
        [$service, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(200, [], (string) json_encode([
            'responseCode'    => '2005800',
            'responseMessage' => 'Successful',
            'refundNo'        => 'SP-REFUND-SUB-2',
        ])));

        $service->refund(new RefundRequest(
            refundAmount:        new Money('50000.00'),
            partnerRefundNo:     'SUB-REFUND-2',
            originalReferenceNo: 'SP-SUB-001',
        ));

        $body = (array) json_decode((string) $http->getRequests()[1]->getBody(), true);
        self::assertArrayNotHasKey('subscriptionId', $body);
    }

    public function testCreateRejectsEmptySubscriptionId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('subscriptionId must not be empty');

        new CreatePaymentRequest(
            partnerReferenceNo: 'SUB-CHARGE-001',
            amount:             new Money('99000.00'),
            accountToken:       'tok_live_abc',
            subscriptionId:     '   ',
        );
    }

    public function testCreatePropagatesApiExceptionOnInsufficientFunds(): void
    {
        // Common failure mode for recurring debits — the user's wallet doesn't
        // have enough balance. Surface the responseCode and message verbatim
        // so the caller's billing dunning logic can branch on it.
        [$service, $http] = $this->build();
        $http->addResponse($this->tokenResponse('tk-1'));
        $http->addResponse(new Response(402, [], (string) json_encode([
            'responseCode'    => '4025400',
            'responseMessage' => 'Insufficient funds',
        ])));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('4025400: Insufficient funds');

        $service->create(new CreatePaymentRequest(
            partnerReferenceNo: 'SUB-CHARGE-099',
            amount:             new Money('99000.00'),
            accountToken:       'tok_live_abc',
            subscriptionId:     'SUB-2026-7',
        ));
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    /**
     * @return array{SubscriptionService, MockClient}
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
        $service       = new SubscriptionService($transport);

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
