<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\CouldNotSaveException;
use StripeIntegration\Payments\Model;
use StripeIntegration\Payments\Model\PaymentMethod;
use StripeIntegration\Payments\Model\Config;
use Psr\Log\LoggerInterface;
use Magento\Framework\Validator\Exception;
use StripeIntegration\Payments\Helper\Logger;
use StripeIntegration\Payments\Exception\RefundOfflineException;

class Refunds
{
    protected $orderPaymentIntents = [];
    private $cache;
    private $multishippingHelper;
    private $customer;
    private $paymentIntent;
    private $config;
    private $helper;

    public function __construct(
        \Magento\Framework\App\CacheInterface $cache,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Multishipping $multishippingHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\PaymentIntent $paymentIntent
    ) {
        $this->cache = $cache;
        $this->config = $config;
        $this->helper = $helper;
        $this->multishippingHelper = $multishippingHelper;
        $this->customer = $helper->getCustomerModel();
        $this->paymentIntent = $paymentIntent;
    }

    public function checkIfWeCanRefundMore($refundedAmount, $canceledAmount, $remainingAmount, $requestedAmount, $order, $currency)
    {
        $cents = 100;
        if ($this->helper->isZeroDecimal($currency))
            $cents = 1;

        $refundedAndCanceledAmount = $refundedAmount + $canceledAmount;

        if ($remainingAmount <= 0)
        {
            if ($refundedAndCanceledAmount < $requestedAmount)
            {
                $humanReadable1 = $this->helper->addCurrencySymbol(($requestedAmount - $refundedAndCanceledAmount) / $cents, $currency);
                $humanReadable2 = $this->helper->addCurrencySymbol($requestedAmount / $cents, $currency);
                $msg = __('%1 out of %2 could not be refunded online. Creating an offline refund instead.', $humanReadable1, $humanReadable2);
                $this->helper->addWarning($msg);
                $this->helper->addOrderComment($msg, $order);
            }

            return false;
        }

        if ($refundedAndCanceledAmount >= $requestedAmount)
        {
            return false;
        }

        return true;
    }

    public function setRefundedAmount($amount, $requestedAmount, $currency, $order)
    {
        $currency = strtolower($currency);
        $orderCurrency = strtolower($order->getOrderCurrencyCode());
        $baseCurrency = strtolower($order->getBaseCurrencyCode());

        $cents = 100;
        if ($this->helper->isZeroDecimal($currency))
            $cents = 1;

        // If this is a partial refund (2nd or 3rd), there will be an amount set already which we need to adjust instead of overwrite
        if ($order->getTotalRefunded() > 0)
        {
            $diff = $amount - $requestedAmount;
            if ($diff == 0)
                return $this; // Let Magento set the refund amount

            $refunded = $diff / $cents;
        }
        else
        {
            $refunded = $amount / $cents;
        }

        if ($currency == $orderCurrency)
        {
            $order->setTotalRefunded($order->getTotalRefunded() + $refunded);
            $baseRefunded = $this->helper->convertOrderAmountToBaseAmount($refunded, $currency, $order);
            $order->setBaseTotalRefunded($order->getBaseTotalRefunded() + $baseRefunded);
        }
        else if ($currency == $baseCurrency)
        {
            $rate = ($order->getBaseToOrderRate() ? $order->getBaseToOrderRate() : 1);
            $order->setTotalRefunded($order->getTotalRefunded() + round(floatval($refunded * $rate), 2));
            $order->setBaseTotalRefunded($order->getBaseTotalRefunded() + $refunded);
        }
        else
        {
            $this->helper->addWarning(__("Could not set order refunded amount because the currency %1 matches neither the order currency, nor the base currency."), $currency);
        }

        return $this;
    }

