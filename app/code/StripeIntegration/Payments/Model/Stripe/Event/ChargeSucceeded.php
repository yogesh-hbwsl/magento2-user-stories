<?php

namespace StripeIntegration\Payments\Model\Stripe\Event;

use StripeIntegration\Payments\Exception\WebhookException;

class ChargeSucceeded extends \StripeIntegration\Payments\Model\Stripe\Event
{
    protected $orderHelper;
    protected $paymentIntentFactory;
    protected $paymentMethodHelper;
    protected $creditmemoHelper;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Data $dataHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\Webhooks $webhooksHelper,
        \StripeIntegration\Payments\Model\PaymentIntentFactory $paymentIntentFactory,
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Helper\Creditmemo $creditmemoHelper,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper,
        \StripeIntegration\Payments\Helper\RequestCache $requestCache,
        \StripeIntegration\Payments\Helper\Compare $compare
    )
    {
        $this->orderHelper = $orderHelper;
        $this->paymentIntentFactory = $paymentIntentFactory;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->creditmemoHelper = $creditmemoHelper;

        parent::__construct($config, $helper, $dataHelper, $subscriptionsHelper, $webhooksHelper, $requestCache, $compare);
    }
    public function process($arrEvent, $object)
    {
        if (!empty($object['metadata']['Multishipping']))
        {
            $orders = $this->webhooksHelper->loadOrderFromEvent($arrEvent, true);
            $paymentIntentModel = $this->paymentIntentFactory->create();

            foreach ($orders as $order)
                $this->orderHelper->onMultishippingChargeSucceeded($order, $object); //To DO

            return;
        }

        if ($this->webhooksHelper->wasCapturedFromAdmin($object))
            return;

        $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);
        $hasSubscriptions = $this->helper->hasSubscriptionsIn($order->getAllItems());

        if ($object && isset($object['outcome'])) {
            $this->dataHelper->setRiskDataToOrder($object, $order, true);
        }

        //Insert Stripe payment method
        $this->paymentMethodHelper->insertPaymentMethods($object, $order, true, true);

        $stripeInvoice = null;
        if (!empty($object['invoice']))
        {
            $stripeInvoice = $this->config->getStripeClient()->invoices->retrieve($object['invoice'], []);
            if ($stripeInvoice->billing_reason == "subscription_cycle" // A subscription has renewed
                || $stripeInvoice->billing_reason == "subscription_update" // A trial subscription was manually ended
                || $stripeInvoice->billing_reason == "subscription_threshold" // A billing threshold was reached
            )
            {
                // We may receive a charge.succeeded event from a recurring subscription payment. In that case we want to create
                // a new order for the new payment, rather than registering the charge against the original order.
                return;
            }
        }

        if (!$order->getEmailSent())
        {
            $isPaymentElement = $order->getPayment()->getAdditionalInformation("client_side_confirmation")
                || $order->getPayment()->getAdditionalInformation("payment_element");
            $isStripeCheckout = $order->getPayment()->getAdditionalInformation("checkout_session_id");
            $isTransactionPending = $order->getPayment()->getAdditionalInformation("is_transaction_pending");

            if ($isStripeCheckout || ($isPaymentElement && $isTransactionPending)) // Magento will send the email for synchronous payment confirmations
            {
                $this->helper->sendNewOrderEmailFor($order);
            }
        }

        if (empty($object['payment_intent']))
            throw new WebhookException("This charge was not created by a payment intent.");

        $transactionId = $object['payment_intent'];

        $payment = $order->getPayment();
        $payment->setTransactionId($transactionId)
            ->setLastTransId($transactionId)
            ->setIsTransactionPending(false)
            ->setAdditionalInformation("is_transaction_pending", false) // this is persisted
            ->setIsTransactionClosed(0)
            ->setIsFraudDetected(false)
            ->save();

        $amountCaptured = ($object["captured"] ? $object['amount_captured'] : 0);

        $this->orderHelper->onTransaction($order, $object, $transactionId);

        if ($amountCaptured > 0)
        {
            // We intentionally do not pass $params in order to avoid multi-currency rounding errors.
            // For example, if $order->getGrandTotal() == $16.2125, Stripe will charge $16.2100. If we
            // invoice for $16.2100, then there will be an order total due for 0.0075 which will cause problems.
            // $params = [
            //     "amount" => $amountCaptured,
            //     "currency" => $object['currency']
            // ];
            $this->helper->invoiceOrder($order, $transactionId, \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE, $params = null, true);
        }
        else if ($amountCaptured == 0) // Authorize Only mode
        {
            if ($hasSubscriptions)
            {
                // If it has trial subscriptions, we want a Paid invoice which will partially refund
                $this->helper->invoiceOrder($order, $transactionId, \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE, null, true);
            }
        }

        if ($this->config->isStripeRadarEnabled() && !empty($object['outcome']['type']) && $object['outcome']['type'] == "manual_review")
            $this->helper->holdOrder($order);

        $order = $this->helper->saveOrder($order);

        if (!empty($stripeInvoice) && $stripeInvoice->status == "paid")
        {
            $this->creditmemoHelper->refundUnderchargedOrder($order, $stripeInvoice->amount_paid, $stripeInvoice->currency);
        }

        // Update the payment intents table, because the payment method was created after the order was placed
        $paymentIntentModel = $this->paymentIntentFactory->create()->load($object['payment_intent'], 'pi_id');
        $quoteId = $paymentIntentModel->getQuoteId();
        if ($quoteId == $order->getQuoteId())
        {
            $paymentIntentModel->setPmId($object['payment_method']);
            $paymentIntentModel->setOrderId($order->getId());
            if (is_numeric($order->getCustomerId()) && $order->getCustomerId() > 0)
                $paymentIntentModel->setCustomerId($order->getCustomerId());
            $paymentIntentModel->save();
        }

    }
}