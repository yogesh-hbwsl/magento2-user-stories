<?php

namespace StripeIntegration\Payments\Model\Stripe\Event;

class PaymentIntentPaymentFailed extends \StripeIntegration\Payments\Model\Stripe\Event
{
    public function process($arrEvent, $object)
    {
        $orders = $this->webhooksHelper->loadOrderFromEvent($arrEvent, true);

        foreach ($orders as $order)
        {
            if (!empty($object['last_payment_error']['message']))
                $lastError = $object['last_payment_error'];
            elseif (!empty($object['last_setup_error']['message']))
                $lastError = $object['last_setup_error'];
            else
                $lastError = null;

            if (!empty($lastError['message'])) // This is set with Stripe Checkout / redirect flow
            {
                switch ($lastError['code'])
                {
                    case 'payment_intent_authentication_failure':
                        $msg = __("Payment authentication failed.");
                        break;
                    case 'payment_intent_payment_attempt_failed':
                        if (strpos($lastError['message'], "expired") !== false)
                        {
                            $msg = __("Customer abandoned the cart. The payment session has expired.");
                            $this->helper->cancelOrCloseOrder($order);
                        }
                        else
                            $msg = __("Payment failed: %1", $lastError['message']);
                        break;
                    default:
                        $msg = __("Payment failed: %1", $lastError['message']);
                        break;
                }
            }
            else if (!empty($object['failure_message']))
                $msg = __("Payment failed: %1", $object['failure_message']);
            else if (!empty($object["outcome"]["seller_message"]))
                $msg = __("Payment failed: %1", $object["outcome"]["seller_message"]);
            else
                $msg = __("Payment failed.");

            $this->webhooksHelper->addOrderComment($order, $msg);
        }
    }
}