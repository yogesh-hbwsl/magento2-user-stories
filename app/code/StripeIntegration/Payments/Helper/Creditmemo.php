<?php

namespace StripeIntegration\Payments\Helper;

class Creditmemo
{
    private $creditmemoRepository;
    private $creditmemoManagement;
    private $creditmemoFactory;
    private $creditmemoService;
    private $helper;

    public function __construct(
        \Magento\Sales\Model\Order\CreditmemoFactory $creditmemoFactory,
        \Magento\Sales\Model\Service\CreditmemoService $creditmemoService,
        \Magento\Sales\Api\CreditmemoRepositoryInterface $creditmemoRepository,
        \Magento\Sales\Api\CreditmemoManagementInterface $creditmemoManagement,
        \StripeIntegration\Payments\Helper\Generic $helper
    ) {
        $this->creditmemoFactory = $creditmemoFactory;
        $this->creditmemoService = $creditmemoService;
        $this->creditmemoRepository = $creditmemoRepository;
        $this->creditmemoManagement = $creditmemoManagement;
        $this->helper = $helper;
    }

    public function save($creditmemo)
    {
        if (empty($creditmemo))
            return null;

        try
        {
            return $this->creditmemoRepository->save($creditmemo);
        }
        catch (\Exception $e)
        {
            $this->helper->logError($e->getMessage(), $e->getTraceAsString());
            return null;
        }
    }

    public function sendEmail($creditmemoId)
    {
        $this->creditmemoManagement->notify($creditmemoId);
    }

    public function validateBaseRefundAmount($order, $baseAmount)
    {
        if (!$order->canCreditmemo())
        {
            throw new \Exception("The order cannot be refunded");
        }

        if ($baseAmount <= 0)
        {
            throw new \Exception("Cannot refund an amount of $baseAmount.");
        }
    }

    public function refundOfflineOrderBaseAmount($order, $baseAmount, $sendCustomerEmail = true)
    {
        try
        {
            $this->validateBaseRefundAmount($order, $baseAmount);

            // Do not refund any order items
            $qtys = $this->getOrderItemQtys($order);

            $params = [
                "qtys" => $qtys,
                "shipping_amount" => '0',
                "adjustment_positive" => $baseAmount,
                "adjustment_negative" => '0',
                "send_email" => ($sendCustomerEmail ? '1' : '0'),
                "do_offline" => '1',
                "items" => [ $qtys ],
                // "comment_text" => __(""),
                // "comment_customer_notify" => 1
            ];

            $creditmemo = $this->creditmemoFactory->createByOrder($order, $params);

            // Create the credit memo
            $creditmemo = $this->creditmemoService->refund($creditmemo, $offline = true);

            if ($params["send_email"])
            {
                $this->sendEmail($creditmemo->getId());
            }

            return $creditmemo;
        }
        catch (\Exception $e)
        {
            $this->helper->logError($e->getMessage(), $e->getTraceAsString());
            return null;
        }
    }

    // Loops and tries to calculate the base amount to refund so that
    // Order.total_paid - Order.total_refunded  == StripeInvoice.total_paid
    // This fixes rounding errors when converting back to a base amount from a 2 decimal-places float
    public function getBaseRefundAmount(int $stripeInvoiceTotalPaid, string $stripeInvoiceCurrency, $order)
    {
        $otherAmounts = 0;
        $otherAmounts += round((float)$order->getTotalRefunded(), 2); // Previously refunded payments
        $otherAmounts += round((float)$order->getTotalCanceled(), 2); // Previously canceled payments

        $baseOtherAmounts = 0;
        $baseOtherAmounts += round((float)$order->getBaseTotalRefunded(), 2); // Previously refunded payments
        $baseOtherAmounts += round((float)$order->getBaseTotalCanceled(), 2); // Previously canceled payments

        $total = round((float)$order->getGrandTotal(), 2);
        $baseTotal = round((float)$order->getBaseGrandTotal(), 2);

        $paidAmount = $this->helper->convertStripeAmountToOrderAmount($stripeInvoiceTotalPaid, $stripeInvoiceCurrency, $order);
        $basePaidAmount = $this->helper->convertStripeAmountToBaseOrderAmount($stripeInvoiceTotalPaid, $stripeInvoiceCurrency, $order); // This is expected to cause a rounding error

        $baseRefundAmount = $baseTotal - $baseOtherAmounts - $basePaidAmount;

        // Fix the rounding error
        do
        {
            // First convert back to order amount
            $refundAmount = $this->helper->convertBaseAmountToOrderAmount($baseRefundAmount, $order, $stripeInvoiceCurrency);

            // We now expect that $amount + $otherAmounts == $total. If not, we adjust the base amount and retry
            $combinedAmount = round((float)($refundAmount + $otherAmounts + $paidAmount), 2);
            $difference = round(abs($total - $combinedAmount), 2);

            if ($difference > 0.01)
            {
                // It is possible that the order includes multiple charges, which means this is not a rounding error
                break;
            }
            else if ($combinedAmount > $total)
            {
                $baseRefundAmount -= 0.01;
            }
            else if ($combinedAmount < $total)
            {
                $baseRefundAmount += 0.01;
            }
            else
            {
                break;
            }
        }
        while (true);

        return $baseRefundAmount;
    }

