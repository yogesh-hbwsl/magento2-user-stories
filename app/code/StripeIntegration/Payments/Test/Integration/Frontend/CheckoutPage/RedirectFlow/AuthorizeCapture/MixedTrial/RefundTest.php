<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\RedirectFlow\AuthorizeCapture\MixedTrial;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class RefundTest extends \PHPUnit\Framework\TestCase
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
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     *
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     */
    public function testPlaceOrder()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("MixedTrial")
            ->setShippingAddress("NewYork")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("NewYork")
            ->setPaymentMethod("StripeCheckout");

        $order = $this->quote->placeOrder();
        $orderIncrementId = $order->getIncrementId();

        // Confirm the payment
        $paymentIntent = $this->tests->confirmCheckoutSession($order, "MixedTrial", "card", "NewYork");
        $customerId = $paymentIntent->customer;

        // Refresh the order
        $order = $this->tests->refreshOrder($order);
        $this->assertFalse($order->canCancel());
        $this->assertTrue($order->canCreditmemo()); // Because Simple Product was paid

        // Invoice checks
        $invoicesCollection = $order->getInvoiceCollection();
        $this->assertEquals(1, $invoicesCollection->count());
        $invoice = $invoicesCollection->getFirstItem();
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());
        $this->assertFalse($invoice->canCancel());
        $this->assertTrue($invoice->canRefund());
        $this->assertFalse($invoice->canCapture()); // Offline capture should be possible

        $customer = $this->tests->stripe()->customers->retrieve($customerId);
        $simpleProductInvoice = $customer->subscriptions->data[0]->latest_invoice;

        // Activate the subscription
        $ordersCount = $this->tests->getOrdersCount();
        $customer = $this->tests->stripe()->customers->retrieve($paymentIntent->customer);
        $this->tests->endTrialSubscription($customer->subscriptions->data[0]->id);
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);

        // Refund the order
        $order = $this->tests->refreshOrder($order);
        $invoices = $order->getInvoiceCollection();
        $this->assertEquals(1, $invoices->getSize());
        $invoice = $invoices->getFirstItem();
        $skus = [];
        $this->assertStringContainsString("pi_", $invoice->getTransactionId());

        $skus['simple-product'] = ['simple-product' => 1];
        $this->tests->refundOnline($invoice, $skus, 5);

        // Stripe checks
        $simpleProductInvoice = $this->tests->stripe()->invoices->retrieve($simpleProductInvoice, ['expand' => ['payment_intent']]);
        $this->tests->compare($simpleProductInvoice, [
            "payment_intent" => [
                "charges" => [
                    "data" => [
                        0 => [
                            "amount_refunded" => 1346
                        ]
                    ]
                ]
            ]
        ]);

        $customer = $this->tests->stripe()->customers->retrieve($customer->id);
        $trialSubscriptionInvoice = $this->tests->stripe()->invoices->retrieve($customer->subscriptions->data[0]->latest_invoice, ['expand' => ['payment_intent']]);
        $this->tests->compare($trialSubscriptionInvoice, [
            "payment_intent" => [
                "charges" => [
                    "data" => [
                        0 => [
                            "amount_refunded" => 0
                        ]
                    ]
                ]
            ]
        ]);
    }
}
