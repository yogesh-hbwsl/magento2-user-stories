<?php

namespace StripeIntegration\Payments\Api;

use StripeIntegration\Payments\Api\ServiceInterface;
use StripeIntegration\Payments\Exception\SCANeededException;
use StripeIntegration\Payments\Exception\InvalidAddressException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Webapi\ServiceInputProcessor;
use Magento\Checkout\Api\Data\ShippingInformationInterfaceFactory;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Registry;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Filter\LocalizedToNormalized;

class Service implements ServiceInterface
{

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var Registry
     */
    protected $registry;

    /**
     * @var PriceCurrencyInterface
     */
    protected $priceCurrency;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var \Magento\Framework\UrlInterface
     */
    private $urlBuilder;

    /**
     * @var \Magento\Framework\Event\ManagerInterface
     */
    private $eventManager;

    /**
     * @var \Magento\Checkout\Model\Cart
     */
    private $cart;

    /**
     * @var \Magento\Checkout\Helper\Data
     */
    private $checkoutHelper;

    /**
     * @var \Magento\Customer\Model\Session
     */
    private $customerSession;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    private $checkoutSession;

    /**
     * @var \StripeIntegration\Payments\Helper\ExpressHelper
     */
    private $expressHelper;

    /**
     * @var \StripeIntegration\Payments\Helper\Generic
     */
    private $paymentsHelper;

    /**
     * @var \StripeIntegration\Payments\Model\Config
     */
    private $config;

    /**
     * @var \StripeIntegration\Payments\Model\StripeCustomer
     */
    private $stripeCustomer;

    /**
     * @var ServiceInputProcessor
     */
    private $inputProcessor;

    /**
     * @var \Magento\Quote\Api\Data\AddressInterface
     */
    private $estimatedAddressFactory;

    /**
     * @var \Magento\Quote\Api\ShippingMethodManagementInterface
     */
    private $shippingMethodManager;

    /**
     * @var \Magento\Checkout\Api\ShippingInformationManagementInterface
     */
    private $shippingInformationManagement;

    /**
     * @var ShippingInformationInterfaceFactory
     */
    private $shippingInformationFactory;

    /**
     * @var \Magento\Quote\Api\CartRepositoryInterface
     */
    private $quoteRepository;

    /**
     * @var CartManagementInterface
     */
    private $quoteManagement;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var LocalizedToNormalized
     */
    private $localizedToNormalized;

    protected $shipmentEstimation;
    protected $localeHelper;
    protected $addressHelper;
    protected $checkoutSessionHelper;
    protected $compare;
    protected $paymentElement;
    protected $paymentIntentHelper;
    protected $initParams;
    protected $multishippingHelper;
    protected $paymentIntent;
    protected $subscriptionsHelper;
    protected $paymentMethodHelper;
    protected $checkoutSessionFactory;
    protected $stripePaymentIntentFactory;

