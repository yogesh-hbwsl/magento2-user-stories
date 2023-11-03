<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeOnly\ManualInvoicing\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class PartialCapturesTest extends \PHPUnit\Framework\TestCase
{
    private $helper;
    private $invoiceRepository;
    private $invoiceService;
    private $objectManager;
    private $orderRepository;
    private $productRepository;
    private $quote;
    private $stripeConfig;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();

        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $this->stripeConfig = $this->objectManager->get(\StripeIntegration\Payments\Model\Config::class);
        $this->productRepository = $this->objectManager->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
        $this->invoiceService = $this->objectManager->get(\Magento\Sales\Model\Service\InvoiceService::class);
        $this->orderRepository = $this->objectManager->get(\Magento\Sales\Api\OrderRepositoryInterface::class);
        $this->invoiceRepository = $this->objectManager->get(\Magento\Sales\Api\InvoiceRepositoryInterface::class);
    }

    /**
     * @magentoAppIsolation enabled
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize
     * @magentoConfigFixture current_store payment/stripe_payments/expired_authorizations 1
     * @magentoConfigFixture current_store payment/stripe_payments/automatic_invoicing 0
     */
    public function testPartialCaptures()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart('Normal')
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $paymentIntent = $this->tests->confirm($order);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);
        $transactionId = $order->getPayment()->getLastTransId();
        $this->assertNotEmpty($transactionId);
        $this->assertEquals(5330, $paymentIntent->amount);
        $this->assertEquals(5330, $paymentIntent->amount_capturable);
        $this->assertEquals(0, $paymentIntent->amount_received);

        $orderItemQtys = [];
        foreach ($order->getAllVisibleItems() as $orderItem)
        {
            if ($orderItem->getSku() == "simple-product")
            {
                $orderItemQtys[$orderItem->getId()] = 2;
            }
        }

        // Partially invoice the order
        \Magento\TestFramework\Helper\Bootstrap::getInstance()->loadArea('adminhtml');
        $this->tests->invoiceOnline($order, ['simple-product' => 2]);

        // Refresh the order object
        $this->helper->clearCache();
        $order = $this->tests->refreshOrder($order);

        // Order checks
        $this->assertEquals(53.30, $order->getGrandTotal());
        $this->assertEquals(0, $order->getTotalRefunded());
        $this->assertEquals(0, $order->getTotalCanceled());
        $this->assertEquals(21.65, $order->getTotalDue());
        $this->assertEquals(31.65, $order->getTotalInvoiced());
        $this->assertEquals(31.65, $order->getTotalPaid());
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());

        // Trigger webhooks
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($paymentIntent->id);
        $this->tests->event()->trigger("charge.captured", $paymentIntent->charges->data[0]);
        $this->tests->event()->trigger("payment_intent.succeeded", $paymentIntent);

        // Stripe checks
        $this->assertEquals(5330, $paymentIntent->amount);
        $this->assertEquals(0, $paymentIntent->amount_capturable);
        $this->assertEquals(3165, $paymentIntent->amount_received);

        // Refresh the order object
        $this->helper->clearCache();
        $order = $this->tests->refreshOrder($order);

        // Order checks
        $this->assertEquals(53.30, $order->getGrandTotal());
        $this->assertEquals(0, $order->getTotalRefunded());
        $this->assertEquals(0, $order->getTotalCanceled());
        $this->assertEquals(21.65, $order->getTotalDue());
        $this->assertEquals(31.65, $order->getTotalInvoiced());
        $this->assertEquals(31.65, $order->getTotalPaid());
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());

        // Invoice checks
        $invoicesCollection = $order->getInvoiceCollection();
        $this->assertEquals(1, $invoicesCollection->getSize());
        $invoice = $invoicesCollection->getFirstItem();
        $this->assertEquals(31.65, $invoice->getGrandTotal());
        $this->assertEquals(2, $invoice->getTotalQty());
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());

        // Invoice the remaining amount. This should create a second payment in Stripe.
        $this->tests->invoiceOnline($order, ['virtual-product' => 2]);

        // Refresh the order object
        $this->helper->clearCache();
        $order = $this->helper->loadOrderByIncrementId($order->getIncrementId());

        // Invoice checks
        $invoicesCollection = $order->getInvoiceCollection();
        $this->assertEquals(2, $invoicesCollection->getSize());
        $invoice = $invoicesCollection->getLastItem();
        $this->assertNotEmpty($invoice->getTransactionId());
        $this->assertEquals(21.65, $invoice->getGrandTotal());
        $this->assertEquals(2, $invoice->getTotalQty());
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());

        // Stripe checks
        $paymentIntent = $this->tests->stripe()->paymentIntents->retrieve($invoice->getTransactionId());
        $charge = $paymentIntent->charges->data[0];
        $orderIncrementId = $order->getIncrementId();
        $this->tests->compare($charge, [
            "amount" => 2165,
            "amount_captured" => 2165,
            "amount_refunded" => 0,
            "description" => "Order #$orderIncrementId by Joyce Strother",
            "metadata" => [
                "Order #" => $orderIncrementId
            ]
        ]);
    }
}
