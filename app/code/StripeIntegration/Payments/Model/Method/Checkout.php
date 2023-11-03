<?php

namespace StripeIntegration\Payments\Model\Method;

use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Model\InfoInterface;
use StripeIntegration\Payments\Helper;
use StripeIntegration\Payments\Helper\Logger;
use Magento\Framework\Exception\CouldNotSaveException;

class Checkout extends \Magento\Payment\Model\Method\AbstractMethod
{
    const METHOD_CODE = 'stripe_payments_checkout';
    protected $_code = self::METHOD_CODE;
    protected $type = 'stripe_checkout';

    // protected $_formBlockType = 'StripeIntegration\Payments\Block\Method\Checkout';
    protected $_infoBlockType = 'StripeIntegration\Payments\Block\PaymentInfo\Checkout';

    protected $_canAuthorize = true;
    protected $_canCapture = true;
    protected $_canCapturePartial = true;
    protected $_canCaptureOnce = true;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_isGateway = true;
    protected $_isInitializeNeeded = true;
    protected $_canVoid = true;
    protected $_canUseInternal = false;
    protected $_canFetchTransactionInfo = true;
    protected $_canUseForMultishipping  = false;
    protected $_canCancelInvoice = true;
    protected $_canUseCheckout = true;
    protected $_canSaveCc = false;

    protected $stripeCustomer = null;

    private $refundsHelper;
    private $checkoutHelper;
    private $order;
    private $checkoutSessionFactory;
    private $checkoutSessionHelper;
    private $subscriptionsHelper;
    private $helper;
    private $config;
    private $paymentIntent;
    private $quoteFactory;
    private $cache;
    private $api;

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
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Model\CheckoutSessionFactory $checkoutSessionFactory,
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

        $this->cache = $context->getCacheManager();
        $this->quoteFactory = $quoteFactory;
        $this->config = $config;
        $this->paymentIntent = $paymentIntent;
        $this->helper = $helper;
        $this->logger = $logger;
        $this->checkoutHelper = $checkoutHelper;
        $this->checkoutSessionFactory = $checkoutSessionFactory;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->checkoutSessionHelper = $checkoutSessionHelper;
        $this->refundsHelper = $refundsHelper;
        $this->api = $api;
    }

    protected function reset()
    {
        $this->stripeCustomer = null;
        $session = $this->checkoutHelper->getCheckout();
        $session->setStripePaymentsCheckoutSessionId(null);
    }

    public function initialize($paymentAction, $stateObject)
    {
        $session = $this->checkoutHelper->getCheckout();
        $info = $this->getInfoInstance();
        $order = $info->getOrder();
        $this->reset();

        // We don't want to send an order email until the payment is collected asynchronously
        $order->setCanSendNewEmailFlag(false);

        try
        {
            $checkoutSessionModel = $this->checkoutSessionFactory->create()->fromOrder($order, true);
            $checkoutSessionObject = $checkoutSessionModel->getStripeObject();

            $info->setAdditionalInformation("checkout_session_id", $checkoutSessionObject->id);
            $info->setAdditionalInformation("payment_action", $this->config->getPaymentAction());
            $session->setStripePaymentsCheckoutSessionId($checkoutSessionObject->id);
            $session->setStripePaymentsCheckoutSessionURL($checkoutSessionObject->url);

            $order->getPayment()
                ->setIsTransactionClosed(0)
                ->setIsTransactionPending(true);

            $state = \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT;
            $status = $order->getConfig()->getStateDefaultStatus($state);
            $stateObject->setState($state);
            $stateObject->setStatus($status);
            $comment = __("The customer was redirected for payment processing. The payment is pending.");
            $order->setState($state)
                ->addStatusToHistory($status, $comment, $isCustomerNotified = false);
        }
        catch (\Stripe\Exception\CardException $e)
        {
            throw new LocalizedException(__($e->getMessage()));
        }
        catch (\Exception $e)
        {
            if (strstr($e->getMessage(), 'Invalid country') !== false) {
                throw new LocalizedException(__('Sorry, this payment method is not available in your country.'));
            }
            throw new LocalizedException(__($e->getMessage()));
        }

        $info->setAdditionalInformation("payment_location", "Redirect flow");

        return $this;
    }

    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        if ($payment->getAdditionalInformation("payment_action") == "order" &&
            $payment->getAdditionalInformation("customer_stripe_id") &&
            $payment->getAdditionalInformation("token"))
        {
            $this->api->createNewCharge($payment, $amount);
            return $this;
        }

        $transactionId = $this->checkoutSessionHelper->getLastTransactionId($payment);

        if (!$transactionId)
        {
            throw new LocalizedException(__('Sorry, it is not possible to invoice this order because the payment is still pending.'));
        }

        try
        {
            $this->helper->capture($transactionId, $payment, $amount, $this->config->retryWithSavedCard());
        }
        catch (\Exception $e)
        {
            $this->helper->dieWithError($e->getMessage());
        }

        return parent::capture($payment, $amount);
    }

    public function refund(InfoInterface $payment, $amount)
    {
        $this->cancel($payment, $amount);
        return $this;
    }

    public function void(InfoInterface $payment)
    {
        $this->cancel($payment);
        return $this;
    }

    public function getTitle()
    {
        return $this->config->getConfigData("title");
    }

    public function isEnabled($quote)
    {
        return $this->config->isEnabled() &&
            $this->config->isRedirectPaymentFlow() &&
            !$this->helper->isAdmin() &&
            !$this->helper->isMultiShipping() &&
            !$this->subscriptionsHelper->isSubscriptionUpdate();
    }

    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if ($this->helper->isRecurringOrder($this))
            return true;

        if (!$this->isEnabled($quote))
            return false;

        return parent::isAvailable($quote);
    }

    public function cancel(\Magento\Payment\Model\InfoInterface $payment, $amount = null)
    {
        try
        {
            $this->refundsHelper->refund($payment, $amount);
        }
        catch (\Exception $e)
        {
            $this->helper->dieWithError($e->getMessage());
        }

        return $this;
    }

    public function getConfigPaymentAction()
    {
        return $this->config->getConfigData('payment_action');
    }

    public function canEdit()
    {
        $info = $this->getInfoInstance();

        if (!empty($info->getTransactionId()))
            return false;

        if (!empty($info->getLastTransId()))
            return false;

        if (empty($info->getAdditionalInformation("token")))
            return false;

        if (empty($info->getAdditionalInformation("customer_stripe_id")))
            return false;

        $token = $info->getAdditionalInformation("token");

        if (strpos($token, "pm_") !== 0)
            return false;

        return true;
    }
}
