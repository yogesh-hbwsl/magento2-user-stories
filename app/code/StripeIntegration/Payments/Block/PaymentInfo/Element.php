<?php

namespace StripeIntegration\Payments\Block\PaymentInfo;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Sales\Model\Order;
use StripeIntegration\Payments\Gateway\Response\FraudHandler;
use StripeIntegration\Payments\Helper\Logger;
use Magento\Sales\Model\OrderRepository;
use StripeIntegration\Payments\Helper\Data as StripeHelperData;

class Element extends \StripeIntegration\Payments\Block\PaymentInfo\Checkout
{
    protected $_template = 'paymentInfo/element.phtml';
    protected $paymentIntents = [];
    public $subscription = null;
    private $setupIntents = [];
    private $stripePaymentIntent;
    private $requestCache;

    /**
     * @var OrderRepository
     */
    protected $orderRepository;

    /**
     * @var StripeHelperData
     */
    protected $stripeHelperData;

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Payment\Gateway\ConfigInterface $config,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptions,
        \StripeIntegration\Payments\Helper\Api $api,
        \StripeIntegration\Payments\Helper\RequestCache $requestCache,
        \StripeIntegration\Payments\Model\Config $paymentsConfig,
        \StripeIntegration\Payments\Model\Stripe\PaymentIntent $stripePaymentIntent,
        \StripeIntegration\Payments\Model\StripePaymentMethodFactory $stripePaymentMethodFactory,
        \Magento\Directory\Model\Country $country,
        \Magento\Payment\Model\Info $info,
        \Magento\Framework\Registry $registry,
        OrderRepository $orderRepository,
        StripeHelperData $stripeHelperData,
        array $data = []
    ) {
        parent::__construct($context, $config, $helper, $paymentMethodHelper, $subscriptions, $api, $paymentsConfig, $stripePaymentMethodFactory, $country, $info, $registry, $data);

        $this->orderRepository = $orderRepository;
        $this->stripeHelperData = $stripeHelperData;
        $this->stripePaymentIntent = $stripePaymentIntent;
        $this->requestCache = $requestCache;
    }

    public function getTemplate()
    {
        $info = $this->getInfo();

        if ($info && $info->getAdditionalInformation("is_subscription_update"))
            return 'paymentInfo/subscription_update.phtml';

        return $this->_template;
    }

    public function getPaymentMethod()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (!empty($paymentIntent->payment_method->type))
            return $paymentIntent->payment_method;

        $info = $this->getInfo();
        if (!empty($info->getAdditionalInformation("token")))
        {
            $token = $info->getAdditionalInformation("token");
            if (strpos($token, "pm_") === 0)
            {
                $key = "payment_method_" . $token;
                $paymentMethod = $this->requestCache->get($key);
                if (!$paymentMethod)
                {
                    $paymentMethod = $this->paymentsConfig->getStripeClient()->paymentMethods->retrieve($token);
                    $this->requestCache->set($key, $paymentMethod);
                }
                return $paymentMethod;
            }
        }

        return null;
    }

    public function isMultiShipping()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (empty($paymentIntent->metadata["Multishipping"]))
            return false;

        return true;
    }

    public function getFormattedAmount()
    {
        /** @var ?\Stripe\PaymentIntent $paymentIntent */
        $paymentIntent = $this->getPaymentIntent();

        if (empty($paymentIntent->amount))
            return '';

        return $this->helper->formatStripePrice($paymentIntent->amount, $paymentIntent->currency);
    }

    public function getFormattedMultishippingAmount()
    {
        $total = $this->getFormattedAmount();

        $paymentIntent = $this->getPaymentIntent();

        /** @var \Magento\Payment\Model\InfoInterface $info */
        $info = $this->getInfo();
        if (!is_numeric($info->getAmountOrdered()))
            return $total;

        $partial = $this->helper->addCurrencySymbol($info->getAmountOrdered(), $paymentIntent->currency);

        return $partial;
    }

    public function getPaymentStatus()
    {
        $paymentIntent = $this->getPaymentIntent();

        return $this->getPaymentIntentStatus($paymentIntent);
    }

    public function getSubscription()
    {
        if (empty($this->subscription))
        {
            $info = $this->getInfo();
            if ($info && $info->getAdditionalInformation("subscription_id"))
            {
                try
                {
                    $subscriptionId = $info->getAdditionalInformation("subscription_id");
                    $this->subscription = $this->paymentsConfig->getStripeClient()->subscriptions->retrieve($subscriptionId);
                }
                catch (\Exception $e)
                {
                    $this->helper->logError($e->getMessage(), $e->getTraceAsString());
                    return null;
                }
            }
        }

        return $this->subscription;
    }

    public function getCustomerId()
    {
        $info = $this->getInfo();
        if ($info && $info->getAdditionalInformation("customer_stripe_id"))
            return $info->getAdditionalInformation("customer_stripe_id");

        return null;
    }

    public function isStripeMethod()
    {
        $method = $this->getInfo()->getMethod();

        if (strpos($method, "stripe_payments") !== 0 || $method == "stripe_payments_invoice")
            return false;

        return true;
    }

    public function getPaymentIntent()
    {
        $transactionId = $this->getInfo()->getLastTransId();
        $transactionId = $this->helper->cleanToken($transactionId);

        if (empty($transactionId) || strpos($transactionId, "pi_") !== 0)
            return null;

        if (isset($this->paymentIntents[$transactionId]))
            return $this->paymentIntents[$transactionId];

        try
        {
            $paymentIntent = $this->stripePaymentIntent->fromPaymentIntentId($transactionId, ['payment_method'])->getStripeObject();
            return $this->paymentIntents[$transactionId] = $paymentIntent;
        }
        catch (\Exception $e)
        {
            return $this->paymentIntents[$transactionId] = null;
        }
    }

    public function getSetupIntent()
    {
        $transactionId = $this->getInfo()->getLastTransId();
        $transactionId = $this->helper->cleanToken($transactionId);

        if (empty($transactionId) || strpos($transactionId, "seti_") !== 0)
            return null;

        if (isset($this->setupIntents[$transactionId]))
            return $this->setupIntents[$transactionId];

        try
        {
            return $this->setupIntents[$transactionId] = $this->paymentsConfig->getStripeClient()->setupIntents->retrieve($transactionId, ['expand' => ['payment_method']]);
        }
        catch (\Exception $e)
        {
            return $this->setupIntents[$transactionId] = null;
        }
    }

    public function getMode()
    {
        $paymentIntent = $this->getPaymentIntent();
        $setupIntent = $this->getSetupIntent();

        if ($paymentIntent && $paymentIntent->livemode)
            return "";
        else if ($setupIntent && $setupIntent->livemode)
            return "";

        return "test/";
    }

    public function getOXXOVoucherLink()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (!empty($paymentIntent->next_action->oxxo_display_details->hosted_voucher_url))
            return $paymentIntent->next_action->oxxo_display_details->hosted_voucher_url;

        return null;
    }

    // For subscription updates
    public function getSubscriptionOrderUrl($orderIncrementId)
    {
        if (empty($orderIncrementId))
            return null;

        $order = $this->helper->loadOrderByIncrementId($orderIncrementId);
        if (!$order || !$order->getId())
            return null;

        return $this->helper->getUrl('sales/order/view', ['order_id' => $order->getId()]);
    }

    public function getOriginalSubscriptionOrderIncrementId()
    {
        $info = $this->getInfo();
        if (!$info)
            return null;

        $incrementId = $info->getAdditionalInformation("original_order_increment_id");
        if (empty($incrementId))
            return null;

        return $incrementId;
    }

    public function getNewSubscriptionOrderIncrementId()
    {
        $info = $this->getInfo();
        if (!$info)
            return null;

        $incrementId = $info->getAdditionalInformation("new_order_increment_id");
        if (empty($incrementId))
            return null;

        return $incrementId;
    }

    public function getPreviousSubscriptionAmount()
    {
        $info = $this->getInfo();
        if (!$info)
            return null;

        return $info->getAdditionalInformation("previous_subscription_amount");
    }

    public function getFormattedSubscriptionAmount()
    {
        if ($this->getPreviousSubscriptionAmount())
            return null;

        return parent::getFormattedSubscriptionAmount();
    }

    public function getFormattedNewSubscriptionAmount()
    {
        if (!$this->getPreviousSubscriptionAmount())
            return null;

        return parent::getFormattedSubscriptionAmount();
    }

    /**
     * prepare the risk element class
     *
     * @param int $riskScore
     * @param string $riskLevel
     * @return string
     */
    public function getRiskElementClass($riskScore = 0, $riskLevel = 'NA')
    {
        return $this->stripeHelperData->getRiskElementClass($riskScore, $riskLevel);
    }
}
