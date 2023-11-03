<?php

namespace StripeIntegration\Payments\Model\Method;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\TemporaryState\CouldNotSaveException;
use Magento\Payment\Model\InfoInterface;

class BankTransfers extends \Magento\Payment\Model\Method\AbstractMethod
{
    const METHOD_CODE = 'stripe_payments_bank_transfers';
    protected $_code = self::METHOD_CODE;
    protected $_infoBlockType = 'StripeIntegration\Payments\Block\PaymentInfo\BankTransfers';
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canUseInternal = false;

    protected $refundsHelper;
    protected $checkoutHelper;
    protected $checkoutSessionFactory;
    protected $checkoutSessionHelper;
    protected $subscriptionsHelper;
    protected $helper;
    protected $config;
    protected $api;
    protected $stripePaymentMethodFactory;
    protected $customer;
    protected $addressHelper;
    protected $cache;
    protected $quoteFactory;
    protected $paymentIntent;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\PaymentIntent $paymentIntent,
        \StripeIntegration\Payments\Model\Stripe\PaymentMethodFactory $stripePaymentMethodFactory,
        \StripeIntegration\Payments\Model\CheckoutSessionFactory $checkoutSessionFactory,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Address $addressHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\CheckoutSession $checkoutSessionHelper,
        \StripeIntegration\Payments\Helper\Refunds $refundsHelper,
        \StripeIntegration\Payments\Helper\Api $api,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Checkout\Helper\Data $checkoutHelper,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );

        $this->stripePaymentMethodFactory = $stripePaymentMethodFactory;
        $this->customer = $helper->getCustomerModel();
        $this->cache = $context->getCacheManager();
        $this->quoteFactory = $quoteFactory;
        $this->config = $config;
        $this->paymentIntent = $paymentIntent;
        $this->helper = $helper;
        $this->addressHelper = $addressHelper;
        $this->logger = $logger;
        $this->checkoutHelper = $checkoutHelper;
        $this->checkoutSessionFactory = $checkoutSessionFactory;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->checkoutSessionHelper = $checkoutSessionHelper;
        $this->refundsHelper = $refundsHelper;
        $this->api = $api;
    }

    public function assignData(\Magento\Framework\DataObject $data)
    {
        parent::assignData($data);

        $additionalData = $data->getAdditionalData();

        if (empty($additionalData["payment_method"]) || strpos($additionalData["payment_method"], "pm_") === false)
        {
            return $this;
        }

        $paymentMethodId = $additionalData["payment_method"];
        $info = $this->getInfoInstance();
        $info->setAdditionalInformation("token", $paymentMethodId);

        return $this;
    }

    public function order(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $paymentIntent = $this->createPaymentIntent($payment, $amount);

        $payment->setTransactionId($paymentIntent->id);
        $payment->setLastTransId($paymentIntent->id);
        $payment->setIsTransactionClosed(0);
        $payment->setIsFraudDetected(false);
        $payment->setIsTransactionPending(true);
        $payment->setAdditionalInformation("customer_stripe_id", $paymentIntent->customer);

        return $this;
    }

    public function refund(InfoInterface $payment, $amount)
    {
        $this->refundsHelper->refund($payment, $amount);
        return $this;
    }

    public function void(InfoInterface $payment)
    {
        $this->refundsHelper->refund($payment);
        return $this;
    }

    public function cancel(\Magento\Payment\Model\InfoInterface $payment, $amount = null)
    {
        $this->refundsHelper->refund($payment, $amount);
        return $this;
    }

    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        try
        {
            if (!$quote || !$quote->getId())
                return false;

            if (!$this->config->initStripe())
                return false;

            $paymentMethodOptions = $this->getPaymentMethodOptions();
            if (!$paymentMethodOptions)
                return false;

            $quoteCurrency = $quote->getQuoteCurrencyCode();
            $quoteCountry = $quote->getBillingAddress()->getCountryId();

            if (!$this->isCountryCurrencySupported($quoteCountry, $quoteCurrency))
                return false;

            $quoteBaseAmount = $quote->getBaseGrandTotal();
            $minimumAmount = $this->config->getConfigData("minimum_amount", "bank_transfers");
            if (is_numeric($minimumAmount) && $quoteBaseAmount < $minimumAmount)
                return false;

            return parent::isAvailable($quote);
        }
        catch (\Exception $e)
        {
            $this->helper->logError($e->getMessage());
            return false;
        }
    }

    protected function createPaymentIntent($payment, $amount)
    {
        $stripe = $this->config->getStripeClient();
        $order = $payment->getOrder();
        $currency = $order->getOrderCurrencyCode();
        $amount = $this->helper->convertBaseAmountToOrderAmount($amount, $order, $currency);
        $paymentMethodId = $payment->getAdditionalInformation("token");

        $cents = 100;
        if ($this->helper->isZeroDecimal($currency))
            $cents = 1;

        $params = [
            "amount" => round(floatval($amount * $cents)),
            "currency" => strtolower($currency),
            "payment_method" => $paymentMethodId,
            "description" => $this->helper->getOrderDescription($order),
            "metadata" => $this->config->getMetadata($order),
            "customer" => $this->getStripeCustomerId($paymentMethodId, $order),
            "confirm" => true,
            "payment_method_types" => ["customer_balance"],
            "payment_method_options" => $this->getPaymentMethodOptions()
        ];

        if (!$order->getIsVirtual())
        {
            $address = $order->getShippingAddress();

            if (!empty($address))
            {
                $params['shipping'] = $this->addressHelper->getStripeShippingAddressFromMagentoAddress($address);
            }
        }

        if ($this->config->isReceiptEmailsEnabled())
        {
            $customerEmail = $order->getCustomerEmail();

            if ($customerEmail)
            {
                $params["receipt_email"] = $customerEmail;
            }
        }

        $paymentIntent = $stripe->paymentIntents->create($params);

        return $paymentIntent;
    }

    protected function getStripeCustomerId($paymentMethodId, $order)
    {
        $stripePaymentMethodModel = $this->stripePaymentMethodFactory->create()->fromPaymentMethodId($paymentMethodId);
        if ($stripePaymentMethodModel->getCustomerId())
        {
            return $stripePaymentMethodModel->getCustomerId();
        }
        else if ($this->customer->getStripeId())
        {
            return $this->customer->getStripeId();
        }
        else
        {
            $this->customer->createStripeCustomer($order);
            return $this->customer->getStripeId();
        }

        return null;
    }

    protected function getPaymentMethodOptions()
    {
        $quote = $this->helper->getQuote();
        $billingAddress = $quote->getBillingAddress();

        // Get the country code
        $countryCode = $billingAddress->getCountryId();
        if (empty($countryCode))
            return null;

        switch ($countryCode)
        {
            case "US":
                $bankTransfer = [
                    'type' => 'us_bank_transfer',
                ];
                break;

            case "GB":
                $bankTransfer = [
                    'type' => 'gb_bank_transfer',
                ];
                break;

            case "JP":
                $bankTransfer = [
                    'type' => 'jp_bank_transfer',
                ];
                break;

            case "MX":
                $bankTransfer = [
                    'type' => 'mx_bank_transfer',
                ];
                break;
            case "BE": // Belgium
            case "DE": // Germany
            case "ES": // Spain
            case "FR": // France
            case "IE": // Ireland, Republic of (EIRE)
            case "NL": // Netherlands
                $bankTransfer = [
                    'type' => 'eu_bank_transfer',
                    'eu_bank_transfer' => [
                        'country' => $countryCode
                    ]
                ];
                break;
            default:
                return null;
        }

        return [
            "customer_balance" => [
                'funding_type' => 'bank_transfer',
                'bank_transfer' => $bankTransfer,
            ]
        ];
    }

    protected function isCountryCurrencySupported($countryCode, $currency)
    {
        $accountModel = $this->config->getAccountModel();
        $accountCurrency = $accountModel->getDefaultCurrency();
        if ($accountCurrency != strtolower($currency))
        {
            return false;
        }

        switch ($countryCode)
        {
            case "US":
                return $currency == "USD";
            case "GB":
                return $currency == "GBP";
            case "JP":
                return $currency == "JPY";
            case "MX":
                return $currency == "MXN";
            default:
                return $currency == "EUR";
        }
    }

    protected function getPaymentIntent()
    {
        $payment = $this->getInfoInstance();
        $paymentIntentId = $payment->getLastTransId();
        $paymentIntentId = $this->helper->cleanToken($paymentIntentId);
        if (empty($paymentIntentId))
            return null;

        $paymentIntent = $this->config->getStripeClient()->paymentIntents->retrieve($paymentIntentId);
        return $paymentIntent;
    }
}