    public function getTransactionId(\Magento\Payment\Model\InfoInterface $payment)
    {
        if ($payment->getCreditmemo() && $payment->getCreditmemo()->getInvoice())
            $invoice = $payment->getCreditmemo()->getInvoice();
        else
            $invoice = null;

        if ($payment->getRefundTransactionId())
        {
            $transactionId = $payment->getRefundTransactionId();
        }
        else if ($invoice && $invoice->getTransactionId())
        {
            $transactionId = $invoice->getTransactionId();
        }
        else
        {
            $transactionId = $payment->getLastTransId();
        }

        if (empty($transactionId) || strpos($transactionId, "pi_") === false)
        {
            if ($this->helper->isAdmin())
            {
                throw new LocalizedException(__("The payment can only be refunded via the Stripe Dashboard. You can retry in offline mode instead."));
            }
            else
            {
                if ($this->isCancelation($payment))
                {
                    throw new RefundOfflineException(__("Canceling order offline."));
                }
                else
                {
                    throw new RefundOfflineException(__("Refunding order offline."));
                }
            }
        }

        return $this->helper->cleanToken($transactionId);
    }

    public function getBaseRefundAmount(\Magento\Payment\Model\InfoInterface $payment, $amount = null)
    {
        if (empty($amount))
        {
            // Order cancelations
            $total = ($payment->getBaseAmountOrdered() - $payment->getBaseAmountPaid());
        }
        else
        {
            // Credit memos
            $total = $amount;
        }

        if (is_numeric($total))
            return $total;

        return 0;
    }

