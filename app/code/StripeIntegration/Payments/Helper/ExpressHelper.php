<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Store;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Sales\Model\Order\Shipment;

class ExpressHelper
{
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var PriceCurrencyInterface
     */
    private $priceCurrency;

    /**
     * @var \Magento\Directory\Helper\Data
     */
    private $directoryHelper;

    /**
     * @var \Magento\Tax\Helper\Data
     */
    private $taxHelper;

    /**
     * @var \Magento\Tax\Api\TaxCalculationInterface
     */
    private $taxCalculation;

    /**
     * @var \StripeIntegration\Payments\Helper\Generic
     */
    private $stripeHelper;

    private $registry;
    private $addressHelper;
    private $paymentsConfig;
    private $subscriptionHelper;

    /**
     * Helper constructor.
     *
     * @param ScopeConfigInterface                           $scopeConfig
     * @param StoreManagerInterface                          $storeManager
     * @param PriceCurrencyInterface                         $priceCurrency
     * @param \Magento\Directory\Helper\Data                 $directoryHelper
     * @param \Magento\Tax\Helper\Data                       $taxHelper
     * @param \Magento\Tax\Api\TaxCalculationInterface       $taxCalculation
     * @param \StripeIntegration\Payments\Helper\Generic       $stripeHelper
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        PriceCurrencyInterface $priceCurrency,
        \Magento\Directory\Helper\Data $directoryHelper,
        \Magento\Tax\Helper\Data $taxHelper,
        \Magento\Tax\Api\TaxCalculationInterface $taxCalculation,
        \StripeIntegration\Payments\Helper\Generic $stripeHelper,
        \StripeIntegration\Payments\Helper\Address $addressHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \Magento\Framework\Registry $registry,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionHelper
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->priceCurrency = $priceCurrency;
        $this->directoryHelper = $directoryHelper;
        $this->taxHelper = $taxHelper;
        $this->taxCalculation = $taxCalculation;
        $this->stripeHelper = $stripeHelper;
        $this->addressHelper = $addressHelper;
        $this->paymentsConfig = $config;
        $this->registry = $registry;
        $this->subscriptionHelper = $subscriptionHelper;
    }

    /**
     * Get Store Config
     * @return mixed
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getStoreConfig($path, $store = null)
    {
        if (!$store) {
            $store = $this->getStoreId();
        }

        return $this->scopeConfig->getValue(
            $path,
            ScopeInterface::SCOPE_STORE,
            $store
        );
    }

    /**
     * Get Store Id
     * @return int
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getStoreId()
    {
        return $this->storeManager->getStore()->getId();
    }

    /**
     * Return default country code
     *
     * @param \Magento\Store\Model\Store|string|int $store
     * @return string
     */
    public function getDefaultCountry($store = null)
    {
        $countryId = $this->directoryHelper->getDefaultCountry($store);

        if ($countryId)
            return $countryId;

        return $this->scopeConfig->getValue('general/country/default', ScopeInterface::SCOPE_WEBSITES);
    }

    /**
     * Get Default Shipping Address
     * @return array
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getDefaultShippingAddress()
    {
        $address = [];
        $address['country'] = $this->getStoreConfig(Shipment::XML_PATH_STORE_COUNTRY_ID);
        $address['postalCode'] = $this->getStoreConfig(Shipment::XML_PATH_STORE_ZIP);
        $address['city'] = $this->getStoreConfig(Shipment::XML_PATH_STORE_CITY);
        $address['addressLine'] = [];
        $address['addressLine'][0] = $this->getStoreConfig(Shipment::XML_PATH_STORE_ADDRESS1);
        $address['addressLine'][1] = $this->getStoreConfig(Shipment::XML_PATH_STORE_ADDRESS2);
        if ($regionId = $this->getStoreConfig(Shipment::XML_PATH_STORE_REGION_ID)) {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $region = $objectManager->create('Magento\Directory\Model\Region')
                                    ->load($regionId);

            $address['region_id'] = $region->getRegionId();
            $address['region'] = $region->getName();
        }

        return $address;
    }

    public function isSubscriptionProduct()
    {
        if (!$this->paymentsConfig->isSubscriptionsEnabled())
            return false;

        // Check the catalog product that we are viewing
        $product = $this->registry->registry('product');

        if ($product && $product->getId())
        {
            if ($product->getTypeId() == "configurable")
            {
                $children = $product->getTypeInstance()->getUsedProducts($product);
                foreach ($children as $child)
                {
                    $childProduct = $this->stripeHelper->loadProductById($child->getEntityId());
                    if ($childProduct && $this->subscriptionHelper->isSubscriptionOptionEnabled($childProduct->getId()))
                        return true;
                }
            }
            else
            {
                return $this->subscriptionHelper->isSubscriptionOptionEnabled($product->getId());
            }
        }

        return false;
    }

    public function isEnabled($location)
    {
        if (!$this->paymentsConfig->initStripe())
            return false;

        $enabled = $this->paymentsConfig->getConfigData("global_enabled", "express");
        if (!$enabled)
            return false;

        $activeLocations = explode(',', (string)$this->paymentsConfig->getConfigData("enabled", "express"));
        if (!in_array($location, $activeLocations))
            return false;

        if ($this->subscriptionHelper->isSubscriptionUpdate())
            return false;

        if ($this->stripeHelper->isAdmin())
            return false;

        if (!$this->storeManager->getStore()->isCurrentlySecure())
            return false;

        if (!$this->paymentsConfig->canCheckout())
            return false;

        return true;
    }

    /**
     * Get Billing Address
     * @return array
     */
    public function getBillingAddress($data)
    {
        return $this->addressHelper->getMagentoAddressFromPRAPIPaymentMethodData($data);
    }

