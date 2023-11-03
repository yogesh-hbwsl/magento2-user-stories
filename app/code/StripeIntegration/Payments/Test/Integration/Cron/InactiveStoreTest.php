<?php

namespace StripeIntegration\Payments\Test\Integration\Cron;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class InactiveStoreTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $tests;
    private $quote;
    private $storeManager;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->storeManager =  $this->objectManager->get(\Magento\Store\Model\StoreManagerInterface::class);
    }

    public function testCleanup()
    {
        $this->quote->setStore("second_store")->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("AuthenticationRequiredCard");

        $order = $this->quote->placeOrder();

        $cron = $this->objectManager->create(\StripeIntegration\Payments\Cron\WebhooksPing::class);

        // Inactivate the store
        $secondStore = $this->storeManager->getStore("second_store");
        $secondStore->setIsActive(0);
        $secondStore->save();

        // Test webhook pings
        $cron->pingWebhookEndpoints();

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