    public function isUnderCharged($order, $invoiceAmountPaid, $invoiceCurrency)
    {
        if (strtolower($order->getOrderCurrencyCode()) != strtolower($invoiceCurrency))
            throw new \Exception("The order and the invoice are not in the same currency");

        $roundingErrorsAllowance = 0.01;
        $invoiceTotal = $this->helper->convertStripeAmountToOrderAmount($invoiceAmountPaid, $invoiceCurrency, $order);
        $round = round((float)$order->getGrandTotal(), 2);
        $isUnderCharged = (($invoiceTotal + $roundingErrorsAllowance) < round((float)$order->getGrandTotal(), 2));

        if ($isUnderCharged && $order->canCreditmemo())
        {
            // a) Includes a trial subscription (0 < invoiceAmountPaid < orderTotal)
            // b) The customer had a credit balance (0 <= invoiceAmountPaid < order total)

            return true;
        }

        return false;
    }

    public function refundUnderchargedOrder($order, $invoiceAmountPaid, $invoiceCurrency, $sendCustomerEmail = true)
    {
        try
        {
            if ($this->isUnderCharged($order, $invoiceAmountPaid, $invoiceCurrency))
            {
                // a) Includes a trial subscription (0 < invoiceAmountPaid < orderTotal)
                // b) The customer had a credit balance (0 <= invoiceAmountPaid < order total)

                // Make sure that the refund amount + paid amount match the order grand total, i.e. avoid rounding errors
                $baseRefundAmount = $this->getBaseRefundAmount($invoiceAmountPaid, $invoiceCurrency, $order);

                if ($baseRefundAmount > 0)
                {
                    $creditmemo = $this->refundOfflineOrderBaseAmount($order, $baseRefundAmount, $sendCustomerEmail);
                    $this->save($creditmemo);
                }
            }
        }
        catch (\Exception $e)
        {
            $this->helper->logError("Could not refund undercharged order: " . $e->getMessage(), $e->getTraceAsString());
        }
    }

    public function getOrderShippingAmount($order)
    {
        if ($order->getBaseShippingAmount())
        {
            return $order->getBaseShippingAmount();
        }
        else if ($order->getShippingAmount())
        {
            return $this->helper->convertOrderAmountToBaseAmount($order->getShippingAmount(), $order->getOrderCurrencyCode(), $order);
        }
        else
        {
            return '0';
        }
    }

    // Returns an array of [ $orderItemId => $qtyOrdered ], suitable for passing to Credit Memos
    public function getOrderItemQtys($order)
    {
        $qtys = [];

        foreach ($order->getAllVisibleItems() as $orderItem)
        {
            $orderItemId = $orderItem->getId();
            $qty = $orderItem->getQtyOrdered();

            if (in_array($orderItem->getProductType(), ['bundle', 'configurable']))
            {
                // If this is set to 0, Magento will add Bundle or Configurable items to the credit memo,
                // which is not the intended behavior. We instead want to create a credit memo without any items,
                // which will cause the order to remain in Processing/Complete status, instead of Closed/Canceled.
                // 0 will trigger the (count(array_unique($qtys)) === 1 && (int)end($qtys) === 0) condition at
                // https://github.com/magento/magento2/blob/2.4.5-p1/app/code/Magento/Sales/Model/Order/CreditmemoFactory.php#L162
                $qtys[$orderItemId] = '-1';
            }
            else
            {
                $qtys[$orderItemId] = '0';
            }
        }

        return $qtys;
    }

