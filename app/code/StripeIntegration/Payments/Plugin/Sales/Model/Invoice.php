<?php

namespace StripeIntegration\Payments\Plugin\Sales\Model;

class Invoice
{
    protected $transactions = [];

    private $transactionSearchResultFactory;
    private $productFactory;
    private $dataHelper;
    private $products;
    private $genericHelper;
    private $subscriptionHelper;

    public function __construct(
        \Magento\Sales\Api\Data\TransactionSearchResultInterfaceFactory $transactionSearchResultFactory,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \StripeIntegration\Payments\Helper\Data $dataHelper,
        \StripeIntegration\Payments\Helper\Generic $genericHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionHelper
    )
    {
        $this->transactionSearchResultFactory = $transactionSearchResultFactory;
        $this->productFactory = $productFactory;
        $this->dataHelper = $dataHelper;
        $this->genericHelper = $genericHelper;
        $this->subscriptionHelper = $subscriptionHelper;
    }

    public function aroundCanCapture($subject, \Closure $proceed)
    {
        // Deprecated as of v2.7.1
        return /* !$this->hasSubscriptions($subject) && */ $proceed();
    }

    public function getTransactions($order)
    {
        if (isset($this->transactions[$order->getId()]))
            return $this->transactions[$order->getId()];

        $transactions = $this->transactionSearchResultFactory->create()->addOrderIdFilter($order->getId());
        return $this->transactions[$order->getId()] = $transactions;
    }

    public function aroundCanCancel($subject, \Closure $proceed)
    {
        $order = $subject->getOrder();

        $isStripePaymentMethod = (strpos($order->getPayment()->getMethod(), "stripe_") === 0);

        if (!$isStripePaymentMethod || !$this->dataHelper->isAdmin())
            return $proceed();

        $isPending = ($subject->getState() == \Magento\Sales\Model\Order\Invoice::STATE_OPEN);
        $transactions = $this->getTransactions($order);
        $hasTransactions = ($transactions->getSize() > 0);
        $wasCaptured = false;
        foreach ($transactions->getItems() as $transaction)
        {
            if ($transaction->getTxnType() == "capture")
                $wasCaptured = true;
        }

        if ($isPending && $hasTransactions)
            return false;

        if ($wasCaptured)
            return false;

        return $proceed();
    }

    public function isUnpaid($subject)
    {
        $transactionId = $subject->getTransactionId();
        if (empty($transactionId))
            return true;

        if (strpos($transactionId, "sub_") !== false) // Trialing subscription invoice
            return true;

        return false;
    }

    public function hasSubscriptions($subject)
    {
        $items = $subject->getAllItems();

        foreach ($items as $item)
        {
            if (!$item->getProductId())
                continue;

            $product = $this->loadProductById($item->getProductId());
            if ($product && $this->subscriptionHelper->isSubscriptionOptionEnabled($product->getId()))
                return true;
        }

        return false;
    }

    public function loadProductById($productId)
    {
        if (!isset($this->products))
            $this->products = [];

        if (!empty($this->products[$productId]))
            return $this->products[$productId];

        $this->products[$productId] = $this->productFactory->create()->load($productId);

        return $this->products[$productId];
    }

}
