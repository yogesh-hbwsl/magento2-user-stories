<?php

namespace StripeIntegration\Payments\Model\Stripe;

class Invoice extends StripeObject
{
    protected $objectSpace = 'invoices';

    public function fromOrder($order, $customerId)
    {
        $daysDue = $order->getPayment()->getAdditionalInformation('days_due');

        if (!is_numeric($daysDue))
            $this->helper->dieWithError("You have specified an invalid value for the invoice due days field.");

        if ($daysDue < 1)
            $this->helper->dieWithError("The invoice due days must be greater or equal to 1.");

        $data = [
            'customer' => $customerId,
            'collection_method' => 'send_invoice',
            'description' => __("Order #%1 by %2", $order->getRealOrderId(), $order->getCustomerName()),
            'days_until_due' => $daysDue,
            'metadata' => [
                'Order #' => $order->getIncrementId()
            ]
        ];

        $this->createObject($data);

        if (!$this->object)
            throw new \Magento\Framework\Exception\LocalizedException(__("The invoice for order #%1 could not be created in Stripe: %2", $order->getIncrementId(), $this->lastError));

        return $this;
    }

    public function finalize()
    {
        $this->config->getStripeClient()->invoices->finalizeInvoice($this->getStripeObject()->id, []);

        return $this;
    }
}
