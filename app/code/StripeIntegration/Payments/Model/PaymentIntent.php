<?php

namespace StripeIntegration\Payments\Model;

use Magento\Framework\Validator\Exception;
use Magento\Framework\Exception\LocalizedException;
use StripeIntegration\Payments\Exception\SCANeededException;
use StripeIntegration\Payments\Helper\Logger;

class PaymentIntent extends \Magento\Framework\Model\AbstractModel
{
    public $paymentIntent = null;
    public $paymentIntentsCache = [];
    public $order = null;
    public $savedCard = null;
    protected $customParams = [];

    const SUCCEEDED = "succeeded";
    const AUTHORIZED = "requires_capture";
    const CAPTURE_METHOD_MANUAL = "manual";
    const CAPTURE_METHOD_AUTOMATIC = "automatic";
    const REQUIRES_ACTION = "requires_action";
    const CANCELED = "canceled";
    const AUTHENTICATION_FAILURE = "payment_intent_authentication_failure";

    private $compare;
    private $addressHelper;
    private $cache;
    private $addressFactory;
    private $customer;
    private $subscriptionsHelper;
    private $paymentIntentHelper;
    private $dataHelper;
    private $helper;
    private $config;
    private $stripePaymentMethod;
    private $stripePaymentIntent;

    public function __construct(
        \StripeIntegration\Payments\Helper\Data $dataHelper,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Compare $compare,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\Address $addressHelper,
        \StripeIntegration\Payments\Helper\PaymentIntent $paymentIntentHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\Stripe\PaymentMethod $stripePaymentMethod,
        \StripeIntegration\Payments\Model\Stripe\PaymentIntent $stripePaymentIntent,
        \Magento\Customer\Model\AddressFactory $addressFactory,
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
        )
    {
        $this->dataHelper = $dataHelper;
        $this->helper = $helper;
        $this->compare = $compare;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->addressHelper = $addressHelper;
        $this->paymentIntentHelper = $paymentIntentHelper;
        $this->cache = $context->getCacheManager();
        $this->config = $config;
        $this->customer = $helper->getCustomerModel();
        $this->addressFactory = $addressFactory;
        $this->stripePaymentMethod = $stripePaymentMethod;
        $this->stripePaymentIntent = $stripePaymentIntent;

        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\ResourceModel\PaymentIntent');
    }

    public function setCustomParams($params)
    {
        $this->customParams = $params;
    }

    protected function getQuoteIdFrom($quote)
    {
        if (empty($quote))
            return null;
        else if ($quote->getId())
            return $quote->getId();
        else if ($quote->getQuoteId())
            throw new \Exception("Invalid quote passed during payment intent creation."); // Need to find the admin case which causes this
        else
            return null;
    }

    // If we already created any payment intents for this quote, load them
    public function loadFromCache($params, $quote, $order, $paymentIntentId = null)
    {
        $quoteId = $this->getQuoteIdFrom($quote);
        if (!$quoteId)
            return null;

        $this->load($quoteId, 'quote_id');
        if (!$this->getPiId())
        {
            if (!$this->getQuoteId() && !empty($paymentIntentId))
            {
                // Case where we don't yet have an entry in this table, so we add it on the fly
                $this->setQuoteId($quoteId);
                $this->setPiId($paymentIntentId);
                $this->save();
            }
            else
            {
                return null;
            }
        }
        $paymentIntent = null;

        try
        {
            $paymentIntent = $this->loadPaymentIntent($this->getPiId(), $order);
        }
        catch (\Exception $e)
        {
            // If the Stripe API keys or the Mode was changed mid-checkout-session, we may get here
            $this->destroy($quoteId);
            return null;
        }

        if ($this->isInvalid($params, $quote, $order, $paymentIntent))
        {
            $this->destroy($quoteId, $this->canCancel(), $paymentIntent);
            return null;
        }

        if ($this->isDifferentFrom($paymentIntent, $params, $quote, $order))
        {
            $paymentIntent = $this->updateFrom($paymentIntent, $params, $quote, $order);
        }

        if ($paymentIntent)
        {
            $this->updateCache($quoteId, $paymentIntent, $order);
        }
        else
        {
            $this->destroy($quoteId);
        }

        return $this->paymentIntent = $paymentIntent;
    }

