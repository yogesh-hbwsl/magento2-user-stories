<?php

namespace StripeIntegration\Payments\Model;

use StripeIntegration\Payments\Exception\InvalidSubscriptionProduct;

class SubscriptionProduct
{
    public $quoteItem = null;
    public $orderItem = null;
    public $product = null;

    private $config;
    private $helper;
    private $linkManagement;
    protected $subscriptionHelper;

    public function __construct(
        \Magento\ConfigurableProduct\Api\LinkManagementInterface $linkManagement,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionHelper
    )
    {
        $this->linkManagement = $linkManagement;
        $this->config = $config;
        $this->helper = $helper;
        $this->subscriptionHelper = $subscriptionHelper;
    }

    public function fromQuoteItem($item)
    {
        if (empty($item) || !$item->getProduct())
            throw new InvalidSubscriptionProduct("Invalid quote item.");

        $this->quoteItem = $item;
        $this->product = null;

        return $this;
    }

    public function fromOrderItem($orderItem)
    {
        if (empty($orderItem) || !$orderItem->getProductId())
            throw new InvalidSubscriptionProduct("Invalid order item.");

        $this->orderItem = $orderItem;
        $this->product = $this->helper->loadProductById($orderItem->getProductId());

        return $this;
    }

    public function fromProductId($productId)
    {
        if (empty($productId))
            throw new InvalidSubscriptionProduct("Invalid product ID.");

        $this->product = $this->helper->loadProductById($productId);

        return $this;
    }

    public function getIsSaleable()
    {
        return $this->product->getIsSalable();
    }

    public function hasStartDate()
    {
        $product = $this->product;
        $subscriptionOptions = $this->subscriptionHelper->getSubscriptionOptionDetails($product->getId());

        if (!$subscriptionOptions ||
            empty($subscriptionOptions->getStartOnSpecificDate()) ||
            empty($subscriptionOptions->getStartDate()) ||
            !is_string($subscriptionOptions->getStartDate()) ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $subscriptionOptions->getStartDate()))
        {
            return false;
        }

