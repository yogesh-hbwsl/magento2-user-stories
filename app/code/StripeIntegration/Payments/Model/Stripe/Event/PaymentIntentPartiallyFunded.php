<?php

namespace StripeIntegration\Payments\Model\Stripe\Event;

class PaymentIntentPartiallyFunded extends \StripeIntegration\Payments\Model\Stripe\Event
{
    public function process($arrEvent, $object)
    {
        $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

        $currency = $object['currency'];
        $totalAmount = $object['amount'];
        $remainingAmount = 0;
        if (!empty($object['next_action']['display_bank_transfer_instructions']['amount_remaining']))
        {
            $remainingAmount = $object['next_action']['display_bank_transfer_instructions']['amount_remaining'];
        }

        if ($remainingAmount == 0)
            return;

        $orderAmountPaid = $this->helper->convertStripeAmountToOrderAmount($totalAmount - $remainingAmount, $currency, $order);
        $orderAmountRemaining = $this->helper->convertStripeAmountToOrderAmount($remainingAmount, $currency, $order);
        $humanReadablePaidAmount = $this->helper->addCurrencySymbol($orderAmountPaid, $currency);
        $humanReadableRemainingAmount = $this->helper->addCurrencySymbol($orderAmountRemaining, $currency);

        $comment = __("Customer paid %1 using bank transfer. To complete payment, ask the customer to transfer %2 more.", $humanReadablePaidAmount, $humanReadableRemainingAmount);
        $this->webhooksHelper->addOrderComment($order, $comment);
    }
}