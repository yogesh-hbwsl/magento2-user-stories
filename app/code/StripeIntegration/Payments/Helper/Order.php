<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\CouldNotSaveException;
use StripeIntegration\Payments\Model\Config;
use Psr\Log\LoggerInterface;
use Magento\Framework\Validator\Exception;
use StripeIntegration\Payments\Helper\Logger;

class Order
{
    private $paymentsHelper;
    private $orderTaxManagement;

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $paymentsHelper,
        \Magento\Tax\Api\OrderTaxManagementInterface $orderTaxManagement
    )
    {
        $this->paymentsHelper = $paymentsHelper;
        $this->orderTaxManagement = $orderTaxManagement;
    }

    public function onMultishippingChargeSucceeded($order, $object)
    {
        // DO NOT call saveOrder() in here. A 3DS may still be happening which will record transactions and save the order elsewhere
        $this->paymentsHelper->sendNewOrderEmailFor($order);
    }

    public function onTransaction($order, $object, $transactionId)
    {
        $action = __("Collected");
        if ($object["captured"] == false)
        {
            if ($order->getState() != "pending" && $order->getPayment()->getAdditionalInformation("server_side_transaction_id") == $transactionId)
            {
                // This transaction does not need to be recorded, it was already created when the order was placed.
                return;
            }
            $action = __("Authorized");
            $transactionType = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH;
            $transactionAmount = $this->paymentsHelper->convertStripeAmountToOrderAmount($object['amount'], $object['currency'], $order);
        }
        else
        {
            if ($order->getTotalPaid() >= $order->getGrandTotal() && $order->getPayment()->getAdditionalInformation("server_side_transaction_id") == $transactionId)
            {
                // This transaction does not need to be recorded, it was already created when the order was placed.
                return;
            }
            $action = __("Captured");
            $transactionType = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE;
            $transactionAmount = $this->paymentsHelper->convertStripeAmountToOrderAmount($object['amount_captured'], $object['currency'], $order);
        }

        $transaction = $order->getPayment()->addTransaction($transactionType, null, false);
        $transaction->setAdditionalInformation("amount", $transactionAmount);
        $transaction->setAdditionalInformation("currency", $object['currency']);
        $transaction->save();

        $state = \Magento\Sales\Model\Order::STATE_PROCESSING;
        $status = $order->getConfig()->getStateDefaultStatus($state);
        $humanReadableAmount = $this->paymentsHelper->addCurrencySymbol($transactionAmount, $object['currency']);
        $comment = __("%1 amount of %2 via Stripe. Transaction ID: %3", $action, $humanReadableAmount, $transactionId);
        $order->setState($state)->addStatusToHistory($status, $comment, $isCustomerNotified = false);
    }

    /**
     * Array
     * (
     *     [code] => US-CA-*-Rate 1
     *     [title] => US-CA-*-Rate 1
     *     [percent] => 8.2500
     *     [amount] => 1.65
     *     [base_amount] => 1.65
     * )
     */
    public function getAppliedTaxes($orderId)
    {
        $taxes = [];
        $appliedTaxes = $this->orderTaxManagement->getOrderTaxDetails($orderId)->getAppliedTaxes();

        foreach ($appliedTaxes as $appliedTax)
        {
            $taxes[] = $appliedTax->getData();
        }

        return $taxes;
    }
}
