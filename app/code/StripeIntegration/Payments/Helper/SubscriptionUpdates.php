<?php

namespace StripeIntegration\Payments\Helper;

class SubscriptionUpdates
{
    private $checkoutSession;

    public function __construct(
        \Magento\Checkout\Model\Session $checkoutSession
    )
    {
        $this->checkoutSession = $checkoutSession;
    }

    public function getSubscriptionUpdateDetails()
    {
        $updateDetails = $this->checkoutSession->getSubscriptionUpdateDetails();

        if (isset($updateDetails['_data']['subscription_id']))
        {
            return $updateDetails;
        }

        return null;
    }
}
