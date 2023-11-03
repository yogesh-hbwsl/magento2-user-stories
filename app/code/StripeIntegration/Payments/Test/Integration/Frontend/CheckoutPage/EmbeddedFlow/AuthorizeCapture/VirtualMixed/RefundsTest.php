<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\VirtualMixed;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class RefundsTest extends \PHPUnit\Framework\TestCase
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
     */
    public function testFullRefunds()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart('VirtualMixed')
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();

        $paymentIntent = $this->tests->confirmSubscription($order);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Invoice checks
        $invoicesCollection = $order->getInvoiceCollection();
        $this->assertEquals(1, $invoicesCollection->count());
        $invoice = $invoicesCollection->getFirstItem();
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());

        // Order checks
        $this->assertEquals(21.66, $order->getBaseGrandTotal());
        $this->assertEquals(21.66, $order->getGrandTotal());
        $this->assertEquals(21.66, $order->getTotalInvoiced());
        $this->assertEquals(21.66, $order->getTotalPaid());
        $this->assertEquals(0, $order->getTotalDue());
        $this->assertEquals(0, $order->getTotalRefunded());
        $this->assertEquals(0, $order->getTotalCanceled());
        $this->assertEquals("complete", $order->getState());
        $this->assertEquals("complete", $order->getStatus());

        // Stripe checks
        $stripe = $this->tests->stripe();
        $customerId = $order->getPayment()->getAdditionalInformation("customer_stripe_id");
        $customer = $stripe->customers->retrieve($customerId);
        $this->assertEquals(1, count($customer->subscriptions->data));

        // Invoice checks
        $invoicesCollection = $order->getInvoiceCollection();
        $this->assertEquals(1, $invoicesCollection->getSize());
        $invoice = $invoicesCollection->getFirstItem();
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());

        $orderTransactions = $this->tests->helper()->getOrderTransactions($order);
        $this->assertCount(1, $orderTransactions);

        // Refund the order
        $this->assertTrue($order->canCreditmemo());
        $this->tests->refundOnline($invoice, []);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Order checks
        $this->assertEquals(21.66, $order->getBaseGrandTotal());
        $this->assertEquals(21.66, $order->getGrandTotal());
        $this->assertEquals(21.66, $order->getTotalInvoiced());
        $this->assertEquals(21.66, $order->getTotalPaid());
        $this->assertEquals(0, $order->getTotalDue());
        $this->assertEquals(21.66, $order->getTotalRefunded());
        $this->assertEquals(0, $order->getTotalCanceled());
        $this->assertFalse($order->canCreditmemo());

        if ($this->tests->magento("<", "2.4") || $this->tests->magento(">=", "2.4.6"))
            $this->assertEquals("closed", $order->getState());
        else
            $this->assertEquals("complete", $order->getState()); // This seems like a bug in Magento 2.4.x. It might be a rounding error on the total_due amount

        $this->assertEquals("closed", $order->getStatus());

        // Stripe checks
        $charges = $stripe->charges->all(['limit' => 10, 'customer' => $customer->id]);

        $this->assertCount(1, $charges);

        $expected = [
            ['amount' => 2166, 'amount_captured' => 2166, 'amount_refunded' => 2166],
        ];

        for ($i = 0; $i < count($charges); $i++)
        {
            $this->assertEquals($expected[$i]['amount'], $charges->data[$i]->amount, "Charge $i");
            $this->assertEquals($expected[$i]['amount_captured'], $charges->data[$i]->amount_captured, "Charge $i");
            $this->assertEquals($expected[$i]['amount_refunded'], $charges->data[$i]->amount_refunded, "Charge $i");
        }
    }
}