    public function getRefundAmount(\Magento\Payment\Model\InfoInterface $payment, $amount = null)
    {
        if (empty($amount))
        {
            // Order cancelations
            $total = ($payment->getAmountOrdered() - $payment->getAmountPaid());
        }
        else
        {
            // Credit memos
            $order = $payment->getOrder();
            $creditmemo = $payment->getCreditmemo();

            if ($amount == $creditmemo->getBaseGrandTotal())
                $total = $creditmemo->getGrandTotal();
            else
                $total = $this->helper->convertBaseAmountToOrderAmount($amount, $order, $order->getOrderCurrencyCode(), $precision = 4);
        }

        if (is_numeric($total))
            return $total;

        return 0;
    }

    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount = null)
    {
        $order = $payment->getOrder();
        $currency = $payment->getOrder()->getOrderCurrencyCode();
        $transactionId = $this->getTransactionId($payment);
        $amount = $this->getRefundAmount($payment, $amount);
        $amount = round($amount, 2);
        $requestedAmount = $this->helper->convertMagentoAmountToStripeAmount($amount, $currency);
        $paymentIntents = $this->getOrderPaymentIntents($order);
        $refundableAmount = $this->getAmountRefundable($paymentIntents);
        $capturableAmount = $this->getAmountCapturable($paymentIntents);

        if ($this->isCancelation($payment) && $capturableAmount == 0)
        {
            $msg = __("Canceling order offline.");
            $this->helper->addOrderComment($msg, $order);
            return $this;
        }

        if ($refundableAmount < $requestedAmount)
        {
            $humanReadable1 = $this->helper->getFormattedStripeAmount($requestedAmount, $currency, $order);
            $humanReadable2 = $this->helper->getFormattedStripeAmount($refundableAmount, $currency, $order);
            if ($refundableAmount == 0)
            {
                if ($this->helper->isAdmin())
                {
                    throw new LocalizedException(__("Requested a refund of %1, but the most amount that can be refunded online is %2. You can retry refunding offline instead.", $humanReadable1, $humanReadable2));
                }
                else
                {
                    // We may get here in cases of abandoned carts. The cron job will attempt to cancel the order.
                    if ($this->isCancelation($payment))
                    {
                        $msg = __("Canceling order offline.");
                    }
                    else
                    {
                        $msg = __("Requested an online refund of %1, but the most amount that can be refunded online is %2. Refunding offline instead.", $humanReadable1, $humanReadable2);
                    }
                    $this->helper->addOrderComment($msg, $order);
                    return $this;
                }
            }
            else
                throw new LocalizedException(__("Requested a refund of %1, but the most amount that can be refunded online is %2.", $humanReadable1, $humanReadable2));
        }

        // Refund strategy with $refundableAmount and $capturableAmount:
        // - Fully cancel authorizations; it is not possible to partially refund the order if there are authorizations, because you must first capture them. You can only cancel the whole order.
        // - Refund the current invoice next; there should be only one.
        // - Refund paid amounts from subscription PIs; there can be one or more depending on how many subscriptions were in the cart.

        $cents = 100;
        if ($this->helper->isZeroDecimal($currency))
            $cents = 1;

        $refundedAmount = 0;
        $canceledAmount = 0;
        $remainingAmount = $requestedAmount;

        // 1. Fully cancel authorizations. It is not possible to partially refund the order if there are authorizations,
        // because you must first capture them. You can only cancel the whole order.
        /** @var \Stripe\PaymentIntent $paymentIntent */
        foreach ($paymentIntents as $paymentIntentId => $paymentIntent)
        {
            if (empty($paymentIntent->charges))
                continue;

            if ($paymentIntent->status != \StripeIntegration\Payments\Model\PaymentIntent::AUTHORIZED
                || $paymentIntent->amount > $remainingAmount)
                continue;

            foreach ($paymentIntent->charges->data as $charge)
            {
                // If it is an uncaptured authorization
                if (!$charge->captured)
                {
                    $humanReadable = $this->helper->addCurrencySymbol($charge->amount / $cents, $currency);

                    // which has not expired yet
                    if (!$charge->refunded)
                    {
                        $this->cache->save($value = "1", $key = "admin_refunded_" . $charge->id, ["stripe_payments"], $lifetime = 60 * 60);
                        $msg = __('We refunded online/released the uncaptured amount of %1 via Stripe. Charge ID: %2', $humanReadable, $charge->id);
                        // We intentionally do not cancel the $charge in this block, there is a $paymentIntent->cancel() further down
                    }
                    // which has expired
                    else
                    {
                        $msg = __('We refunded offline the expired authorization of %1. Charge ID: %2', $humanReadable, $charge->id);
                    }

                    if ($this->isCancelation($payment))
                    {
                        $this->helper->overrideCancelActionComment($payment, $msg);
                    }
                    else
                    {
                        $this->helper->addOrderComment($msg, $order);
                    }

                    $remainingAmount -= $charge->amount;
                    $canceledAmount += $charge->amount;
                }
            }

            // Fully cancel the payment intent
            $this->config->getStripeClient()->paymentIntents->cancel($paymentIntent->id, [
                "cancellation_reason" => "requested_by_customer"
            ]);
        }

        if (!$this->checkIfWeCanRefundMore($refundedAmount, $canceledAmount, $remainingAmount, $requestedAmount, $order, $currency))
        {
            $this->setRefundedAmount($refundedAmount, $requestedAmount, $currency, $order);
            return $this;
        }

        // 2. Refund the current invoice next; there should be only one match.
        foreach ($paymentIntents as $paymentIntentId => $paymentIntent)
        {
            if (empty($paymentIntent->charges))
                continue;

            if ($paymentIntentId != $transactionId)
                continue;

            foreach ($paymentIntent->charges->data as $charge)
            {
                if ($charge->captured && !$charge->invoice)
                {
                    $amountToRefund = min($remainingAmount, $charge->amount - $charge->amount_refunded);
                    if ($amountToRefund <= 0)
                        continue;

                    $this->cache->save($value = "1", $key = "admin_refunded_" . $charge->id, ["stripe_payments"], $lifetime = 60 * 60);
                    $refund = $this->config->getStripeClient()->refunds->create([
                        'charge' => $charge->id,
                        'amount' => $amountToRefund,
                        'reason' => "requested_by_customer"
                    ]);

                    $humanReadable = $this->helper->addCurrencySymbol($amountToRefund / $cents, $currency);
                    $msg = __('We refunded online %1 via Stripe. Charge ID: %2', $humanReadable, $charge->id);
                    $this->helper->addOrderComment($msg, $order);

                    $remainingAmount -= $amountToRefund;
                    $refundedAmount += $amountToRefund;
                }

                if (!$this->checkIfWeCanRefundMore($refundedAmount, $canceledAmount, $remainingAmount, $requestedAmount, $order, $currency))
                {
                    $this->setRefundedAmount($refundedAmount, $requestedAmount, $currency, $order);
                    return $this;
                }
            }
        }

        if (!$this->checkIfWeCanRefundMore($refundedAmount, $canceledAmount, $remainingAmount, $requestedAmount, $order, $currency))
        {
            $this->setRefundedAmount($refundedAmount, $requestedAmount, $currency, $order);
            return $this;
        }

        // 3. Refund amounts from subscription payments; there can be one or more depending on how many subscriptions were in the cart.
        foreach ($paymentIntents as $paymentIntentId => $paymentIntent)
        {
            if (empty($paymentIntent->charges))
                continue;

            foreach ($paymentIntent->charges->data as $charge)
            {
                if ($charge->captured && $charge->invoice)
                {
                    $amountToRefund = min($remainingAmount, $charge->amount - $charge->amount_refunded);
                    if ($amountToRefund <= 0)
                        continue;

                    $this->cache->save($value = "1", $key = "admin_refunded_" . $charge->id, ["stripe_payments"], $lifetime = 60 * 60);
                    $refund = $this->config->getStripeClient()->refunds->create([
                        'charge' => $charge->id,
                        'amount' => $amountToRefund,
                        'reason' => "requested_by_customer"
                    ]);

                    $humanReadable = $this->helper->addCurrencySymbol($amountToRefund / $cents, $currency);
                    $msg = __('We refunded online %1 via Stripe. Charge ID: %2. Invoice ID: %3', $humanReadable, $charge->id, $charge->invoice);
                    $this->helper->addOrderComment($msg, $order);

                    $remainingAmount -= $amountToRefund;
                    $refundedAmount += $amountToRefund;
                }

                if (!$this->checkIfWeCanRefundMore($refundedAmount, $canceledAmount, $remainingAmount, $requestedAmount, $order, $currency))
                {
                    $this->setRefundedAmount($refundedAmount, $requestedAmount, $currency, $order);
                    return $this;
                }
            }
        }

        // We are calling checkIfWeCanRefundMore one last time in case an order comment/warning needs to be added
        $this->checkIfWeCanRefundMore($refundedAmount, $canceledAmount, $remainingAmount, $requestedAmount, $order, $currency);
        $this->setRefundedAmount($refundedAmount, $requestedAmount, $currency, $order);
    }


    public function getAmountRefundable($paymentIntents)
    {
        $amount = 0;

        foreach ($paymentIntents as $pi)
        {
            if (empty($pi->charges))
                continue;

            foreach ($pi->charges->data as $charge)
            {
                $amount += ($charge->amount - $charge->amount_refunded);
            }
        }

        return $amount;
    }

    public function getAmountCapturable($paymentIntents)
    {
        $capturable = 0;

        foreach ($paymentIntents as $pi)
        {
            $capturable += $pi->amount_capturable;
        }

        return $capturable;
    }

    public function getOrderPaymentIntents($order)
    {
        $orderPaymentIntents = [];
        $paymentIntentIds = [];
        $transactions = $this->helper->getOrderTransactions($order);
        foreach ($transactions as $transaction)
        {
            $id = $this->helper->cleanToken($transaction->getTxnId());
            if ($id)
                $paymentIntentIds[$id] = $id;
        }

        $lastTransId = $this->helper->cleanToken($order->getPayment()->getLastTransId());
        if ($lastTransId)
            $paymentIntentIds[$lastTransId] = $lastTransId;

        foreach ($paymentIntentIds as $id)
        {
            $pi = $this->config->getStripeClient()->paymentIntents->retrieve($id, []);
            $orderPaymentIntents[$id] = $pi;
        }

        return $orderPaymentIntents;
    }

    public function isCancelation($payment)
    {
        if ($payment->getCreditmemo() && $payment->getCreditmemo()->getInvoice())
        {
            return false;
        }
        else
        {
            return true;
        }
    }

    public function refundMultishipping(\Stripe\PaymentIntent $paymentIntent, $payment, $baseAmount)
    {
        if (empty($paymentIntent->status) || $paymentIntent->status != "requires_capture")
            throw new \Exception("Cannot refund multishipping payment."); // We should never get this case

        $orders = $this->helper->getOrdersByTransactionId($paymentIntent->id);

        $totalAmount = 0;
        $processedAmount = 0;
        $incrementIds = [];
        foreach ($orders as $order)
        {
            $totalAmount += $order->getGrandTotal();
            $processedAmount += $order->getTotalPaid() + $order->getTotalCanceled();
            $incrementIds[] = "#" . $order->getIncrementId();
        }

        $order = $payment->getOrder();
        $currency = $payment->getOrder()->getOrderCurrencyCode();
        $amountToRefund = $this->getRefundAmount($payment, $baseAmount);
        $baseAmountToRefund = $this->getBaseRefundAmount($payment, $baseAmount);
        $humanReadableOrdersTotal = $this->helper->addCurrencySymbol($totalAmount, $currency);

        if ($this->isCancelation($payment))
        {
            $performOnlineCapture = (($amountToRefund + $processedAmount) >= $totalAmount);
        }
        else
        {
            // We do not add the refund because that is included as a processed amount inside $order->getTotalPaid()
            $performOnlineCapture = ($processedAmount >= $totalAmount);
        }

        if ($performOnlineCapture)
        {
            // Online capture which does not include refunded and canceled amounts
            $magentoAmount = $this->multishippingHelper->getFinalAmountWithRefund($orders, $order, $baseAmountToRefund, $currency);
            $stripeAmountToCapture = $this->helper->convertMagentoAmountToStripeAmount($magentoAmount, $currency);

            $transactionType = "capture";

            if ($stripeAmountToCapture < 0)
            {
                $humanReadable = $this->helper->addCurrencySymbol($magentoAmount, $currency);
                throw new LocalizedException(__("Cannot refund %1.", $humanReadable));
            }
            else if ($stripeAmountToCapture == 0)
            {
                $this->config->getStripeClient()->paymentIntents->cancel($paymentIntent->id, []);
                $humanReadableAmount = $this->helper->getFormattedStripeAmount($paymentIntent->amount, $currency, $order);
                $msg = __("Canceled the authorization of %1 online. This amount includes %2 multishipping orders.", $humanReadableAmount, count($orders));
                $transactionType = "void";
            }
            else if ($stripeAmountToCapture < $paymentIntent->amount)
            {
                $humanReadableAmount = $this->helper->addCurrencySymbol($magentoAmount, $currency);
                $this->config->getStripeClient()->paymentIntents->capture($paymentIntent->id, ['amount_to_capture' => $stripeAmountToCapture]);
                $msg = __("Partially captured %1 online. This amount is part of %2 multishipping orders totaling %3, and does not include cancelations and refunds.", $humanReadableAmount, count($orders), $humanReadableOrdersTotal);

            }
            else if ($stripeAmountToCapture == $paymentIntent->amount)
            {
                $this->config->getStripeClient()->paymentIntents->capture($paymentIntent->id, ['amount_to_capture' => $stripeAmountToCapture]);
                $humanReadableAmount = $this->helper->addCurrencySymbol($magentoAmount, $currency);
                $msg = __("Captured %1 online. This amount includes %2 multishipping orders.", $humanReadableAmount, count($orders));
            }
            else // $stripeAmountToCapture > $paymentIntent->amount
            {
                $humanReadable = $this->helper->getFormattedStripeAmount($paymentIntent->amount, $paymentIntent->currency, $order);
                throw new LocalizedException(__("The most amount that can be captured online is %1.", $humanReadable));
            }

            // Process all other related orders
            foreach ($orders as $relatedOrder)
            {
                if ($relatedOrder->getId() == $order->getId())
                    continue;

                $transaction = $this->helper->addTransaction($relatedOrder, $transactionId = $paymentIntent->id, $transactionType, $parentTransactionId = $paymentIntent->id);
                $this->helper->saveTransaction($transaction);

                if ($relatedOrder->getState() == "pending")
                    $this->helper->setProcessingState($relatedOrder, $msg);
                else
                    $this->helper->addOrderComment($msg, $relatedOrder);

                $this->helper->saveOrder($relatedOrder);
            }

            $this->helper->addWarning($msg);
            $this->helper->overrideCancelActionComment($payment, $msg);
        }
        else
        {
            $humanReadableAmount = $this->helper->addCurrencySymbol($amountToRefund, $currency);
            $humanReadableDate = $this->multishippingHelper->getFormattedCaptureDate($order);
            $msg = __("Scheduled %1 to be refunded via cron on %2. This amount is part of %3 multishipping orders totaling %4. To refund now instead, invoice or cancel all multishipping orders (%5). ", $humanReadableAmount, $humanReadableDate, count($orders), $humanReadableOrdersTotal, implode(", ", $incrementIds));
            throw new RefundOfflineException($msg);
        }
    }
}