    public function canCancel($paymentIntent = null)
    {
        if (empty($paymentIntent))
            $paymentIntent = $this->paymentIntent;

        if (empty($paymentIntent))
        {
            return false;
        }

        if ($this->paymentIntentHelper->isSuccessful($paymentIntent))
        {
            return false;
        }

        if ($paymentIntent->status == $this::CANCELED)
        {
            return false;
        }

        return true;
    }

    public function canUpdate($paymentIntent)
    {
        return $this->canCancel($paymentIntent);
    }

    public function loadPaymentIntent($paymentIntentId, $order = null)
    {
        $paymentIntent = $this->config->getStripeClient()->paymentIntents->retrieve($paymentIntentId);

        // If the PI has a customer attached, load the customer locally as well
        if (!empty($paymentIntent->customer))
        {
            $customer = $this->helper->getCustomerModelByStripeId($paymentIntent->customer);
            if ($customer)
                $this->customer = $customer;

            if (!$this->customer->getStripeId())
            {
                $this->customer->createStripeCustomer($order, ["id" => $paymentIntent->customer]);
            }
        }

        return $this->paymentIntent = $paymentIntent;
    }

    public function create($params, $quote, $order = null)
    {
        if (empty($params['amount']) || $params['amount'] <= 0)
            return null;

        $paymentIntent = $this->loadFromCache($params, $quote, $order);

        if (!$paymentIntent)
        {
            $paymentIntent = $this->config->getStripeClient()->paymentIntents->create($params);
            $this->updateCache($quote->getId(), $paymentIntent, $order);

            if ($order)
            {
                $payment = $order->getPayment();
                $payment->setAdditionalInformation("payment_intent_id", $paymentIntent->id);
            }
        }

        return $this->paymentIntent = $paymentIntent;
    }

    protected function updateCache($quoteId, $paymentIntent, $order = null)
    {
        $this->setPiId($paymentIntent->id);
        $this->setQuoteId($quoteId);

        if ($order)
        {
            if ($order->getIncrementId())
                $this->setOrderIncrementId($order->getIncrementId());

            if ($order->getId())
                $this->setOrderId($order->getId());
        }

        $this->save();
    }

    protected function getPaymentMethodOptions($quote, $savePaymentMethod = null)
    {
        $sfuOptions = $captureOptions = [];

        if ($this->helper->isAdmin() && $savePaymentMethod)
        {
            $setupFutureUsage = "on_session";
        }
        else if ($savePaymentMethod === false)
        {
            $setupFutureUsage = "none";
        }
        else
        {
            // Get the default setting
            $setupFutureUsage = $this->config->getSetupFutureUsage($quote);
        }

        if ($setupFutureUsage)
        {
            $value = ["setup_future_usage" => $setupFutureUsage];

            $sfuOptions['card'] = $value;

            // For APMs, we can't use MOTO, so we switch them to off_session.
            if ($setupFutureUsage == "on_session" && $this->config->isAuthorizeOnly() && $this->config->retryWithSavedCard())
                $value = ["setup_future_usage" =>  "off_session"];

            $canBeSavedOnSession = \StripeIntegration\Payments\Helper\PaymentMethod::CAN_BE_SAVED_ON_SESSION;
            foreach ($canBeSavedOnSession as $code)
            {
                if (isset($sfuOptions[$code]))
                    continue;

                $sfuOptions[$code] = $value;
            }

            // The following methods do not display if we request an on_session setup
            $value = ["setup_future_usage" => "off_session"];
            $canBeSavedOffSession = \StripeIntegration\Payments\Helper\PaymentMethod::CAN_BE_SAVED_OFF_SESSION;
            foreach ($canBeSavedOffSession as $code)
            {
                if (isset($sfuOptions[$code]))
                    continue;

                $sfuOptions[$code] = $value;
            }
        }

        if ($this->config->isAuthorizeOnly($quote))
        {
            $value = [ "capture_method" => "manual" ];

            foreach (\StripeIntegration\Payments\Helper\PaymentMethod::CAN_AUTHORIZE_ONLY as $pmCode)
            {
                $captureOptions[$pmCode] = $value;
            }
        }

        $wechatOptions["wechat_pay"]["client"] = $this->paymentIntentHelper->getWechatClient();

        return array_merge_recursive($sfuOptions, $captureOptions, $wechatOptions);
    }

