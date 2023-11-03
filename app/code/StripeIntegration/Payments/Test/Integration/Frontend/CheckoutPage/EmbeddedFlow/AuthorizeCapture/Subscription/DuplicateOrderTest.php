<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\Subscription;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Checkout\Model\SessionFactory as CheckoutSessionFactory;
use PHPUnit\Framework\Constraint\StringContains;
use StripeIntegration\Payments\Test\Integration\Mock\Magento\Sales\Model\Order as MockOrder;
// use StripeIntegration\Payments\Test\Integration\Mock\Plugin\Sales\Model\Service\OrderService as MockOrderService;
use StripeIntegration\Payments\Test\Integration\Mock\Magento\Quote\Model\QuoteManagement as MockQuoteManagement;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class DuplicateOrderTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $paymentElement;
    private $quote;
    private $sessionManager;
    private $tests;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();

        $this->objectManager->configure([
            'preferences' => [
                \Magento\Quote\Model\QuoteManagement::class => MockQuoteManagement::class,
                \Magento\Sales\Model\Order::class => MockOrder::class
            ]
        ]);

        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->sessionManager = $this->objectManager->get(\Magento\Framework\Session\SessionManagerInterface::class);
        $this->paymentElement = $this->objectManager->get(\StripeIntegration\Payments\Model\PaymentElement::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     */
    public function testCrashBeforeOrderSave()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Subscription")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $ordersCount = $this->tests->getOrdersCount();

        try
        {
            MockOrder::$crashBeforeOrderSave = true;
            $order = $this->quote->placeOrder();
            $this->assertTrue(false);
        }
        catch (\Exception $e)
        {
            $this->assertEquals("crashBeforeOrderSave", $e->getMessage());
        }

        // There should be no new order created in Magento
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount, $newOrdersCount);

        // The customer will have a single subscription set up
        $customerModel = $this->tests->helper()->getCustomerModel();
        $subscriptions = $customerModel->getSubscriptions();
        $this->assertCount(1, $subscriptions);

        // 2nd order placement which also crashes
        try
        {
            $order = $this->quote->placeOrder();
            $this->assertTrue(false);
        }
        catch (\Exception $e)
        {
            $this->assertEquals("crashBeforeOrderSave", $e->getMessage());
        }

        // There should be no new order created in Magento
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount, $newOrdersCount);

        // The customer should still have a single subscription set up
        $subscriptions = $customerModel->getSubscriptions();
        $this->assertCount(1, $subscriptions);

        // Final order placement will succeed
        MockOrder::$crashBeforeOrderSave = false;
        $order = $this->quote->placeOrder();

        // The customer should still have a single subscription set up
        $subscriptions = $customerModel->getSubscriptions();
        $this->assertCount(1, $subscriptions);
    }
}
