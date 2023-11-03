<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Multishipping\Model\Checkout\Type\Multishipping\State;
use StripeIntegration\Payments\Exception\SCANeededException;
use Magento\Framework\Exception\LocalizedException;
use StripeIntegration\Payments\Exception\SkipCaptureException;

class Multishipping
{
    protected $checkout = null;

    private $multishippingCheckoutFactory;
    private $paymentIntentHelper;
    private $paymentIntent;
    private $multishippingQuote;
    private $multishippingOrderFactory;
    private $multishippingOrderCollection;
    private $paymentIntentFactory;
    private $state;
    private $checkoutSession;
    private $eventManager;
    private $session;
    private $config;
    private $helper;

    private $remoteAddress;
    private $httpHeader;

    public function __construct(
        \Magento\Multishipping\Model\Checkout\Type\Multishipping\State $state,
        \Magento\Framework\Session\SessionManagerInterface $session,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \StripeIntegration\Payments\Model\Checkout\Type\MultishippingFactory $multishippingCheckoutFactory,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\PaymentIntent $paymentIntentHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\PaymentIntent $paymentIntent,
        \StripeIntegration\Payments\Model\PaymentIntentFactory $paymentIntentFactory,
        \StripeIntegration\Payments\Model\Multishipping\Quote $multishippingQuote,
        \StripeIntegration\Payments\Model\Multishipping\OrderFactory $multishippingOrderFactory,
        \StripeIntegration\Payments\Model\ResourceModel\Multishipping\Order\Collection $multishippingOrderCollection,
        \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress $remoteAddress,
        \Magento\Framework\HTTP\Header $httpHeader
    )
    {
        $this->state = $state;
        $this->session = $session;
        $this->checkoutSession = $checkoutSession;
        $this->eventManager = $eventManager;
        $this->multishippingCheckoutFactory = $multishippingCheckoutFactory;
        $this->helper = $helper;
        $this->paymentIntentHelper = $paymentIntentHelper;
        $this->config = $config;
        $this->paymentIntent = $paymentIntent;
        $this->multishippingQuote = $multishippingQuote;
        $this->multishippingOrderFactory = $multishippingOrderFactory;
        $this->multishippingOrderCollection = $multishippingOrderCollection;
        $this->paymentIntentFactory = $paymentIntentFactory;
        $this->remoteAddress = $remoteAddress;
        $this->httpHeader = $httpHeader;
    }

    protected function getCheckout()
    {
        if ($this->checkout)
            return $this->checkout;

        return $this->checkout = $this->multishippingCheckoutFactory->create();
    }

    protected function resetCheckout($quoteId)
    {
        // Clear the payment method. In cases where the payment failed on a new PM,
        // we cannot reuse the token for order placements. Ask the user to specify a new one.
        $multishippingQuoteModel = $this->multishippingQuote;
        $multishippingQuoteModel->load($quoteId, 'quote_id');
        $multishippingQuoteModel->delete();

        // If the payment was unsuccessful, cancel the PaymentIntent so that the associated orders are also canceled
        $paymentIntentModel = $this->paymentIntentFactory->create()->load($quoteId, 'quote_id');
        if ($paymentIntentModel->getPiId())
        {
            try
            {
                $paymentIntent = $this->config->getStripeClient()->paymentIntents->retrieve($paymentIntentModel->getPiId());
                if ($this->paymentIntentHelper->canCancel($paymentIntent))
                {
                    $paymentIntent->cancel();
                }
            }
            catch (\Exception $e)
            {
                $this->helper->logError("Could not cancel payment intent: " . $e->getMessage());
            }
        }
    }