    public function getMultishippingParamsFrom($quote, $orders, $paymentMethodId)
    {
        $amount = 0;
        $currency = null;
        $orderIncrementIds = [];

        foreach ($orders as $order)
        {
            $amount += round(floatval($order->getGrandTotal()), 2);
            $currency = $order->getOrderCurrencyCode();
            $orderIncrementIds[] = $order->getIncrementId();
        }

        $cents = 100;
        if ($this->helper->isZeroDecimal($currency))
            $cents = 1;

        $params['amount'] = round(floatval($amount * $cents));
        $params['currency'] = strtolower($currency);
        $params['capture_method'] = $this->config->getCaptureMethod();

        if ($usage = $this->config->getSetupFutureUsage($quote))
            $params['setup_future_usage'] = $usage;

        $params['payment_method'] = $paymentMethodId;

        $this->setCustomerFromPaymentMethodId($paymentMethodId);

        if (!$this->customer->getStripeId())
        {
            $this->customer->createStripeCustomerIfNotExists();
        }

        if ($this->customer->getStripeId())
            $params["customer"] = $this->customer->getStripeId();

        $params["description"] = $this->helper->getMultishippingOrdersDescription($quote, $orders);
        $params["metadata"] = $this->config->getMultishippingMetadata($quote, $orders);

        $customerEmail = $quote->getCustomerEmail();
        if ($customerEmail && $this->config->isReceiptEmailsEnabled())
            $params["receipt_email"] = $customerEmail;

        return $params;
    }

    public function setCustomerFromPaymentMethodId($paymentMethodId, $order = null)
    {
        $paymentMethod = $this->stripePaymentMethod->fromPaymentMethodId($paymentMethodId)->getStripeObject();
        if (!empty($paymentMethod->customer))
        {
            $customer = $this->helper->getCustomerModelByStripeId($paymentMethod->customer);
            if (!$customer)
            {

                $this->customer->createStripeCustomer($order, ["id" => $paymentMethod->customer]);
            }
            else
            {
                $this->customer = $customer;
            }
        }
    }

