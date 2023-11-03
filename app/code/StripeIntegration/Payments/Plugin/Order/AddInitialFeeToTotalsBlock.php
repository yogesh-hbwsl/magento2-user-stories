<?php
namespace StripeIntegration\Payments\Plugin\Order;

use Magento\Framework\DataObject;
use Magento\Quote\Api\Data\TotalsInterface;
use Magento\Sales\Block\Order\Totals;
use Magento\Sales\Model\Order;
use StripeIntegration\Payments\Helper\Logger;

class AddInitialFeeToTotalsBlock
{
    protected $quotes = [];
    protected $fees = [];

    private $helper;
    private $quoteFactory;
    private $storeManager;

    public function __construct(
        \StripeIntegration\Payments\Helper\InitialFee $helper,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager
    )
    {
        $this->helper = $helper;
        $this->quoteFactory = $quoteFactory;
        $this->storeManager = $storeManager;
    }

    public function afterGetOrder(Totals $subject, Order $order)
    {
        if (!$order->getPayment() || !$order->getPayment()->getMethod() || strpos($order->getPayment()->getMethod(), "stripe_") === false)
            return $order;

        if (empty($subject->getTotal("grand_total")))
            return $order;

        if ($subject->getTotal('initial_fee') !== false)
            return $order;

        if (!$order || !$order->getPayment())
            return $order;

        if ($this->isRecurringOrder($subject, $order))
            return $order;

        if ($this->removeInitialFee($order))
            return $order;

        if (!isset($this->quotes[$order->getId()]))
            $this->quotes[$order->getId()] = $this->quoteFactory->create()->load($order->getQuoteId());

        $quote = $this->quotes[$order->getId()];
        $orderItems = $this->getFilteredOrderItems($subject, $order);

        if (!isset($this->fees[$order->getId()]))
            $this->fees[$order->getId()] = $this->helper->getTotalInitialFeeForOrder($orderItems, $order);

        $baseFee = $this->fees[$order->getId()]['base_initial_fee'];
        $fee = $this->fees[$order->getId()]['initial_fee'];
        if ($fee > 0)
        {
            $subject->addTotalBefore(new DataObject([
                'code' => 'initial_fee',
                'base_value' => $baseFee,
                'value' => $fee,
                'label' => __('Initial Fee')
            ]), TotalsInterface::KEY_GRAND_TOTAL);
        }

        return $order;
    }

    public function isRecurringOrder($subject, $order)
    {
        if ($order->getPayment()->getAdditionalInformation("is_recurring_subscription"))
            return true;

        return false;
    }

    public function removeInitialFee($order)
    {
        $payment = $order->getPayment();
        if (!$payment)
            return false;

        return $payment->getAdditionalInformation("remove_initial_fee");
    }

    public function getFilteredOrderItems(Totals $subject, Order $order)
    {
        $orderItems = $order->getAllItems();
        $orderItemMap = [];
        foreach ($orderItems as $orderItem)
        {
            $orderItemMap[$orderItem->getId()] = $orderItem;
        }

        $filteredOrderItems = [];

        if ($subject->getInvoice())
        {
            $invoiceItems = $subject->getInvoice()->getAllItems();
            foreach ($invoiceItems as $invoiceItem)
            {
                if (isset($orderItemMap[$invoiceItem->getOrderItemId()]))
                {
                    $filteredOrderItems[] = $orderItemMap[$invoiceItem->getOrderItemId()];
                }
            }
        }
        else if ($subject->getCreditmemo())
        {
            $creditmemoItems = $subject->getCreditmemo()->getAllItems();
            foreach ($creditmemoItems as $creditmemoItem)
            {
                if (isset($orderItemMap[$creditmemoItem->getOrderItemId()]))
                {
                    $filteredOrderItems[] = $orderItemMap[$creditmemoItem->getOrderItemId()];
                }
            }
        }
        else
        {
            $filteredOrderItems = $orderItemMap;
        }

        return $filteredOrderItems;
    }
}
