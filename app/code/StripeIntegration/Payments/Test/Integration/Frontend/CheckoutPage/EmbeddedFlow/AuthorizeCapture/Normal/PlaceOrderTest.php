<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class PlaceOrderTest extends \PHPUnit\Framework\TestCase
{
    private $helper;
    private $objectManager;
    private $quote;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);

        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     */
    public function testNormalCart()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Check if Radar risk value is been set to the order
        $this->assertIsNumeric($order->getStripeRadarRiskScore());
        $this->assertGreaterThanOrEqual(0, $order->getStripeRadarRiskScore());
        $this->assertNotEquals('NA', $order->getStripeRadarRiskLevel());

        // Check Stripe Payment method
        $paymentMethod = $this->tests->loadPaymentMethod($order->getId());
        $this->assertEquals('card', $paymentMethod->getPaymentMethodType());

        // Check that the emails were sent
        $this->assertEquals(1, $order->getEmailSent(), "The order email was not sent.");
        $this->assertEquals(1, $order->getInvoiceCollection()->getFirstItem()->getEmailSent(), "The invoice email was not sent.");

        $invoicesCollection = $order->getInvoiceCollection();

        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
        $this->assertNotEmpty($invoicesCollection);
        $this->assertEquals(1, $invoicesCollection->count());
        $this->assertEquals(0, $order->getTotalDue());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalPaid());

        $invoice = $invoicesCollection->getFirstItem();

        $this->assertEquals(2, count($invoice->getAllItems()));
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());

        $transactions = $this->helper->getOrderTransactions($order);
        $this->assertEquals(1, count($transactions));

        // As of v3.3.2, guest checkouts no longer have a Stripe customer object created, unless absolutely needed (5 cases)
        $paymentIntentId = $order->getPayment()->getLastTransId();
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($paymentIntentId, []);
        $this->assertEmpty($paymentIntent->customer);
        // $customer = $this->tests->stripe()->customers->retrieve($paymentIntent->customer);

        // $this->tests->compare($customer, [
        //     "name" => "Joyce Strother",
        //     "phone" => "626-945-7637",
        //     "email" => "joyce@example.com",
        //     "address" => [
        //         "city" => "Mira Loma",
        //         "country" => "US",
        //         "line1" => "2974 Providence Lane",
        //         "postal_code" => "91752",
        //         "state" => "California"
        //     ],
        //     "shipping" => [
        //         "address" => [
        //             "city" => "Mira Loma",
        //             "country" => "US",
        //             "line1" => "2974 Providence Lane",
        //             "line2" =>"",
        //             "postal_code" => "91752",
        //             "state" => "California"
        //         ],
        //         "name" => "Joyce Strother",
        //         "phone" => "626-945-7637"
        //     ],
        // ]);

        // After processing webhook events, the order should remain unchanged
        $this->tests->event()->triggerPaymentIntentEvents($order->getPayment()->getLastTransId());
        $order = $this->tests->refreshOrder($order);
        $orderComments = $order->getAllStatusHistory();
        $this->assertCount(1, $orderComments);

        // Check that the transaction IDs have been associated with the order
        $transactions = $this->tests->helper()->getOrderTransactions($order);
        $this->assertEquals(1, count($transactions));
        foreach ($transactions as $key => $transaction)
        {
            $this->assertEquals($paymentIntentId, $transaction->getTxnId());
            $this->assertContains($transaction->getTxnType(), ["capture"]);
        }

    }
}