    public function getParamsFrom($quote, $order = null, $paymentMethodId = null)
    {
        if (!empty($this->customParams))
            return $this->customParams;

        if ($order)
        {
            $amount = $order->getGrandTotal();
            $currency = $order->getOrderCurrencyCode();
            $savePaymentMethod = (bool)$order->getPayment()->getAdditionalInformation("save_payment_method");

            if (empty($paymentMethodId) && $order->getPayment() && $order->getPayment()->getAdditionalInformation("token"))
            {
                $paymentMethodId = $order->getPayment()->getAdditionalInformation("token");
            }
        }
        else
        {
            $amount = $quote->getGrandTotal();
            $currency = $quote->getQuoteCurrencyCode();
            $savePaymentMethod = null;
        }

        $cents = 100;
        if ($this->helper->isZeroDecimal($currency))
            $cents = 1;

        $params['amount'] = round(floatval($amount * $cents));
        $params['currency'] = strtolower($currency);
        $params['automatic_payment_methods'] = [ 'enabled' => 'true' ];

        $statementDescriptor = $this->config->getStatementDescriptor();
        if (!empty($statementDescriptor))
            $params["statement_descriptor"] = $statementDescriptor;

        if ($paymentMethodId)
        {
            $params['payment_method'] = $paymentMethodId;
            $this->setCustomerFromPaymentMethodId($paymentMethodId, $order);
        }

        if (!$this->customer->getStripeId())
        {
            if ($this->helper->isCustomerLoggedIn() || $this->config->alwaysSaveCards())
            {
                $this->customer->createStripeCustomerIfNotExists(false, $order);
            }
        }

        if ($this->customer->getStripeId())
            $params["customer"] = $this->customer->getStripeId();

        if ($order)
        {
            $params["description"] = $this->helper->getOrderDescription($order);
            $params["metadata"] = $this->config->getMetadata($order);
        }
        else
        {
            $params["description"] = $this->helper->getQuoteDescription($quote);
        }

        $params['amount'] = $this->adjustAmountForSubscriptions($params['amount'], $params['currency'], $quote, $order);

        $shipping = $this->getShippingAddressFrom($quote, $order);
        if ($shipping)
            $params['shipping'] = $shipping;
        else if (isset($params['shipping']))
            unset($params['shipping']);

        if ($order)
            $customerEmail = $order->getCustomerEmail();
        else
            $customerEmail = $quote->getCustomerEmail();

        if ($customerEmail && $this->config->isReceiptEmailsEnabled())
            $params["receipt_email"] = $customerEmail;

        if ($this->config->isLevel3DataEnabled())
        {
            $level3Data = $this->helper->getLevel3DataFrom($order);
            if ($level3Data)
                $params["level3"] = $level3Data;
        }

        return $params;
    }

    // Adds initial fees, or removes item amounts if there is a trial set
    protected function adjustAmountForSubscriptions($amount, $currency, $quote, $order = null)
    {
        $cents = 100;
        if ($this->helper->isZeroDecimal($currency))
            $cents = 1;

        if ($order)
        {
            $subscription = $this->subscriptionsHelper->getSubscriptionFromOrder($order);
        }
        else
        {
            $subscription = $this->subscriptionsHelper->getSubscriptionFromQuote($quote);
        }

        $subscriptionsTotal = 0;
        if (!empty($subscription['profile']))
        {
            $subscriptionsTotal += $this->subscriptionsHelper->getSubscriptionTotalFromProfile($subscription['profile']);
        }

        $finalAmount = round(floatval((($amount/$cents) - $subscriptionsTotal) * $cents));
        return max(0, $finalAmount);
    }

    public function getClientSecret($paymentIntent = null)
    {
        if (empty($paymentIntent))
            $paymentIntent = $this->paymentIntent;

        if (empty($paymentIntent))
            return null;

        return $paymentIntent->client_secret;
    }

    public function getStatus()
    {
        if (empty($this->paymentIntent))
            return null;

        return $this->paymentIntent->status;
    }

    public function getPaymentIntentID()
    {
        if (empty($this->paymentIntent))
            return null;

        return $this->paymentIntent->id;
    }

    // Returns true if the payment intent:
    // a) is in a state that cannot be used for a purchase
    // b) a parameter that cannot be updated has changed
    public function isInvalid($params, $quote, $order, $paymentIntent)
    {
        if ($params['amount'] <= 0)
        {
            return true;
        }

        if (empty($paymentIntent))
        {
            return true;
        }

        if ($paymentIntent->status == $this::CANCELED)
        {
            return true;
        }

        // You cannot modify `customer` on a PaymentIntent once it already has been set. To fulfill a payment with a different Customer,
        // cancel this PaymentIntent and create a new one.
        if (!empty($paymentIntent->customer))
        {
            if (empty($params["customer"]) || $paymentIntent->customer != $params["customer"])
            {
                return true;
            }
        }

        // You passed an empty string for 'shipping'. We assume empty values are an attempt to unset a parameter; however 'shipping'
        // cannot be unset. You should remove 'shipping' from your request or supply a non-empty value.
        if (!empty($paymentIntent->shipping))
        {
            if (empty($params["shipping"]))
            {
                return true;
            }
        }

        // Case where the user navigates to the standard checkout, the PI is created,
        // and then the customer switches to multishipping checkout.
        if ($this->helper->isMultiShipping())
        {
            if (!empty($paymentIntent->automatic_payment_methods))
            {
                return true;
            }
        }
        // ...and vice versa
        else
        {
            if (empty($paymentIntent->automatic_payment_methods))
            {
                return true;
            }
        }

        if ($this->paymentIntentHelper->isSuccessful($paymentIntent) || $this->paymentIntentHelper->requiresOfflineAction($paymentIntent))
        {
            $expectedValues = [];
            $updateableValues = ['description', 'metadata'];

            foreach ($params as $key => $value)
            {
                if (in_array($key, $updateableValues))
                    continue;

                $expectedValues[$key] = $value;
            }

            $ignoreValues = [
                'payment_method_options', // Despite passing these, the returned PI may not support some of the passed payment methods
                'payment_method' // In automated tests, ignore PM tokens as these change on the actual PI
            ];

            foreach ($params as $key => $value)
            {
                if (in_array($key, $ignoreValues))
                    unset($expectedValues[$key]);
            }

            if ($this->compare->isDifferent($paymentIntent, $expectedValues))
            {
                return true;
            }
        }

        return false;
    }

