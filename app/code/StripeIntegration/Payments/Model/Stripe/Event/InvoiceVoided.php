<?php

namespace StripeIntegration\Payments\Model\Stripe\Event;

class InvoiceVoided extends \StripeIntegration\Payments\Model\Stripe\Event
{
    public function process($arrEvent, $object)
    {
        $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

        switch ($order->getPayment()->getMethod())
        {
            case "stripe_payments_invoice":
                $this->webhooksHelper->refundOfflineOrCancel($order);
                $comment = __("The invoice was voided from the Stripe Dashboard.");
                $order->addStatusToHistory(false, $comment, $isCustomerNotified = false);
                $this->helper->saveOrder($order);
                break;
        }
    }
}