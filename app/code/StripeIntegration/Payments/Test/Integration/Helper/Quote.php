<?php

namespace StripeIntegration\Payments\Test\Integration\Helper;

class Quote
{
    protected $objectManager = null;
    protected $quote = null;
    protected $order = null;
    protected $store = null;
    protected $quoteRepository = null;
    protected $productRepository = null;
    protected $availablePaymentMethods = [];
    protected $customerEmail = null;
    protected $customer = null;

    private $address;
    private $addressFactory;
    private $addressRepository;
    private $api;
    private $attributeCollectionFactory;
    private $cartManagement;
    private $checkoutHelper;
    private $checkoutSession;
    private $checkoutSessionsCollectionFactory;
    private $customerRepository;
    private $customerSession;
    private $linkManagement;
    private $objectFactory;
    private $orderFactory;
    private $paymentElementFactory;
    private $paymentsHelper;
    private $quoteAddressFactory;
    private $quoteCollectionFactory;
    private $quoteManagement;
    private $storeManager;
    private $stripeConfig;

    public function __construct()
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->quoteRepository = $this->objectManager->create(\Magento\Quote\Api\CartRepositoryInterface::class);
        $this->productRepository = $this->objectManager->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
        $this->checkoutSession = $this->objectManager->get(\Magento\Checkout\Model\Session::class);
        $this->cartManagement = $this->objectManager->get(\Magento\Quote\Api\CartManagementInterface::class);
        $this->orderFactory = $this->objectManager->get(\Magento\Sales\Model\OrderFactory::class);
        $this->objectFactory = $this->objectManager->get(\Magento\Framework\DataObject\Factory::class);
        $this->stripeConfig = $this->objectManager->get(\StripeIntegration\Payments\Model\Config::class);
        $this->checkoutHelper = $this->objectManager->get(\Magento\Checkout\Helper\Data::class);
        $this->attributeCollectionFactory = $this->objectManager->get(\Magento\Eav\Model\ResourceModel\Entity\Attribute\CollectionFactory::class);
        $this->address = $this->objectManager->get(\StripeIntegration\Payments\Test\Integration\Helper\Address::class);
        $this->customerSession = $this->objectManager->get(\Magento\Customer\Model\Session::class);
        $this->customerRepository = $this->objectManager->get(\Magento\Customer\Api\CustomerRepositoryInterface::class);
        $this->checkoutSessionsCollectionFactory = $this->objectManager->get(\StripeIntegration\Payments\Model\ResourceModel\CheckoutSession\CollectionFactory::class);
        $this->api = $this->objectManager->get(\StripeIntegration\Payments\Api\Service::class);
        $this->paymentElementFactory = $this->objectManager->get(\StripeIntegration\Payments\Model\PaymentElementFactory::class);
        $this->paymentsHelper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);

        $this->quoteManagement = $this->objectManager->get(\StripeIntegration\Payments\Test\Integration\Helper\QuoteManagement::class);

        \Magento\TestFramework\Helper\Bootstrap::getInstance()->loadArea(\Magento\Framework\App\Area::AREA_FRONTEND);

        $this->storeManager = $this->objectManager->get(\Magento\Store\Model\StoreManagerInterface::class);
        $this->store = $this->storeManager->getStore();
        $this->linkManagement = $this->objectManager->get(\Magento\ConfigurableProduct\Api\LinkManagementInterface::class);
        $this->addressRepository = $this->objectManager->get(\Magento\Customer\Api\AddressRepositoryInterface::class);
        $this->addressFactory = $this->objectManager->get(\Magento\Customer\Model\AddressFactory::class);
        $this->quoteAddressFactory = $this->objectManager->get(\Magento\Quote\Api\Data\AddressInterfaceFactory::class);
        $this->quoteCollectionFactory = $this->objectManager->get(\Magento\Quote\Model\ResourceModel\Quote\CollectionFactory::class);
    }

    public function setStore(string $storeCode)
    {
        $this->storeManager->setCurrentStore($storeCode);
        $store = $this->storeManager->getStore($storeCode);

        $this->store = $store;

        return $this;
    }

    public function create()
    {
        $this->quote = $this->objectManager
            ->create(\Magento\Quote\Model\Quote::class)
            ->setStoreId($this->store->getId())
            ->setWebsiteId($this->store->getWebsiteId())
            ->setInventoryProcessed(false);

        $this->checkoutHelper->getCheckout()->replaceQuote($this->quote);

        return $this;
    }

    public function createAdmin()
    {
        \Magento\TestFramework\Helper\Bootstrap::getInstance()->loadArea(\Magento\Framework\App\Area::AREA_ADMINHTML);

        $this->quote = $this->objectManager
            ->create(\Magento\Quote\Model\Quote::class)
            ->setStoreId($this->store->getId())
            ->setWebsiteId($this->store->getWebsiteId())
            ->setInventoryProcessed(false);

        $this->checkoutHelper->getCheckout()->replaceQuote($this->quote);

        return $this;
    }

    public function save()
    {
        $this->quote->setTotalsCollectedFlag(false)->collectTotals();
        $this->quoteRepository->save($this->quote);

        return $this;
    }

    public function reset()
    {
        $this->quote = null;
        $this->checkoutHelper->getCheckout()->clearStorage()->clearQuote()->resetCheckout()->clearHelperData();
        $this->quoteCollectionFactory->create()->walk('delete');
        $this->paymentsHelper->clearCache();

        return $this;
    }

    public function setCustomer($identifier)
    {
        switch ($identifier) {
            case 'Guest':
                $this->customerSession->setCustomerId(null);

                $this->quote->setCustomerIsGuest(true)
                    ->setCheckoutMethod(\Magento\Quote\Api\CartManagementInterface::METHOD_GUEST)
                    ->setCustomerClassId(3);
                break;

            case 'LoggedIn':
                $this->customer = $customer = $this->customerRepository->get('customer@example.com');
                $this->customerSession->setCustomerId($customer->getId());

                $this->quote->assignCustomer($customer);

                break;

            default:
                # code...
                break;
        }

        return $this;
    }

    // Multishipping Checkout
    public function login()
    {
        $this->setCustomer("LoggedIn");
        $checkout = $this->checkoutHelper->getCheckout();
        $addresses = $this->customer->getAddresses();
        $this->customerSession->loginById($this->customer->getId());

        $addressIds = [];
        foreach ($addresses as $address)
        {
            $addressIds[] = $address->getId();
        }

        $shippingInfo = [];
        foreach ($this->quote->getAllVisibleItems() as $quoteItem)
        {
            $shippingInfo[] = [
                $quoteItem->getId() => [
                    'qty' => $quoteItem->getQtyToAdd(),
                    'address' => $addressIds[0]
                ]
            ];
        }
        $checkout->setShippingItemsInformation($shippingInfo);

        $methods = [];
        $addresses = $this->quote->getAllShippingAddresses();
        foreach ($addresses as $address)
        {
            $methods[$address->getId()] = 'flatrate_flatrate';
        }
        $checkout->setShippingMethods($methods);
        return $this->save();
    }

    // OnePage Checkout
    public function loginOpc()
    {
        $this->setCustomer("LoggedIn");
        $checkout = $this->checkoutHelper->getCheckout();
        $addresses = $this->customer->getAddresses();
        $this->customerSession->loginById($this->customer->getId());

        $billingAddressId = $this->customer->getDefaultBilling();
        $shippingAddressId = $this->customer->getDefaultShipping();
        $shippingAddress = $this->addressFactory->create()->load($shippingAddressId);
        $billingAddress = $this->addressFactory->create()->load($billingAddressId);

        $this->quote->getShippingAddress()->addData($shippingAddress->getData());
        $this->quote->getShippingAddress()->save();

        $this->quote->getBillingAddress()->addData($billingAddress->getData());
        $this->quote->getBillingAddress()->save();

        $this->setShippingMethod("FlatRate");

        return $this->save();
    }

    public function addProduct($sku, $qty, $params = null)
    {
        $product = $this->productRepository->get($sku);

        if ($product->getTypeId() == "bundle" && !empty($params))
        {
            $requestParams = [
                'product' => $product->getId(),
                'bundle_option' => [],
                'bundle_option_qty' => [],
                'qty' => $qty
            ];

            $selections = $this->getBundleSelections($product);

            foreach ($params as $sku => $skuQty)
            {
                if (isset($selections[$sku]))
                {
                    $optionId = $selections[$sku]['option_id'];
                    $selectionId = $selections[$sku]['selection_id'];

                    $requestParams['bundle_option'][$optionId] = $selectionId;
                    $requestParams['bundle_option_qty'][$optionId] = $skuQty;
                }
            }

            $request = $this->objectFactory->create($requestParams);
            $result = $this->quote->addProduct($product, $request);
            if (is_string($result))
                throw new \Exception($result);
        }
        else if ($product->getTypeId() == "configurable" && !empty($params))
        {
            $this->linkManagement->getChildren($sku); // Sets the store filter cache key

            $requestParams = [
                "product" => $product->getId(),
                'super_attribute' => [],
                'qty' => $qty
            ];

            foreach ($params as $attribute)
            {
                foreach ($attribute as $attributeCode => $optionId)
                {
                    $attributeModel = $this->attributeCollectionFactory->create()->addFieldToFilter('attribute_code', $attributeCode)->load()->getFirstItem();
                    if ($attributeModel) {
                        $requestParams['super_attribute'][$attributeModel->getAttributeId()] = $optionId;
                    }
                }
            }

            $request = $this->objectFactory->create($requestParams);
            $result = $this->quote->addProduct($product, $request);
            if (is_string($result))
                throw new \Exception($result);
        }
        else
        {
            $this->quote->addProduct($product, $qty);
        }

        return $this;
    }

    public function getAttributeIdByAttributeCode($attributeCode)
    {
        $attributeModel = $this->attributeCollectionFactory->create()->addFieldToFilter('attribute_code', $attributeCode)->load()->getFirstItem();
        return $attributeModel->getAttributeId();
    }

    public function setCart($identifier)
    {
        $this->quote->removeAllItems();

        switch ($identifier)
        {
            case 'Normal':
                $this->addProduct('simple-product', 2);
                $this->addProduct('virtual-product', 2);
                break;

            case 'Virtual':
                $this->addProduct('virtual-product', 2);
                break;

            case 'Subscription':
                $this->addProduct('simple-monthly-subscription-product', 2);
                break;

            case 'SubscriptionInitialFee':
                $this->addProduct('simple-monthly-subscription-initial-fee-product', 1);
                break;

            case 'ConfigurableSubscription':
                $this->addProduct('configurable-subscription', 1, [["subscription" => "monthly"]]);
                break;

            case 'Subscriptions':
                $this->addProduct('virtual-monthly-subscription-product', 1);
                $this->addProduct('simple-monthly-subscription-initial-fee-product', 1);
                break;

            case 'MixedCart':
                $this->addProduct('simple-product', 2);
                $this->addProduct('simple-monthly-subscription-initial-fee-product', 2);
                break;

            case 'VirtualMixed':
                $this->addProduct('virtual-product', 1);
                $this->addProduct('virtual-monthly-subscription-product', 1);
                break;

            case 'Trial':
                $this->addProduct('virtual-trial-monthly-subscription-product', 1);
                $this->addProduct('simple-trial-monthly-subscription-product', 1);
                break;

            case 'TrialVirtual':
                $this->addProduct('virtual-trial-monthly-subscription-product', 1);
                break;

            case 'TrialSimple':
                $this->addProduct('simple-trial-monthly-subscription-product', 1);
                break;

            case 'MixedTrial':
                $this->addProduct('simple-product', 1);
                $this->addProduct('simple-trial-monthly-subscription-product', 1);
                break;

            case 'ZeroAmount':
                $this->addProduct('free-product', 1);
                $this->addProduct('virtual-trial-monthly-subscription-product', 1);
                break;

            case "DynamicBundleSubscription":
                $this->addProduct('bundle-dynamic', 2, ["simple-product" => 2, "simple-monthly-subscription-product" => 2]);
                break;

            case 'DynamicBundleMixedTrial':
                $this->addProduct('bundle-dynamic', 2, ["simple-product" => 2, "simple-trial-monthly-subscription-product" => 2]);
                break;

            case 'DynamicBundleMixedTrialInitialFee':
                $this->addProduct('bundle-dynamic', 2, ["simple-product" => 2, "simple-trial-monthly-subscription-initial-fee" => 2]);
                break;

            case 'DynamicBundleDoubleMixedTrial':
                $this->addProduct('bundle-dynamic', 2, ["simple-product" => 2, "simple-trial-monthly-subscription-product" => 2]);
                $this->addProduct('simple-product', 2);
                break;

            case 'FixedBundleMixedTrial':
                $this->addProduct('bundle-fixed', 2, ["simple-product" => 2, "simple-trial-monthly-subscription-product" => 2]);
                $this->addProduct('simple-product', 2);
                break;

            default:
                break;
        }

        return $this;
    }

    public function setCouponCode($couponCode)
    {
        $this->quote->setCouponCode($couponCode);
        return $this->save();
    }

    public function getBundleSelections($product)
    {
        $selectionCollection = $product->getTypeInstance()
            ->getSelectionsCollection(
                $product->getTypeInstance()->getOptionsIds($product),
                $product
            );

        $bundleSelections = [];
        foreach ($selectionCollection as $selection)
            $bundleSelections[$selection->getSku()] = $selection->getData();

        return $bundleSelections;
    }

    public function setCurrency($currencyCode)
    {
        $this->storeManager->getStore()->setCurrentCurrencyCode($currencyCode);
        $this->quote->setQuoteCurrencyCode($currencyCode);
        $this->quote->setStoreCurrencyCode($currencyCode);
        return $this->save();
    }

    public function setShippingAddress($identifier)
    {
        $address = $this->address->getMagentoFormat($identifier);

        if ($address)
        {
            $this->quote->getShippingAddress()->addData($address);
        }

        return $this->save();
    }

    public function setShippingMethod($identifier)
    {
        $shippingAddress = $this->quote->getShippingAddress();

        if ($shippingAddress)
        {
            $shippingAddress->setCollectShippingRates(true)->collectShippingRates()->save();

            switch ($identifier) {
                case 'FlatRate':
                    $shippingAddress->setShippingMethod('flatrate_flatrate');
                    break;

                case 'Free':
                    $shippingAddress->setShippingMethod('freeshipping_freeshipping');
                    break;

                case 'Best':
                    $shippingAddress->setShippingMethod('tablerate_bestway');
                    break;

                default:
                    # code...
                    break;
            }

            // foreach ($this->quote->getAllItems() as $quoteItem)
            // {
            //     $shippingAddress->requestShippingRates($quoteItem);
            // }
        }

        return $this->save();
    }

    public function setBillingAddress($identifier)
    {
        $address = $this->address->getMagentoFormat($identifier);

        if ($address)
        {
            $this->quote->getBillingAddress()->addData($address);
            $this->quote->setCustomerEmail($address["email"]);
            $this->customerEmail = $address["email"];
        }

        return $this->save();
    }

    public function getPaymentMethodData($identifier)
    {
        $data = null;

        switch ($identifier)
        {
            case 'SuccessCard':
                $data = [
                    'method' => 'stripe_payments',
                    'additional_data' => [
                        "payment_element" => true,
                        "payment_method" => $this->createPaymentMethodFrom('4242424242424242')->id
                    ]
                ];
                break;

            case 'DeclinedCard':
                $data = [
                    'method' => 'stripe_payments',
                    'additional_data' => [
                        "payment_element" => true,
                        "payment_method" => $this->createPaymentMethodFrom('4000000000000002')->id
                    ]
                ];
                break;

            case 'InsufficientFundsCard':
                $data = [
                    'method' => 'stripe_payments',
                    'additional_data' => [
                        "payment_element" => true,
                        "payment_method" => $this->createPaymentMethodFrom('4000000000009995')->id
                    ]
                ];
                break;

            case 'AuthenticationRequiredCard':
                $data = [
                    'method' => 'stripe_payments',
                    'additional_data' => [
                        "payment_element" => true,
                        "payment_method" => $this->createPaymentMethodFrom('4000002760003184')->id
                    ]
                ];
                break;

            case 'ElevatedRiskCard':
                $data = [
                    'method' => 'stripe_payments',
                    'additional_data' => [
                        "payment_element" => true,
                        "payment_method" => $this->createPaymentMethodFrom('4000000000009235')->id
                    ]
                ];
                break;

            case 'StripeCheckout':

                // Delete all previous sessions
                $this->checkoutSessionsCollectionFactory->create()->walk('delete');

                $billingAddressData = $this->quote->getBillingAddress()->getData();
                $shippingAddressData = $this->quote->getShippingAddress()->getData();
                $this->availablePaymentMethods = json_decode($this->api->get_checkout_payment_methods($billingAddressData, $shippingAddressData), true);

                $data = [
                    'method' => 'stripe_payments_checkout'
                ];
                break;

            case 'MexicoInstallmentsCard':
                $paymentMethod = $this->createPaymentMethodFrom('4000004840000008');
                $data = [
                    'method' => 'stripe_payments',
                    'additional_data' => [
                        "payment_element" => true,
                        "payment_method" => $paymentMethod->id
                    ]
                ];
                break;

            case 'SubscriptionUpdate':
                $data = [
                    'method' => 'stripe_payments',
                    'additional_data' => [
                        "is_subscription_update" => true
                    ]
                ];
                break;

            case 'StripeInvoice':
                $data = [
                    'method' => 'stripe_payments_invoice',
                    'days_due' => 7
                ];
                break;

            default:
                break;

        }

        return $data;
    }

    public function setPaymentMethod($identifier)
    {
        $data = $this->getPaymentMethodData($identifier);
        $this->quote->getPayment()->setQuote($this->quote);

        if ($data)
        {
            $this->quote->getPayment()->importData($data);
        }

        return $this->save();
    }

    public function createPaymentMethodFrom($cardNumber, $billingAddress = null)
    {
        $params = [
          'type' => 'card',
          'card' => [
            'number' => $cardNumber,
            'exp_month' => 8,
            'exp_year' => date("Y", time()) + 1,
            'cvc' => '314',
          ],
        ];

        if ($billingAddress)
            $params['billing_details'] = $this->address->getStripeFormat($billingAddress);

        return $this->stripeConfig->getStripeClient()->paymentMethods->create($params);
    }

    public function placeOrder()
    {
        $this->quote->collectTotals()->save();

        if (!$this->quote->getCustomerEmail() && $this->customerEmail) // Magento 2.3
            $this->quote->setCustomerEmail($this->customerEmail);

        return $this->cartManagement->submit($this->quote);
        // $orderId = $this->quoteManagement->placeOrder($this->quote->getId());
        // return $this->orderFactory->create()->load($orderId);
    }

    public function mockOrder()
    {
        $order = $this->quoteManagement->mockOrder($this->quote);
        $this->checkoutSession->replaceQuote($this->quote);
        return $order;
    }

    // ---------------------

    public function getQuote()
    {
        $this->checkoutSession->replaceQuote($this->quote);
        return $this->quote;
    }

    public function setQuote($quote)
    {
        $this->quote = $quote;
        $this->checkoutSession->replaceQuote($quote);
        return $this;
    }

    public function getQuoteItem($sku)
    {
        foreach ($this->quote->getAllItems() as $quoteItem)
        {
            if ($quoteItem->getSku() == $sku)
                return $quoteItem;
        }

        return null;
    }

    public function getAvailablePaymentMethods()
    {
        return $this->availablePaymentMethods['methods'];
    }

    public function getStore()
    {
        return $this->store;
    }
}
