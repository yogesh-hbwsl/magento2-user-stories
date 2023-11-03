<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\MixedTrial;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class MultiCurrencyRefundsTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $quote;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     *
     * @magentoConfigFixture current_store currency/options/base USD
     * @magentoConfigFixture current_store currency/options/allow EUR,USD
     * @magentoConfigFixture current_store currency/options/default EUR
     */
    public function testRefunds()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("MixedTrial")
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
        $this->assertEquals(1, $invoicesCollection->getSize());
        $invoice = $invoicesCollection->getFirstItem();
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());

        // Order checks
        $this->tests->compare($order->debug(), [
            "base_grand_total" => 31.66,
            "grand_total" => 26.90,
            "base_total_invoiced" => 31.66,
            "total_invoiced" => 26.90,
            "base_total_paid" => 31.66,
            "total_paid" => 26.90,
            "base_total_due" => 0,
            "total_due" => 0,
            "total_refunded" => 13.45,
            "total_canceled" => "unset",
            "state" => "processing",
            "status" => "processing"
        ]);

        // Stripe checks
        $stripe = $this->tests->stripe();
        $customerId = $order->getPayment()->getAdditionalInformation("customer_stripe_id");
        $customer = $stripe->customers->retrieve($customerId);
        $this->assertEquals(1, count($customer->subscriptions->data));

        // Expire the trial subscription
        $ordersCount = $this->tests->getOrdersCount();
        $subscription = $this->tests->endTrialSubscription($customer->subscriptions->data[0]->id);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Check that a new order was created
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);

        // Invoice checks
        $invoicesCollection = $order->getInvoiceCollection();
        $this->assertEquals(1, $invoicesCollection->getSize());
        $invoice = $invoicesCollection->getFirstItem();
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());

        // Credit memo checks
        $creditmemoCollection = $order->getCreditmemosCollection();
        $this->assertEquals(1, $creditmemoCollection->getSize());

        // Order checks
        $this->tests->compare($order->debug(), [
            "base_grand_total" => 31.66,
            "grand_total" => 26.90,
            "base_total_invoiced" => 31.66,
            "total_invoiced" => 26.90,
            "base_total_paid" => 31.66,
            "total_paid" => 26.90,
            "base_total_due" => 0,
            "total_due" => 0,
            "total_refunded" => 13.45,
            "total_canceled" => "unset",
            "state" => "processing",
            "status" => "processing"
        ]);

        // Refund the order
        $this->assertTrue($order->canCreditmemo());
        $this->tests->refundOnline($invoice, ['simple-product' => 1], $baseShipping = 5);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // We have a rounding error because the credit memos do not include the items that were refunded
        // We basically converted the rounded 13.45 back to a rounded base amount of 15.83, but the original base
        // amount was 15.8255, calculated from the order items, with tax applied.
        // v3.2.8 is not affected because it does not use Helper/Creditmemo, it adds the order items instead.
        $roundingError = 0.01;

        $this->tests->compare($order->debug(), [
            "base_total_refunded" => $order->getBaseGrandTotal() - $roundingError,
            "total_refunded" => $order->getGrandTotal(),
            "total_canceled" => "unset",
            "state" => "processing",
            "status" => "processing"
        ]);

        // Refund the trial subscription via the 2nd order
        $oldIncrementId = $order->getIncrementId();
        $order = $this->tests->getLastOrder();
        $this->assertNotEquals($oldIncrementId, $order->getIncrementId());
        $this->assertTrue($order->canCreditmemo());
        $this->assertEquals(0, $order->getCreditmemosCollection()->getSize());
        $invoice = $order->getInvoiceCollection()->getFirstItem();

        if ($this->tests->magento("<", "2.4"))
        {
            // Magento 2.3.7-p3 does not perform a currency conversion on the tax_amount
            $this->expectExceptionMessage("Could not refund payment: Requested a refund of €13.58, but the most amount that can be refunded online is €13.45.");
        }

        $this->tests->refundOnline($invoice, ['simple-trial-monthly-subscription-product' => 1], $baseShipping = 5);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Order checks
        $this->tests->compare($order->debug(), [
            "base_total_refunded" => 15.83,
            "total_refunded" => 13.45,
            "total_canceled" => "unset",
            "state" => "closed",
            "status" => "closed"
        ]);

        $this->assertFalse($order->canCreditmemo()); // @todo: inverse rounding error, should be false

        // Stripe checks
        $charges = $stripe->charges->all(['limit' => 10, 'customer' => $customer->id]);

        $expected = [
            ['amount' => 1345, 'amount_captured' => 1345, 'amount_refunded' => 1345, 'currency' => 'eur'],
            ['amount' => 1345, 'amount_captured' => 1345, 'amount_refunded' => 1345, 'currency' => 'eur'],
        ];

        for ($i = 0; $i < count($charges); $i++)
        {
            $this->assertEquals($expected[$i]['currency'], $charges->data[$i]->currency, "Charge $i");
            $this->assertEquals($expected[$i]['amount'], $charges->data[$i]->amount, "Charge $i");
            $this->assertEquals($expected[$i]['amount_captured'], $charges->data[$i]->amount_captured, "Charge $i");
            $this->assertEquals($expected[$i]['amount_refunded'], $charges->data[$i]->amount_refunded, "Charge $i");
        }
    }
}
