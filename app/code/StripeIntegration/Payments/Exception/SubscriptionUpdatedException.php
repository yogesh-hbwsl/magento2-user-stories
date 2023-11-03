<?php

namespace StripeIntegration\Payments\Exception;

class SubscriptionUpdatedException extends \StripeIntegration\Payments\Exception\WebhookException
{
    // The quote which we will use to create a recurring order
    protected $quoteId = null;

    public function __construct($quoteId)
    {
        $this->quoteId = $quoteId;

        parent::__construct(__("The subscription has been updated and we have no order back reference."), 202);
    }

    public function getQuoteId()
    {
        return $this->quoteId;
    }
}
