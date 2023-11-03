<?php

namespace StripeIntegration\Payments\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use StripeIntegration\Payments\Exception\SilentException;

class ConfigChangedObserver implements ObserverInterface
{
    protected $messageManager;
    protected $request;
    protected $redirect;
    protected $helper;
    protected $subscriptions;

    private $webhooksSetupFactory;
    private $helperFactory;

    public function __construct(
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \StripeIntegration\Payments\Helper\WebhooksSetupFactory $webhooksSetupFactory,
        \StripeIntegration\Payments\Helper\GenericFactory $helperFactory
    )
    {
        $this->messageManager = $messageManager;
        $this->webhooksSetupFactory = $webhooksSetupFactory;
        $this->helperFactory = $helperFactory;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        // We use factories because this method is called from inside the Magento install scripts
        try
        {
            $webhooksSetup = $this->webhooksSetupFactory->create();
            $helper = $this->helperFactory->create();

            if ($webhooksSetup->isConfigureNeeded())
            {
                $webhooksSetup->configure();

                if (count($webhooksSetup->errorMessages) > 0)
                    $helper->addError("Errors encountered during Stripe webhooks configuration. Please see var/log/stripe_payments_webhooks.log for details.");
                else
                    $helper->addSuccess("Stripe webhooks have been re-configured successfully.");
            }
        }
        catch (SilentException $e)
        {
            if (!empty($helper) && $helper->isAdmin())
                $helper->addError($e->getMessage());
        }
        catch (\Exception $e)
        {
            // During the Magento installation, we may crash because the helper cannot be instantiated
        }
    }
}