        return true;
    }

    public function getProduct()
    {
        if ($this->product) // This will always be set if it was initialized from an order item
            return $this->product;

        if (!$this->quoteItem)
            return null;

        if (!$this->quoteItem->getProduct())
            return null;

        if (!$this->quoteItem->getProduct()->getId())
            return null;

        $productId = $this->quoteItem->getProduct()->getId();
        $product = $this->helper->loadProductById($productId);
        if (!$product || !$product->getId())
            return null;

        if (!$this->subscriptionHelper->isSubscriptionOptionEnabled($product->getId()))
            return null;

        return $this->product = $product;
    }

    public function getProductId()
    {
        $product = $this->getProduct();
        if (!$product)
            return null;

        return $product->getId();
    }

    public function getTrialDays()
    {
        $product = $this->getProduct();

        if (!$product)
            return null;

        $subscriptionOptionDetails = $this->subscriptionHelper->getSubscriptionOptionDetails($product->getId());
        if (!$subscriptionOptionDetails)
            return null;

        if (!$subscriptionOptionDetails->getSubTrial() || !is_numeric($subscriptionOptionDetails->getSubTrial()) || $subscriptionOptionDetails->getSubTrial() < 1)
            return null;

        return $subscriptionOptionDetails->getSubTrial();
    }

    public function hasTrialPeriod()
    {
        $trialDays = $this->getTrialDays();
        if (!is_numeric($trialDays) || $trialDays < 1)
            return false;

        return true;
    }

    public function getTrialEnd()
    {
        if (!$this->hasTrialPeriod())
            return null;

        $trialDays = $this->getTrialDays();
        $timeDifference = $this->helper->getStripeApiTimeDifference();

        return (time() + $trialDays * 24 * 60 * 60 + $timeDifference);
    }

    public function canUpgradeDowngrade()
    {
        if (!$this->isSubscriptionProduct())
            return false;

        return $this->areUpgradesAllowed();
    }

    public function canChangeShipping()
    {
        if (!$this->isSubscriptionProduct())
            return false;

        if ($this->orderItem && $this->orderItem->getProductType() == "simple")
        {
            return true;
        }

        return false;
    }

    public function isSubscriptionProduct(
        ?\Magento\Catalog\Api\Data\ProductInterface $product = null
    )
    {
        if (!$product)
            $product = $this->product;

        if (!$product)
            $product = $this->getProduct();

        if (!$product || !$product->getId())
            return false;

        $product = $this->product;

        $subscriptionOptionDetails = $this->subscriptionHelper->getSubscriptionOptionDetails($product->getId());

        if (!$subscriptionOptionDetails || !$subscriptionOptionDetails->getSubEnabled()) {
            return false;
        }

        $interval = $subscriptionOptionDetails->getSubInterval();
        $intervalCount = (int)$subscriptionOptionDetails->getSubIntervalCount();

        if (!$interval)
            return false;

        if ($intervalCount && !is_numeric($intervalCount))
            return false;

        if ($intervalCount < 0)
            return false;

        return true;
    }

    // This method assumes that the orderItem product is an active subscription product,
    // i.e. it only checks the parent product.
    public function isParentSubscriptionProduct()
    {
        $orderItem = $this->orderItem;

        if (!$orderItem || !$orderItem->getId())
        {
            return false;
        }

        if (!$orderItem->getParentItem() || !$orderItem->getParentItem()->getId())
        {
            return false;
        }

        $parentItem = $orderItem->getParentItem();

        return (in_array($parentItem->getProductType(), ["configurable", "bundle"]));
    }

    public function isSimpleProduct()
    {
        $orderItem = $this->orderItem;

        if (!$orderItem || !$orderItem->getId())
        {
            return false;
        }

        if ($orderItem->getProductType() != "simple")
        {
            return false;
        }

        return true;
    }

    public function isVirtualProduct()
    {
        $orderItem = $this->orderItem;

        if (!$orderItem || !$orderItem->getId())
        {
            return false;
        }

        if ($orderItem->getProductType() != "virtual")
        {
            return false;
        }

        return true;
    }

    public function getSubscriptionDetails()
    {
        if ($this->isParentSubscriptionProduct())
        {
            $product = $this->getParentProduct();
        }
        else if ($this->isSimpleProduct() || $this->isVirtualProduct())
        {
            $product = $this->getProduct();
        }
        else
        {
            return null;
        }

        if (!$product)
            return null;

        return $this->subscriptionHelper->getSubscriptionOptionDetails($product->getId());
    }

    public function areUpgradesAllowed()
    {
        $subscriptionDetails = $this->getSubscriptionDetails();

        return ($subscriptionDetails && $subscriptionDetails->getUpgradesDowngrades());
    }

    public function useProrationsForUpgrades()
    {
        if (!$this->areUpgradesAllowed())
            return false;

        $subscriptionDetails = $this->getSubscriptionDetails();

        return ($subscriptionDetails && $subscriptionDetails->getProrateUpgrades());
    }

    public function useProrationsForDowngrades()
    {
        if (!$this->areUpgradesAllowed())
            return false;

        $subscriptionDetails = $this->getSubscriptionDetails();

        return ($subscriptionDetails && $subscriptionDetails->getProrateDowngrades());
    }

    public function getParentProduct()
    {
        $orderItem = $this->orderItem;
        if (!$orderItem || !$orderItem->getParentItem() || !$orderItem->getParentItem()->getProductId())
            return null;

        $parentProductId = $orderItem->getParentItem()->getProductId();
        $parentProduct = $this->helper->loadProductById($parentProductId);
        if (!$parentProduct || !$parentProduct->getId())
            return null;

        return $parentProduct;
    }
}