    public function refundFromStripeDashboard($order, array $object)
    {
        if ($order->getState() == "holded" && $order->canUnhold())
            $order->unhold();

        // Check if the order has an invoice with the charge ID we are refunding
        $chargeId = $object['id'];
        $chargeAmount = $object['amount'];
        $currentRefund = $this->getCurrentRefundFrom($object);
        $currency = $currentRefund['currency'];
        $baseToOrderRate = $order->getBaseToOrderRate();

        if (isset($object["payment_intent"]))
            $pi = $object["payment_intent"];
        else
            $pi = "not_exists";

        // Calculate the real refund amount
        $isMultiCurrencyRefund = ($currentRefund['currency'] != $order->getOrderCurrencyCode());
        $refundAmount = $this->helper->convertStripeAmountToOrderAmount($currentRefund['amount'], $currentRefund['currency'], $order);
        $baseRefundAmount = $this->helper->convertStripeAmountToBaseOrderAmount($currentRefund['amount'], $currentRefund['currency'], $order);

        $baseTotalNotRefunded = $order->getBaseGrandTotal() - $order->getBaseTotalRefunded();
        $totalNotRefunded = $order->getGrandTotal() - $order->getTotalRefunded();

        if ($isMultiCurrencyRefund)
            $isPartialRefund = ($totalNotRefunded > $refundAmount);
        else
            $isPartialRefund = ($baseTotalNotRefunded > $baseRefundAmount);

        if (!$order->canCreditmemo())
        {
            if ($order->canCancel())
            {
                if (!$isPartialRefund)
                {
                    $order->cancel();
                    $this->helper->saveOrder($order);
                    return true;
                }
                else if ($isPartialRefund)
                {
                    // Don't do anything on a partial refund, we expect a paynemt_intent.succeeded to arrive for the partial capture.
                    return false;
                }
            }
            else if (!$isPartialRefund)
            {
                $invoices = $order->getInvoiceCollection();
                $canceled = 0;
                foreach ($invoices as $invoice)
                {
                    if ($invoice->canCancel())
                    {
                        $invoice->cancel();
                        $this->helper->saveInvoice($invoice);
                        $canceled++;
                    }
                }
                if ($canceled > 0)
                {
                    if ($order->canCancel())
                    {
                        $order->getPayment()->setCancelOfflineWithComment(__("The authorization was canceled via Stripe."));
                        $order->cancel();
                    }

                    $this->helper->saveOrder($order);
                    return true;
                }
            }

            $msg = __('A refund was issued via Stripe, but a Credit Memo could not be created.');
            $this->helper->addOrderComment($msg, $order);
            $this->helper->saveOrder($order);
            return false;
        }

        if ($baseTotalNotRefunded < $baseRefundAmount)
        {
            $humanReadable1 = $this->helper->addCurrencySymbol($refundAmount, $currency);
            $humanReadable2 = $this->helper->addCurrencySymbol($totalNotRefunded, $currency);
            $msg = __('A refund of %1 was issued via Stripe, but the amount is bigger than the available of %2.', $humanReadable1, $humanReadable2);
            $this->helper->addOrderComment($msg, $order);
            $this->helper->saveOrder($order);
            return false;
        }

        $creditmemo = $this->refundOfflineOrderBaseAmount($order, $baseRefundAmount);
        $comment = __("We refunded %1 through Stripe.", $this->helper->addCurrencySymbol($refundAmount, $currency));

        if ($order->getBaseTotalRefunded() == $order->getBaseGrandTotal() ||
            $order->getTotalRefunded() == $order->getGrandTotal())
        {
            $state = \Magento\Sales\Model\Order::STATE_CLOSED;
            $status = $order->getConfig()->getStateDefaultStatus($state);
            $order->setState($state)->addStatusToHistory($status, $comment);
        }
        else
        {
            $order->addStatusToHistory($status = false, $comment);
        }

        $this->save($creditmemo);
        $this->helper->saveOrder($order);

        return true;
    }

    private function getCurrentRefundFrom($webhookData)
    {
        $lastRefundDate = 0;
        $currentRefund = null;

        foreach ($webhookData['refunds']['data'] as $refund)
        {
            // There might be multiple refunds, and we are looking for the most recent one
            if ($refund['created'] > $lastRefundDate)
            {
                $lastRefundDate = $refund['created'];
                $currentRefund = $refund;
            }
        }

        return $currentRefund;
    }

    private function getInvoiceWithTransactionId($transactionId, $order)
    {
        foreach($order->getInvoiceCollection() as $item)
        {
            $invoiceTransactionId = $this->helper->cleanToken($item->getTransactionId());
            if ($transactionId == $invoiceTransactionId)
                return $item;
        }

        return null;
    }
}