    protected function getFinalRedirectUrl($quoteId)
    {
        $checkout = $this->getCheckout();

        if ($this->session->getAddressErrors())
        {
            $this->state->setCompleteStep(State::STEP_OVERVIEW);
            $this->state->setActiveStep(State::STEP_RESULTS);
            $this->resetCheckout($quoteId);
            return $this->helper->getUrl('multishipping/checkout/results');
        }
        else if ($this->session->getOrderIds())
        {
            // It is possible that the orders were placed and a crash happened after,
            // resulting in an empty quote and a re-submission at the Overview page.
            // Strategy: Redirect to the success page
            $this->state->setCompleteStep(State::STEP_OVERVIEW);
            $this->state->setActiveStep(State::STEP_SUCCESS);
            $this->checkoutSession->clearQuote();
            $this->checkoutSession->setDisplaySuccess(true);
            $checkout->deactivateQuote($checkout->getQuote());
            return $this->helper->getUrl('multishipping/checkout/success');
        }
        else
        {
            $this->helper->addError(__("Could not place order: Your checkout session has expired."));
            return $this->helper->getUrl('checkout/cart');
        }
    }

    public function placeOrder($quoteId)
    {
        $checkout = $this->getCheckout();

        if (empty($quoteId))
            return $this->getFinalRedirectUrl($quoteId);

        $multishippingQuoteModel = $this->multishippingQuote->load($quoteId, 'quote_id');

        if (!$multishippingQuoteModel->getPaymentMethodId())
        {
            $this->helper->addError(__("Please specify a payment method."));
            return $this->helper->getUrl('multishipping/checkout/billing');
        }

        if (!$checkout->validateMinimumAmount())
        {
            $error = $checkout->getMinimumAmountError();
            return $this->helper->getUrl('multishipping/checkout/overview');
        }

        $quote = $this->helper->loadQuoteById($quoteId);

        $results = $checkout->createOrders();
        $orders = $results['orders'];
        $errors = $results['exceptionList'];
        $successful = $failed = [];

        foreach ($orders as $order)
        {
            $model = $this->multishippingOrderFactory->create();
            $model->load($order->getId(), 'order_id');
            $model->setQuoteId($quoteId);
            $model->setOrderId($order->getId());

            if (isset($errors[$order->getIncrementId()]))
            {
                $error = $errors[$order->getIncrementId()]->getMessage();
                $model->setLastError($error);
                $failed[] = $order;
            }
            else
            {
                $model->setLastError(null);
                $successful[] = $order;
            }

            $model->save();
        }

        $checkout->setResultsPageData($quote, $successful, $failed, $errors);

        $addressErrors = $checkout->getAddressErrors($quote, $successful, $failed, $errors);
        if (count($addressErrors) > 0 && count($successful) == 0)
            return $this->getFinalRedirectUrl($quoteId);


        if ($this->config->getPaymentAction() === 'order')
        {
            return $this->getFinalRedirectUrl($quoteId);
        }

        try
        {

            $params = $this->paymentIntent->getMultishippingParamsFrom($quote, $successful, $multishippingQuoteModel->getPaymentMethodId());
            $params['automatic_payment_methods'] = ["enabled" => true ];
            $paymentIntent = $this->paymentIntent->create($params, $quote);

            $isManualCapture = ($paymentIntent->capture_method == "manual");
            $multishippingQuoteModel->setPaymentIntentId($paymentIntent->id);
            $multishippingQuoteModel->setManualCapture($isManualCapture);
            $multishippingQuoteModel->save();

            foreach ($successful as $order)
            {
                $this->paymentIntent->setTransactionDetails($order->getPayment(), $paymentIntent);
                $this->helper->saveOrder($order);
            }

            if ($this->paymentIntentHelper->canConfirm($paymentIntent))
            {
                $confirmParams['payment_method'] = $multishippingQuoteModel->getPaymentMethodId();

                if (!empty($paymentIntent->automatic_payment_methods->enabled))
                    $confirmParams["return_url"] = $this->helper->getUrl('stripe/payment/index');

                $confirmParams["setup_future_usage"] = 'on_session';

                $remoteAddress = $this->remoteAddress->getRemoteAddress();
                $userAgent = $this->httpHeader->getHttpUserAgent();

                if ($remoteAddress && $userAgent)
                {
                    $confirmParams['mandate_data']['customer_acceptance'] = [
                        "type" => "online",
                        "online" => [
                            "ip_address" => $remoteAddress,
                            "user_agent" => $userAgent,
                        ]
                    ];
                }
                $paymentIntent = $this->config->getStripeClient()->paymentIntents->confirm($paymentIntent->id, $confirmParams);

                if ($this->paymentIntent->requiresAction($paymentIntent))
                    throw new SCANeededException($paymentIntent->client_secret);

                $this->onPaymentConfirmed($quoteId, $successful, $paymentIntent);
            }
        }
        catch (SCANeededException $e)
        {
            throw $e;
        }
        catch (\Stripe\Exception\CardException $e)
        {
            $this->helper->logError($e->getMessage());
            $this->setAddressErrorForRemainingOrders($quote, $e->getMessage());
            $this->helper->sendPaymentFailedEmail($checkout->getQuote(), $e->getMessage());
            return $this->getFinalRedirectUrl($quoteId);
        }
        catch (LocalizedException $e)
        {
            $this->helper->logError($e->getMessage());
            $this->setAddressErrorForRemainingOrders($quote, $e->getMessage());
            $this->helper->sendPaymentFailedEmail($checkout->getQuote(), $e->getMessage());
            return $this->getFinalRedirectUrl($quoteId);
        }
        catch (\Exception $e)
        {
            $this->helper->logError($e->getMessage(), $e->getTraceAsString());
            $this->setAddressErrorForRemainingOrders($quote, __("A server side error has occurred. Please contact us for assistance."));
            $this->helper->sendPaymentFailedEmail($checkout->getQuote(), $e->getMessage());
            return $this->getFinalRedirectUrl($quoteId);
        }

        return $this->getFinalRedirectUrl($quoteId);
    }

