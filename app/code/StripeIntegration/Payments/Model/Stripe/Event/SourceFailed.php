<?php

namespace StripeIntegration\Payments\Model\Stripe\Event;

class SourceFailed extends \StripeIntegration\Payments\Model\Stripe\Event
{
    public function process($arrEvent, $object)
    {
        $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

        $this->helper->cancelOrCloseOrder($order);
        $this->webhooksHelper->addOrderCommentWithEmail($order, "Your order has been canceled because the payment authorization failed.");
    }
}