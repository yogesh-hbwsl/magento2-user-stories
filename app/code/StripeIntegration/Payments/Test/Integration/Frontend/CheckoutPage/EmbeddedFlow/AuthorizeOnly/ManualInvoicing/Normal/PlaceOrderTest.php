<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeOnly\ManualInvoicing;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class PlaceOrderTest extends \PHPUnit\Framework\TestCase
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
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize
     * @magentoConfigFixture current_store payment/stripe_payments/automatic_invoicing 0
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
        $paymentIntent = $this->tests->confirm($order);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        $invoicesCollection = $order->getInvoiceCollection();

        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
        $this->assertEquals(0, $invoicesCollection->count());

        $this->assertTrue($order->canInvoice());
    }
}
