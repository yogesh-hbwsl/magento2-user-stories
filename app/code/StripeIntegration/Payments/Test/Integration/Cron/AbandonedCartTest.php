<?php

namespace StripeIntegration\Payments\Test\Integration\Cron;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class AbandonedCartTest extends \PHPUnit\Framework\TestCase
{
    private $tests;
    private $quote;
    private $objectManager;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     */
    public function testCleanup()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("AuthenticationRequiredCard");

        $order = $this->quote->placeOrder();

        $cron = $this->objectManager->create(\StripeIntegration\Payments\Cron\WebhooksPing::class);

        // Test canceling abandoned orders
        $canceledSetupIntents = $cron->cancelAbandonedPayments(0, 1);
        foreach ($canceledSetupIntents as $setupIntent)
            $this->tests->event()->trigger("setup_intent.canceled", $setupIntent);

        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("canceled", $order->getState());
        $this->assertEquals("canceled", $order->getStatus());

        // Check
        $cron->clearStaleData();
    }
}
