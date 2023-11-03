<?php

namespace StripeIntegration\Payments\Model;

use StripeIntegration\Payments\Exception;
use Magento\Framework\Exception\LocalizedException;

class CheckoutSession extends \Magento\Framework\Model\AbstractModel
{
    protected ?\StripeIntegration\Payments\Model\Stripe\Checkout\Session $stripeCheckoutSession = null;
    protected $stripeCheckoutSessionFactory;
    protected \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper;
    protected \StripeIntegration\Payments\Helper\CheckoutSession $checkoutSessionHelper;
    protected \StripeIntegration\Payments\Helper\Generic $paymentsHelper;
    protected $localeHelper;
    protected $stripeCouponFactory;
    protected $customer;
    protected $config;
    protected $paymentIntent;
    protected $scopeConfig;
    protected $stripeProductFactory;
    protected $stripePriceFactory;
    protected $compare;
    protected $startDateFactory;
    protected $quote = null;
    protected $order = null;
    protected $stripeCustomer;

    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb $resourceCollection
     * @param array $data
     */
    public function __construct(
        \StripeIntegration\Payments\Model\Stripe\Checkout\SessionFactory $stripeCheckoutSessionFactory,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\CheckoutSession $checkoutSessionHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Generic $paymentsHelper,
        \StripeIntegration\Payments\Helper\Locale $localeHelper,
        \StripeIntegration\Payments\Model\Stripe\CouponFactory $stripeCouponFactory,
        \StripeIntegration\Payments\Model\PaymentIntent $paymentIntent,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \StripeIntegration\Payments\Model\Stripe\ProductFactory $stripeProductFactory,
        \StripeIntegration\Payments\Model\Stripe\PriceFactory $stripePriceFactory,
        \StripeIntegration\Payments\Helper\Compare $compare,
        \StripeIntegration\Payments\Model\Subscription\StartDateFactory $startDateFactory,
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->stripeCheckoutSessionFactory = $stripeCheckoutSessionFactory;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->checkoutSessionHelper = $checkoutSessionHelper;
        $this->config = $config;
        $this->paymentsHelper = $paymentsHelper;
        $this->localeHelper = $localeHelper;
        $this->stripeCouponFactory = $stripeCouponFactory;
        $this->customer = $this->paymentsHelper->getCustomerModel();
        $this->paymentIntent = $paymentIntent;
        $this->scopeConfig = $scopeConfig;
        $this->stripeProductFactory = $stripeProductFactory;
        $this->stripePriceFactory = $stripePriceFactory;
        $this->compare = $compare;
        $this->startDateFactory = $startDateFactory;
        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\ResourceModel\CheckoutSession');
    }

    public function fromQuote($quote): CheckoutSession
    {
        try
        {
            $this->quote = $quote;

            if (empty($quote) || empty($quote->getId()))
                return $this;

            $this->load($quote->getId(), 'quote_id');

            if ($this->getCheckoutSessionId())
            {
                $this->stripeCheckoutSession  = $this->stripeCheckoutSessionFactory->create()->load($this->getCheckoutSessionId());

                $params = $this->getParamsFromQuote($quote);

                $checkoutSession = $this->stripeCheckoutSession->getStripeObject();
                if ($this->hasChanged($params))
                {
                    $this->cancelOrder(__("The customer returned from Stripe and changed the cart details."));
                    $this->cancelCheckoutSession();
                    $this->createFromQuote($quote);
                }
                else if ($this->hasExpired())
                {
                    $this->cancelOrder(__("The customer left from the payment page without paying."));
                    $this->cancelCheckoutSession();
                    $this->createFromQuote($quote);
                }

                return $this;
            }

            return $this;
        }
        catch (\Exception $e)
        {
            $this->paymentsHelper->logError($e->getMessage(), $e->getTraceAsString());
            $this->setData([]);
            return $this;
        }
    }