    /**
     * Get Shipping Address from Result
     * @return array
     */
    public function getShippingAddressFromResult($result)
    {
        $address = $this->addressHelper->getMagentoAddressFromPRAPIResult($result['shippingAddress'], __("shipping"));
        $address['email'] = $result['payerEmail'];
        return $address;
    }

    /**
     * Get Label
     * @param \Magento\Quote\Model\Quote $quote
     *
     * @return string
     */
    public function getLabel($quote = null)
    {
        return $this->paymentsConfig->getPRAPIDescription();

        // $email = $this->stripeHelper->getCustomerEmail();
        // $first = $quote->getCustomerFirstname();
        // $last = $quote->getCustomerLastname();

        // if (empty($email) && empty($first) && empty($last)) {
        //     return (string) __('Order');
        // } elseif (empty($email)) {
        //     return (string) __('Order by %1 %2', $first, $last);
        // }

        // return (string) __('Order by %1 %2 <%3>', $first, $last, $email);
    }

    /**
     * Get Cart items
     * @param \Magento\Quote\Model\Quote $quote
     *
     * @return array
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getCartItems($quote)
    {
        // Get Currency and Amount
        $amount = $quote->getGrandTotal();
        $currency = $quote->getQuoteCurrencyCode();
        if (empty($currency))
            $currency = $quote->getStore()->getCurrentCurrency()->getCode();
        $discount = $quote->getSubtotal() - $quote->getSubtotalWithDiscount();

        // Get Quote Items
        $shouldInclTax = $this->shouldCartPriceInclTax($quote->getStore());
        $displayItems = [];
        $taxAmount = 0;
        $items = $quote->getAllVisibleItems();
        foreach ($items as $item)
        {
            /** @var $item \Magento\Quote\Model\Quote\Item */
            if ($item->getParentItem())
                continue;

            $rowTotal = $shouldInclTax ? $item->getRowTotalInclTax() : $item->getRowTotal();
            $price = $shouldInclTax ? $item->getPriceInclTax() : $item->getPrice();

            if (!$shouldInclTax)
                $taxAmount += $item->getTaxAmount();

            $label = $item->getName();
            if ($item->getQty() > 1) {
                $formattedPrice = $this->priceCurrency->format($price, false);
                $label .= sprintf(' (%s x %s)', $item->getQty(), $formattedPrice);
            }

            $displayItems[] = [
                'label' => $label,
                'amount' => $this->stripeHelper->convertMagentoAmountToStripeAmount($rowTotal, $currency),
                'pending' => false
            ];
        }

        // Add Shipping
        if (!$quote->getIsVirtual()) {
            $address = $quote->getShippingAddress();
            if ($address->getShippingInclTax() > 0)
            {
                $price = $shouldInclTax ? $address->getShippingInclTax() : $address->getShippingAmount();
                $displayItems[] = [
                    'label' => (string)__('Shipping'),
                    'amount' => $this->stripeHelper->convertMagentoAmountToStripeAmount($price, $currency)
                ];
            }
        }

        // Add Tax
        if ($taxAmount > 0) {
            $displayItems[] = [
                'label' => __('Tax'),
                'amount' => $this->stripeHelper->convertMagentoAmountToStripeAmount($taxAmount, $currency)
            ];
        }

        // Add Discount
        if ($discount > 0)
        {
            $displayItems[] = [
                'label' => __('Discount'),
                'amount' => -$this->stripeHelper->convertMagentoAmountToStripeAmount($discount, $currency)
            ];
        }

        $data = [
            'currency' => strtolower($currency),
            'total' => [
                'label' => $this->getLabel($quote),
                'amount' => $this->stripeHelper->convertMagentoAmountToStripeAmount($amount, $currency),
                'pending' => false
            ],
            'displayItems' => $displayItems
        ];

        return $data;
    }

    /**
     * Should Cart Price Include Tax
     *
     * @param  null|int|string|Store $store
     * @return bool
     */
    public function shouldCartPriceInclTax($store = null)
    {
        if ($this->taxHelper->displayCartBothPrices($store)) {
            return true;
        } elseif ($this->taxHelper->displayCartPriceInclTax($store)) {
            return true;
        }

        return false;
    }

    /**
     * Get Product Price with(without) Taxes
     * @param \Magento\Catalog\Model\Product $product
     * @param float|null $price
     * @param bool $inclTax
     * @param int $customerId
     * @param int $storeId
     *
     * @return float
     * @throws LocalizedException
     */
    public function getProductDataPrice($product, $price = null, $inclTax = false, $customerId = null, $storeId = null)
    {
        if (!($taxAttribute = $product->getCustomAttribute('tax_class_id')))
            return $price;

        if (!$price) {
            $price = $product->getPrice();
        }

        $productRateId = $taxAttribute->getValue();
        $rate = $this->taxCalculation->getCalculatedRate($productRateId, $customerId, $storeId);
        if ((int) $this->scopeConfig->getValue(
            'tax/calculation/price_includes_tax',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) === 1
        ) {
            $priceExclTax = $price / (1 + ($rate / 100));
        } else {
            $priceExclTax = $price;
        }

        $priceInclTax = $priceExclTax + ($priceExclTax * ($rate / 100));

        return round($inclTax ? floatval($priceInclTax) : floatval($priceExclTax), PriceCurrencyInterface::DEFAULT_PRECISION);
    }

    /**
     * Check is Shipping Required
     * @param \Magento\Quote\Model\Quote $quote
     * @param \Magento\Catalog\Model\Product $product
     *
     * @return bool
     */
    public function shouldRequestShipping($quote, $product = null, $attribute = null)
    {
        if ($quote && !$quote->isVirtual())
            return true;

        if ($product && in_array($product->getTypeId(), ["virtual", "downloadable"]))
            return false;

        return true;
    }
}