    public function finalizeOrder($quoteId, $error = null)
    {
        $quote = $this->helper->loadQuoteById($quoteId);
        $successfulOrders = $this->getSuccessfulOrdersForQuoteId($quoteId);

        if ($error)
        {
            $this->onPaymentFailed($quote, $error);
        }
        else
        {
            $this->onPaymentConfirmed($quoteId, $successfulOrders);
        }

        $this->eventManager->dispatch(
            'checkout_submit_all_after',
            ['orders' => $successfulOrders, 'quote' => $quote]
        );

        return $this->getFinalRedirectUrl($quoteId);
    }

    public function onPaymentFailed($quote, $error)
    {
        $this->setAddressErrorForRemainingOrders($quote, $error);

        $msg = __("Payment failed: %1", $error);

        $orderModels = $this->multishippingOrderCollection->getByQuoteId($quote->getId());
        foreach ($orderModels as $orderModel)
        {
            if ($orderModel->getOrderId())
            {
                $order = $this->helper->loadOrderById($orderModel->getOrderId());
                if ($order && $order->getId())
                {
                    $this->helper->addOrderComment($msg, $order);
                    $this->helper->cancelOrCloseOrder($order, true, true);
                    $this->helper->saveOrder($order);
                }

                $orderModel->setLastError($error);
                $orderModel->save();
            }
        }
    }