    // Creates a session if one does not exist
    public function fromOrder($order, $cancelOldOrder = false)
    {
        $this->quote = null;
        $this->order = $order;

        if (empty($order))
        {
            throw new \Exception('Invalid Stripe Checkout order.');
        }

        $this->load($order->getQuoteId(), 'quote_id');

        $this->quote = $this->paymentsHelper->loadQuoteById($order->getQuoteId());

        if (empty($this->quote) || empty($this->quote->getId()))
        {
            throw new \Exception('Could not find quote for order.');
        }

        if ($this->getCheckoutSessionId())
        {
            $this->stripeCheckoutSession  = $this->stripeCheckoutSessionFactory->create()->load($this->getCheckoutSessionId());
        }
        else
        {
            $this->stripeCheckoutSession = $this->stripeCheckoutSessionFactory->create();
        }

        if ($cancelOldOrder && $this->getOrderIncrementId() && $this->getOrderIncrementId() != $order->getIncrementId())
        {
            $oldOrder = $this->paymentsHelper->loadOrderByIncrementId($this->getOrderIncrementId());
            $comment = __("The cart contents or customer details have changed. The order is canceled because a new one will be placed (#%1) with the new details.", $order->getIncrementId());
            $oldOrder->addStatusToHistory($status = false, $comment, $isCustomerNotified = false);
            $this->paymentsHelper->cancelOrCloseOrder($oldOrder);
        }

        $params = $this->getParamsFromOrder($order);
        $stripeCheckoutSessionObject = $this->stripeCheckoutSession->getStripeObject();

        if (!$stripeCheckoutSessionObject)
        {
            $this->stripeCheckoutSession->fromParams($params);
            $stripeCheckoutSessionObject = $this->stripeCheckoutSession->getStripeObject();
        }
        else if ($this->hasChanged($params))
        {
            $this->cancelCheckoutSession();
            $this->stripeCheckoutSession->fromParams($params);
            $stripeCheckoutSessionObject = $this->stripeCheckoutSession->getStripeObject();
        }
        else if (!empty($params["payment_intent_data"]) && !empty($stripeCheckoutSessionObject->payment_intent))
        {
            /** @var \Stripe\PaymentIntent $paymentIntent */
            $paymentIntent = $stripeCheckoutSessionObject->payment_intent;
            $updateParams = $this->checkoutSessionHelper->getPaymentIntentUpdateParams($params["payment_intent_data"], $paymentIntent);
            if (!empty($paymentIntent->id))
                $this->config->getStripeClient()->paymentIntents->update($paymentIntent->id, $updateParams);
        }

        $this->setQuoteId($this->quote->getId());
        $this->setOrderIncrementId($order->getIncrementId());
        $this->setCheckoutSessionId($this->stripeCheckoutSession->getId());
        $this->save();

        return $this;
    }

    protected function createFromQuote($quote)
    {
        $params = $this->getParamsFromQuote($quote);
        if (!empty($params["payment_intent_data"])) // In subscription mode, this is not set
            $params["payment_intent_data"]["description"] = $this->paymentsHelper->getQuoteDescription($quote);

        $this->stripeCheckoutSession = $this->stripeCheckoutSessionFactory->create();
        $checkoutSession = $this->stripeCheckoutSession->fromParams($params)->getStripeObject();
        $this->setCheckoutSessionId($checkoutSession->id)
            ->setQuoteId($quote->getId())
            ->save();
    }

    public function getAvailablePaymentMethods($quote)
    {
        $methods = [];

        if (empty($quote) || empty($quote->getId()))
            return $methods;

        try
        {
            $checkoutSession = $this->fromQuote($quote)->getStripeObject();

            if (!$checkoutSession)
            {
                $this->createFromQuote($quote);
                $checkoutSession = $this->stripeCheckoutSession->getStripeObject();
            }

            if (!empty($checkoutSession->payment_method_types))
                $methods = $checkoutSession->payment_method_types;

            return $methods;
        }
        catch (\Exception $e)
        {
            $this->paymentsHelper->logError($e->getMessage());
            throw $e;
        }
    }

    public function updateCustomerEmail($email)
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        {
            // The email is invalid
            return;
        }

        if (!$this->config->isEnabled() || !$this->config->isRedirectPaymentFlow())
        {
            return;
        }

        if ($this->paymentsHelper->isCustomerLoggedIn())
        {
            // No need to update logged in customers
            return;
        }

        $quote = $this->paymentsHelper->getQuote();
        $this->fromQuote($quote);

        if (!$this->getCheckoutSessionId() || !$this->stripeCheckoutSession)
        {
            return;
        }
        else
        {
            /** @var \Stripe\Checkout\Session $checkoutSession */
            $checkoutSession = $this->stripeCheckoutSession->load($this->getCheckoutSessionId())->getStripeObject();
        }

