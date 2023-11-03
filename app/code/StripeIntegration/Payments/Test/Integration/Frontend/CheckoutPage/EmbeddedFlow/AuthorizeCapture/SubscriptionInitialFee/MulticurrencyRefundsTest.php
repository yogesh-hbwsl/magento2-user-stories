<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\SubscriptionInitialFee;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class MulticurrencyRefundsTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     *
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     */
    public function testRefunds()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("SubscriptionInitialFee")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $paymentIntent = $this->tests->confirmSubscription($order);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);
        $orderIncrementId = $order->getIncrementId();

        $this->tests->compare($order->debug(), [
            "state" => "processing",
            "status" => "processing",
            "base_total_paid" => $order->getBaseGrandTotal(),
            "total_paid" => $order->getGrandTotal(),
        ]);

        $invoicesCollection = $order->getInvoiceCollection();
        $this->assertEquals(1, $invoicesCollection->getSize());
        $invoice = $invoicesCollection->getFirstItem();

        $creditMemo = $this->tests->refundOnline($invoice, [], $baseShipping = 5);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        $this->assertEquals(round($creditMemo->getGrandTotal(), 4), $order->getGrandTotal());
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($paymentIntent->id);

        $grandTotal = round(floatval($order->getGrandTotal()) * 100);

        $this->tests->compare($paymentIntent, [
            "amount" => $grandTotal,
            "charges" => [
                "data" => [
                    0 => [
                        "amount_refunded" => $grandTotal
                    ]
                ]
            ],
            "description" => "Subscription order #$orderIncrementId by Joyce Strother"
        ]);
    }
}
