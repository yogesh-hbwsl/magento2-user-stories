<?php

namespace StripeIntegration\Payments\Model\Stripe\Event;

class InvoicePaid extends \StripeIntegration\Payments\Model\Stripe\Event
{
    public function process($arrEvent, $object)
    {
        $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);
        $paymentMethod = $order->getPayment()->getMethod();

        if ($paymentMethod != "stripe_payments_invoice")
            return;

        $order->getPayment()->setLastTransId($object['payment_intent'])->save();

        foreach($order->getInvoiceCollection() as $invoice)
        {
            $invoice->setTransactionId($object['payment_intent']);
            $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
            $invoice->pay();
            $this->helper->saveInvoice($invoice);
        }

        $this->helper->setProcessingState($order, __("The customer has paid the invoice for this order."));
        $this->helper->saveOrder($order);
    }
}