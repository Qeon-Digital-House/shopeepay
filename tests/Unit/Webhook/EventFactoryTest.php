<?php

declare(strict_types=1);

namespace ShopeePay\Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use ShopeePay\Webhook\Event\AuthCaptured;
use ShopeePay\Webhook\Event\PaymentCompleted;
use ShopeePay\Webhook\Event\PaymentFailed;
use ShopeePay\Webhook\Event\RefundCompleted;
use ShopeePay\Webhook\Event\SubscriptionPaymentCompleted;
use ShopeePay\Webhook\Event\SubscriptionPaymentFailed;
use ShopeePay\Webhook\Event\UnknownEvent;
use ShopeePay\Webhook\EventFactory;

final class EventFactoryTest extends TestCase
{
    public function testLinkAndPaySuccessYieldsPaymentCompleted(): void
    {
        $event = (new EventFactory())->create([
            'serviceCode'                => '56',
            'latestTransactionStatus'    => '00',
            'transactionStatusDesc'      => 'Successful',
            'originalReferenceNo'        => 'SP-2026-001',
            'originalPartnerReferenceNo' => 'ORDER-1',
        ]);

        self::assertInstanceOf(PaymentCompleted::class, $event);
        self::assertTrue($event->isSuccess());
        self::assertSame('56', $event->serviceCode);
        self::assertSame('SP-2026-001', $event->originalReferenceNo);
        self::assertSame('ORDER-1', $event->originalPartnerReferenceNo);
    }

    public function testLinkAndPayFailureYieldsPaymentFailed(): void
    {
        $event = (new EventFactory())->create([
            'serviceCode'             => '56',
            'latestTransactionStatus' => '06',
            'transactionStatusDesc'   => 'Cancelled by user',
        ]);

        self::assertInstanceOf(PaymentFailed::class, $event);
        self::assertFalse($event->isSuccess());
    }

    public function testSubscriptionDispatchHonorsBothStatuses(): void
    {
        $factory = new EventFactory();

        $ok = $factory->create([
            'serviceCode'             => '52',
            'latestTransactionStatus' => '00',
        ]);
        $bad = $factory->create([
            'serviceCode'             => '52',
            'latestTransactionStatus' => '04',
        ]);

        self::assertInstanceOf(SubscriptionPaymentCompleted::class, $ok);
        self::assertInstanceOf(SubscriptionPaymentFailed::class,    $bad);
    }

    public function testAuthCaptureYieldsAuthCaptured(): void
    {
        $event = (new EventFactory())->create([
            'serviceCode'             => '65',
            'latestTransactionStatus' => '00',
        ]);

        self::assertInstanceOf(AuthCaptured::class, $event);
    }

    public function testRefundOnSvc69IsAlwaysRefundCompleted(): void
    {
        $event = (new EventFactory())->create([
            'serviceCode'             => '69',
            'latestTransactionStatus' => '00',
        ]);

        self::assertInstanceOf(RefundCompleted::class, $event);
    }

    public function testRefundReferenceOnParentSvcRoutesToRefundCompleted(): void
    {
        // A svc 56 payload with a refundReferenceNo signals a refund notify,
        // NOT a payment notify. Same for svc 52 and 65.
        $event = (new EventFactory())->create([
            'serviceCode'             => '56',
            'latestTransactionStatus' => '00',
            'refundReferenceNo'       => 'SP-REFUND-001',
        ]);

        self::assertInstanceOf(RefundCompleted::class, $event);
    }

    public function testRefundAmountInAdditionalInfoAlsoTriggersRefund(): void
    {
        $event = (new EventFactory())->create([
            'serviceCode'             => '52',
            'latestTransactionStatus' => '00',
            'additionalInfo'          => [
                'refundAmount' => ['value' => '50000.00', 'currency' => 'IDR'],
            ],
        ]);

        self::assertInstanceOf(RefundCompleted::class, $event);
    }

    public function testServiceCodeFallsBackToAdditionalInfo(): void
    {
        $event = (new EventFactory())->create([
            'latestTransactionStatus' => '00',
            'additionalInfo'          => ['serviceCode' => '56'],
        ]);

        self::assertInstanceOf(PaymentCompleted::class, $event);
        self::assertSame('56', $event->serviceCode);
    }

    public function testUnknownServiceCodeReturnsUnknownEvent(): void
    {
        // This is the forward-compat fallback the design's "Failure Modes"
        // table flags as critical: if a caller's match has no default arm,
        // these events will silently drop. Documented in UnknownEvent's
        // docblock and the README quickstart.
        $event = (new EventFactory())->create([
            'serviceCode'             => '99',
            'latestTransactionStatus' => '00',
            'transactionStatusDesc'   => 'Some new shopeepay event',
        ]);

        self::assertInstanceOf(UnknownEvent::class, $event);
        self::assertSame('99', $event->serviceCode);
        // Raw payload remains accessible so callers can still log + investigate.
        self::assertSame('Some new shopeepay event', $event->raw['transactionStatusDesc']);
    }

    public function testMissingServiceCodeAnywhereAlsoMapsToUnknownEvent(): void
    {
        $event = (new EventFactory())->create([
            'latestTransactionStatus' => '00',
            'someUnrelatedField'      => 'whatever',
        ]);

        self::assertInstanceOf(UnknownEvent::class, $event);
        self::assertSame('', $event->serviceCode);
    }
}
