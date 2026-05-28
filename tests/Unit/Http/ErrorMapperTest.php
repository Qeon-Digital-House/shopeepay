<?php

declare(strict_types=1);

namespace ShopeePay\Tests\Unit\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use ShopeePay\Exception\ApiException;
use ShopeePay\Exception\ConfigException;
use ShopeePay\Exception\NetworkException;
use ShopeePay\Http\ErrorMapper;

final class ErrorMapperTest extends TestCase
{
    public function testNetworkInterfaceMapsToNetworkException(): void
    {
        $psr = new class extends \RuntimeException implements NetworkExceptionInterface {
            public function getRequest(): \Psr\Http\Message\RequestInterface
            {
                return (new Psr17Factory())->createRequest('GET', 'https://example/');
            }
        };

        $mapped = ErrorMapper::fromClientException($psr);

        self::assertInstanceOf(NetworkException::class, $mapped);
        self::assertSame($psr, $mapped->getPrevious());
    }

    public function testRequestInterfaceMapsToConfigException(): void
    {
        $psr = new class extends \RuntimeException implements RequestExceptionInterface {
            public function getRequest(): \Psr\Http\Message\RequestInterface
            {
                return (new Psr17Factory())->createRequest('GET', 'https://example/');
            }
        };

        $mapped = ErrorMapper::fromClientException($psr);

        self::assertInstanceOf(ConfigException::class, $mapped);
    }

    public function testGenericClientExceptionMapsToNetworkException(): void
    {
        $psr = new class extends \RuntimeException implements ClientExceptionInterface {
        };

        $mapped = ErrorMapper::fromClientException($psr);

        self::assertInstanceOf(NetworkException::class, $mapped);
    }

    public function testHttp200WithSuccessResponseCodeReturnsNull(): void
    {
        $response = new Response(200, [], json_encode([
            'responseCode'    => '2005400',
            'responseMessage' => 'Successful',
            'referenceNo'     => 'SP-2026-001',
        ]) ?: '');

        self::assertNull(ErrorMapper::fromResponse($response));
    }

    public function testHttp200WithInBodyAuthFailureSurfacesAsApiException(): void
    {
        // Real-world SNAP BI behavior: HTTP 200 wrapper with a 4011000 in
        // the body. AccessTokenManager relies on us surfacing this so it
        // can retry once.
        $response = new Response(200, [], json_encode([
            'responseCode'    => '4011000',
            'responseMessage' => 'Token expired',
        ]) ?: '');

        $api = ErrorMapper::fromResponse($response);

        self::assertInstanceOf(ApiException::class, $api);
        self::assertSame('4011000', $api->responseCode);
        self::assertSame('Token expired', $api->responseMessage);
    }

    public function testHttp400WithJsonBodyMapsToApiExceptionWithFields(): void
    {
        $response = new Response(400, [], json_encode([
            'responseCode'       => '4005401',
            'responseMessage'    => 'Invalid amount',
            'referenceNo'        => 'SP-X',
            'partnerReferenceNo' => 'ORDER-7',
        ]) ?: '');

        $api = ErrorMapper::fromResponse($response);

        self::assertInstanceOf(ApiException::class, $api);
        self::assertSame('4005401', $api->responseCode);
        self::assertSame('Invalid amount', $api->responseMessage);
        self::assertSame('SP-X', $api->referenceNo);
        self::assertSame('ORDER-7', $api->partnerReferenceNo);
    }

    public function testNon2xxWithUnparseableBodyMapsToNetworkException(): void
    {
        $response = new Response(502, [], '<html>nginx 502 bad gateway</html>');

        $this->expectException(NetworkException::class);
        $this->expectExceptionMessage('malformed gateway response');

        ErrorMapper::fromResponse($response);
    }

    public function testEmptyBodyAlsoMapsToNetworkException(): void
    {
        $response = new Response(500, [], '');

        $this->expectException(NetworkException::class);

        ErrorMapper::fromResponse($response);
    }
}
