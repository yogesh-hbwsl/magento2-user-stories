<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\CardsEmbedded\AuthorizeOnly\ManualInvoicing\Normal;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class PartialRefundsTest extends \PHPUnit\Framework\TestCase
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
        $paymentIntent = $this->tests->confirm($order);
        $paymentIntentId = $paymentIntent->id;

        \Magento\TestFramework\Helper\Bootstrap::getInstance()->loadArea('adminhtml');

        $invoice1 = $this->tests->invoiceOnline($order, ['simple-product' => 2]);
        $this->assertNotEmpty($invoice1->getTransactionId());
        $transactionId1 = $invoice1->getTransactionId();
        $transactionId1 = $this->helper->cleanToken($transactionId1);
        $paymentIntent1 = $this->tests->event()->triggerPaymentIntentEvents($transactionId1, $this);

        $this->assertEquals(31.65, $invoice1->getGrandTotal());
        $this->assertEquals(2, $invoice1->getTotalQty());
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice1->getState());

        // Invoice the remaining amount. This should create a second payment in Stripe.
        $invoice2 = $this->tests->invoiceOnline($order, ['virtual-product' => 2]);
        $this->assertNotEmpty($invoice2->getTransactionId());
        $transactionId2 = $invoice2->getTransactionId();
        $transactionId2 = $this->helper->cleanToken($transactionId2);
        $this->assertNotEquals($transactionId1, $transactionId2);
        $paymentIntent2 = $this->tests->event()->triggerPaymentIntentEvents($transactionId2, $this);

        $this->assertEquals(21.65, $invoice2->getGrandTotal());
        $this->assertEquals(2, $invoice2->getTotalQty());
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice2->getState());

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        $this->tests->compare($order->debug(), [
            'total_paid' => 53.30,
            'total_due' => 0,
            'state' => "processing",
            'status' => "processing"
        ]);
        // $this->assertFalse($order->canInvoice());
        $this->assertTrue($order->canCreditmemo());

        // Invoice checks
        $invoicesCollection = $order->getInvoiceCollection();
        $this->assertEquals(2, $invoicesCollection->getSize());

        // Partially refund the order
        $this->tests->refundOnline($invoice1, ['simple-product' => 2], $baseShipping = 10);
        $this->tests->event()->trigger("charge.refunded", $paymentIntent1->charges->data[0]->id);

        $this->tests->refundOnline($invoice2, ['virtual-product' => 2]);
        $this->tests->event()->trigger("charge.refunded", $paymentIntent2->charges->data[0]->id);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        if ($this->tests->magento("<", "2.4"))
        {
            // In Magento 2.3.7, the tax is not refunded in one of the 2 credit memos. This only happens
            // with programmatic refunds, i.e. refunding manually from the Magento admin will refund 53.30
            $this->assertEquals(51.65, $order->getTotalRefunded());
        }
        else
        {
            $this->assertEquals(53.30, $order->getTotalRefunded());
        }

        $this->assertFalse($order->canInvoice());
        $this->assertFalse($order->canCreditmemo());
        $this->assertEquals("closed", $order->getState());
        $this->assertEquals("closed", $order->getStatus());
    }
}