    public function updateFrom($paymentIntent, $params, $quote, $order, $cache = true)
    {
        if (empty($quote))
            return null;

        if ($this->isDifferentFrom($paymentIntent, $params, $quote, $order))
        {
            $paymentIntent = $this->updateStripeObject($paymentIntent, $params);

            if ($cache)
                $this->updateCache($quote->getId(), $paymentIntent, $order);
        }

        return $this->paymentIntent = $paymentIntent;
    }

    public function updateStripeObject($paymentIntent, $params)
    {
        $updateParams = $this->paymentIntentHelper->getFilteredParamsForUpdate($params, $paymentIntent);

        return $this->config->getStripeClient()->paymentIntents->update($paymentIntent->id, $updateParams);
    }

    public function destroy($quoteId, $cancelPaymentIntent = false, $paymentIntent = null)
    {
        if (!$paymentIntent)
            $paymentIntent = $this->paymentIntent;

        $this->paymentIntent = null;
        $this->delete();
        $this->clearInstance();
        /** @var \StripeIntegration\Payments\Model\ResourceModel\PaymentIntent\Collection $collection */
        $collection = $this->getCollection();
        $collection->deleteForQuoteId($quoteId);

        if ($paymentIntent && $cancelPaymentIntent && $this->canCancel($paymentIntent))
            $paymentIntent->cancel();

        $this->customParams = [];
    }

    protected function _clearData()
    {
        $this->setPiId(null);
        $this->setQuoteId(null);
        $this->setOrderIncrementId(null);
        $this->setInvoiceId(null);
        $this->setCustomerId(null);
        $this->setOrderId(null);
        $this->setPmId(null);

        return $this;
    }

    public function isDifferentFrom($paymentIntent, $params, $quote, $order = null)
    {
        $expectedValues = [];

        foreach ($this->paymentIntentHelper->getUpdateableParams($params, $paymentIntent) as $key)
        {
            if (empty($params[$key]))
                $expectedValues[$key] = "unset";
            else
                $expectedValues[$key] = $params[$key];
        }

        return $this->compare->isDifferent($paymentIntent, $expectedValues);
    }

    public function getShippingAddressFrom($quote, $order = null)
    {
        if ($order)
            $obj = $order;
        else if ($quote)
            $obj = $quote;
        else
            throw new \Exception("No quote or order specified");

        if (!$obj || $obj->getIsVirtual())
            return null;

        $address = $obj->getShippingAddress();

        if (empty($address))
            return null;

        // This is the case where we only have the quote
        if (empty($address->getFirstname()))
            $address = $this->addressFactory->create()->load($address->getAddressId());

        if (empty($address->getFirstname()))
            return null;

        return $this->addressHelper->getStripeShippingAddressFromMagentoAddress($address);
    }

    public function requiresAction($paymentIntent = null)
    {
        if (empty($paymentIntent))
            $paymentIntent = $this->paymentIntent;

        return (
            !empty($paymentIntent->status) &&
            $paymentIntent->status == $this::REQUIRES_ACTION
        );
    }

