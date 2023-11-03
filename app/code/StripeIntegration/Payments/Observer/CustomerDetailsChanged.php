<?php

namespace StripeIntegration\Payments\Observer;

use Magento\Framework\Event\ObserverInterface;
use StripeIntegration\Payments\Helper\Logger;

class CustomerDetailsChanged implements ObserverInterface
{
    private $helper;
    private $config;
    private $stripeCustomerFactory;

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\StripeCustomerFactory $stripeCustomerFactory
    )
    {
        $this->helper = $helper;
        $this->config = $config;
        $this->stripeCustomerFactory = $stripeCustomerFactory;
    }

    /**
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $event = $observer->getEvent();
        $savedCustomer = $event->getCustomerDataObject();
        $prevCustomerData = $event->getOrigCustomerDataObject();

        if (empty($prevCustomerData) || empty($savedCustomer))
            return;

        $oldName = $prevCustomerData->getFirstname() . " " . $prevCustomerData->getLastname();
        $newName = $savedCustomer->getFirstname() . " " . $savedCustomer->getLastname();

        if ($savedCustomer->getEmail() == $prevCustomerData->getEmail() && $oldName == $newName)
            return;

        $customerId = $savedCustomer->getId();
        $customerModel = $this->stripeCustomerFactory->create()->load($customerId, "customer_id");
        $customerStripeId = $customerModel->getStripeId();

        if (!$customerStripeId)
            return;

        try
        {
            \StripeIntegration\Payments\Model\Config::$stripeClient->customers->update($customerStripeId, [
                'email' => $savedCustomer->getEmail(),
                'name' => $newName,
                'description' => null
            ]);

        }
        catch (\Exception $e)
        {
            $this->helper->logError("Could not update Stripe customer: " . $e->getMessage());
        }
    }
}