    public function onPaymentConfirmed($quoteId, $successfulOrders, $paymentIntent = null)
    {
        $multishippingQuoteModel = $this->multishippingQuote->load($quoteId, 'quote_id');
        if (!$multishippingQuoteModel->getManualCapture())
            $multishippingQuoteModel->setCaptured(true)->save();

        if (!$paymentIntent)
            $paymentIntent = $this->config->getStripeClient()->paymentIntents->retrieve($multishippingQuoteModel->getPaymentIntentId());

        $riskScore = '';
        $riskLevel = 'NA';
        if ($paymentIntent && isset($paymentIntent->charges->data[0])) {
            if (isset($paymentIntent->charges->data[0]->outcome->risk_score) && $paymentIntent->charges->data[0]->outcome->risk_score >= 0) {
                $riskScore = $paymentIntent->charges->data[0]->outcome->risk_score;
            }
            if (isset($paymentIntent->charges->data[0]->outcome->risk_level)) {
                $riskLevel = $paymentIntent->charges->data[0]->outcome->risk_level;
            }
        }

        foreach ($successfulOrders as $order)
        {
            $this->paymentIntent->setTransactionDetails($order->getPayment(), $paymentIntent);

            if ($this->config->isAuthorizeOnly())
            {
                if ($this->config->isAutomaticInvoicingEnabled())
                    $this->helper->invoicePendingOrder($order, $paymentIntent->id);
            }
            else
            {
                $invoice = $this->helper->invoiceOrder($order, $paymentIntent->id, \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
            }

            if ($multishippingQuoteModel->getManualCapture())
            {
                $transactionType = "authorization";
                $this->helper->setProcessingState($order, __("Payment authorization succeeded."));
            }
            else
            {
                $transactionType = "capture";
                $this->helper->setProcessingState($order, __("Payment succeeded."));
            }

            $charge = $paymentIntent->charges->data[0];

            if ($this->config->isStripeRadarEnabled() && !empty($charge->outcome->type) && $charge->outcome->type == "manual_review")
                $this->helper->holdOrder($order);

            //Risk Data to sales_order table
            if ($riskScore >= 0) {
                $order->setStripeRadarRiskScore($riskScore);
            }
            $order->setStripeRadarRiskLevel($riskLevel);

            $this->helper->saveOrder($order);
            $transaction = $this->helper->addTransaction($order, $paymentIntent->id, $transactionType);
            $this->helper->saveTransaction($transaction);
        }

        $quote = $this->helper->loadQuoteById($quoteId);
        $checkout = $this->getCheckout();
        $checkout->removeSuccessfulOrdersFromQuote($quote, $successfulOrders);
    }

    public function setAddressErrorForRemainingOrders($quote, $error)
    {
        $addressErrors = $this->session->getAddressErrors();
        $successfulOrderIds = $this->session->getOrderIds();

        $shippingAddresses = $quote->getAllShippingAddresses();
        if ($quote->hasVirtualItems())
            $shippingAddresses[] = $quote->getBillingAddress();

        if ($error)
        {
            foreach ($shippingAddresses as $shippingAddress)
            {
                $id = $shippingAddress->getId();

                if (!isset($addressErrors[$id]))
                    $addressErrors[$id] = (string)$error;
            }

            $this->session->setAddressErrors($addressErrors);
            $this->session->setOrderIds([]);
        }
    }

    public function getSuccessfulOrdersForQuoteId($quoteId)
    {
        $orders = [];
        $orderModels = $this->multishippingOrderCollection->getByQuoteId($quoteId);
        foreach ($orderModels as $orderModel)
        {
            if (!$orderModel->getLastError() && $orderModel->getOrderId())
            {
                $order = $this->helper->loadOrderById($orderModel->getOrderId());
                if ($order && $order->getId())
                    $orders[] = $order;
            }
        }

        return $orders;
    }

    public function captureOrdersFromAdminArea($orders, $paymentIntentId, $payment, $baseAmount, $retryAuthorization)
    {
        try
        {
            $paymentIntent = $this->config->getStripeClient()->paymentIntents->retrieve($paymentIntentId, []);
        }
        catch (\Exception $e)
        {
            return $this->helper->dieWithError("Could not retrieve Payment Intent: " . $e->getMessage());
        }

        if (in_array($paymentIntent->status, ["requires_payment_method", "requires_confirmation", "requires_action", "processing"]))
            $this->helper->dieWithError(__("The payment for this order has not been authorized yet."));

        if (in_array($paymentIntent->status, ["canceled", "succeeded"]))
        {
            // If the charge was captured or canceled externally, fallback to the error handling of normal captures.
            return $this->helper->capture($paymentIntentId, $payment, $baseAmount, $retryAuthorization);
        }

        if ($paymentIntent->status != "requires_capture")
            return $this->helper->dieWithError("The payment intent has a status of " . $paymentIntent->status . " and cannot be captured. Please contact magento@stripe.com for assistance.");

        $ordersTotal = 0;
        $incrementIds = [];
        foreach ($orders as $relatedOrder)
        {
            $incrementIds[] = "#" . $relatedOrder->getIncrementId();
            $ordersTotal += $relatedOrder->getGrandTotal();
        }

        $order = $payment->getOrder();

        $humanReadableOrdersTotal = $this->helper->addCurrencySymbol($ordersTotal, $paymentIntent->currency);

        if ($this->areOrdersFullyProcessed($orders, $order, $baseAmount))
        {
            $authorizedAmount = $this->helper->getFormattedStripeAmount($paymentIntent->amount, $paymentIntent->currency, $order);
            $magentoAmount = $this->getFinalAmountWithCapture($orders, $order, $baseAmount, $paymentIntent->currency);
            $stripeAmount = $this->helper->convertMagentoAmountToStripeAmount($magentoAmount, $paymentIntent->currency);
            $finalAmount = $stripeAmount;

            if ($stripeAmount > $paymentIntent->amount)
            {
                $finalAmount = $paymentIntent->amount;
                $msg = __("The amount to be captured (%1) is larger than the authorized amount of %2. We will capture %2 instead.", $magentoAmount, $authorizedAmount);
                $this->helper->addWarning($msg);
                $this->helper->addOrderComment($msg, $order);
            }

            $this->helper->getCache()->save($value = "1", $key = "admin_captured_" . $paymentIntent->id, ["stripe_payments"], $lifetime = 60 * 60);

            try
            {
                $this->config->getStripeClient()->paymentIntents->capture($paymentIntent->id, ['amount_to_capture' => $finalAmount]);
            }
            catch (\Exception $e)
            {
                return $this->helper->dieWithError($e->getMessage());
            }

            $humanReadableAmount = $this->helper->getFormattedStripeAmount($finalAmount, $paymentIntent->currency, $order);
            if ($magentoAmount < $ordersTotal)
            {
                $msg = __("Partially captured %1 online. This amount is part of %2 multishipping orders totaling %3, and does not include cancelations and refunds.", $humanReadableAmount, count($orders), $humanReadableOrdersTotal);
                $this->helper->overrideInvoiceActionComment($payment, $msg);
            }
            else
            {
                $msg = __("Captured %1 online. This is a joint amount for %2 multishipping orders.", $humanReadableAmount, count($orders), $humanReadableOrdersTotal);
                $this->helper->overrideInvoiceActionComment($payment, $msg);
            }

            $riskScore = '';
            $riskLevel = 'NA';
            if ($paymentIntent && isset($paymentIntent->charges->data[0])) {
                if (isset($paymentIntent->charges->data[0]->outcome->risk_score) && $paymentIntent->charges->data[0]->outcome->risk_score >= 0) {
                    $riskScore = $paymentIntent->charges->data[0]->outcome->risk_score;
                }
                if (isset($paymentIntent->charges->data[0]->outcome->risk_level)) {
                    $riskLevel = $paymentIntent->charges->data[0]->outcome->risk_level;
                }
            }

            // Process all other related orders
            foreach ($orders as $relatedOrder)
            {
                if ($relatedOrder->getId() == $order->getId())
                    continue;

                $transaction = $this->helper->addTransaction($relatedOrder, $transactionId = $paymentIntent->id, $transactionType = "capture", $parentTransactionId = $paymentIntent->id);
                $this->helper->saveTransaction($transaction);

                if ($relatedOrder->getState() == "pending")
                    $this->helper->setProcessingState($relatedOrder, $msg);
                else
                    $this->helper->addOrderComment($msg, $relatedOrder);

                //Risk Data to sales_order table
                if ($riskScore >= 0) {
                    $order->setStripeRadarRiskScore($riskScore);
                }
                $order->setStripeRadarRiskLevel($riskLevel);

                $this->helper->saveOrder($relatedOrder);
            }
        }
        else
        {
            $finalAmount = $this->helper->convertBaseAmountToOrderAmount($baseAmount, $order, $paymentIntent->currency, 2);
            $humanReadableAmount = $this->helper->addCurrencySymbol($finalAmount, $paymentIntent->currency);
            $humanReadableDate = $this->getFormattedCaptureDate($order);

            $msg = __("Scheduled %1 to be captured via cron on %5. This amount is part of %2 multishipping orders totaling %3. To capture now instead, invoice or cancel all multishipping orders (%4). ", $humanReadableAmount, count($orders), $humanReadableOrdersTotal, implode(", ", $incrementIds), $humanReadableDate);
            $this->helper->addWarning($msg);
            $this->helper->overrideInvoiceActionComment($payment, $msg);
            $this->helper->saveOrder($order);
        }
    }

    public function getFormattedCaptureDate($order)
    {
        $captureTime = strtotime($order->getCreatedAt());
        $captureTime += (6 * 24 * 60 * 60 + 1 * 60 * 60); // 6 days and 1 hour after order placement
        $humanReadableDate = date('l jS \of F', $captureTime);
        return $humanReadableDate;
    }

    public function captureOrdersFromCronJob($orders, $paymentIntentId)
    {
        if (empty($orders))
            throw new \Exception("No orders specified.");

        if ($this->areOrdersUnprocessed($orders))
            throw new SkipCaptureException("Action needed", SkipCaptureException::ORDERS_NOT_PROCESSED);

        $paymentIntent = $this->config->getStripeClient()->paymentIntents->retrieve($paymentIntentId, []);

        if (in_array($paymentIntent->status, ["requires_payment_method", "requires_confirmation", "requires_action", "processing"]))
            throw new SkipCaptureException(__("The payment for this order has not been authorized yet."));

        if (in_array($paymentIntent->status, ["canceled", "succeeded"]))
            throw new SkipCaptureException("Cannot capture $paymentIntentId because it has a status of {$paymentIntent->status}", SkipCaptureException::INVALID_STATUS);

        if ($paymentIntent->status != "requires_capture")
            throw new \Exception("The payment intent has a status of " . $paymentIntent->status . " and cannot be captured. Please contact magento@stripe.com for assistance.");

        $exampleOrder = reset($orders);

        $ordersTotal = 0;
        foreach ($orders as $relatedOrder)
            $ordersTotal += $relatedOrder->getGrandTotal();

        $humanReadableOrdersTotal = $this->helper->addCurrencySymbol($ordersTotal, $paymentIntent->currency);

        $authorizedAmount = $this->helper->getFormattedStripeAmount($paymentIntent->amount, $paymentIntent->currency, $exampleOrder);
        $magentoAmount = $this->getFinalAmountWithCapture($orders, null, null, $paymentIntent->currency);
        $stripeAmount = $this->helper->convertMagentoAmountToStripeAmount($magentoAmount, $paymentIntent->currency);
        $finalAmount = $stripeAmount;

        if ($stripeAmount > $paymentIntent->amount)
        {
            $finalAmount = $paymentIntent->amount;
            $msg = __("Cron: The amount to be captured (%1) is larger than the authorized amount of %2. We will capture %2 instead. Transaction ID: %3", $magentoAmount, $authorizedAmount, $paymentIntentId);
            $this->helper->logError($msg);
        }

        if ($finalAmount == 0)
            throw new SkipCaptureException("The total capture amount is 0.", SkipCaptureException::ZERO_AMOUNT);

        $this->helper->getCache()->save($value = "1", $key = "admin_captured_" . $paymentIntent->id, ["stripe_payments"], $lifetime = 60 * 60);

        $this->config->getStripeClient()->paymentIntents->capture($paymentIntent->id, ['amount_to_capture' => $finalAmount]);

        $humanReadableAmount = $this->helper->getFormattedStripeAmount($finalAmount, $paymentIntent->currency, $exampleOrder);
        if ($magentoAmount < $ordersTotal)
        {
            $msg = __("Cron: Partially captured %1 online. This amount is part of %2 multishipping orders totaling %3, and does not include cancelations and refunds. Transaction ID: %4", $humanReadableAmount, count($orders), $humanReadableOrdersTotal, $paymentIntentId);
        }
        else
        {
            $msg = __("Cron: Captured %1 online. This amount is part of %2 multishipping orders totaling %3. Transaction ID: %4", $humanReadableAmount, count($orders), $humanReadableOrdersTotal, $paymentIntentId);
        }

        $this->helper->logInfo($msg);

        // Process all other related orders
        foreach ($orders as $relatedOrder)
        {
            $transaction = $this->helper->addTransaction($relatedOrder, $transactionId = $paymentIntent->id, $transactionType = "capture", $parentTransactionId = $paymentIntent->id);
            $this->helper->saveTransaction($transaction);

            if ($relatedOrder->getState() == "pending")
                $this->helper->setProcessingState($relatedOrder, $msg);
            else
                $this->helper->addOrderComment($msg, $relatedOrder);

            $this->helper->saveOrder($relatedOrder);
        }
    }

    public function areOrdersUnprocessed($orders)
    {
        foreach ($orders as $order)
        {
            $remaining = $baseRemaining = 0;

            if ($order->getTotalPaid() > 0 || $order->getBaseTotalPaid() > 0)
                return false;
        }

        return true;
    }

    public function areOrdersFullyProcessed($orders, $currentOrder = null, $baseCaptureAmount = null)
    {
        $total = 0;
        $processed = 0;

        foreach ($orders as $order)
        {
            $total += $order->getGrandTotal();
            $processed += ($order->getTotalPaid() + $order->getTotalCanceled());

            if ($currentOrder && $order->getId() == $currentOrder->getId())
                $processed += $this->helper->convertBaseAmountToOrderAmount($baseCaptureAmount, $order, $order->getOrderCurrencyCode());
        }

        return ($processed >= $total);
    }

    public function getFinalAmountWithCapture($orders, $currentOrder, $baseCaptureAmount, $currency)
    {
        $total = 0;

        foreach ($orders as $order)
        {
            $total += round(floatval($order->getGrandTotal()), 2);
            $total -= round(floatval($order->getTotalRefunded()), 2);
            $total -= round(floatval($order->getTotalCanceled()), 2);

            if ($currentOrder && $order->getId() == $currentOrder->getId())
                $total += round($this->helper->convertBaseAmountToOrderAmount($baseCaptureAmount, $currentOrder, $currency), 2);
        }

        return $total;
    }

    public function getFinalAmountWithRefund($orders, $currentOrder, $baseRefundAmount, $currency)
    {
        return $this->getFinalAmountWithCapture($orders, $currentOrder, -$baseRefundAmount, $currency);
    }


    public function isMultishippingPayment($paymentIntent)
    {
        $orders = $this->helper->getOrdersByTransactionId($paymentIntent->id);

        if (count($orders) <= 1)
            return false;

        if (empty($paymentIntent->metadata->Multishipping))
            return false;

        return true;
    }

    public function isMultishippingQuote($quoteId)
    {
        if (empty($quoteId))
            return false;

        $quote = $this->helper->loadQuoteById($quoteId);
        if (!$quote || !$quote->getId())
            return false;

        return (bool)$quote->getIsMultiShipping();
    }
}
