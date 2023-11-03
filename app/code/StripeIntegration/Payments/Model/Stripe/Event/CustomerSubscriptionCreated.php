<?php

namespace StripeIntegration\Payments\Model\Stripe\Event;

class CustomerSubscriptionCreated extends \StripeIntegration\Payments\Model\Stripe\Event
{
    public function process($arrEvent, $object, $stdEvent)
    {
        $subscription = $stdEvent->data->object;

        try
        {
            $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);
            $this->subscriptionsHelper->updateSubscriptionEntry($subscription, $order);

            if (empty($subscription->latest_invoice) && $order->getPayment()->getAdditionalInformation("is_future_subscription_setup"))
            {
                $this->helper->cancelOrCloseOrder($order, true, true);
                $comment = __("No payment has been collected. A separate order will be created with the first payment.");
                $order->addStatusToHistory(false, $comment, $isCustomerNotified = false);
                $this->helper->saveOrder($order);
            }
        }
        catch (\Exception $e)
        {
            if ($object['status'] == "incomplete" || $object['status'] == "trialing")
            {
                // A PaymentElement has created an incomplete subscription which has no order yet
                $this->subscriptionsHelper->updateSubscriptionEntry($subscription, null);
            }
            else
            {
                throw $e;
            }
        }
    }
}