        if (!empty($checkoutSession->customer_details->email) && $email != $checkoutSession->customer_details->email)
        {
            if ($checkoutSession->customer)
            {
                $this->config->getStripeClient()->customers->update($checkoutSession->customer, [
                    'email' => $email
                ]);
            }
        }
    }

    public function getStripeObject()
    {
        if (empty($this->stripeCheckoutSession))
            return null;

        return $this->stripeCheckoutSession->getStripeObject();
    }

    protected function getParamsFromOrder($order)
    {
        $subscription = $this->subscriptionsHelper->getSubscriptionFromOrder($order);
        $lineItems = $this->getLineItems($subscription, $order->getGrandTotal(), $order->getOrderCurrencyCode());
        $params = $this->getParamsFrom($lineItems, $subscription, $order->getQuote(), $order);

        return $params;
    }

    protected function getParamsFromQuote($quote)
    {
        if (empty($quote))
            throw new \Exception("No quote specified for Checkout params.");

        $subscription = $this->subscriptionsHelper->getSubscriptionFromQuote($quote);
        $lineItems = $this->getLineItems($subscription, $quote->getGrandTotal(), $quote->getQuoteCurrencyCode());
        $params = $this->getParamsFrom($lineItems, $subscription, $quote);

        if ($params["mode"] == 'payment') {
            $stripeCheckoutOnSession = \StripeIntegration\Payments\Helper\PaymentMethod::STRIPE_CHECKOUT_ON_SESSION_PM;
            $value = ['setup_future_usage' => 'on_session'];
            foreach ($stripeCheckoutOnSession as $code)
            {
                $params["payment_method_options"][$code] = $value;
            }

            $stripeCheckoutOffSession = \StripeIntegration\Payments\Helper\PaymentMethod::STRIPE_CHECKOUT_OFF_SESSION_PM;
            $value = ['setup_future_usage' => 'off_session'];
            foreach ($stripeCheckoutOffSession as $code)
            {
                $params["payment_method_options"][$code] = $value;
            }

            $stripeCheckoutNoneSession = \StripeIntegration\Payments\Helper\PaymentMethod::STRIPE_CHECKOUT_NONE_PM;
            $value = ['setup_future_usage' => 'none'];
            foreach ($stripeCheckoutNoneSession as $code)
            {
                $params["payment_method_options"][$code] = $value;
            }
        }

        return $params;
    }

    protected function getLineItems($subscription, $grandTotal, $currencyCode)
    {
        $currency = strtolower($currencyCode);
        $lines = [];

        if (empty($subscription['profile']))
        {
            $oneTimePayment = $this->getOneTimePayment($grandTotal, $currency);
            if ($oneTimePayment)
                $lines[] = $oneTimePayment;
        }
        else
        {
            $profile = $subscription['profile'];
            $subscriptionsProductIDs[] = $subscription['product']->getId();
            $interval = $profile['interval'];
            $intervalCount = $profile['interval_count'];

            $subscriptionTotal = $this->subscriptionsHelper->getSubscriptionTotalFromProfile($profile);
            $subscriptionTotal = round(floatval($subscriptionTotal), 2);

            $remainingAmount = $grandTotal - $subscriptionTotal;

            $oneTimePayment = $this->getOneTimePayment($remainingAmount, $currency, true);
            if ($oneTimePayment)
                $lines[] = $oneTimePayment;

            $recurringPayment = $this->getRecurringPayment($subscription, $subscriptionsProductIDs, $subscriptionTotal, $currency, $interval, $intervalCount);
            if ($recurringPayment)
                $lines[] = $recurringPayment;
        }

        return $lines;
    }

    protected function getParamsFrom($lineItems, $subscription, $quote, $order = null)
    {
        $returnUrl = $this->paymentsHelper->getUrl('stripe/payment/index', ["payment_method" => "stripe_checkout"]);
        $cancelUrl = $this->paymentsHelper->getUrl('stripe/payment/cancel', ["payment_method" => "stripe_checkout"]);

        $params = [
            'expires_at' => $this->getExpirationTime(),
            'cancel_url' => $cancelUrl,
            'success_url' => $returnUrl,
            'locale' => $this->localeHelper->getStripeCheckoutLocale()
        ];

        if (!empty($subscription))
        {
            $params["mode"] = "subscription";
            $params["line_items"] = $lineItems;
            $params["subscription_data"] = [
                "metadata" => $this->subscriptionsHelper->collectMetadataForSubscription($quote, $subscription, $order)
            ];

            $profile = $subscription['profile'];

            if ($profile['expiring_coupon'])
            {
                $coupon = $this->stripeCouponFactory->create()->fromSubscriptionProfile($profile);
                if ($coupon->getId())
                {
                    $params['discounts'][] = ['coupon' => $coupon->getId()];
                }
            }

            $startDateModel = $this->startDateFactory->create()->fromProfile($profile);
            $hasOneTimePayment = false;
            foreach($lineItems as $lineItem)
            {
                if ($this->isOneTimePayment($lineItem))
                {
                    $hasOneTimePayment = true;
                    break;
                }
            }
            if ($startDateModel->isCompatibleWithTrials($hasOneTimePayment))
            {
                if ($profile['trial_end'])
                {
                    $params['subscription_data']['trial_period_days'] = $startDateModel->getDaysUntilStartDate($profile['trial_end']);
                }
                else if ($profile['trial_days'])
                {
                    $params['subscription_data']['trial_period_days'] = $profile['trial_days'];
                }
            }

            $hasPhases = $startDateModel->hasPhases();
            $startDateParams = $startDateModel->getParams($hasOneTimePayment, true);
            $hasStartDate = !empty($startDateParams);

            if ($hasStartDate)
            {
                $params['subscription_data'] = array_merge_recursive($params['subscription_data'], $startDateParams);
            }

            $params["payment_method_options"] = $this->getPaymentMethodOptions();
        }
        else if ($this->config->getPaymentAction() == "order")
        {
            $params['mode'] = 'setup';
            $params['payment_method_types'] = ['card'];
        }
        else
        {
            $paymentIntentParams = $this->paymentIntent->getParamsFrom($quote, $order);
            $params["mode"] = "payment";
            $params["line_items"] = $lineItems;
            $params["payment_intent_data"] = $this->convertToPaymentIntentData($paymentIntentParams, $quote);
            $params["submit_type"] = "pay";
            $params["payment_method_options"] = $this->getPaymentMethodOptions();
        }

        if ($this->config->alwaysSaveCards())
        {
            try
            {
                $this->customer->createStripeCustomerIfNotExists(false, $order);
                $this->stripeCustomer = $this->customer->retrieveByStripeID();
                if (!empty($this->stripeCustomer->id))
                    $params['customer'] = $this->stripeCustomer->id;
            }
            catch (\Stripe\Exception\CardException $e)
            {
                throw new LocalizedException(__($e->getMessage()));
            }
            catch (\Exception $e)
            {
                $this->paymentsHelper->dieWithError(__('An error has occurred. Please contact us to complete your order.'), $e);
            }
        }
        else
        {
            if ($this->paymentsHelper->isCustomerLoggedIn())
                $this->customer->createStripeCustomerIfNotExists(false, $order);

            $this->stripeCustomer = $this->customer->retrieveByStripeID();
            if (!empty($this->stripeCustomer->id))
                $params['customer'] = $this->stripeCustomer->id;
            else if ($order)
                $params['customer_email'] = $order->getCustomerEmail();
            else if ($quote->getCustomerEmail())
                $params['customer_email'] = $quote->getCustomerEmail();
        }

        return $params;
    }

    protected function getPaymentMethodOptions()
    {
        return [
            "acss_debit" => [
                "mandate_options" => [
                    "payment_schedule" => "sporadic",
                    "transaction_type" => "personal"
                ]
            ],
            // "bacs_debit" => [
            //     "setup_future_usage" => "off_session"
            // ]
        ];
    }

    protected function getExpirationTime()
    {
        $storeId = $this->paymentsHelper->getStoreId();
        $cookieLifetime = $this->scopeConfig->getValue("web/cookie/cookie_lifetime", \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
        $oneHour = 1 * 60 * 60;
        $twentyFourHours = 24 * 60 * 60;
        $cookieLifetime = max($oneHour, $cookieLifetime);
        $cookieLifetime = min($twentyFourHours, $cookieLifetime);
        $timeDifference = $this->paymentsHelper->getStripeApiTimeDifference();

        return time() + $cookieLifetime + $timeDifference;
    }


    protected function getOneTimePayment($oneTimeAmount, $currency, $isUsedWithSubscription = false)
    {
        if ($oneTimeAmount > 0)
        {
            if ($isUsedWithSubscription)
            {
                $productId = "one_time_payment";
                $name = __("One time payment");
            }
            else
            {
                $productId = "amount_due";
                $name = __("Amount due");
            }

            $metadata = [
                'Type' => 'RegularProductsTotal',
            ];

            $stripeAmount = $this->paymentsHelper->convertMagentoAmountToStripeAmount($oneTimeAmount, $currency);

            $stripeProductModel = $this->stripeProductFactory->create()->fromData($productId, $name, $metadata);
            $stripePriceModel = $this->stripePriceFactory->create()->fromData($stripeProductModel->getId(), $stripeAmount, $currency);

            $lineItem = [
                'price' => $stripePriceModel->getId(),
                'quantity' => 1,
            ];

            return $lineItem;
        }

        return null;
    }

    protected function getRecurringPayment($subscription, $subscriptionsProductIDs, $allSubscriptionsTotal, $currency, $interval, $intervalCount)
    {
        if (!empty($subscription['profile']) && $allSubscriptionsTotal > 0)
        {
            $profile = $subscription['profile'];

            $interval = $profile['interval'];
            $intervalCount = $profile['interval_count'];
            $currency = $profile['currency'];
            $magentoAmount = $this->subscriptionsHelper->getSubscriptionTotalWithDiscountAdjustmentFromProfile($profile);
            $stripeAmount = $this->paymentsHelper->convertMagentoAmountToStripeAmount($magentoAmount, $currency);

            if (!empty($subscription['quote_item']))
            {
                $stripeProductModel = $this->stripeProductFactory->create()->fromQuoteItem($subscription['quote_item']);
            }
            else if (!empty($subscription['order_item']))
            {
                $stripeProductModel = $this->stripeProductFactory->create()->fromOrderItem($subscription['order_item']);
            }
            else
            {
                throw new LocalizedException(__("Could not create subscription product in Stripe."));
            }

            $stripePriceModel = $this->stripePriceFactory->create()->fromData($stripeProductModel->getId(), $stripeAmount, $currency, $interval, $intervalCount);

            $lineItem = [
                'price' => $stripePriceModel->getId(),
                'quantity' => 1,

            ];

            return $lineItem;
        }

        return null;
    }

    protected function convertToPaymentIntentData($data, $quote)
    {
        $supportedParams = ['application_fee_amount', 'capture_method', 'description', 'metadata', 'on_behalf_of', 'receipt_email', 'setup_future_usage', 'shipping', 'statement_descriptor', 'statement_descriptor_suffix', 'transfer_data', 'transfer_group'];

        $params = [];

        $data['capture_method'] = $this->config->getCaptureMethod();
        $futureUsage = $this->config->getSetupFutureUsage($quote);

        if ($futureUsage)
        {
            $data['setup_future_usage'] = $futureUsage;
        }

        foreach ($data as $key => $value)
            if (in_array($key, $supportedParams))
                $params[$key] = $value;

        return $params;
    }


    // Compares parameters which may affect which payment methods will be available at the Stripe Checkout landing page
    protected function hasChanged($params)
    {
        $checkoutSession = $this->stripeCheckoutSession->getStripeObject();

        if (empty($checkoutSession))
        {
            throw new \Exception("No Stripe Checkout session found.");
        }

        if ($params["mode"] == "subscription")
        {
            $comparisonParams = [
                "payment_intent" => "unset",
                "mode" => $params["mode"]
            ];
        }
        else if ($params["mode"] == "setup")
        {
            $comparisonParams = [
                "payment_intent" => "unset",
                "mode" => $params["mode"]
            ];
        }
        else
        {
            $comparisonParams = [
                "submit_type" => $params["submit_type"]
            ];

            if (!empty($params["payment_intent_data"]["capture_method"]))
                $comparisonParams["payment_intent"]["capture_method"] = $params["payment_intent_data"]["capture_method"];
            // else
                // is set as automatic or whatever the configured default is

            // Shipping country may affect payment methods
            if (!empty($params["payment_intent_data"]["shipping"]["address"]["country"]))
                $comparisonParams["payment_intent"]["shipping"]["address"]["country"] = $params["payment_intent_data"]["shipping"]["address"]["country"];
            else
                $comparisonParams["payment_intent"]["shipping"] = "unset";

            // Save customer card may affect payment methods
            if (!empty($params["payment_intent_data"]["setup_future_usage"]))
                $comparisonParams["payment_intent"]["setup_future_usage"] = $params["payment_intent_data"]["setup_future_usage"];
            else
                $comparisonParams["payment_intent"]["setup_future_usage"] = "unset";

            // Customer does not affect which payment methods are available, but it may do in the future based on Radar risk level or customer credit score
            if (!empty($params["customer"]))
                $comparisonParams["customer"] = $params["customer"];
        }

        if (!empty($params['subscription_data']))
        {
            if (!empty($checkoutSession->subscription))
            {
                throw new \Exception("Subscription data is not supported in this version.");
            }
        }

        if ($this->compare->isDifferent($checkoutSession, $comparisonParams))
        {
            return true;
        }

        if ($params['mode'] != "setup")
        {
            $lineItems = $this->config->getStripeClient()->checkout->sessions->allLineItems($checkoutSession->id, ['limit' => 100]);
            if (count($lineItems->data) != count($params['line_items']))
            {
                return true;
            }

            $comparisonParams = [];
            foreach ($lineItems->data as $i => $item)
            {
                $comparisonParams[$i] = [
                    'price' => [
                        'id' => $params['line_items'][$i]['price']
                    ],
                    'quantity' => $params['line_items'][$i]['quantity']
                ];

                if (!isset($params['line_items'][$i]['recurring']))
                    $comparisonParams[$i]['price']['recurring'] = "unset";
                else
                {
                    $comparisonParams[$i]['price']['recurring']['interval'] = $params['line_items'][$i]['recurring']['interval'];
                    $comparisonParams[$i]['price']['recurring']['interval_count'] = $params['line_items'][$i]['recurring']['interval_count'];
                }
            }

            if ($this->compare->isDifferent($lineItems->data, $comparisonParams))
            {
                return true;
            }
        }

        return false;
    }

    public function getOrder()
    {
        $orderIncrementId = $this->getOrderIncrementId();

        if (empty($orderIncrementId))
            return null;

        $order = $this->paymentsHelper->loadOrderByIncrementId($orderIncrementId);
        if ($order && $order->getId())
            return $order;

        return null;
    }

    protected function hasExpired()
    {
        $checkoutSession = $this->stripeCheckoutSession->getStripeObject();

        if (empty($checkoutSession))
        {
            throw new \Exception("No Stripe Checkout session found.");
        }

        return ($checkoutSession->status == "expired" || $checkoutSession->status == "complete");
    }

    protected function cancelOrder($orderComment)
    {
        if (!$this->getOrderIncrementId())
            return;

        $order = $this->paymentsHelper->loadOrderByIncrementId($this->getOrderIncrementId());
        if (!$order || !$order->getId())
            return;

        $state = \Magento\Sales\Model\Order::STATE_CANCELED;
        $status = $order->getConfig()->getStateDefaultStatus($state);
        $order->addStatusToHistory($status, $orderComment, $isCustomerNotified = false);
        $this->paymentsHelper->saveOrder($order);

        $this->setOrderIncrementId(null)->save();
    }

    protected function cancelCheckoutSession()
    {
        $checkoutSession = $this->stripeCheckoutSession->getStripeObject();

        if (empty($checkoutSession))
        {
            throw new \Exception("No Stripe Checkout session found.");
        }

        try
        {
            if ($this->canCancel())
            {
                $this->config->getStripeClient()->checkout->sessions->expire($checkoutSession->id, []);
                $this->setCheckoutSessionId(null)->save();
            }
        }
        catch (\Exception $e)
        {
            $this->paymentsHelper->logError("Cannot cancel checkout session: " . $e->getMessage());
        }
    }

    protected function canCancel()
    {
        $checkoutSession = $this->stripeCheckoutSession->getStripeObject();

        if (empty($checkoutSession))
        {
            return false;
        }

        if (in_array($checkoutSession->status, ["expired", "complete"]))
            return false;

        return true;
    }

    protected function isOneTimePayment($lineItem)
    {
        if (!empty($lineItem['price']['recurring']))
            return false;

        if (!empty($lineItem['price_data']['recurring']))
            return false;

        if (is_string($lineItem['price']))
        {
            $price = $this->config->getStripeClient()->prices->retrieve($lineItem['price'], []);
            if (!empty($price['recurring']))
                return false;
        }

        return true;
    }
}