    public function getConfirmParams($order, $paymentIntent, $includeCvcToken = false)
    {
        $confirmParams = [
            "use_stripe_sdk" => true
        ];

        $savePaymentMethod = null;
        $paymentMethod = null;
        if ($order->getPayment()->getAdditionalInformation("token"))
        {
            // We are using a saved payment method token
            $confirmParams["payment_method"] = $order->getPayment()->getAdditionalInformation("token");

            $paymentMethod = $this->stripePaymentMethod->fromPaymentMethodId($confirmParams["payment_method"])->getStripeObject();
            if (!empty($paymentMethod->customer))
            {
                $savePaymentMethod = false;
            }
        }

        if (!empty($paymentIntent->automatic_payment_methods->enabled))
            $confirmParams["return_url"] = $this->helper->getUrl('stripe/payment/index');

        $quote = $this->helper->loadQuoteById($order->getQuoteId());
        $options = $this->getPaymentMethodOptions($quote, $savePaymentMethod);
        if (!empty($options))
        {
            $confirmParams["payment_method_options"] = $options;
        }

        if ($this->helper->isAdmin())
        {
            if (!$this->cache->load("no_moto_gate"))
            {
                $confirmParams["payment_method_options"]["card"]["moto"] = "true";
                if ($order->getPayment()->getAdditionalInformation("save_payment_method"))
                {
                    // Override the existing value
                    $confirmParams["payment_method_options"]["card"]["setup_future_usage"] = "off_session";
                }
            }
            else
            {
                $confirmParams["off_session"] = true;
            }
        }

        if ($includeCvcToken && $order->getPayment()->getAdditionalInformation("cvc_token") && $paymentIntent->object != "setup_intent")
        {
            $confirmParams["payment_method_options"]["card"]['cvc_token'] = $order->getPayment()->getAdditionalInformation("cvc_token");
        }

        $mandateData = $this->paymentIntentHelper->getMandateData($paymentMethod, $paymentIntent);
        if (!empty($mandateData))
        {
            $confirmParams = array_merge($confirmParams, $mandateData);
        }

        return $confirmParams;
    }

    public function confirm($paymentIntent, $confirmParams)
    {
        try
        {
            $this->paymentIntent = $paymentIntent;

            try
            {
                $result = $this->config->getStripeClient()->paymentIntents->confirm($paymentIntent->id, $confirmParams);
                $this->stripePaymentIntent->fromObject($result);
            }
            catch (\Stripe\Exception\InvalidRequestException $e)
            {
                if (!$this->dataHelper->isMOTOError($e->getError()))
                    throw $e;

                $this->cache->save($value = "1", $key = "no_moto_gate", ["stripe_payments"], $lifetime = 6 * 60 * 60);
                unset($confirmParams['payment_method_options']['card']['moto']);
                $result = $this->config->getStripeClient()->paymentIntents->confirm($paymentIntent->id, $confirmParams);
                $this->stripePaymentIntent->fromObject($result);
            }

            if ($this->requiresAction($result))
                throw new SCANeededException("Authentication Required: " . $paymentIntent->client_secret);

            return $this->paymentIntent = $result;
        }
        catch (SCANeededException $e)
        {
            if ($this->helper->isAdmin())
                $this->helper->dieWithError(__("This payment method cannot be used because it requires a customer authentication. To avoid authentication in the admin area, please contact Stripe support to request access to the MOTO gate for your Stripe account."));

            if ($this->helper->isMultiShipping())
                throw $e;

            // Front-end case (Payment Request API, REST API, GraphQL API), this will trigger the 3DS modal.
            $this->helper->dieWithError($e->getMessage());
        }
        catch (\Exception $e)
        {
            $this->helper->dieWithError($e->getMessage(), $e);
        }
    }

