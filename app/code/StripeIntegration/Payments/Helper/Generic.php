<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Framework\Validator\Exception;
use StripeIntegration\Payments\Model\Config;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Store\Model\ScopeInterface;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\LocalizedException;
use StripeIntegration\Payments\Helper\Data as DataHelper;

class Generic
{
    public $magentoCustomerId = null;
    public $urlBuilder = null;
    protected $cards = [];
    public $orderComments = [];
    public $currentCustomer = null;
    public $productRepository = null;
    public $bundleProductOptions = [];
    public $quoteHasSubscriptions = [];
    private $scopeConfig;
    private $backendSessionQuote;
    private $request;
    private $appState;
    private $storeManager;
    private $order;
    private $invoice;
    private $invoiceService;
    private $creditmemo;
    private $customerSession;
    private $checkoutSession;
    private $resource;
    private $coreRegistry;
    private $adminOrderAddressForm;
    private $customerRegistry;
    private $messageManager;
    private $productFactory;
    private $quoteFactory;
    private $orderFactory;
    private $cache;
    private $userContext;
    private $orderSender;
    private $priceCurrency;
    private $customerRepositoryInterface;
    private $orderCommentSender;
    private $creditmemoFactory;
    private $creditmemoService;
    private $paymentExtensionFactory;
    private $customerCollection;
    private $apiFactory;
    private $addressHelper;
    private $quoteRepository;
    private $orderCollectionFactory;
    private $couponFactory;
    private $stripeCouponFactory;
    private $checkoutHelper;
    private $ruleRepository;
    private $invoiceSender;
    private $quoteHelper;
    private $taxConfig;
    private $transactionSearchResultFactory;
    private $orderRepository;
    private $invoiceRepository;
    private $transactionRepository;
    private $creditmemoRepository;
    private $orderPaymentRepository;
    private $sessionManager;
    private $multishippingQuoteFactory;
    private $productMetadata;
    private $products;
    private $quotes;
    private $orders;
    private $_order;
    private $_hasTrialSubscriptions;
    private $hasOnlyTrialSubscriptions;
    private $restController;
    private $subscriptionHelper = null;

    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Backend\Model\Session\Quote $backendSessionQuote,
        \Magento\Framework\App\Request\Http $request,
        \Magento\Framework\App\State $appState,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Sales\Model\Order $order,
        \Magento\Sales\Model\Order\Invoice $invoice,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Sales\Model\Order\Creditmemo $creditmemo,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Framework\App\ResourceConnection $resource,
        \Magento\Framework\Registry $coreRegistry,
        \Magento\Sales\Block\Adminhtml\Order\Create\Form\Address $adminOrderAddressForm,
        \Magento\Customer\Model\CustomerRegistry $customerRegistry,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \Magento\Sales\Api\Data\OrderInterfaceFactory $orderFactory,
        \Magento\Framework\UrlInterface $urlBuilder,
        \Magento\Framework\App\CacheInterface $cache,
        \Magento\Authorization\Model\UserContextInterface $userContext,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Framework\Pricing\PriceCurrencyInterface $priceCurrency,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepositoryInterface,
        \Magento\Sales\Model\Order\Email\Sender\OrderCommentSender $orderCommentSender,
        \Magento\Sales\Model\Order\CreditmemoFactory $creditmemoFactory,
        \Magento\Sales\Model\Service\CreditmemoService $creditmemoService,
        \Magento\Sales\Api\Data\OrderPaymentExtensionInterfaceFactory $paymentExtensionFactory,
        \StripeIntegration\Payments\Model\ResourceModel\StripeCustomer\Collection $customerCollection,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        \StripeIntegration\Payments\Helper\ApiFactory $apiFactory,
        \StripeIntegration\Payments\Helper\Address $addressHelper,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory,
        \Magento\SalesRule\Model\CouponFactory $couponFactory,
        \StripeIntegration\Payments\Model\CouponFactory $stripeCouponFactory,
        \Magento\Checkout\Helper\Data $checkoutHelper,
        \Magento\SalesRule\Api\RuleRepositoryInterface $ruleRepository,
        \Magento\Sales\Api\Data\TransactionSearchResultInterfaceFactory $transactionSearchResultFactory,
        \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender,
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \Magento\Tax\Model\Config $taxConfig,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Sales\Api\InvoiceRepositoryInterface $invoiceRepository,
        \Magento\Sales\Api\TransactionRepositoryInterface $transactionRepository,
        \Magento\Sales\Api\CreditmemoRepositoryInterface $creditmemoRepository,
        \Magento\Sales\Api\OrderPaymentRepositoryInterface $orderPaymentRepository,
        \Magento\Framework\Session\SessionManagerInterface $sessionManager,
        \StripeIntegration\Payments\Model\Multishipping\QuoteFactory $multishippingQuoteFactory,
        \Magento\Framework\App\ProductMetadataInterface $productMetadata,
        \StripeIntegration\Payments\Plugin\Webapi\Controller\Rest $restController
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->backendSessionQuote = $backendSessionQuote;
        $this->request = $request;
        $this->appState = $appState;
        $this->storeManager = $storeManager;
        $this->order = $order;
        $this->invoice = $invoice;
        $this->invoiceService = $invoiceService;
        $this->creditmemo = $creditmemo;
        $this->customerSession = $customerSession;
        $this->checkoutSession = $checkoutSession;
        $this->resource = $resource;
        $this->coreRegistry = $coreRegistry;
        $this->adminOrderAddressForm = $adminOrderAddressForm;
        $this->customerRegistry = $customerRegistry;
        $this->messageManager = $messageManager;
        $this->productFactory = $productFactory;
        $this->quoteFactory = $quoteFactory;
        $this->orderFactory = $orderFactory;
        $this->urlBuilder = $urlBuilder;
        $this->cache = $cache;
        $this->userContext = $userContext;
        $this->orderSender = $orderSender;
        $this->priceCurrency = $priceCurrency;
        $this->customerRepositoryInterface = $customerRepositoryInterface;
        $this->orderCommentSender = $orderCommentSender;
        $this->creditmemoFactory = $creditmemoFactory;
        $this->creditmemoService = $creditmemoService;
        $this->paymentExtensionFactory = $paymentExtensionFactory;
        $this->customerCollection = $customerCollection;
        $this->productRepository = $productRepository;
        $this->apiFactory = $apiFactory;
        $this->addressHelper = $addressHelper;
        $this->quoteRepository = $quoteRepository;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->couponFactory = $couponFactory;
        $this->stripeCouponFactory = $stripeCouponFactory;
        $this->checkoutHelper = $checkoutHelper;
        $this->ruleRepository = $ruleRepository;
        $this->invoiceSender = $invoiceSender;
        $this->quoteHelper = $quoteHelper;
        $this->taxConfig = $taxConfig;
        $this->transactionSearchResultFactory = $transactionSearchResultFactory;
        $this->orderRepository = $orderRepository;
        $this->invoiceRepository = $invoiceRepository;
        $this->transactionRepository = $transactionRepository;
        $this->creditmemoRepository = $creditmemoRepository;
        $this->orderPaymentRepository = $orderPaymentRepository;
        $this->sessionManager = $sessionManager;
        $this->multishippingQuoteFactory = $multishippingQuoteFactory;
        $this->productMetadata = $productMetadata;
        $this->restController = $restController;
    }

    protected function getBackendSessionQuote()
    {
        return $this->backendSessionQuote->getQuote();
    }

    public function isSecure()
    {
        return $this->request->isSecure();
    }

    public function getSessionQuote()
    {
        return $this->checkoutSession->getQuote();
    }

    public function getCheckoutSession()
    {
        return $this->checkoutSession;
    }

    public function getQuote($quoteId = null)
    {
        // Admin area new order page
        if ($this->isAdmin())
            return $this->getBackendSessionQuote();

        // Front end checkout
        $quote = $this->getSessionQuote();

        // API Request
        if (empty($quote) || !is_numeric($quote->getGrandTotal()))
        {
            try
            {
                if ($quoteId)
                    $quote = $this->quoteRepository->get($quoteId);
                else if ($this->quoteHelper->quoteId) {
                    $quote = $this->quoteRepository->get($this->quoteHelper->quoteId);
                }
            }
            catch (\Exception $e)
            {

            }
        }

        return $quote;
    }

    public function getStoreId()
    {
        if ($this->isAdmin())
        {
            if ($this->request->getParam('order_id', null))
            {
                // Viewing an order
                $order = $this->order->load($this->request->getParam('order_id', null));
                return $order->getStoreId();
            }
            if ($this->request->getParam('invoice_id', null))
            {
                // Viewing an invoice
                $invoice = $this->invoice->load($this->request->getParam('invoice_id', null));
                return $invoice->getStoreId();
            }
            else if ($this->request->getParam('creditmemo_id', null))
            {
                // Viewing a credit memo
                $creditmemo = $this->creditmemo->load($this->request->getParam('creditmemo_id', null));
                return $creditmemo->getStoreId();
            }
            else
            {
                // Creating a new order
                $quote = $this->getBackendSessionQuote();
                return $quote->getStoreId();
            }
        }
        else
        {
            return $this->storeManager->getStore()->getId();
        }
    }

    public function getCurrentStore()
    {
        return $this->storeManager->getStore();
    }

    public function loadProductBySku($sku)
    {
        try
        {
            return $this->productRepository->get($sku);
        }
        catch (\Exception $e)
        {
            return null;
        }
    }

    public function loadProductById($productId)
    {
        if (!isset($this->products))
            $this->products = [];

        if (!is_numeric($productId))
            return null;

        if (!empty($this->products[$productId]))
            return $this->products[$productId];

        $this->products[$productId] = $this->productFactory->create()->load($productId);

        return $this->products[$productId];
    }

    public function loadQuoteById($quoteId)
    {
        if (!isset($this->quotes))
            $this->quotes = [];

        if (!is_numeric($quoteId))
            return null;

        if (!empty($this->quotes[$quoteId]))
            return $this->quotes[$quoteId];

        $this->quotes[$quoteId] = $this->quoteFactory->create()->load($quoteId);

        return $this->quotes[$quoteId];
    }

    public function loadOrderByIncrementId($incrementId, $useCache = true)
    {
        if (!isset($this->orders))
            $this->orders = [];

        if (empty($incrementId))
            return null;

        if (!empty($this->orders[$incrementId]) && $useCache)
            return $this->orders[$incrementId];

        try
        {
            $orderModel = $this->orderFactory->create();
            $order = $orderModel->loadByIncrementId($incrementId);
            if ($order && $order->getId())
                return $this->orders[$incrementId] = $order;
        }
        catch (\Exception $e)
        {
            return null;
        }
    }

    public function loadOrderById($orderId)
    {
        return $this->orderFactory->create()->load($orderId);
    }

    public function loadCustomerById($customerId)
    {
        return $this->customerRepositoryInterface->getById($customerId);
    }

    public function isAdmin()
    {
        $areaCode = $this->appState->getAreaCode();

        return $areaCode == \Magento\Backend\App\Area\FrontNameResolver::AREA_CODE;
    }

    public function isGraphQLRequest()
    {
        $areaCode = $this->appState->getAreaCode();

        return ($areaCode == "graphql");
    }

    public function isAPIRequest()
    {
        $areaCode = $this->appState->getAreaCode();

        switch ($areaCode)
        {
            case 'webapi_rest': // \Magento\Framework\App\Area::AREA_WEBAPI_REST:
            case 'webapi_soap': // \Magento\Framework\App\Area::AREA_WEBAPI_SOAP:
            case 'graphql': // \Magento\Framework\App\Area::AREA_GRAPHQL: - Magento 2.1 doesn't have the constant
                return true;
            default:
                return false;
        }
    }

    public function isCustomerLoggedIn()
    {
        return $this->customerSession->isLoggedIn();
    }

    public function getCustomerId()
    {
        // If we are in the back office
        if ($this->isAdmin())
        {
            // About to refund/invoice an order
            if ($order = $this->coreRegistry->registry('current_order'))
                return $order->getCustomerId();

            // About to capture an invoice
            if ($invoice = $this->coreRegistry->registry('current_invoice'))
                return $invoice->getCustomerId();

            // Creating a new order from admin
            if ($this->adminOrderAddressForm && $this->adminOrderAddressForm->getCustomerId())
                return $this->adminOrderAddressForm->getCustomerId();
        }
        // If we are on the REST API
        else if ($this->userContext->getUserType() == UserContextInterface::USER_TYPE_CUSTOMER)
        {
            return $this->userContext->getUserId();
        }
        // If we are on the checkout page
        else if ($this->customerSession->isLoggedIn())
        {
            return $this->customerSession->getCustomerId();
        }
        // A webhook has instantiated this object
        else if (!empty($this->magentoCustomerId))
        {
            return $this->magentoCustomerId;
        }

        return null;
    }

    public function getMagentoCustomer()
    {
        if ($this->customerSession->getCustomer()->getEntityId())
            return $this->customerSession->getCustomer();

        $customerId = $this->getCustomerId();
        if (!$customerId) return;

        $customer = $this->customerRegistry->retrieve($customerId);

        if ($customer->getEntityId())
            return $customer;

        return null;
    }

    public function isGuest()
    {
        return !$this->customerSession->isLoggedIn();
    }

    // Should return the email address of guest customers
    public function getCustomerEmail()
    {
        $customer = $this->getMagentoCustomer();

        if (!$customer)
            $customer = $this->getGuestCustomer();

        if (!$customer)
            return null;

        if (!$customer->getEmail())
            return null;

        return trim(strtolower($customer->getEmail()));
    }

    public function getGuestCustomer($order = null)
    {
        if ($order)
        {
            return $this->getAddressFrom($order, 'billing');
        }
        else if (isset($this->_order))
        {
            return $this->getAddressFrom($this->_order, 'billing');
        }
        else
            return null;
    }

    public function getMultiCurrencyAmount($payment, $baseAmount)
    {
        $order = $payment->getOrder();
        $grandTotal = $order->getGrandTotal();
        $baseGrandTotal = $order->getBaseGrandTotal();

        $rate = $order->getBaseToOrderRate();
        if ($rate == 0) $rate = 1;

        // Full capture, ignore currency rate in case it changed
        if ($baseAmount == $baseGrandTotal)
            return $grandTotal;
        // Partial capture, consider currency rate but don't capture more than the original amount
        else if (is_numeric($rate))
            return min($baseAmount * $rate, $grandTotal);
        // Not a multicurrency capture
        else
            return $baseAmount;
    }

    public function getAddressFrom($order, $addressType = 'shipping')
    {
        if (!$order) return null;

        $addresses = $order->getAddresses();
        if (!empty($addresses))
        {
            foreach ($addresses as $address)
            {
                if ($address["address_type"] == $addressType)
                    return $address;
            }
        }
        else if ($addressType == "shipping" && $order->getShippingAddress() && $order->getShippingAddress()->getStreet(1))
        {
            return $order->getShippingAddress();
        }
        else if ($addressType == "billing" && $order->getBillingAddress() && $order->getBillingAddress()->getStreet(1))
        {
            return $order->getBillingAddress();
        }

        return null;
    }

    // Do not use Config::isSubscriptionsEnabled(), a circular dependency injection will appear
    public function isSubscriptionsEnabled()
    {
        $storeId = $this->getStoreId();

        $data = $this->scopeConfig->getValue("payment/stripe_payments_subscriptions/active", ScopeInterface::SCOPE_STORE, $storeId);

        return (bool)$data;
    }

    /**
     * Description
     * @param object $item
     * @return \Magento\Catalog\Model\Product|null
     */
    public function getSubscriptionProductFromOrderItem($item)
    {
        if (!in_array($item->getProductType(), ["simple", "virtual"]))
            return null;

        $product = $this->loadProductById($item->getProductId());

        if ($product && $this->getSubscriptionsHelper()->isSubscriptionOptionEnabled($product->getId()))
        {
            return $product;
        }

        return null;
    }

    public function getSubscriptionProductFromQuoteItem($quoteItem)
    {
        if (!in_array($quoteItem->getProductType(), ["simple", "virtual"]))
            return null;

        $productId = $quoteItem->getProductId();

        if (empty($productId))
            return null;

        $product = $this->loadProductById($productId);

        if (!$product || !$this->getSubscriptionsHelper()->isSubscriptionOptionEnabled($product->getId()))
            return null;

        return $product;
    }

    /**
     * Description
     * @param array<\Magento\Sales\Model\Order\Item> $items
     * @return bool
     */
    public function hasSubscriptionsIn($items, $returnSubscriptions = false)
    {
        if (!$this->isSubscriptionsEnabled())
            return false;

        if (empty($items))
            return false;

        foreach ($items as $item)
        {
            $product = $this->getSubscriptionProductFromOrderItem($item);
            if ($product)
                return true;
        }

        return false;
    }

    public function hasTrialSubscriptions($quote = null)
    {
        if (isset($this->_hasTrialSubscriptions) && $this->_hasTrialSubscriptions)
            return true;

        if (!$quote)
            $quote = $this->getQuote();

        $items = $quote->getAllItems();

        return $this->_hasTrialSubscriptions = $this->hasTrialSubscriptionsIn($items);
    }

    /**
     * Description
     * @param array<\Magento\Sales\Model\Order\Item> $items
     * @return bool
     */
    public function hasTrialSubscriptionsIn($items)
    {
        if (!$this->isSubscriptionsEnabled())
            return false;

        foreach ($items as $item)
        {
            $product = $this->getSubscriptionProductFromOrderItem($item);
            if (!$product)
                continue;

            $subscriptionOptionDetails = $this->getSubscriptionsHelper()->getSubscriptionOptionDetails($product->getId());

            if (!$subscriptionOptionDetails)
                continue;

            $trial = $subscriptionOptionDetails->getSubTrial();
            if (is_numeric($trial) && $trial > 0)
                return true;
            else
                continue;
        }

        return false;
    }

    public function hasOnlyTrialSubscriptionsIn($items)
    {
        if (!$this->isSubscriptionsEnabled())
            return false;

        $foundAtLeastOneTrialSubscriptionProduct = false;

        foreach ($items as $item)
        {
            if (!in_array($item->getProductType(), ["simple", "virtual", "downloadable", "giftcard"]))
                continue;

            $product = $this->loadProductById($item->getProductId());

            if ($product)
            {
                $subscriptionOptionDetails = $this->getSubscriptionsHelper()->getSubscriptionOptionDetails($product->getId());

                if (!$subscriptionOptionDetails)
                    continue;

                $trial = $subscriptionOptionDetails->getSubTrial();
                if (is_numeric($trial) && $trial > 0)
                {
                    $foundAtLeastOneTrialSubscriptionProduct = true;
                }
                else
                {
                    return false;
                }
            }
        }

        return $foundAtLeastOneTrialSubscriptionProduct;
    }

    public function hasSubscriptions($quote = null)
    {
        if (empty($quote))
            $quote = $this->getQuote();

        if (empty($quote))
            return false;

        $quoteId = $quote->getId();
        if (isset($this->quoteHasSubscriptions[$quoteId]))
            return $this->quoteHasSubscriptions[$quoteId];

        $items = $quote->getAllItems();

        return $this->quoteHasSubscriptions[$quoteId] = $this->hasSubscriptionsIn($items);
    }

    public function hasOnlyTrialSubscriptions($quote = null)
    {
        if (!$quote)
            $quote = $this->getQuote();

        if ($quote && $quote->getId() && isset($this->hasOnlyTrialSubscriptions[$quote->getId()]))
            return $this->hasOnlyTrialSubscriptions[$quote->getId()];

        $items = $quote->getAllItems();

        return $this->hasOnlyTrialSubscriptions[$quote->getId()] = $this->hasOnlyTrialSubscriptionsIn($items);
    }

    public function isZeroDecimal(string $currency)
    {
        return in_array(strtolower($currency), array(
            'bif', 'djf', 'jpy', 'krw', 'pyg', 'vnd', 'xaf',
            'xpf', 'clp', 'gnf', 'kmf', 'mga', 'rwf', 'vuv', 'xof'));
    }

    public function isAuthorizationExpired($charge)
    {
        if (!$charge->refunded)
            return false;

        if (empty($charge->refunds->data[0]->reason))
            return false;

        if ($charge->refunds->data[0]->reason == "expired_uncaptured_charge")
            return true;

        return false;
    }

    public function addWarning($msg)
    {
        if (is_string($msg))
            $msg = __($msg);

        $this->messageManager->addWarningMessage($msg);
    }

    public function addError($msg)
    {
        if (is_string($msg))
            $msg = __($msg);

        $this->messageManager->addErrorMessage( $msg );
    }

    public function addSuccess($msg)
    {
        if (is_string($msg))
            $msg = __($msg);

        $this->messageManager->addSuccessMessage( $msg );
    }

    public function logError($msg, $trace = null)
    {
        if (!$this->isAuthenticationRequiredMessage($msg))
        {
            $entry = Config::module() . ": " . $msg;

            if ($trace)
                $entry .= "\n$trace";

            \StripeIntegration\Payments\Helper\Logger::log($entry);
        }
    }

    public function logInfo($msg)
    {
        $entry = Config::module() . ": " . $msg;
        \StripeIntegration\Payments\Helper\Logger::logInfo($entry);
    }

    public function isStripeAPIKeyError($msg)
    {
        $pos1 = stripos($msg, "Invalid API key provided");
        $pos2 = stripos($msg, "No API key provided");
        if ($pos1 !== false || $pos2 !== false)
            return true;

        return false;
    }

    public function cleanError($msg)
    {
        if ($this->isStripeAPIKeyError($msg))
            return "Invalid Stripe API key provided.";

        return $msg;
    }

    public function isMultiShipping($quote = null)
    {
        if (empty($quote))
            $quote = $this->getQuote();

        if (empty($quote))
            return false;

        return $quote->getIsMultiShipping();
    }

    public function shouldLogExceptionTrace($e)
    {
        if (empty($e))
            return false;

        $msg = $e->getMessage();
        if ($this->isAuthenticationRequiredMessage($msg))
            return false;

        if (get_class($e) == \Stripe\Exception\CardException::class) // i.e. card declined, insufficient funds etc
            return false;

        if (get_class($e) == \Magento\Framework\Exception\CouldNotSaveException::class)
        {
            switch ($msg)
            {
                case "Your card was declined.":
                    return false;
                default:
                    break;
            }
        }

        return true;
    }

    public function dieWithError($msg, $e = null)
    {
        $this->logError($msg);

        if ($this->shouldLogExceptionTrace($e))
        {
            if ($e->getMessage() != $msg)
                $this->logError($e->getMessage());

            $this->logError($e->getTraceAsString());
        }

        if ($this->isAdmin())
        {
            throw new CouldNotSaveException(__($msg));
        }
        else if ($this->isAPIRequest())
        {
            if ($this->isGraphQLRequest())
            {
                throw new \Magento\Framework\GraphQl\Exception\GraphQlInputException(__($msg));
            }
            else
            {
                $this->restController->setDisplay(true);
                throw new CouldNotSaveException(__($this->cleanError($msg)), $e);
            }
        }
        else if ($this->isMultiShipping())
        {
            throw new \Magento\Framework\Exception\LocalizedException(__($msg), $e);
        }
        else
        {
            $error = $this->cleanError($msg);
            throw new \Exception($error);
        }
    }

    public function isValidToken($token)
    {
        if (!is_string($token))
            return false;

        if (!strlen($token))
            return false;

        if (strpos($token, "_") === FALSE)
            return false;

        return true;
    }

    public function captureOrder($order)
    {
        foreach($order->getInvoiceCollection() as $invoice)
        {
            $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
            $invoice->capture();
            $this->invoiceRepository->save($invoice);
        }
    }

    public function getInvoiceAmounts($invoice, $details)
    {
        $currency = strtolower($details['currency']);
        $cents = 100;
        if ($this->isZeroDecimal($currency))
            $cents = 1;
        $amount = ($details['amount'] / $cents);
        $baseAmount = round(floatval($amount / $invoice->getBaseToOrderRate()), 2);

        if (!empty($details["shipping"]))
        {
            $shipping = ($details['shipping'] / $cents);
            $baseShipping = round(floatval($shipping / $invoice->getBaseToOrderRate()), 2);
        }
        else
        {
            $shipping = 0;
            $baseShipping = 0;
        }

        if (!empty($details["tax"]))
        {
            $tax = ($details['tax'] / $cents);
            $baseTax = round(floatval($tax / $invoice->getBaseToOrderRate()), 2);
        }
        else
        {
            $tax = 0;
            $baseTax = 0;
        }

        return [
            "amount" => $amount,
            "base_amount" => $baseAmount,
            "shipping" => $shipping,
            "base_shipping" => $baseShipping,
            "tax" => $tax,
            "base_tax" => $baseTax
        ];
    }

    // Used for partial invoicing triggered from a partial Stripe dashboard capture
    public function adjustInvoiceAmounts(&$invoice, $details)
    {
        if (!is_array($details))
            return;

        $amounts = $this->getInvoiceAmounts($invoice, $details);
        $amount = $amounts['amount'];
        $baseAmount = $amounts['base_amount'];

        if ($invoice->getGrandTotal() != $amount)
        {
            if (!empty($amounts['shipping']))
                $invoice->setShippingAmount($amounts['shipping']);

            if (!empty($amounts['base_shipping']))
                $invoice->setBaseShippingAmount($amounts['base_shipping']);

            if (!empty($amounts['tax']))
                $invoice->setTaxAmount($amounts['tax']);

            if (!empty($amounts['base_tax']))
                $invoice->setBaseTaxAmount($amounts['base_tax']);

            $invoice->setGrandTotal($amount);
            $invoice->setBaseGrandTotal($baseAmount);

            $subtotal = 0;
            $baseSubtotal = 0;
            $items = $invoice->getAllItems();
            foreach ($items as $item)
            {
                $subtotal += $item->getRowTotal();
                $baseSubtotal += $item->getBaseRowTotal();
            }

            $invoice->setSubtotal($subtotal);
            $invoice->setBaseSubtotal($baseSubtotal);
        }
    }

    public function invoiceSubscriptionOrder($order, $transactionId = null, $captureCase = \Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE, $amount = null, $save = true)
    {
        $invoice = $this->invoiceService->prepareInvoice($order);
        $invoice->setRequestedCaptureCase($captureCase);

        if ($transactionId)
        {
            $invoice->setTransactionId($transactionId);
            $order->getPayment()->setLastTransId($transactionId);
        }

        $this->adjustInvoiceAmounts($invoice, $amount);

        $invoice->register();

        $comment = __("Captured payment of %1 through Stripe.", $order->formatPrice($invoice->getGrandTotal()));
        $order->addStatusToHistory($status = 'processing', $comment, $isCustomerNotified = false);

        if ($save)
        {
            $this->saveInvoice($invoice);
            $this->saveOrder($order);
            $this->sendInvoiceEmail($invoice);
        }

        return $invoice;
    }

    public function invoiceOrder($order, $transactionId = null, $captureCase = \Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE, $amount = null, $save = true)
    {
        // This will kick in with "Authorize Only" mode orders, but not with "Authorize & Capture"
        if ($order->canInvoice())
        {
            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->setRequestedCaptureCase($captureCase);

            if ($transactionId)
            {
                $invoice->setTransactionId($transactionId);
                $order->getPayment()->setLastTransId($transactionId);
            }

            $this->adjustInvoiceAmounts($invoice, $amount);

            $invoice->register();

            if ($save)
            {
                $this->saveInvoice($invoice);
                $this->saveOrder($order);
                $this->sendInvoiceEmail($invoice);
            }

            return $invoice;
        }
        // Invoices have already been generated with either Authorize Only or Authorize & Capture, but have not actually been captured because
        // the source is not chargeable yet. These should have a pending status.
        else
        {
            foreach($order->getInvoiceCollection() as $invoice)
            {
                if ($invoice->canCapture())
                {
                    $invoice->setRequestedCaptureCase($captureCase);

                    $this->adjustInvoiceAmounts($invoice, $amount);

                    if ($transactionId && !$invoice->getTransactionId())
                    {
                        $invoice->setTransactionId($transactionId);
                        $order->getPayment()->setLastTransId($transactionId);
                    }

                    $invoice->pay();

                    if ($save)
                    {
                        $this->saveInvoice($invoice);
                        $this->saveOrder($order);
                        $this->sendInvoiceEmail($invoice);
                    }

                    return $invoice;
                }
            }
        }

        return null;
    }

    // Pending orders are the ones that were placed with an asynchronous payment method, such as SOFORT or SEPA Direct Debit,
    // which may finalize the charge after several days or weeks
    public function invoicePendingOrder($order, $transactionId = null, $amount = null)
    {
        if (!$order->canInvoice())
            throw new \Exception("Order #" . $order->getIncrementId() . " cannot be invoiced.");

        $invoice = $this->invoiceService->prepareInvoice($order);

        if ($transactionId)
        {
            $captureCase = \Magento\Sales\Model\Order\Invoice::NOT_CAPTURE;
            $invoice->setTransactionId($transactionId);
            $order->getPayment()->setLastTransId($transactionId);
        }
        else
        {
            $captureCase = \Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE;
        }

        $invoice->setRequestedCaptureCase($captureCase);

        $this->adjustInvoiceAmounts($invoice, $amount);

        $invoice->register();

        $this->sendInvoiceEmail($invoice);

        $this->saveInvoice($invoice);
        $this->saveOrder($order);

        return $invoice;
    }

    public function cancelOrCloseOrder($order, $refundInvoices = false, $refundOffline = true)
    {
        $canceled = false;

        // When in Authorize & Capture, uncaptured invoices exist, so we should cancel them first
        foreach($order->getInvoiceCollection() as $invoice)
        {
            if ($invoice->getState() == \Magento\Sales\Model\Order\Invoice::STATE_CANCELED)
                continue;

            if ($invoice->canCancel())
            {
                $invoice->cancel();
                $this->saveInvoice($invoice);
                $canceled = true;
            }
            else if ($refundInvoices)
            {
                $creditmemo = $this->creditmemoFactory->createByOrder($order);
                $creditmemo->setInvoice($invoice);
                $this->creditmemoService->refund($creditmemo, $refundOffline);
                $this->saveCreditmemo($creditmemo);
                $canceled = true;
            }
        }

        // When there are no invoices, the order can be canceled
        if ($order->canCancel())
        {
            $order->cancel();
            $canceled = true;
        }

        $this->saveOrder($order);

        return $canceled;
    }

    public function maskError($msg)
    {
        if (stripos($msg, "You must verify a phone number on your Stripe account") === 0)
            return $msg;

        return false;
    }

    // Removes decorative strings that Magento adds to the transaction ID
    public function cleanToken($token)
    {
        if (empty($token))
            return null;

        return preg_replace('/-.*$/', '', $token);
    }

    public function formatStripePrice($price, $currency = null)
    {
        $precision = 0;
        if (!$this->isZeroDecimal($currency))
        {
            $price /= 100;
            $precision = 2;
        }

        return $this->priceCurrency->format($price, false, $precision, null, strtoupper($currency));
    }

    public function getUrl($path, $additionalParams = [])
    {
        $params = ['_secure' => $this->request->isSecure()];
        return $this->urlBuilder->getUrl($path, $params + $additionalParams);
    }

    public function getRequest()
    {
        return $this->request;
    }

    public function updateBillingAddress($token)
    {
        if (strpos($token, "pm_") === 0)
        {
            $paymentMethod = \Stripe\PaymentMethod::retrieve($token);
            $quote = $this->getQuote();
            $magentoBillingDetails = $this->addressHelper->getStripeAddressFromMagentoAddress($quote->getBillingAddress());
            $paymentMethodBillingDetails = [
                "address" => [
                    "city" => $paymentMethod->billing_details->address->city,
                    "line1" => $paymentMethod->billing_details->address->line1,
                    "line2" => $paymentMethod->billing_details->address->line2,
                    "country" => $paymentMethod->billing_details->address->country,
                    "postal_code" => $paymentMethod->billing_details->address->postal_code,
                    "state" => $paymentMethod->billing_details->address->state
                ],
                "phone" => $paymentMethod->billing_details->phone,
                "name" => $paymentMethod->billing_details->name,
                "email" => $paymentMethod->billing_details->email
            ];
            if ($paymentMethodBillingDetails != $magentoBillingDetails || $paymentMethodBillingDetails["address"] != $magentoBillingDetails["address"])
            {
                \Stripe\PaymentMethod::update(
                  $paymentMethod->id,
                  ['billing_details' => $magentoBillingDetails]
                );
            }
        }
    }

    public function sendNewOrderEmailFor($order, $forceSend = false)
    {
        if (empty($order) || !$order->getId())
            return;

        if (!$order->getEmailSent() && $forceSend)
        {
            $order->setCanSendNewEmailFlag(true);
        }

        // Send the order email
        if ($order->getCanSendNewEmailFlag())
        {
            try
            {
                $this->orderSender->send($order);
                return true;
            }
            catch (\Exception $e)
            {
                $this->logError($e->getMessage());
                $this->logError($e->getTraceAsString());
            }
        }

        return false;
    }

    public function sendInvoiceEmail($invoice)
    {
        try
        {
            $this->invoiceSender->send($invoice);
            return true;
        }
        catch (\Exception $e)
        {
            $this->logError($e->getMessage(), $e->getTraceAsString());
        }

        return false;
    }

    // An assumption is made that Webhooks->initStripeFrom($order) has already been called
    // to set the store and currency before the conversion, as the pricingHelper uses those
    public function getFormattedStripeAmount($amount, $currency, $order)
    {
        $orderAmount = $this->convertStripeAmountToOrderAmount($amount, $currency, $order);

        return $this->addCurrencySymbol($orderAmount, $currency);
    }

    public function convertBaseAmountToStoreAmount($baseAmount)
    {
        $store = $this->storeManager->getStore();
        return $store->getBaseCurrency()->convert($baseAmount, $store->getCurrentCurrencyCode());
    }

    public function convertBaseAmountToOrderAmount($baseAmount, $order, $stripeCurrency, $precision = 4)
    {
        $currency = $order->getOrderCurrencyCode();

        if (strtolower($stripeCurrency) == strtolower($order->getOrderCurrencyCode()))
        {
            $rate = $order->getBaseToOrderRate();
            if (empty($rate))
                return $baseAmount; // The base currency and the order currency are the same

            return round(floatval($baseAmount * $rate), $precision);
        }
        else
        {
            $store = $this->storeManager->getStore();
            $amount = $store->getBaseCurrency()->convert($baseAmount, $stripeCurrency);

            return round(floatval($amount), $precision);
        }

        // $rate = $this->currencyFactory->create()->load($order->getBaseCurrencyCode())->getAnyRate($currency);
    }

    public function convertMagentoAmountToStripeAmount($amount, $currency)
    {
        if (empty($amount) || !is_numeric($amount) || $amount < 0)
            return 0;

        $cents = 100;
        if ($this->isZeroDecimal($currency))
            $cents = 1;

        $amount = round($amount, 2);

        return round($amount * $cents);
    }

    public function convertOrderAmountToBaseAmount($amount, $currency, $order)
    {
        if (strtolower($currency) == strtolower($order->getOrderCurrencyCode()))
            $rate = $order->getBaseToOrderRate();
        else
            throw new \Exception("Currency code $currency was not used to place order #" . $order->getIncrementId());

        // $rate = $this->currencyFactory->create()->load($order->getBaseCurrencyCode())->getAnyRate($currency);
        if (empty($rate))
            return $amount; // The base currency and the order currency are the same

        return round(floatval($amount / $rate), 2);
    }

    public function convertStripeAmountToBaseOrderAmount($amount, $currency, $order)
    {
        if (strtolower($currency) != strtolower($order->getOrderCurrencyCode()))
            throw new \Exception("The order currency does not match the Stripe currency");

        $cents = 100;

        if ($this->isZeroDecimal($currency))
            $cents = 1;

        $amount = ((float)$amount / $cents);
        $baseAmount = round($amount / (float)$order->getBaseToOrderRate(), 2);

        return $baseAmount;
    }

    public function convertStripeAmountToOrderAmount($amount, $currency, $order)
    {
        if (strtolower($currency) != strtolower($order->getOrderCurrencyCode()))
            throw new \Exception("The order currency does not match the Stripe currency");

        $cents = 100;

        if ($this->isZeroDecimal($currency))
            $cents = 1;

        $amount = ($amount / (float)$cents);

        return $amount;
    }

    public function convertStripeAmountToQuoteAmount($amount, $currency, $quote)
    {
        if (strtolower($currency) != strtolower($quote->getQuoteCurrencyCode()))
            throw new \Exception("The quote currency does not match the Stripe currency");

        $cents = 100;

        if ($this->isZeroDecimal($currency))
            $cents = 1;

        $amount = ($amount / (float)$cents);

        return $amount;
    }

    public function convertStripeAmountToBaseQuoteAmount($amount, $currency, $quote)
    {
        if (strtolower($currency) != strtolower($quote->getQuoteCurrencyCode()))
            throw new \Exception("The order currency does not match the Stripe currency");

        $cents = 100;

        if ($this->isZeroDecimal($currency))
            $cents = 1;

        $amount = ($amount / $cents);
        $baseAmount = round(floatval($amount / $quote->getBaseToQuoteRate()), 2);

        return $baseAmount;
    }

    public function convertStripeAmountToMagentoAmount($amount, $currency)
    {
        $cents = 100;

        if ($this->isZeroDecimal($currency))
            $cents = 1;

        $amount = (floatval($amount) / $cents);

        return round($amount, 2);
    }

    public function getCurrentCurrencyCode()
    {
        return $this->storeManager->getStore()->getCurrentCurrency()->getCode();
    }

    public function getBaseCurrencyCode()
    {
        return $this->storeManager->getStore()->getBaseCurrencyCode();
    }

    public function addCurrencySymbol($amount, $currencyCode = null)
    {
        if (empty($currencyCode))
            $currencyCode = $this->getCurrentCurrencyCode();

        $precision = 2;
        if ($this->isZeroDecimal($currencyCode))
            $precision = 0;

        return $this->priceCurrency->format($amount, false, $precision, null, strtoupper($currencyCode));
    }

    public function getClearSourceInfo($data)
    {
        $info = [];
        $remove = ['mandate_url', 'fingerprint', 'client_token', 'data_string'];
        foreach ($data as $key => $value)
        {
            if (!in_array($key, $remove))
                $info[$key] = $value;
        }

        // Remove Klarna pay fields
        $startsWith = ["pay_"];
        foreach ($info as $key => $value)
        {
            foreach ($startsWith as $part)
            {
                if (strpos($key, $part) === 0)
                    unset($info[$key]);
            }
        }

        return $info;
    }

    public function notifyCustomer($order, $comment)
    {
        $order->addStatusToHistory($status = false, $comment, $isCustomerNotified = true);
        $order->setCustomerNote($comment);
        try
        {
            $this->orderCommentSender->send($order, $notify = true, $comment);
        }
        catch (\Exception $e)
        {
            $this->logError("Order email sending failed: " . $e->getMessage());
        }
    }

    public function sendNewOrderEmailWithComment($order, $comment)
    {
        $order->addStatusToHistory($status = false, $comment, $isCustomerNotified = true);
        $this->orderComments[$order->getIncrementId()] = $comment;
        $order->setEmailSent(false);
        $this->orderSender->send($order, true);
    }

    public function isAuthenticationRequiredMessage($message)
    {
        return (strpos($message, "Authentication Required: ") !== false);
    }

    public function getMultishippingOrdersDescription($quote, $orders)
    {
        $customerName = $quote->getCustomerFirstname() . " " . $quote->getCustomerLastname();

        $orderIncrementIds = [];
        foreach ($orders as $order)
            $orderIncrementIds[] = "#" . $order->getIncrementId();

        $description = __("Multishipping orders %1 by %2", implode(", ", $orderIncrementIds), $customerName);

        return $description;
    }

    public function getOrderDescription($order)
    {
        if ($order->getCustomerIsGuest())
        {
            $customer = $this->getGuestCustomer($order);
            $customerName = $customer->getFirstname() . ' ' . $customer->getLastname();
        }
        else
            $customerName = $order->getCustomerName();

        if ($this->hasSubscriptionsIn($order->getAllItems()))
            $subscription = "subscription ";
        else
            $subscription = "";

        if ($this->isMultiShipping())
            $description = "Multi-shipping {$subscription}order #" . $order->getRealOrderId() . " by $customerName";
        else
            $description = "{$subscription}order #" . $order->getRealOrderId() . " by $customerName";

        return ucfirst($description);
    }

    public function getQuoteDescription($quote)
    {
        if ($quote->getCustomerIsGuest())
        {
            $customer = $this->getGuestCustomer($quote);
            $customerName = $customer->getFirstname() . ' ' . $customer->getLastname();
        }
        else
            $customerName = $quote->getCustomerName();

        if (!empty($customerName))
            $description = __("Cart %1 by %2", $quote->getId(), $customerName);
        else
            $description = __("Cart %1", $quote->getId());

        return $description;
    }

    public function supportsSubscriptions(?string $method)
    {
        if (empty($method))
            return false;

        return in_array($method, ["stripe_payments", "stripe_payments_checkout", "stripe_payments_express"]);
    }

    public function isStripeCheckoutMethod(?string $method)
    {
        if (empty($method))
            return false;

        return in_array($method, ["stripe_payments_checkout"]);
    }

    public function getLevel3DataFrom($order)
    {
        if (empty($order))
            return null;

        $merchantReference = $order->getIncrementId();

        if (empty($merchantReference))
            return null;

        $currency = $order->getOrderCurrencyCode();
        $cents = $this->isZeroDecimal($currency) ? 1 : 100;

        $data = [
            "merchant_reference" => $merchantReference,
            "line_items" => $this->getLevel3DataLineItemsFrom($order, $cents)
        ];

        if (!$order->getIsVirtual())
        {
            $data["shipping_address_zip"] = $order->getShippingAddress()->getPostcode();
            $data["shipping_amount"] = round(floatval($order->getShippingInclTax()) * $cents);
        }

        $data = array_merge($data, $this->getLevel3AdditionalDataFrom($order, $cents));

        return $data;
    }

    public function getLevel3DataLineItemsFrom($order, $cents)
    {
        $items = [];

        $quoteItems = $order->getAllVisibleItems();
        foreach ($quoteItems as $item)
        {
            $amount = $item->getPrice();
            $tax = round(floatval($item->getTaxAmount()) * $cents);
            $discount = round(floatval($item->getDiscountAmount()) * $cents);

            $items[] = [
                "product_code" => substr($item->getSku(), 0, 12),
                "product_description" => substr($item->getName(), 0, 26),
                "unit_cost" => round($amount * $cents),
                "quantity" => $item->getQtyOrdered(),
                "tax_amount" => $tax,
                "discount_amount" => $discount
            ];
        }

        return $items;
    }

    public function getLevel3AdditionalDataFrom($order, $cents)
    {
        // You can overwrite to add the shipping_from_zip or customer_reference parameters here
        return [];
    }

    public function getCustomerModel()
    {
        if ($this->currentCustomer)
            return $this->currentCustomer;

        $this->currentCustomer = \Magento\Framework\App\ObjectManager::getInstance()->get('StripeIntegration\Payments\Model\StripeCustomer');
        if ($this->currentCustomer->getStripeId())
            return $this->currentCustomer;

        $pk = $this->getPublishableKey();
        if (empty($pk))
            return $this->currentCustomer;

        $customerId = $this->getCustomerId();
        $model = null;

        if (is_numeric($customerId) && $customerId > 0)
        {
            $model = $this->customerCollection->getByCustomerId($customerId, $pk);
            if ($model && $model->getId() && $model->existsInStripe())
            {
                $model->updateSessionId();
                $this->currentCustomer = $model;
            }
        }
        else
        {
            $stripeCustomerId = $this->sessionManager->getStripeCustomerId();
            $model = null;

            if ($stripeCustomerId)
            {
                $model = $this->customerCollection->getByStripeCustomerIdAndPk($stripeCustomerId, $pk);
            }
            else
            {
                $sessionId = $this->sessionManager->getSessionId();
                $model = $this->customerCollection->getBySessionId($sessionId, $pk);
            }

            if ($model && $model->getId() && $model->existsInStripe())
                $this->currentCustomer = $model;
        }

        if (!$this->currentCustomer)
        {
            $this->currentCustomer = \Magento\Framework\App\ObjectManager::getInstance()->create('StripeIntegration\Payments\Model\StripeCustomer');
        }

        return $this->currentCustomer;
    }

    public function getCustomerModelByStripeId($stripeId)
    {
        return $this->customerCollection->getByStripeCustomerId($stripeId);
    }

    public function getPublishableKey()
    {
        $storeId = $this->getStoreId();
        $mode = $this->scopeConfig->getValue("payment/stripe_payments_basic/stripe_mode", \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
        $pk = $this->scopeConfig->getValue("payment/stripe_payments_basic/stripe_{$mode}_pk", \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);

        if (empty($pk))
            return null;

        return trim($pk);
    }

    public function getStripeUrl($liveMode, $objectType, $id)
    {
        if ($liveMode)
            return "https://dashboard.stripe.com/$objectType/$id";
        else
            return "https://dashboard.stripe.com/test/$objectType/$id";
    }

    public function holdOrder(&$order)
    {
        $order->setHoldBeforeState($order->getState());
        $order->setHoldBeforeStatus($order->getStatus());
        $order->setState(\Magento\Sales\Model\Order::STATE_HOLDED)
            ->setStatus($order->getConfig()->getStateDefaultStatus(\Magento\Sales\Model\Order::STATE_HOLDED));
        $comment = __("Order placed under manual review by Stripe Radar.");
        $order->addStatusToHistory(false, $comment, false);

        return $order;
    }

    public function addOrderComment($msg, $order, $isCustomerNotified = false)
    {
        if ($order)
            $order->addCommentToStatusHistory($msg);
    }

    public function overrideInvoiceActionComment(\Magento\Sales\Model\Order\Payment $payment, $msg)
    {
        try
        {
            $extensionAttributes = $payment->getExtensionAttributes();
            if ($extensionAttributes === null)
            {
                $extensionAttributes = $this->paymentExtensionFactory->create();
                $payment->setExtensionAttributes($extensionAttributes);
            }

            if (!method_exists($extensionAttributes, "setNotificationMessage"))
            {
                throw new \Exception("Older version of Magento detected.");
            }

            $extensionAttributes->setNotificationMessage($msg);
        }
        catch (\Exception $e)
        {
            // Magento 2.3.5 and older did not support the notification_message extension attribute, which will cause an exception
            // https://github.com/magento/magento2/blob/2.3.6/app/code/Magento/Sales/etc/extension_attributes.xml#L17
        }
    }

    public function overrideCancelActionComment(\Magento\Sales\Model\Order\Payment $payment, $msg)
    {
        $payment->setMessage($msg);
        $this->overrideInvoiceActionComment($payment, $msg);
    }

    public function capture($token, $payment, $amount, $useSavedCard = false)
    {
        $token = $this->cleanToken($token);
        $order = $payment->getOrder();

        if ($token == "cannot_capture_subscriptions")
        {
            $msg = __("Subscription items cannot be captured online. Capturing offline instead.");
            $this->addWarning($msg);
            $this->overrideInvoiceActionComment($payment, $msg);
            return;
        }

        try
        {
            $paymentObject = $ch = null;
            $finalAmount = $amountToCapture = 0;

            if (strpos($token, 'pi_') === 0)
            {
                $pi = \Stripe\PaymentIntent::retrieve($token);

                if (empty($pi->charges->data[0]) || $pi->status == "requires_action")
                    $this->dieWithError(__("The payment for this order has not been authorized yet."));

                $ch = $pi->charges->data[0];
                $paymentObject = $pi;
                $amountToCapture = "amount_to_capture";
            }
            else if (strpos($token, 'ch_') === 0)
            {
                $ch = \Stripe\Charge::retrieve($token);
                $paymentObject = $ch;
                $amountToCapture = "amount";
            }
            else
            {
                $this->dieWithError(__("We do not know how to capture payments with a token of this format."));
            }

            $currency = $ch->currency;

            if ($currency == strtolower($order->getOrderCurrencyCode()))
                $finalAmount = $this->getMultiCurrencyAmount($payment, $amount);
            else if ($currency == strtolower($order->getBaseCurrencyCode()))
                $finalAmount = $amount;
            else
                $this->dieWithError(__("Cannot capture payment because it was created using a different currency (%1).", $ch->currency));

            $cents = 100;
            if ($this->isZeroDecimal($currency))
                $cents = 1;

            $stripeAmount = round(floatval($finalAmount * $cents));

            if ($this->isAuthorizationExpired($ch))
            {
                if ($useSavedCard)
                {
                    return $this->apiFactory->create()->reCreateCharge($payment, $amount, $ch);
                }
                else
                    return $this->dieWithError("The payment authorization with the customer's bank has expired. If you wish to create a new payment using a saved card, please enable Expired Authorizations from Configuration &rarr; Sales &rarr; Payment Methods &rarr; Stripe &rarr; Card Payments &rarr; Expired Authorizations.");
            }
            else if ($ch->refunded)
            {
                $this->dieWithError("The amount for this invoice has been refunded in Stripe.");
            }
            else if ($ch->captured)
            {
                $capturedAmount = $ch->amount - $ch->amount_refunded;
                $humanReadableAmount = $this->formatStripePrice($stripeAmount - $capturedAmount, $ch->currency);

                if ($order->getInvoiceCollection()->getSize() > 0)
                {
                    foreach ($order->getInvoiceCollection() as $invoice)
                    {
                        if ($invoice->getState() == \Magento\Sales\Model\Order\Invoice::STATE_PAID)
                        {
                            if ($invoice->getGrandTotal() < $order->getGrandTotal()) // Is this a partial invoice?
                            {
                                if ($useSavedCard)
                                {
                                    $this->apiFactory->create()->reCreateCharge($payment, $amount, $ch);
                                    return;
                                }
                                else
                                {
                                    return $this->dieWithError("The payment has already been partially captured, and the remaining amount has been released. If you wish to create a new payment using a saved card, please enable Expired Authorizations from Configuration &rarr; Sales &rarr; Payment Methods &rarr; Stripe &rarr; Card Payments &rarr; Expired Authorizations.");
                                }
                            }
                            else
                            {
                                // In theory we should never get in here because Magento cannot Invoice orders which have already been fully invoiced..
                                $msg = __("%1 could not be captured online because it was already captured via Stripe. Capturing %1 offline instead.", $humanReadableAmount);
                                $this->addWarning($msg);
                                $this->addOrderComment($msg, $order);
                                return;
                            }
                        }
                    }
                }

                if ($this->hasTrialSubscriptionsIn($order->getAllItems()))
                {
                    $msg = __("%1 could not be captured online because this cart includes subscriptions which are trialing. Capturing %1 offline instead.", $humanReadableAmount);
                }
                else if (($stripeAmount - $capturedAmount) == 0)
                {
                    // Case with a regular item and a subscription with PaymentElement, before the webhook arrives.
                    $humanReadableAmount = $this->formatStripePrice($stripeAmount, $ch->currency);
                    $msg = __("%1 has already captured via Stripe. The invoice was in Pending status, likely because a webhook could not be delivered to your website. Capturing %1 offline instead.", $humanReadableAmount);
                }
                else
                    $msg = __("%1 could not be captured online because it was already captured via Stripe. Capturing %1 offline instead.", $humanReadableAmount);

                $this->addWarning($msg);
                $this->overrideInvoiceActionComment($payment, $msg);
            }
            else // status == pending
            {
                $availableAmount = $ch->amount;
                if ($availableAmount < $stripeAmount)
                {
                    $available = $this->formatStripePrice($availableAmount, $ch->currency);
                    $requested = $this->formatStripePrice($stripeAmount, $ch->currency);

                    if ($this->hasSubscriptionsIn($order->getAllItems()))
                        $msg = __("Capturing %1 instead of %2 because subscription items cannot be captured.", $available, $requested);
                    else
                        $msg = __("The maximum available amount to capture is %1, but a capture of %2 was requested. Will capture %1 instead.", $available, $requested);

                    $this->addWarning($msg);
                    $this->addOrderComment($msg, $order);
                    $stripeAmount = $availableAmount;
                }

                $key = "admin_captured_" . $paymentObject->id;
                try
                {
                    $this->cache->save($value = "1", $key, ["stripe_payments"], $lifetime = 60 * 60);
                    $paymentObject->capture(array($amountToCapture => $stripeAmount));
                }
                catch (\Exception $e)
                {
                    $this->cache->remove($key);
                    throw $e;
                }
            }
        }
        catch (\Exception $e)
        {
            $this->dieWithError($e->getMessage(), $e);
        }
    }

    public function deduplicatePaymentMethod($customerId, $paymentMethodId, $paymentMethodType, $fingerprint, $stripeClient)
    {
        if ($paymentMethodType != "card" || empty($fingerprint) || empty($customerId) || empty($paymentMethodId))
            return;

        try
        {

            switch ($paymentMethodType)
            {
                case "card":

                    $subscriptions = [];
                    $data = $stripeClient->subscriptions->all(['limit' => 100, 'customer' => $customerId]);
                    foreach ($data->autoPagingIterator() as $subscription)
                        $subscriptions[] = $subscription;

                    $collection = $stripeClient->paymentMethods->all([
                      'customer' => $customerId,
                      'type' => $paymentMethodType
                    ]);

                    foreach ($collection->data as $paymentMethod)
                    {
                        if ($paymentMethod['id'] == $paymentMethodId || $paymentMethod['card']['fingerprint'] != $fingerprint)
                            continue;

                        // Update subscriptions which use the card that will be deleted
                        foreach ($subscriptions as $subscription)
                        {
                            if ($subscription->default_payment_method == $paymentMethod['id'])
                            {
                                try
                                {
                                    $stripeClient->subscriptions->update($subscription->id, ['default_payment_method' => $paymentMethodId]);
                                }
                                catch (\Exception $e)
                                {
                                    $this->logError($e->getMessage(), $e->getTraceAsString());
                                }
                            }
                        }

                        // Detach the card from the customer
                        try
                        {
                            $stripeClient->paymentMethods->detach($paymentMethod['id']);
                        }
                        catch (\Exception $e)
                        {
                            $this->logError($e->getMessage());
                            $this->logError($e->getTraceAsString());
                        }
                    }

                    break;

                default:

                    break;
            }
        }
        catch (\Exception $e)
        {
            $this->logError($e->getMessage());
            $this->logError($e->getTraceAsString());
        }
    }

    public function getPRAPIMethodType()
    {
        if (empty($_SERVER['HTTP_USER_AGENT']))
            return null;

        $userAgent = strtolower($_SERVER['HTTP_USER_AGENT']);

        if (strpos($userAgent, 'chrome') !== false)
            return 'Google Pay';

        if (strpos($userAgent, 'safari') !== false)
            return 'Apple Pay';

        if (strpos($userAgent, 'edge') !== false)
            return 'Microsoft Pay';

        if (strpos($userAgent, 'opera') !== false)
            return 'Opera Browser Wallet';

        if (strpos($userAgent, 'firefox') !== false)
            return 'Firefox Browser Wallet';

        if (strpos($userAgent, 'samsung') !== false)
            return 'Samsung Browser Wallet';

        if (strpos($userAgent, 'qqbrowser') !== false)
            return 'QQ Browser Wallet';

        return null;
    }

    public function getPaymentLocation($location)
    {
        if (stripos($location, 'product') === 0)
            return "Product Page";

        switch ($location) {
            case 'cart':
                return "Shopping Cart Page";

            case 'checkout':
                return "Checkout Page";

            case 'minicart':
                return "Mini cart";

            default:
                return "Unknown";
        }
    }

    public function getCustomerOrders($customerId, $statuses = [], $paymentMethodId = null)
    {
        $collection = $this->orderCollectionFactory->create($customerId)
            ->addAttributeToSelect('*')
            ->join(
                ['pi' => $this->resource->getTableName('stripe_payment_intents')],
                'main_table.customer_id = pi.customer_id and main_table.increment_id = pi.order_increment_id',
                []
            )
            ->setOrder(
                'created_at',
                'desc'
            );

        if (!empty($statuses))
            $collection->addFieldToFilter('main_table.status', ['in' => $statuses]);

        if (!empty($paymentMethodId))
            $collection->addFieldToFilter('pi.pm_id', ['eq' => $paymentMethodId]);

        return $collection;
    }

    public function loadCouponByCouponCode($couponCode)
    {
        return $this->couponFactory->create()->loadByCode($couponCode);
    }

    public function loadRuleByRuleId($ruleId)
    {
        return $this->ruleRepository->getById($ruleId);
    }

    public function loadStripeCouponByRuleId($ruleId)
    {
        return $this->stripeCouponFactory->create()->load($ruleId, 'rule_id');
    }

    public function sendPaymentFailedEmail($quote, $msg)
    {
        try
        {
            if (!$quote)
                $quote = $this->getQuote();

            $this->checkoutHelper->sendPaymentFailedEmail($quote, $msg);
        }
        catch (\Exception $e)
        {
            $this->logError($e->getMessage(), $e->getTraceAsString());
        }
    }

    public function isRecurringOrder($method)
    {
        try
        {
            $info = $method->getInfoInstance();

            if (!$info)
                return false;

            return $info->getAdditionalInformation("is_recurring_subscription");
        }
        catch (\Exception $e)
        {
            return false;
        }

        return false;
    }

    public function resetPaymentData($payment)
    {
        if ($payment->getPaymentId())
            return;

        // Reset a previously initialized 3D Secure session
        $payment->setAdditionalInformation('stripejs_token', null)
            ->setAdditionalInformation('token', null)
            ->setAdditionalInformation("is_recurring_subscription", null)
            ->setAdditionalInformation("is_migrated_subscription", null)
            ->setAdditionalInformation("remove_initial_fee", null)
            ->setAdditionalInformation("customer_stripe_id", null)
            ->setAdditionalInformation("payment_element", null)
            ->setAdditionalInformation("payment_location", null)
            ->setAdditionalInformation("cvc_token", null)
            ->setAdditionalInformation("client_secret", null)
            ->setAdditionalInformation("manual_authentication", null)
            ->setAdditionalInformation("is_subscription_update", null);
    }

    protected function isSavedPaymentMethod(\Stripe\PaymentMethod $paymentMethod)
    {
        if (empty($paymentMethod->customer))
        {
            return false;
        }

        // With subscriptions, the payment method is created with PaymentElement and saved automatically before the order is placed.
        // We don't want to perform CVC checks if this is a freshly created payment method.
        $age = time() - $paymentMethod->created;
        if ($age < (4 * 60))
        {
            return false;
        }

        return true;
    }

    public function assignPaymentData($payment, $data)
    {
        $this->resetPaymentData($payment);

        if ($this->isMultiShipping())
        {
            $payment->setAdditionalInformation("payment_location", "Multishipping checkout");
        }
        else if ($this->isAdmin())
        {
            $payment->setAdditionalInformation("payment_location", "Admin area");
        }
        else if (!empty($data['is_migrated_subscription']))
        {
            $payment->setAdditionalInformation("payment_location", "CLI migrated subscription order");
        }
        else if (!empty($data['is_recurring_subscription']))
        {
            $payment->setAdditionalInformation("payment_location", "Recurring subscription order");
        }
        else if (!empty($data['is_prapi']))
        {
            $payment->setAdditionalInformation('is_prapi', true);
            $payment->setAdditionalInformation('prapi_location', $data['prapi_location']);

            if (!empty($data['prapi_title']))
            {
                $payment->setAdditionalInformation('prapi_title', $data['prapi_title']);
                $location = $data['prapi_title'] . " via " . $data['prapi_location'] . " page";
                $payment->setAdditionalInformation("payment_location", $location);
            }
            else
            {
                $payment->setAdditionalInformation("payment_location", "Wallet payment");
            }
        }
        else if (!empty($data['is_subscription_update']))
        {
            $payment->setAdditionalInformation("is_subscription_update", true);
        }

        if (!empty($data['payment_element']))
        {
            // Used by the new checkout flow
            $token = (isset($data['payment_method']) ? $data['payment_method'] : null);
            $payment->setAdditionalInformation('payment_element', $data['payment_element']);
            $payment->setAdditionalInformation('token', $token);

            if (!empty($data['manual_authentication']))
            {
                $payment->setAdditionalInformation('manual_authentication', $data['manual_authentication']);
            }

            // Token is null when using a new payment method, and is set when using a saved payment method
            if ($token)
            {
                $config = DataHelper::getSingleton(\StripeIntegration\Payments\Model\Config::class);

                if ($config->reCheckCVCForSavedCards())
                {
                    $payment->setAdditionalInformation('cvc_token', null);
                    $stripePaymentMethod = DataHelper::getSingleton(\StripeIntegration\Payments\Model\Stripe\PaymentMethod::class);
                    $paymentMethod = $stripePaymentMethod->fromPaymentMethodId($token)->getStripeObject();

                    if ($paymentMethod->type == "card" && $this->isSavedPaymentMethod($paymentMethod))
                    {
                        if (!empty($data['cvc_token']))
                        {
                            $payment->setAdditionalInformation('cvc_token', $data['cvc_token']);
                        }
                        else
                        {
                            throw new LocalizedException(__("Your card has been declined. Please check your CVC and try again."));
                        }
                    }
                }
            }

            if ($this->isMultiShipping())
            {
                if (!empty($data['cc_stripejs_token'])) {
                    // Used in the Magento admin, Wallet button, GraphQL API and REST API
                    $card = explode(':', $data['cc_stripejs_token']);
                    $data['cc_stripejs_token'] = $card[0];
                    $payment->setAdditionalInformation('token', $card[0]);

                    $quoteId = $payment->getQuoteId();
                    $multishippingQuoteModel = $this->multishippingQuoteFactory->create();
                    $multishippingQuoteModel->load($quoteId, 'quote_id');
                    $multishippingQuoteModel->setQuoteId($quoteId);
                    $multishippingQuoteModel->setPaymentMethodId($card[0]);
                    $multishippingQuoteModel->save();
                }
            }
        }
        else if (!empty($data['cc_stripejs_token']))
        {
            // Used in the Magento admin, Wallet button, GraphQL API and REST API
            $card = explode(':', $data['cc_stripejs_token']);
            $data['cc_stripejs_token'] = $card[0];
            $payment->setAdditionalInformation('token', $card[0]);

            if (isset($data['save_payment_method']) && $data['save_payment_method'])
                $payment->setAdditionalInformation('save_payment_method', true);

            if ($this->isMultiShipping())
            {
                $quoteId = $payment->getQuoteId();
                $multishippingQuoteModel = $this->multishippingQuoteFactory->create();
                $multishippingQuoteModel->load($quoteId, 'quote_id');
                $multishippingQuoteModel->setQuoteId($quoteId);
                $multishippingQuoteModel->setPaymentMethodId($card[0]);
                $multishippingQuoteModel->save();
            }
        }
        else if (!empty($data['is_recurring_subscription']))
            $payment->setAdditionalInformation('is_recurring_subscription', $data['is_recurring_subscription']);

        if (!empty($data['is_migrated_subscription']))
            $payment->setAdditionalInformation('is_migrated_subscription', true);
    }

    public function shippingIncludesTax($store = null)
    {
        return $this->taxConfig->shippingPriceIncludesTax($store);
    }

    public function priceIncludesTax($store = null)
    {
        return $this->taxConfig->priceIncludesTax($store);
    }

    /**
     * Transaction interface types
     * const TYPE_PAYMENT = 'payment';
     * const TYPE_ORDER = 'order';
     * const TYPE_AUTH = 'authorization';
     * const TYPE_CAPTURE = 'capture';
     * const TYPE_VOID = 'void';
     * const TYPE_REFUND = 'refund';
     **/
    public function addTransaction($order, $transactionId, $transactionType = "capture", $parentTransactionId = null)
    {
        try
        {
            $payment = $order->getPayment();

            if ($parentTransactionId)
            {
                $payment->setTransactionId($transactionId . "-$transactionType");
                $payment->setParentTransactionId($parentTransactionId);
            }
            else
            {
                $payment->setTransactionId($transactionId);
                $payment->setParentTransactionId(null);
            }

            $transaction = $payment->addTransaction($transactionType, null, true);
            return  $transaction;
        }
        catch (Exception $e)
        {
            $this->logError($e->getMessage(), $e->getTraceAsString());
        }
    }

    public function getOrderTransactions($order)
    {
        $transactions = $this->transactionSearchResultFactory->create()->addOrderIdFilter($order->getId());
        return $transactions->getItems();
    }

    // $orderItemQtys = [$orderItem->getId() => int $qty, ...]
    public function invoiceOrderItems($order, $orderItemQtys, $save = true)
    {
        if (empty($orderItemQtys))
            return null;

        $invoice = $this->invoiceService->prepareInvoice($order, $orderItemQtys);
        $invoice->register();
        $order->setIsInProcess(true);

        if ($save)
        {
            $this->saveInvoice($invoice);
            $this->saveOrder($order);
        }

        return $invoice;
    }

    public function isPendingCheckoutOrder($order)
    {
        $method = $order->getPayment()->getMethod();
        if (!$this->isStripeCheckoutMethod($method))
            return false;

        if ($order->getState() != "pending_payment")
            return false;

        if ($order->getPayment()->getLastTransId())
            return false;

        return true;
    }

    public function clearCache()
    {
        $this->products = [];
        $this->orders = [];
        $this->quoteHasSubscriptions = [];
        $this->quotes = [];
        return $this;
    }

    public function saveOrder($order)
    {
        return $this->orderRepository->save($order);
    }

    public function saveInvoice($invoice)
    {
        return $this->invoiceRepository->save($invoice);
    }

    public function saveQuote($quote)
    {
        return $this->quoteRepository->save($quote);
    }

    public function saveTransaction($transaction)
    {
        return $this->transactionRepository->save($transaction);
    }

    public function saveCreditmemo($creditmemo)
    {
        return $this->creditmemoRepository->save($creditmemo);
    }

    public function savePayment($payment)
    {
        return $this->orderPaymentRepository->save($payment);
    }

    public function saveProduct($product)
    {
        return $this->productRepository->save($product);
    }

    public function setProcessingState($order, $comment = null, $isCustomerNotified = false)
    {
        $state = \Magento\Sales\Model\Order::STATE_PROCESSING;
        $status = $order->getConfig()->getStateDefaultStatus($state);

        if ($comment)
            $order->setState($state)->addStatusToHistory($status, $comment, $isCustomerNotified);
        else
            $order->setState($state)->setStatus($status);
    }

    public function setOrderState($order, $state, $comment = null, $isCustomerNotified = false)
    {
        $status = $order->getConfig()->getStateDefaultStatus($state);

        if ($comment)
            $order->setState($state)->addStatusToHistory($status, $comment, $isCustomerNotified);
        else
            $order->setState($state)->setStatus($status);
    }

    public function getStripeApiTimeDifference()
    {
        $timeDifference = $this->cache->load("stripe_api_time_difference");
        if (!is_numeric($timeDifference))
        {
            $localTime = time();
            $product = \Stripe\Product::create([
               'name' => 'Time Query',
               'type' => 'service'
            ]);
            $timeDifference = $product->created - ($localTime + 1); // The 1 added second accounts for the delay in creating the product
            $this->cache->save($timeDifference, $key = "stripe_api_time_difference", $tags = ["stripe_payments"], $lifetime = 24 * 60 * 60);
            $product->delete();
        }
        return $timeDifference;
    }

    public function isTransactionId($transactionId)
    {
        $isPaymentIntent = (strpos($transactionId, "pi_") === 0);
        $isCharge = (strpos($transactionId, "ch_") === 0);
        return ($isPaymentIntent || $isCharge);
    }

    public function getOrdersByTransactionId($transactionId)
    {
        $orders = [];

        if (!$this->isTransactionId($transactionId))
            return $orders;

        $transactions = $this->transactionSearchResultFactory->create()->addFieldToFilter('txn_id', $transactionId);

        foreach ($transactions as $transaction)
        {
            if (!$transaction->getOrderId())
                continue;

            $orderId = $transaction->getOrderId();
            if (isset($orders[$orderId]))
                continue;

            $order = $this->loadOrderById($orderId);
            if ($order && $order->getId())
                $orders[$orderId] = $order;
        }

        return $orders;
    }

    public function getCache()
    {
        return $this->cache;
    }

    public function removeTransactions($order)
    {
        $order->getPayment()->setLastTransId(null);
        $order->getPayment()->setTransactionId(null);
        $order->getPayment()->save();
    }

    public function magentoVersion($operator, $version)
    {
        $magentoVersion = $this->productMetadata->getVersion();
        return version_compare($magentoVersion, $version, $operator);
    }

    public function getSubscriptionsHelper()
    {
        if (!$this->subscriptionHelper) {
            $this->subscriptionHelper = DataHelper::getSingleton(\StripeIntegration\Payments\Helper\Subscriptions::class);
        }

        return $this->subscriptionHelper;
    }
}