    /**
     * Service constructor.
     *
     * @param \Psr\Log\LoggerInterface                                     $logger
     * @param ScopeConfigInterface                                         $scopeConfig
     * @param StoreManagerInterface                                        $storeManager
     * @param \Magento\Framework\UrlInterface                              $urlBuilder
     * @param \Magento\Framework\Event\ManagerInterface                    $eventManager
     * @param \Magento\Checkout\Model\Cart                                 $cart
     * @param \Magento\Checkout\Helper\Data                                $checkoutHelper
     * @param \Magento\Customer\Model\Session                              $customerSession
     * @param \Magento\Checkout\Model\Session                              $checkoutSession
     * @param \StripeIntegration\Payments\Helper\ExpressHelper             $expressHelper
     * @param \StripeIntegration\Payments\Helper\Generic                     $paymentsHelper
     * @param \StripeIntegration\Payments\Model\Config                       $config
     * @param ServiceInputProcessor                                        $inputProcessor
     * @param \Magento\Quote\Api\Data\AddressInterfaceFactory              $estimatedAddressFactory
     * @param \Magento\Quote\Api\ShippingMethodManagementInterface         $shippingMethodManager
     * @param \Magento\Checkout\Api\ShippingInformationManagementInterface $shippingInformationManagement
     * @param ShippingInformationInterfaceFactory                          $shippingInformationFactory
     * @param \Magento\Quote\Api\CartRepositoryInterface                   $quoteRepository
     * @param CartManagementInterface                                      $quoteManagement
     * @param ProductRepositoryInterface                                   $productRepository
     */
    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        \Magento\Framework\UrlInterface $urlBuilder,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Checkout\Model\Cart $cart,
        \Magento\Checkout\Helper\Data $checkoutHelper,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Framework\Serialize\SerializerInterface $serializer,
        \Magento\Framework\Filter\LocalizedToNormalized $localizedToNormalized,
        \StripeIntegration\Payments\Helper\ExpressHelper $expressHelper,
        \StripeIntegration\Payments\Helper\Generic $paymentsHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\PaymentElement $paymentElement,
        \StripeIntegration\Payments\Model\CheckoutSessionFactory $checkoutSessionFactory,
        \StripeIntegration\Payments\Model\Stripe\PaymentIntentFactory $stripePaymentIntentFactory,
        ServiceInputProcessor $inputProcessor,
        \Magento\Quote\Api\Data\AddressInterfaceFactory $estimatedAddressFactory,
        \Magento\Quote\Api\ShippingMethodManagementInterface $shippingMethodManager,
        \Magento\Quote\Api\ShipmentEstimationInterface $shipmentEstimation,
        \Magento\Checkout\Api\ShippingInformationManagementInterface $shippingInformationManagement,
        ShippingInformationInterfaceFactory $shippingInformationFactory,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        CartManagementInterface $quoteManagement,
        ProductRepositoryInterface $productRepository,
        \StripeIntegration\Payments\Model\PaymentIntent $paymentIntent,
        Registry $registry,
        PriceCurrencyInterface $priceCurrency,
        \StripeIntegration\Payments\Helper\Address $addressHelper,
        \StripeIntegration\Payments\Helper\Locale $localeHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\CheckoutSession $checkoutSessionHelper,
        \StripeIntegration\Payments\Helper\Compare $compare,
        \StripeIntegration\Payments\Helper\Multishipping $multishippingHelper,
        \StripeIntegration\Payments\Helper\InitParams $initParams,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper,
        \StripeIntegration\Payments\Helper\PaymentIntent $paymentIntentHelper
    ) {
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->urlBuilder = $urlBuilder;
        $this->eventManager = $eventManager;
        $this->cart = $cart;
        $this->checkoutHelper = $checkoutHelper;
        $this->customerSession = $customerSession;
        $this->checkoutSession = $checkoutSession;
        $this->serializer = $serializer;
        $this->localizedToNormalized = $localizedToNormalized;
        $this->expressHelper = $expressHelper;
        $this->paymentsHelper = $paymentsHelper;
        $this->config = $config;
        $this->paymentElement = $paymentElement;
        $this->checkoutSessionFactory = $checkoutSessionFactory;
        $this->stripeCustomer = $paymentsHelper->getCustomerModel();
        $this->inputProcessor = $inputProcessor;
        $this->estimatedAddressFactory = $estimatedAddressFactory;
        $this->shippingMethodManager = $shippingMethodManager;
        $this->shipmentEstimation = $shipmentEstimation;
        $this->shippingInformationManagement = $shippingInformationManagement;
        $this->shippingInformationFactory = $shippingInformationFactory;
        $this->quoteRepository = $quoteRepository;
        $this->quoteManagement = $quoteManagement;
        $this->productRepository = $productRepository;
        $this->paymentIntent = $paymentIntent;
        $this->registry = $registry;
        $this->priceCurrency = $priceCurrency;
        $this->addressHelper = $addressHelper;
        $this->localeHelper = $localeHelper;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->checkoutSessionHelper = $checkoutSessionHelper;
        $this->compare = $compare;
        $this->multishippingHelper = $multishippingHelper;
        $this->initParams = $initParams;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->paymentIntentHelper = $paymentIntentHelper;
        $this->stripePaymentIntentFactory = $stripePaymentIntentFactory;
    }

    /**
     * Returns the Stripe Checkout redirect URL
     * @return string
     */
    public function redirect_url()
    {
        $checkout = $this->checkoutHelper->getCheckout();
        $redirectUrl = $this->checkoutHelper->getCheckout()->getStripePaymentsRedirectUrl();
        $successUrl = $this->storeManager->getStore()->getUrl('checkout/onepage/success/');

        // The order was not placed / not saved because some of some exception
        $lastRealOrderId = $checkout->getLastRealOrderId();
        if (empty($lastRealOrderId))
            throw new LocalizedException(__("Your checkout session has expired. Please refresh the checkout page and try again."));

        // The order was placed, but could not be loaded
        $order = $this->paymentsHelper->loadOrderByIncrementId($lastRealOrderId);
        if (empty($order) || empty($order->getPayment()))
            throw new LocalizedException(__("Sorry, the order could not be placed. Please contact us for more help."));

        // The order was loaded
        if (empty($checkout->getStripePaymentsCheckoutSessionURL()))
            throw new LocalizedException(__("Sorry, the order could not be placed. Please contact us for more help."));

        $sessionURL = $checkout->getStripePaymentsCheckoutSessionURL();
        $this->checkoutHelper->getCheckout()->restoreQuote();
        $this->checkoutHelper->getCheckout()->setLastRealOrderId($lastRealOrderId);
        return $sessionURL;
    }

    /**
     * Return URL
     * @param mixed $address
     * @return string
     */
    public function estimate_cart($address)
    {
        try
        {
            $quote = $this->cart->getQuote();
            $rates = [];

            if (!$quote->isVirtual())
            {
                // Set Shipping Address
                $shippingAddress = $this->addressHelper->getMagentoAddressFromPRAPIResult($address, __("shipping"));
                $quote->getShippingAddress()->addData($shippingAddress);
                $rates = $this->shipmentEstimation->estimateByExtendedAddress($quote->getId(), $quote->getShippingAddress());
            }

            $shouldInclTax = $this->expressHelper->shouldCartPriceInclTax($quote->getStore());
            $currency = $quote->getQuoteCurrencyCode();
            $result = [];
            foreach ($rates as $rate) {
                if ($rate->getErrorMessage()) {
                    continue;
                }

                $result[] = [
                    'id' => $rate->getCarrierCode() . '_' . $rate->getMethodCode(),
                    'label' => implode(' - ', [$rate->getCarrierTitle(), $rate->getMethodTitle()]),
                    //'detail' => $rate->getMethodTitle(),
                    'amount' => $this->paymentsHelper->convertMagentoAmountToStripeAmount($shouldInclTax ? $rate->getPriceInclTax() : $rate->getPriceExclTax(), $currency)
                ];
            }

            return $this->serializer->serialize([
                "results" => $result
            ]);
        }
        catch (\Exception $e)
        {
            throw new CouldNotSaveException(__($e->getMessage()), $e);
        }
    }

    /**
     * Apply Shipping Method
     *
     * @param mixed $address
     * @param string|null $shipping_id
     *
     * @return string
     * @throws CouldNotSaveException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function apply_shipping($address, $shipping_id = null)
    {
        if (count($address) === 0) {
            $address = $this->expressHelper->getDefaultShippingAddress();
        }

        $quote = $this->cart->getQuote();

        try {
            if (!$quote->isVirtual()) {
                // Set Shipping Address
                $shippingAddress = $this->addressHelper->getMagentoAddressFromPRAPIResult($address, __("shipping"));
                $shipping = $quote->getShippingAddress()
                      ->addData($shippingAddress);

                if ($shipping_id) {
                    // Set Shipping Method
                    $shipping->setShippingMethod($shipping_id)
                             ->setCollectShippingRates(true)
                             ->collectShippingRates();

                    $parts = explode('_', $shipping_id);
                    $carrierCode = array_shift($parts);
                    $methodCode = implode("_", $parts);

                    $shippingAddress = $this->inputProcessor->convertValue($shippingAddress, 'Magento\Quote\Api\Data\AddressInterface');

                    /** @var \Magento\Checkout\Api\Data\ShippingInformationInterface $shippingInformation */
                    $shippingInformation = $this->shippingInformationFactory->create();
                    $shippingInformation
                        // ->setBillingAddress($shippingAddress)
                        ->setShippingAddress($shippingAddress)
                        ->setShippingCarrierCode($carrierCode)
                        ->setShippingMethodCode($methodCode);

                    $this->shippingInformationManagement->saveAddressInformation($quote->getId(), $shippingInformation);

                    // Update totals
                    $quote->setTotalsCollectedFlag(false);
                    $quote->collectTotals();
                }
            }

            $result = $this->expressHelper->getCartItems($quote);
            unset($result["currency"]);
            return $this->serializer->serialize([
                "results" => $result
            ]);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__($e->getMessage()), $e);
        }
    }

    public function set_billing_address($data)
    {
        try {
            $quote = $this->cart->getQuote();

            // Place Order
            $billingAddress = $this->expressHelper->getBillingAddress($data);

            // Set Billing Address
            $quote->getBillingAddress()
                  ->addData($billingAddress);

            $quote->setTotalsCollectedFlag(false);
            $quote->save();
        }
        catch (\Exception $e)
        {
            throw new CouldNotSaveException(__($e->getMessage()), $e);
        }

        return $this->serializer->serialize([
            "results" => null
        ]);
    }

    /**
     * Place Order
     *
     * @param mixed $result
     *
     * @return string
     * @throws CouldNotSaveException
     */
    public function place_order($result, $location)
    {
        $paymentMethod = $result['paymentMethod'];
        $paymentMethodId = $paymentMethod['id'];

        $quote = $this->cart->getQuote();
        $quote->setIsWalletButton(true);

        try {
            // Create an Order ID for the customer's quote
            $quote->reserveOrderId();

            // Set Billing Address
            $billingAddress = $this->expressHelper->getBillingAddress($paymentMethod['billing_details']);
            $quote->getBillingAddress()
                  ->addData($billingAddress);

            if (!$quote->isVirtual())
            {
                // Set Shipping Address
                try
                {
                    $shippingAddress = $this->expressHelper->getShippingAddressFromResult($result);
                }
                catch (InvalidAddressException $e)
                {
                    $data = $quote->getShippingAddress()->getData();
                    $shippingAddress = $this->addressHelper->filterAddressData($data);
                }

                if ($this->addressHelper->isRegionRequired($shippingAddress["country_id"]))
                {
                    if (empty($shippingAddress["region"]) && empty($shippingAddress["region_id"]))
                    {
                        throw new LocalizedException(__("Please specify a shipping address region/state."));
                    }
                }

                if (empty($shippingAddress["telephone"]) && !empty($billingAddress["telephone"]))
                    $shippingAddress["telephone"] = $billingAddress["telephone"];

                $shipping = $quote->getShippingAddress()
                                  ->addData($shippingAddress);

                // Set Shipping Method
                if (!empty($result['shippingOption']['id']))
                    $shipping->setShippingMethod($result['shippingOption']['id'])
                         ->setCollectShippingRates(true);
                else if (empty($shipping->getShippingMethod()))
                    throw new LocalizedException(__("Could not place order: Please specify a shipping method."));
            }

            // Update totals
            $quote->setTotalsCollectedFlag(false);
            $quote->collectTotals();

            // For multi-stripe account configurations, load the correct Stripe API key from the correct store view
            $this->storeManager->setCurrentStore($quote->getStoreId());
            $this->config->initStripe();

            // Set Checkout Method
            if (!$this->customerSession->isLoggedIn())
            {
                // Use Guest Checkout
                $quote->setCheckoutMethod(\Magento\Checkout\Model\Type\Onepage::METHOD_GUEST)
                      ->setCustomerId(null)
                      ->setCustomerEmail($quote->getBillingAddress()->getEmail())
                      ->setCustomerIsGuest(true)
                      ->setCustomerGroupId(\Magento\Customer\Api\Data\GroupInterface::NOT_LOGGED_IN_ID);
            }
            else
            {
                $quote->setCheckoutMethod(\Magento\Checkout\Model\Type\Onepage::METHOD_CUSTOMER);
            }

            $quote->getPayment()->unsPaymentId(); // Causes the Helper/Generic.php::resetPaymentData() method to reset any previous values
            $quote->getPayment()->importData(['method' => 'stripe_payments_express', 'additional_data' => [
                'cc_stripejs_token' => $paymentMethodId,
                'is_prapi' => true,
                'prapi_location' => $location,
                'prapi_title' => $this->paymentsHelper->getPRAPIMethodType()
            ]]);

            // Save Quote
            // $this->paymentsHelper->saveQuote($quote);

            // Place Order
            /** @var \Magento\Sales\Model\Order $order */
            $order = $this->quoteManagement->submit($quote);
            if ($order)
            {
                $this->eventManager->dispatch(
                    'checkout_type_onepage_save_order_after',
                    ['order' => $order, 'quote' => $quote]
                );

                $this->checkoutSession
                    ->setLastQuoteId($quote->getId())
                    ->setLastSuccessQuoteId($quote->getId())
                    ->setLastOrderId($order->getId())
                    ->setLastRealOrderId($order->getIncrementId())
                    ->setLastOrderStatus($order->getStatus());
            }

            $this->eventManager->dispatch(
                'checkout_submit_all_after',
                [
                    'order' => $order,
                    'quote' => $quote
                ]
            );

            return $this->serializer->serialize([
                'redirect' => $this->urlBuilder->getUrl('checkout/onepage/success', ['_secure' => $this->paymentsHelper->isSecure()])
            ]);
        }
        catch (\Exception $e)
        {
            return $this->paymentsHelper->dieWithError($e->getMessage(), $e);
        }
    }

    /**
     * Add to Cart
     *
     * @param string $request
     * @param string|null $shipping_id
     *
     * @return string
     * @throws CouldNotSaveException
     */
    public function addtocart($request, $shipping_id = null)
    {
        $params = [];
        parse_str($request, $params);

        $productId = $params['product'];
        $related = $params['related_product'];

        if (isset($params['qty'])) {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

            $this->localizedToNormalized->setOptions(['locale' => $this->localeHelper->getLocale()]);
            $params['qty'] = $this->localizedToNormalized->filter((string)$params['qty']);
        }

        $quote = $this->cart->getQuote();

        try {
            // Get Product
            $storeId = $this->storeManager->getStore()->getId();
            $product = $this->productRepository->getById($productId, false, $storeId);

            $this->eventManager->dispatch(
                'stripe_payments_express_before_add_to_cart',
                ['product' => $product, 'request' => $request]
            );

            // Check is update required
            $isUpdated = false;
            foreach ($quote->getAllItems() as $item) {
                if ($item->getProductId() == $productId) {
                    $item = $this->cart->updateItem($item->getId(), $params);
                    if ($item->getHasError()) {
                        throw new LocalizedException(__($item->getMessage()));
                    }

                    $isUpdated = true;
                    break;
                }
            }

            // Add Product to Cart
            if (!$isUpdated) {
                $item = $this->cart->addProduct($product, $params);
                if ($item->getHasError()) {
                    throw new LocalizedException(__($item->getMessage()));
                }

                if (!empty($related)) {
                    $this->cart->addProductsByIds(explode(',', $related));
                }
            }

            $this->cart->save();

            if ($shipping_id) {
                // Set Shipping Method
                if (!$quote->isVirtual()) {
                    // Set Shipping Method
                    $quote->getShippingAddress()->setShippingMethod($shipping_id)
                             ->setCollectShippingRates(true)
                             ->collectShippingRates();
                }
            }

            // Update totals
            $quote->setTotalsCollectedFlag(false);
            $quote->collectTotals();
            $quote->save();

            $result = $this->expressHelper->getCartItems($quote);
            return $this->serializer->serialize([
                "results" => $result
            ]);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__($e->getMessage()), $e);
        }
    }

    /**
     * Get Cart Contents
     *
     * @return string
     * @throws CouldNotSaveException
     */
    public function get_cart()
    {
        $quote = $this->cart->getQuote();

        try {
            $result = $this->expressHelper->getCartItems($quote);
            return $this->serializer->serialize($result);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__($e->getMessage()), $e);
        }
    }

    public function get_prapi_params($type)
    {
        switch ($type)
        {
            case 'checkout':
            case 'cart':
            case 'minicart':
                return $this->serializer->serialize($this->getApplePayParams($type));
            default: // Product
                $parts = explode(":", $type);

                if ($parts[0] == "product" && is_numeric($parts[1]))
                {
                    $attribute = null;
                    if (!empty($parts[2]))
                        $attribute = $parts[2];

                    return $this->serializer->serialize($this->getProductApplePayParams($parts[1], $attribute));
                }
                else
                    throw new CouldNotSaveException(__("Invalid type specified for Wallet Button params"));
        }
    }

    /**
     * Get Payment Request Params
     * @return array
     */
    public function getApplePayParams($location = null)
    {
        $requestShipping = !$this->getQuote()->isVirtual();

        if ($location == "checkout" && $requestShipping)
        {
            $shippingAddress = $this->getQuote()->getShippingAddress();
            $address = $this->addressHelper->getStripeAddressFromMagentoAddress($shippingAddress);
            if (!empty($address["address"]["line1"])
                && !empty($address["address"]["city"])
                && !empty($address["address"]["country"])
                && !empty($address["address"]["postal_code"])
            )
            {
                $requestShipping = false;
            }
        }

        return array_merge(
            [
                'country' => $this->getCountry(),
                'requestPayerName' => true,
                'requestPayerEmail' => true,
                'requestPayerPhone' => true,
                'requestShipping' => $requestShipping,
            ],
            $this->expressHelper->getCartItems($this->getQuote())
        );
    }

    /**
     * Get Payment Request Params for Single Product
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getProductApplePayParams($productId, $attribute)
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $product = $this->paymentsHelper->loadProductById($productId);

        if (!$product || !$product->getId())
            return [];

        $quote = $this->getQuote();

        $currency = $quote->getQuoteCurrencyCode();
        if (empty($currency)) {
            $currency = $quote->getStore()->getCurrentCurrency()->getCode();
        }

        // Get Current Items in Cart
        $params = $this->expressHelper->getCartItems($quote);
        $amount = $params['total']['amount'];
        $items = $params['displayItems'];

        $shouldInclTax = $this->expressHelper->shouldCartPriceInclTax($quote->getStore());
        $convertedFinalPrice = $this->priceCurrency->convertAndRound(
            $product->getFinalPrice(),
            null,
            $currency
        );

        $price = $this->expressHelper->getProductDataPrice(
            $product,
            $convertedFinalPrice,
            $shouldInclTax,
            $quote->getCustomerId(),
            $quote->getStore()->getStoreId()
        );

        // Append Current Product
        $productTotal = $this->paymentsHelper->convertMagentoAmountToStripeAmount($price, $currency);
        $amount += $productTotal;

        $items[] = [
            'label' => $product->getName(),
            'amount' => $productTotal,
            'pending' => false
        ];

        return [
            'country' => $this->getCountry(),
            'currency' => strtolower($currency),
            'total' => [
                'label' => $this->getLabel(),
                'amount' => $amount,
                'pending' => true
            ],
            'displayItems' => $items,
            'requestPayerName' => true,
            'requestPayerEmail' => true,
            'requestPayerPhone' => true,
            'requestShipping' => $this->expressHelper->shouldRequestShipping($quote, $product, $attribute),
        ];
    }

    /**
     * Get Quote
     * @return \Magento\Quote\Model\Quote
     */
    public function getQuote()
    {
        $quote = $this->checkoutHelper->getCheckout()->getQuote();
        if (!$quote->getId()) {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $quote = $objectManager->create('Magento\Checkout\Model\Session')->getQuote();
        }

        return $quote;
    }

    /**
     * Get Country Code
     * @return string
     */
    public function getCountry()
    {
        $countryCode = $this->getQuote()->getBillingAddress()->getCountryId();
        if (empty($countryCode)) {
            $countryCode = $this->expressHelper->getDefaultCountry();
        }
        return $countryCode;
    }

    /**
     * Get Label
     * @return string
     */
    public function getLabel()
    {
        return $this->expressHelper->getLabel($this->getQuote());
    }

    public function get_trialing_subscriptions($billingAddress = null, $shippingAddress = null, $shippingMethod = null, $couponCode = null)
    {
        $quote = $this->paymentsHelper->getQuote();

        if (!empty($billingAddress))
            $quote->getBillingAddress()->addData($this->toSnakeCase($billingAddress));

        if (!empty($shippingAddress))
        {
            $quote->getShippingAddress()->addData($this->toSnakeCase($shippingAddress));

            if (!empty($shippingMethod['carrier_code']) && !empty($shippingMethod['method_code']))
            {
                $code = $shippingMethod['carrier_code'] . "_" . $shippingMethod['method_code'];

                $quote->getShippingAddress()
                        ->setCollectShippingRates(true)
                        ->collectShippingRates()
                        ->setShippingMethod($code);
            }
        }

        if (!empty($couponCode))
            $quote->setCouponCode($couponCode);
        else
            $quote->setCouponCode('');

        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();

        if (!empty($billingAddress))
        {
            // The billing address may be empty at the shopping cart case, in which case the save would fail and reset the data
            // we just set to the old values. On the checkout page, the save is necessary.
            $this->quoteRepository->save($quote);
        }

        $subscriptions = $this->subscriptionsHelper->getTrialingSubscriptionsAmounts($quote);
        return $this->serializer->serialize($subscriptions);
    }

    public function get_checkout_payment_methods($billingAddress, $shippingAddress = null, $shippingMethod = null, $couponCode = null)
    {
        try
        {
            $quote = $this->paymentsHelper->getQuote();

            if (!empty($billingAddress))
                $quote->getBillingAddress()->addData($this->toSnakeCase($billingAddress));

            if (!empty($shippingAddress))
                $quote->getShippingAddress()->addData($this->toSnakeCase($shippingAddress));

            if (!empty($couponCode))
                $quote->setCouponCode($couponCode);
            else
                $quote->setCouponCode('');

            $quote->setTotalsCollectedFlag(false);
            $quote->collectTotals();
            $this->quoteRepository->save($quote);

            $currentCheckoutSessionId = $this->checkoutSessionHelper->getCheckoutSessionIdFromQuote($quote);
            $checkoutSessionModel = $this->checkoutSessionFactory->create()->fromQuote($quote);
            $methods = $checkoutSessionModel->getAvailablePaymentMethods($quote);
            $newCheckoutSessionId = $this->checkoutSessionHelper->getCheckoutSessionIdFromQuote($quote);
        }
        catch (\Stripe\Exception\InvalidRequestException $e)
        {
            return $this->serializer->serialize([
                "error" => __($e->getMessage())
            ]);
        }
        catch (\Exception $e)
        {
            $this->paymentsHelper->logError($e->getMessage(), $e->getTraceAsString());

            return $this->serializer->serialize([
                "error" => __("Sorry, the selected payment method is not available. Please use a different payment method.")
            ]);
        }

        if ($checkoutSessionModel->getOrder() && $currentCheckoutSessionId == $newCheckoutSessionId)
        {
            $response = [
                "methods" => $methods,
                "place_order" => false,
                "checkout_session_id" => $newCheckoutSessionId
            ];
        }
        else
        {
            $response = [
                "methods" => $methods,
                "place_order" => true,
                "checkout_session_id" => $newCheckoutSessionId
            ];
        }

        return $this->serializer->serialize($response);
    }

    // Get Stripe Checkout session ID, only if it is still valid/open/non-expired AND an order for it exists
    public function get_checkout_session_id()
    {
        $checkoutSessionModel = $this->checkoutSessionFactory->create();
        $quote = $this->paymentsHelper->getQuote();
        /** @var \Stripe\Checkout\Session $session */
        $session = $checkoutSessionModel->fromQuote($quote)->getStripeObject();

        if (empty($session->id))
            return null;

        $model = $this->checkoutSessionHelper->getCheckoutSessionModel();

        if (!$model || !$model->getOrderIncrementId())
            return null;

        return $session->url;
    }

    /**
     * Restores the quote of the last placed order
     *
     * @api
     *
     * @return mixed
     */
    public function restore_quote()
    {
        try
        {
            $this->restoreQuote();
            return $this->serializer->serialize([]);
        }
        catch (\Exception $e)
        {
            return $this->serializer->serialize([
                "error" => $e->getMessage()
            ]);
        }
    }

    private function restoreQuote()
    {
        $checkout = $this->checkoutHelper->getCheckout();

        /** @var \Magento\Sales\Model\Order $order */
        $order = $checkout->getLastRealOrder();
        if ($order->getId())
        {
            try
            {
                $quote = $this->paymentsHelper->loadQuoteById($order->getQuoteId());
                $quote->setIsActive(1)->setReservedOrderId(null);
                $this->paymentsHelper->saveQuote($quote);
                $this->checkoutSession->replaceQuote($quote)->setLastRealOrderId($order->getIncrementId());
                return true;
            }
            catch (\Magento\Framework\Exception\NoSuchEntityException $e)
            {
                return false;
            }
        }

        return false;
    }

    /**
     * After a payment failure, and before placing the order for a 2nd time, we call the update_cart method to check if anything
     * changed between the quote and the previously placed order. If it has, we cancel the old order and place a new one.
     *
     * @api
     *
     * @return mixed
     */
    public function update_cart($quoteId = null, $data = null)
    {
        try
        {
            $quote = $this->paymentsHelper->getQuote($quoteId);
            if (!$quote || !$quote->getId())
            {
                return $this->serializer->serialize([
                    "placeNewOrder" => true,
                    "reason" => "The quote could not be loaded."
                ]);

                // $this->paymentsHelper->addError(__("Your checkout session has expired. Please try to place the order again."));

                // return $this->serializer->serialize([
                //     "redirect" => $this->paymentsHelper->getUrl("checkout/cart")
                // ]);
            }

            $this->paymentElement->load($quote->getId(), 'quote_id');
            if ($this->paymentElement->getOrderIncrementId())
            {
                $orderIncrementId = $this->paymentElement->getOrderIncrementId();
            }
            else if ($quote->getReservedOrderId())
            {
                $orderIncrementId = $quote->getReservedOrderId();
            }
            else
            {
                return $this->serializer->serialize([
                    "placeNewOrder" => true,
                    "reason" => "The quote does not have an order increment ID."
                ]);
            }

            $order = $this->paymentsHelper->loadOrderByIncrementId($orderIncrementId);
            if (!$order || !$order->getId())
            {
                return $this->serializer->serialize([
                    "placeNewOrder" => true,
                    "reason" => "Order #$orderIncrementId could not be loaded."
                ]);
            }

            if (in_array($order->getState(), ['canceled', 'complete', 'closed']))
            {
                return $this->serializer->serialize([
                    "placeNewOrder" => true,
                    "reason" => "Order #$orderIncrementId is in an invalid state."
                ]);
            }

            if ($order->getIsMultiShipping())
                throw new \Exception("This method cannot be used in multi-shipping mode.");

            if ($this->compare->isDifferent($quote->getData(), [
                "is_virtual" => $order->getIsVirtual(),
                "base_currency_code" => $order->getBaseCurrencyCode(),
                "store_currency_code" => $order->getStoreCurrencyCode(),
                "quote_currency_code" => $order->getOrderCurrencyCode(),
                "global_currency_code" => $order->getGlobalCurrencyCode(),
                "customer_email" => $order->getCustomerEmail(),
                "customer_is_guest" => $order->getCustomerIsGuest(),
                "base_subtotal" => $order->getBaseSubtotal(),
                "subtotal" => $order->getSubtotal(),
                "base_grand_total" => $order->getBaseGrandTotal(),
                "grand_total" => $order->getGrandTotal(),
            ]))
            {
                $msg = __("The order details have changed (%1).", $this->compare->lastReason);
                $this->paymentsHelper->addOrderComment($msg, $order);
                $this->paymentsHelper->saveOrder($order);
                return $this->serializer->serialize([
                    "placeNewOrder" => true,
                    "reason" => $msg
                ]);
            }

            $quoteItems = [];
            $orderItems = [];

            foreach ($quote->getAllItems() as $item)
            {
                $quoteItems[$item->getItemId()] = [
                    "sku" => $item->getSku(),
                    "qty" => $item->getQty(),
                    "row_total" => $item->getRowTotal(),
                    "base_row_total" => $item->getBaseRowTotal()
                ];
            }

            foreach ($order->getAllItems() as $item)
            {
                $orderItems[$item->getQuoteItemId()] = [
                    "sku" => $item->getSku(),
                    "qty" => $item->getQtyOrdered(),
                    "row_total" => $item->getRowTotal(),
                    "base_row_total" => $item->getBaseRowTotal()
                ];
            }

            if ($this->compare->isDifferent($quoteItems, $orderItems))
            {
                $msg = __("The order items have changed (%1).", $this->compare->lastReason);
                $this->paymentsHelper->addOrderComment($msg, $order);
                $this->paymentsHelper->saveOrder($order);
                return $this->serializer->serialize([
                    "placeNewOrder" => true,
                    "reason" => $msg
                ]);
            }

            if (!$quote->getIsVirtual())
            {
                $expectedData = $this->getAddressComparisonData($order->getShippingAddress()->getData());

                if ($this->compare->isDifferent($quote->getShippingAddress()->getData(), $expectedData))
                {
                    $msg = __("The order shipping address has changed (%1).", $this->compare->lastReason);
                    $this->paymentsHelper->addOrderComment($msg, $order);
                    $this->paymentsHelper->saveOrder($order);
                    return $this->serializer->serialize([
                        "placeNewOrder" => true,
                        "reason" => $msg
                    ]);
                }
            }

            if (!$quote->getIsVirtual() && $this->compare->isDifferent($quote->getShippingAddress()->getData(), [
                "shipping_method" => $order->getShippingMethod(),
                "shipping_description" => $order->getShippingDescription(),
                "shipping_amount" => $order->getShippingAmount(),
                "base_shipping_amount" => $order->getBaseShippingAmount()
            ]))
            {
                $msg = __("The order shipping method has changed (%1).", $this->compare->lastReason);
                $this->paymentsHelper->addOrderComment($msg, $order);
                $this->paymentsHelper->saveOrder($order);
                return $this->serializer->serialize([
                    "placeNewOrder" => true,
                    "reason" => $msg
                ]);
            }

            $expectedData = $this->getAddressComparisonData($order->getBillingAddress()->getData());

            if ($this->compare->isDifferent($quote->getBillingAddress()->getData(), $expectedData))
            {
                $msg = __("The order billing address has changed (%1).", $this->compare->lastReason);
                $this->paymentsHelper->addOrderComment($msg, $order);
                $this->paymentsHelper->saveOrder($order);
                return $this->serializer->serialize([
                    "placeNewOrder" => true,
                    "reason" => $msg
                ]);
            }

            // Invalidate the payment intent.
            try
            {
                if (!empty($data['additional_data']))
                {
                    $payment = $order->getPayment();
                    $this->paymentsHelper->assignPaymentData($payment, $data['additional_data']);
                    $payment->save();
                }

                if (!empty($data['additional_data']['payment_method']))
                {
                    // A saved payment method has been passed
                    $this->paymentElement->updateFromOrder($order, $data['additional_data']['payment_method']);
                }
                else
                {
                    $this->paymentElement->updateFromOrder($order);
                }

                if ($this->paymentElement->requiresConfirmation())
                {
                    $this->paymentElement->confirm($order);
                }
            }
            catch (\Exception $e)
            {
                return $this->serializer->serialize([
                    "error" => $e->getMessage()
                ]);
            }

            return $this->serializer->serialize([
                "placeNewOrder" => false
            ]);
        }
        catch (\Exception $e)
        {
            $this->paymentsHelper->logError($e->getMessage(), $e->getTraceAsString());

            return $this->serializer->serialize([
                "placeNewOrder" => true,
                "reason" => "An error has occurred: " . $e->getMessage()
            ]);
        }
    }

    /**
     * If the last payment requires further action, this returns the client secret of the object that requires action
     *
     * @api
     *
     * @return mixed|null
     */
    public function get_requires_action()
    {
        $orderId = $this->checkoutSession->getLastRealOrderId();
        if (empty($orderId))
        {
            return null;
        }

        $order = $this->paymentsHelper->loadOrderByIncrementId($orderId);
        if (!$order || !$order->getId())
        {
            return null;
        }

        $paymentIntent = $this->getPaymentIntentFromOrder($order); // Works for bank transfers and most APMs after v3.4.x

        if (!$paymentIntent)
        {
            // Legacy payment element flow from versions 3.0 - 3.3
            $this->paymentElement->fromQuoteId($order->getQuoteId());
            $paymentIntent = $this->paymentElement->getPaymentIntent();
        }

        if ($paymentIntent && $paymentIntent->status == "requires_action")
        {
            if (!$this->paymentIntentHelper->requiresOfflineAction($paymentIntent))
            {
                // Non-card based confirms may redirect the customer externally. We restore the quote just before it in case the
                // customer clicks the back button on the browser before authenticating the payment.
                $this->restoreQuote();
            }

            return $paymentIntent->client_secret;
        }

        return null;
    }

    protected function getPaymentIntentFromOrder($order)
    {
        if (!$order || !$order->getPayment())
        {
            return null;
        }

        $payment = $order->getPayment();

        $transactionId = $this->paymentsHelper->cleanToken($payment->getLastTransId());

        if (strpos($transactionId, "pi_") !== 0)
        {
            return null;
        }

        $paymentIntent = $this->stripePaymentIntentFactory->create()->fromPaymentIntentId($transactionId);

        return $paymentIntent->getStripeObject();
    }

    /**
     * Returns the params needed to initialize the Payment Element component at the specified site section
     *
     * @api
     * @param string $section
     *
     * @return string|null
     */
    public function get_init_params($section)
    {
        $params = [];

        if ($section == "my_payment_methods")
        {
            $params = $this->serializer->unserialize($this->initParams->getMyPaymentMethodsParams());
        }

        return $this->serializer->serialize($params);
    }

    /**
     * Places a multishipping order
     *
     * @api
     * @param int|null $quoteId
     *
     * @return mixed|null $result
     */
    public function place_multishipping_order($quoteId = null)
    {
        if (empty($quoteId))
        {
            $quote = $this->paymentsHelper->getQuote();
            $quoteId = $quote->getId();
        }

        try
        {
            $redirectUrl = $this->multishippingHelper->placeOrder($quoteId);
            return $this->serializer->serialize(["redirect" => $redirectUrl]);
        }
        catch (SCANeededException $e)
        {
            return $this->serializer->serialize(["authenticate" => $e->getMessage()]);
        }
        catch (\Exception $e)
        {
            $this->paymentsHelper->logError($e->getMessage(), $e->getTraceAsString());
            return $this->serializer->serialize(["error" => $e->getMessage()]);
        }
    }

    /**
     * Finalizes a multishipping order after a card is declined or customer authentication fails and redirects the customer to the results or success page
     *
     * @api
     * @param string|null $error
     *
     * @return mixed|null $result
     */
    public function finalize_multishipping_order($quoteId = null, $error = null)
    {
        if (empty($quoteId))
        {
            $quote = $this->paymentsHelper->getQuote();
            $quoteId = $quote->getId();
        }

        try
        {
            $redirectUrl = $this->multishippingHelper->finalizeOrder($quoteId, $error);
            return $this->serializer->serialize(["redirect" => $redirectUrl]);
        }
        catch (\Exception $e)
        {
            $this->paymentsHelper->logError($e->getMessage(), $e->getTraceAsString());
            return $this->serializer->serialize(["error" => $e->getMessage()]);
        }
    }

    private function getAddressComparisonData($addressData)
    {
        $comparisonFields = ["region_id", "region", "postcode", "lastname", "street", "city", "email", "telephone", "country_id", "firstname", "address_type", "company", "vat_id"];

        $params = [];

        foreach ($comparisonFields as $field)
        {
            if (!empty($addressData[$field]))
                $params[$field] = $addressData[$field];
            else
                $params[$field] = "unset";
        }

        return $params;
    }

    private function toSnakeCase($array)
    {
        $result = [];

        foreach ($array as $key => $value) {
            $key = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
            $result[$key] = $value;
        }

        return $result;
    }

    private function cancelOrder($order, $comment)
    {
        $this->paymentsHelper->removeTransactions($order);
        $order->addStatusToHistory($status = false, $comment, $isCustomerNotified = false);
        $this->paymentsHelper->cancelOrCloseOrder($order);

        if ($this->paymentIntent->getOrderIncrementId())
        {
            $this->paymentIntent->setOrderIncrementId(null);
            $this->paymentIntent->setOrderId(null);
            $this->paymentIntent->save();
        }
    }

    public function get_upcoming_invoice()
    {
        try
        {
            $data = $this->subscriptionsHelper->getUpcomingInvoice(time());
            return $this->serializer->serialize(["upcomingInvoice" => $data]);
        }
        catch (\Stripe\Exception\InvalidRequestException $e)
        {
            if (strpos($e->getMessage(), "The price specified supports currencies of") !== false)
                return $this->serializer->serialize(["error" => __("Cannot update subscription because the original was purchased in a different currency.")]);

            return $this->serializer->serialize(["error" => $e->getMessage()]);
        }
        catch (\Exception $e)
        {
            $this->paymentsHelper->logError($e->getMessage(), $e->getTraceAsString());
            return $this->serializer->serialize(["error" => $e->getMessage()]);
        }
    }

    /**
     * Add a new saved payment method by ID
     *
     * @api
     * @param string $paymentMethodId
     *
     * @return mixed $paymentMethod
     */
    public function add_payment_method($paymentMethodId)
    {
        if (!$this->stripeCustomer->isLoggedIn())
            throw new \Exception((string)__("The customer is not logged in."));

        if (empty($paymentMethodId))
            throw new \Exception((string)__("Please specify a payment method ID."));

        $this->stripeCustomer->createStripeCustomerIfNotExists();

        try
        {
            $paymentMethod = $this->stripeCustomer->attachPaymentMethod($paymentMethodId);
            $methods = $this->paymentMethodHelper->formatPaymentMethods([
                $paymentMethod->type => [
                    $paymentMethod
                ]
            ], true);

            return $this->serializer->serialize(array_pop($methods));
        }
        catch (\Exception $e)
        {
            throw new \Exception((string)__("Could not add payment method: %1.", $e->getMessage()));
        }
    }

    /**
     * Delete a saved payment method by ID
     *
     * @api
     * @param string $paymentMethodId
     * @param string $fingerprint
     *
     * @return mixed $result
     */
    public function delete_payment_method($paymentMethodId, $fingerprint = null)
    {
        if (!$this->stripeCustomer->isLoggedIn())
            throw new \Exception((string)__("The customer is not logged in."));

        if (empty($paymentMethodId))
            throw new \Exception((string)__("Please specify a payment method ID."));

        if (!$this->stripeCustomer->getStripeId())
            throw new \Exception((string)__("The payment method does not exist."));

        try
        {
            $paymentMethod = $this->stripeCustomer->deletePaymentMethod($paymentMethodId, $fingerprint);

            if (!empty($paymentMethod->card->last4))
                return $this->serializer->serialize(__("Card •••• %1 has been deleted.", $paymentMethod->card->last4));

            return $this->serializer->serialize(__("The payment method has been deleted."));
        }
        catch (\Exception $e)
        {
            throw new \Exception((string)__("Could not delete payment method: %1.", $e->getMessage()));
        }
    }

    /**
     * List a customer's saved payment methods
     *
     * @api
     * @return mixed $result
     */
    public function list_payment_methods()
    {
        if (!$this->stripeCustomer->isLoggedIn())
            throw new \Exception((string)__("The customer is not logged in."));

        return $this->serializer->serialize($this->stripeCustomer->getSavedPaymentMethods(null, true));
    }

    /**
     * Cancels the last order placed by the customer, if it's quote ID matches the currently active quote
     *
     * @api
     * @param string $errorMessage
     *
     * @return mixed $result
     */
    public function cancel_last_order($errorMessage)
    {
        try
        {
            $quote = $this->paymentsHelper->getQuote();
            $orderId = $this->checkoutSession->getLastRealOrderId();

            if (empty($orderId))
                throw new \Exception((string)__("The customer does not have an order."));

            $order = $this->paymentsHelper->loadOrderByIncrementId($orderId);

            if (!$order || !$order->getId())
                throw new \Exception((string)__("The order could not be loaded."));

            if ($order->getQuoteId() != $quote->getId())
                throw new \Exception((string)__("The order does not match the current quote."));

            $this->cancelOrder($order, $errorMessage);
        }
        catch (\Exception $e)
        {

        }

        return $this->serializer->serialize([]);
    }

    /**
     * Get Module Configuration for Stripe initialization
     * @return mixed
     */
    public function getModuleConfiguration()
    {
        return $this->initParams->getWalletParams();
    }
}