    public function setTransactionDetails(\Magento\Payment\Model\InfoInterface $payment, $intent)
    {
        $payment->setTransactionId($intent->id);
        $payment->setLastTransId($intent->id);
        $payment->setIsTransactionClosed(0);
        $payment->setIsFraudDetected(false);

        if (!empty($intent->charges->data[0]))
        {
            $charge = $intent->charges->data[0];

            if ($this->config->isStripeRadarEnabled() &&
                isset($charge->outcome->type) &&
                $charge->outcome->type == 'manual_review')
            {
                $payment->setAdditionalInformation("stripe_outcome_type", $charge->outcome->type);
            }

            $payment->setIsTransactionPending(false);
            $payment->setAdditionalInformation("is_transaction_pending", false); // this is persisted

            if ($intent->charges->data[0]->captured == false)
                $payment->setIsTransactionClosed(false);
            else
                $payment->setIsTransactionClosed(true);
        }
        else
        {
            $payment->setIsTransactionPending(true);
            $payment->setAdditionalInformation("is_transaction_pending", true); // this is persisted
        }

        // Let's save the Stripe customer ID on the order's payment in case the customer registers after placing the order
        if (!empty($intent->customer))
            $payment->setAdditionalInformation("customer_stripe_id", $intent->customer);
    }

    public function processSuccessfulOrder($order, $intent)
    {
        $this->setTransactionDetails($order->getPayment(), $intent);

        $shouldCreateInvoice = $order->canInvoice() && $this->config->isAuthorizeOnly() && $this->config->isAutomaticInvoicingEnabled();

        if ($shouldCreateInvoice)
        {
            $invoice = $order->prepareInvoice();
            $invoice->setTransactionId($intent->id);
            $invoice->register();
            $order->addRelatedObject($invoice);
        }
    }

    public function processPendingOrder($order, $intent)
    {
        $payment = $order->getPayment();

        if (!empty($intent->customer))
            $payment->setAdditionalInformation("customer_stripe_id", $intent->customer);

        $payment->setIsTransactionClosed(0);
        $payment->setIsFraudDetected(false);
        $payment->setIsTransactionPending(true); // not authorized yet
        $payment->setAdditionalInformation("is_transaction_pending", true); // this is persisted
        $order->setCanSendNewEmailFlag(false);

        if (strpos($intent->id, "seti_") === 0 && in_array($intent->status, ['processing', 'succeeded']))
        {
            $payment->setTransactionId("cannot_capture_subscriptions");
        }
        else if (strpos($intent->id, "pi_") === 0)
        {
            $payment->setTransactionId($intent->id);
        }
    }

    public function processTrialSubscriptionOrder($order, $subscription)
    {
        $payment = $order->getPayment();
        $payment->setAdditionalInformation("customer_stripe_id", $subscription->customer);
        $payment->setAdditionalInformation("is_trial_subscription_setup", true);
        $payment->setTransactionId(null);
        $payment->setIsTransactionPending(false);
        $payment->setAdditionalInformation("is_transaction_pending", false); // this is persisted
        $payment->setIsTransactionClosed(true);
        $payment->setIsFraudDetected(false);
    }

    public function processFutureSubscriptionOrder($order, $subscription)
    {
        $payment = $order->getPayment();
        $payment->setAdditionalInformation("customer_stripe_id", $subscription->customer);
        $payment->setAdditionalInformation("is_future_subscription_setup", true);
        $payment->setTransactionId(null);
        $payment->setIsTransactionPending(true);
        $payment->setAdditionalInformation("is_transaction_pending", true); // this is persisted
        $payment->setIsTransactionClosed(false);
        $payment->setIsFraudDetected(false);
    }

    public function updateData($paymentIntentId, $order)
    {
        $this->load($paymentIntentId, 'pi_id');

        $this->setPiId($paymentIntentId);
        $this->setQuoteId($order->getQuoteId());
        $this->setOrderIncrementId($order->getIncrementId());
        $customerId = $order->getCustomerId();
        if (!empty($customerId))
            $this->setCustomerId($customerId);
        $this->setPmId($order->getPayment()->getAdditionalInformation("token"));
        $this->save();
    }
}
