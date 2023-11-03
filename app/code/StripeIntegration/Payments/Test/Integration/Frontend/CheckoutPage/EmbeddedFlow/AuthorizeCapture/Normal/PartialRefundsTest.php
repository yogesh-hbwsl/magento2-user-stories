<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class PartialRefundsTest extends \PHPUnit\Framework\TestCase
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
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     */
    public function testPartialRefunds()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart('Normal')
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $this->tests->confirm($order);

        // We are not hardcoding the value because running the whole file vs running the test case only produces a different grand total, 26.66 vs 26.65
        $orderGrandTotal = $order->getGrandTotal();
        $stripeGrandTotal = $orderGrandTotal * 100;

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());

        // Invoice checks
        $invoicesCollection = $order->getInvoiceCollection();
        $this->assertEquals(1, $invoicesCollection->count());
        $invoice = $invoicesCollection->getFirstItem();
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());

        // Partially refund the order
        $this->assertTrue($order->canCreditmemo());
        $this->tests->refundOnline($invoice, ['simple-product' => 1], $baseShipping = 10);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Order checks
        $this->tests->compare($order->getData(), [
            "total_invoiced" => $orderGrandTotal,
            "total_paid" => $orderGrandTotal,
            "total_due" => 0,
            "total_refunded" => 20.83,
            "total_canceled" => 0,
            "state" => "processing",
            "status" => "processing"
        ]);
        $this->assertTrue($order->canCreditmemo());

        // Stripe checks
        $paymentIntentId = $this->helper->cleanToken($order->getPayment()->getLastTransId());
        $paymentIntent = $this->stripeConfig->getStripeClient()->paymentIntents->retrieve($paymentIntentId);
        $this->compare->object($paymentIntent, [
            "amount" => $stripeGrandTotal,
            "amount_capturable" => 0,
            "amount_received" => $stripeGrandTotal,
            "status" => "succeeded",
            "charges" => [
                "data" => [
                    0 => [
                        "amount" => $stripeGrandTotal,
                        "amount_captured" => $stripeGrandTotal,
                        "amount_refunded" => 2083,
                        "status" => "succeeded"
                    ]
                ]
            ]
        ]);

        // Refund the remaining amount
        $this->assertTrue($order->canCreditmemo());
        $this->tests->refundOnline($invoice, ['virtual-product' => 2, 'simple-product' => 1]);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Invoice checks
        $invoicesCollection = $order->getInvoiceCollection();
        $this->assertEquals(1, $invoicesCollection->count());
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());

        // Order checks
        $this->tests->compare($order->getData(), [
            "total_invoiced" => $orderGrandTotal,
            "total_paid" => $orderGrandTotal,
            "total_due" => 0,
            "total_refunded" => $orderGrandTotal,
            "total_canceled" => 0,
            "state" => "closed",
            "status" => "closed"
        ]);
        $this->assertFalse($order->canCreditmemo());

        // Stripe checks
        $paymentIntent = $this->stripeConfig->getStripeClient()->paymentIntents->retrieve($paymentIntentId);
        $this->compare->object($paymentIntent, [
            "amount" => $stripeGrandTotal,
            "amount_capturable" => 0,
            "amount_received" => $stripeGrandTotal,
            "status" => "succeeded",
            "charges" => [
                "data" => [
                    0 => [
                        "amount" => $stripeGrandTotal,
                        "amount_captured" => $stripeGrandTotal,
                        "amount_refunded" => $stripeGrandTotal,
                        "status" => "succeeded"
                    ]
                ]
            ]
        ]);
    }
}
