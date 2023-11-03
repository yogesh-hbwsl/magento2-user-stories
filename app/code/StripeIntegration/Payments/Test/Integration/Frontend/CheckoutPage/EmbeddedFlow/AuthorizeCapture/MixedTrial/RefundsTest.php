<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class RefundsTest extends \PHPUnit\Framework\TestCase
{
    private $compare;
    private $helper;
    private $objectManager;
    private $productRepository;
    private $quote;
    private $stripeConfig;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->compare = new \StripeIntegration\Payments\Test\Integration\Helper\Compare($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();

        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $this->stripeConfig = $this->objectManager->get(\StripeIntegration\Payments\Model\Config::class);
        $this->productRepository = $this->objectManager->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
    }

    /**
     * magentoAppIsolation enabled
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     */
    public function testTrialRefunds()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart('MixedTrial')
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $paymentIntent = $this->tests->confirmSubscription($order);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Order checks
        $this->assertEquals($order->getGrandTotal(), $order->getTotalInvoiced());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalPaid());
        $this->assertEquals(0, $order->getTotalDue());
        $this->assertEquals(15.83, $order->getTotalRefunded());
        $this->assertEquals(0, $order->getTotalCanceled());
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
        $this->assertFalse($order->canCancel());
        $this->assertTrue($order->canCreditmemo()); // Because Simple Product was paid

        // Invoice checks
        $invoicesCollection = $order->getInvoiceCollection();
        $this->assertEquals(1, $invoicesCollection->count());
        $invoice = $invoicesCollection->getFirstItem();
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());
        $this->assertFalse($invoice->canCancel());
        $this->assertFalse($invoice->canCapture()); // Offline capture should be possible

        // Stripe checks
        $stripe = $this->stripeConfig->getStripeClient();
        $customerId = $order->getPayment()->getAdditionalInformation("customer_stripe_id");
        $customer = $stripe->customers->retrieve($customerId);
        $this->assertEquals(1, count($customer->subscriptions->data));

        // Expire the trial subscription
        $ordersCount = $this->tests->getOrdersCount();
        foreach ($customer->subscriptions->data as $subscription)
        {
            $this->tests->endTrialSubscription($subscription->id);
        }

        // Check that a new order was created
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Order checks
        $this->assertEquals($order->getGrandTotal(), $order->getTotalInvoiced());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalPaid());
        $this->assertEquals(0, $order->getTotalDue());
        $this->assertEquals(15.83, $order->getTotalRefunded());
        $this->assertEquals(0, $order->getTotalCanceled());
        $this->assertTrue($order->canCreditmemo());
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
        $this->assertFalse($order->canCancel());

        // Invoice checks
        $invoicesCollection = $order->getInvoiceCollection();
        $this->assertEquals(1, $invoicesCollection->count());
        $invoice = $invoicesCollection->getFirstItem();
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());
        $this->assertFalse($invoice->canCancel());
        $this->assertFalse($invoice->canCapture());
        $this->assertTrue($invoice->canRefund());

        // Refund the order remainder
        $this->tests->refundOnline($invoice, ['simple-product' => 1], $baseShipping = 5);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Order checks
        $this->assertEquals($order->getGrandTotal(), $order->getTotalInvoiced());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalPaid());
        $this->assertEquals(0, $order->getTotalDue());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalRefunded());
        $this->assertEquals(0, $order->getTotalCanceled());
        $this->assertEquals("processing", $order->getState()); // Not closed because the simple trial subscription is a simple product that must be shipped. Closed orders cannot be shipped.
        $this->assertEquals("processing", $order->getStatus());

        // Refund the trial subscription
        $newOrder = $this->tests->getLastOrder();
        $this->assertNotEquals($order->getIncrementId(), $newOrder->getIncrementId());
        $this->assertTrue($newOrder->canCreditmemo());
        $invoice = $newOrder->getInvoiceCollection()->getFirstItem();
        $this->tests->refundOnline($invoice, ['simple-trial-monthly-subscription-product' => 1], $baseShipping = 5);

        // Refresh the order object
        $order = $this->tests->refreshOrder($newOrder);

        // Order checks
        $this->assertEquals($order->getGrandTotal(), $order->getTotalInvoiced());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalPaid());
        $this->assertEquals(0, $order->getTotalDue());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalRefunded());
        $this->assertEquals(0, $order->getTotalCanceled());
        $this->assertFalse($order->canCreditmemo());
        $this->assertEquals("closed", $order->getState());
        $this->assertEquals("closed", $order->getStatus());

        // Stripe checks
        $charges = $stripe->charges->all(['limit' => 10, 'customer' => $customer->id]);

        $expected = [
            ['amount' => 1583, 'amount_captured' => 1583, 'amount_refunded' => 1583],
            ['amount' => 1583, 'amount_captured' => 1583, 'amount_refunded' => 1583],
        ];

        for ($i = 0; $i < count($charges); $i++)
        {
            $this->assertEquals($expected[$i]['amount'], $charges->data[$i]->amount, "Charge $i");
            $this->assertEquals($expected[$i]['amount_captured'], $charges->data[$i]->amount_captured, "Charge $i");
            $this->assertEquals($expected[$i]['amount_refunded'], $charges->data[$i]->amount_refunded, "Charge $i");
        }
    }
}
