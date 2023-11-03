<?php

namespace StripeIntegration\Payments\Observer;

class AfterRemoveCartItem implements \Magento\Framework\Event\ObserverInterface
{
    private $helper;
    private $subscriptionsHelper;
    private $request;

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \Magento\Framework\App\Request\Http $request
    )
    {
        $this->helper = $helper;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->request = $request;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        try
        {
            if ($this->request->getFullActionName() == 'checkout_cart_updateItemOptions')
                return;

            $this->subscriptionsHelper->cancelSubscriptionUpdate();
        }
        catch (\Exception $e)
        {
            $this->helper->logError($e->getMessage(), $e->getTraceAsString());
        }
    }
}
