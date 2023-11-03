<?php

namespace StripeIntegration\Payments\Model\Stripe\Event;

class SourceCanceled extends \StripeIntegration\Payments\Model\Stripe\Event
{
    public function process($arrEvent, $object)
    {
        $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

        $canceled = $this->helper->cancelOrCloseOrder($order);
        if ($canceled)
            $this->webhooksHelper->addOrderCommentWithEmail($order, "Sorry, your order has been canceled because a payment request was sent to your bank, but we did not receive a response back. Please contact us or place your order again.");
    }
}