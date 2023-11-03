<?php

namespace StripeIntegration\Payments\Model\Stripe\Event;

class ChargeCaptured extends \StripeIntegration\Payments\Model\Stripe\Event
{
    public function process($arrEvent, $object)
    {
        if ($this->webhooksHelper->wasCapturedFromAdmin($object))
            return;

        $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

        if (empty($object['payment_intent']))
            return;

        $paymentIntentId = $object['payment_intent'];

        $chargeAmount = $this->helper->convertStripeAmountToOrderAmount($object['amount_captured'], $object['currency'], $order);
        $transactionType = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE;
        $transaction = $this->helper->addTransaction($order, $paymentIntentId, $transactionType, $paymentIntentId);
        $transaction->setAdditionalInformation("amount", $chargeAmount);
        $transaction->setAdditionalInformation("currency", $object['currency']);
        $transaction->save();

        $humanReadableAmount = $this->helper->addCurrencySymbol($chargeAmount, $object['currency']);
        $comment = __("%1 amount of %2 via Stripe. Transaction ID: %3", __("Captured"), $humanReadableAmount, $paymentIntentId);
        $order->addStatusToHistory(false, $comment, $isCustomerNotified = false);
        $this->helper->saveOrder($order);

        $params = [
            "amount" => $object['amount_captured'],
            "currency" => $object['currency']
        ];

        $captureCase = \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE;

        $this->helper->invoiceOrder($order, $paymentIntentId, $captureCase, $params);
    }
}