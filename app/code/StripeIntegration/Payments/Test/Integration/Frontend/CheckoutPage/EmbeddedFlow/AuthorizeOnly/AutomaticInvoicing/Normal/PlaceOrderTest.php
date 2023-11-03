<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeOnly\AutomaticInvoicing\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class PlaceOrderTest extends \PHPUnit\Framework\TestCase
{
    private $cache;
    private $compare;
    private $objectManager;
    private $quote;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->compare = new \StripeIntegration\Payments\Test\Integration\Helper\Compare($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();

        $this->cache = $this->objectManager->get(\Magento\Framework\App\CacheInterface::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize
     * @magentoConfigFixture current_store payment/stripe_payments/automatic_invoicing 1
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
        $this->tests->confirm($order);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        $invoicesCollection = $order->getInvoiceCollection();

        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
        $this->assertNotEmpty($invoicesCollection);
        $this->assertEquals(1, $invoicesCollection->count());

        $invoice = $invoicesCollection->getFirstItem();

        $this->assertEquals(2, count($invoice->getAllItems()));
        $this->assertTrue($invoice->canCapture());
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_OPEN, $invoice->getState());

        $paymentIntentId = $order->getPayment()->getLastTransId();
        $paymentIntentId = $this->tests->helper()->cleanToken($paymentIntentId);

        // Order checks
        $this->assertEquals(0, $order->getTotalPaid());
        $this->assertEquals(0, $order->getTotalRefunded());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalDue());

        $invoicesCollection = $order->getInvoiceCollection();
        $invoice = $invoicesCollection->getFirstItem();
        $this->assertTrue($invoice->canCapture());
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_OPEN, $invoice->getState());

        // Capture the invoice
        $invoice->capture();
        $this->tests->helper()->saveInvoice($invoice);
        $this->tests->helper()->saveOrder($order);
        $this->cache->remove($key = "admin_captured_$paymentIntentId");

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);
        $paymentIntentId = $order->getPayment()->getLastTransId();
        $paymentIntentId = $this->tests->helper()->cleanToken($paymentIntentId);
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($paymentIntentId);
        $charge = $paymentIntent->charges->data[0];

        // Trigger webhooks
        $this->tests->event()->trigger("charge.captured", $charge);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        $this->assertEquals($order->getGrandTotal(), $order->getTotalPaid());
        $this->assertEquals(0, $order->getTotalRefunded());
        $this->assertEquals(0, $order->getTotalDue());

        $transactions = $this->tests->helper()->getOrderTransactions($order);
        foreach ($transactions as $t)
        {
            if ($t->getParentTxnId())
            {
                $txnId = $paymentIntentId . "-" . $t->getTxnType();
                $txnType = "capture";
            }
            else
            {
                $txnId = $paymentIntentId;
                $txnType = "authorization";
            }

            $this->compare->object($t->getData(), [
                "txn_id" => $txnId,
                "txn_type" => $txnType
            ]);
        }
    }
}
