<?php

namespace StripeIntegration\Payments\Model;

use StripeIntegration\Payments\Helper\Data as DataHelper;
use StripeIntegration\Payments\Helper\Subscriptions;

class StripeCustomer extends \Magento\Framework\Model\AbstractModel
{
    // This is the Customer object, retrieved through the Stripe API
    var $_stripeCustomer = null;
    var $_defaultPaymentMethod = null;
    var $_subscriptions = [];

    public $customerCard = null;
    public $paymentMethodsCache = [];

    private $sessionManager;
    private $paymentMethodHelper;
    private $localeHelper;
    private $addressHelper;
    private $config;
    private $helper;
    private $customerSession;

    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb $resourceCollection
     * @param array $data
     */
    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Address $addressHelper,
        \StripeIntegration\Payments\Helper\Locale $localeHelper,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Framework\Session\SessionManagerInterface $sessionManager,
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->config = $config;
        $this->helper = $helper;
        $this->addressHelper = $addressHelper;
        $this->localeHelper = $localeHelper;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->sessionManager = $sessionManager;
        $this->customerSession = $customerSession;

        parent::__construct($context, $registry, $resource, $resourceCollection, $data); // This will also call _construct after DI logic
    }

    // Called by parent::__construct() after DI logic
    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\ResourceModel\StripeCustomer');
    }

    public function loadFromData($customerStripeId, $customerObject)
    {
        if (empty($customerObject))
            return null;

        if (empty($customerStripeId))
            return null;

        $this->load($customerStripeId, 'stripe_id');

        // For older orders placed by customers that are out of sync
        if (empty($this->getStripeId()))
        {
            $this->setStripeId($customerStripeId);
            $this->setLastRetrieved(time());
        }

        $this->_stripeCustomer = $customerObject;

        return $this;
    }

    public function updateSessionId()
    {
        if (!$this->getStripeId()) return;
        if ($this->helper->isAdmin()) return;

        $sessionId = $this->customerSession->getSessionId();
        if ($sessionId != $this->getSessionId())
        {
            $this->setSessionId($sessionId);
            $this->save();
        }
    }

    // Loads the customer from the Stripe API
    public function createStripeCustomerIfNotExists($skipCache = false, $order = null)
    {
        // If the payment method has not yet been selected, skip this step
        // $quote = $this->helper->checkoutSession;
        // $paymentMethod = $quote->getPayment()->getMethod();
        // if (empty($paymentMethod) || $paymentMethod != "stripe_payments") return;

        if (!$this->existsInStripe($skipCache))
        {
            $this->createStripeCustomer($order);
        }

        return $this->retrieveByStripeID();
    }

    public function existsInStripe($skipCache = false)
    {
        if (!$this->getStripeId())
            return false;

        $retrievedSecondsAgo = (time() - $this->getLastRetrieved());

        // if the customer was retrieved from Stripe in the last 10 minutes, we're good to go
        // otherwise retrieve them now to make sure they were not deleted from Stripe somehow
        if (!$skipCache && $retrievedSecondsAgo < (60 * 10))
            return true;

        if (!$this->retrieveByStripeID($this->getStripeId()))
            return false;

        return true;
    }

    public function createStripeCustomer($order = null, $extraParams = null)
    {
        $params = $this->getParams($order);

        if (!empty($extraParams['id']))
            $params['id'] = $extraParams['id'];

        return $this->createNewStripeCustomer($params);
    }

    public function getParams($order = null)
    {
        // Defaults
        $customerFirstname = "";
        $customerLastname = "";
        $customerEmail = "";
        $customerId = 0;

        $customer = $this->helper->getMagentoCustomer();

        if ($customer)
        {
            // Registered Magento customers
            $customerFirstname = $customer->getFirstname();
            $customerLastname = $customer->getLastname();
            $customerEmail = $customer->getEmail();
            $customerId = $customer->getEntityId();
        }
        else if ($order)
        {
            // Guest customers
            $address = $this->helper->getAddressFrom($order, 'billing');
            $customerFirstname = $address->getFirstname();
            $customerLastname = $address->getLastname();
            $customerEmail = $address->getEmail();
            $customerId = 0;
        }
        else
        {
            if ($order && $order->getQuoteId())
                $quote = $this->helper->getQuote($order->getQuoteId());
            else
                $quote = $this->helper->getQuote();

            if ($quote)
            {
                // Guest customer at checkout, with Always Save Cards enabled, or with subscriptions in the cart
                $address = $quote->getBillingAddress();
                $customerFirstname = $address->getFirstname();
                $customerLastname = $address->getLastname();
                $customerEmail = $address->getEmail();
                $customerId = 0;
            }
        }

        $params = [
            'magento_customer_id' => $customerId
        ];

        if (empty($customerFirstname) && empty($customerLastname))
            $params["name"] = "Guest";
        else
            $params["name"] = "$customerFirstname $customerLastname";

        if ($customerEmail)
            $params["email"] = $customerEmail;

        if ($this->getStripeId())
            $params["id"] = $this->getStripeId();

        return $params;
    }

    public function createNewStripeCustomer($params)
    {
        try
        {
            if (empty($params))
                return;

            $magentoCustomerId = $params['magento_customer_id'];
            unset($params['magento_customer_id']);

            if (!empty($params["id"]))
            {
                $stripeCustomerId = $params["id"];
                unset($params["id"]);
                try
                {
                    $this->_stripeCustomer = $this->config->getStripeClient()->customers->update($stripeCustomerId, $params);
                }
                catch (\Stripe\Exception\ApiErrorException $e)
                {
                    if ($e->getError()->code == "resource_missing")
                        $this->_stripeCustomer = \Stripe\Customer::create($params);
                }
            }
            else
            {
                $this->_stripeCustomer = \Stripe\Customer::create($params);
            }

            if (!$this->_stripeCustomer)
                return null;

            $this->sessionManager->setStripeCustomerId($this->_stripeCustomer->id);

            $this->setStripeId($this->_stripeCustomer->id);
            $this->setCustomerId($magentoCustomerId);

            $this->setLastRetrieved(time());

            if (!empty($params['email']))
                $this->setCustomerEmail($params['email']);

            $this->setPk($this->config->getPublishableKey());
            $this->updateSessionId();

            $this->save();

            return $this->_stripeCustomer;
        }
        catch (\Exception $e)
        {
            if ($this->helper->isStripeAPIKeyError($e->getMessage()))
            {
                $this->config->setIsStripeAPIKeyError(true);
                throw new \StripeIntegration\Payments\Exception\SilentException(__($e->getMessage()));
            }
            $msg = __('Could not set up customer profile: %1', $e->getMessage());
            $this->helper->dieWithError($msg, $e);
        }
    }

    public function getDefaultPaymentMethod()
    {
        if (isset($this->_defaultPaymentMethod))
            return $this->_defaultPaymentMethod;

        $customer = $this->retrieveByStripeID();

        if (empty($customer->invoice_settings->default_payment_method))
            return null;

        try
        {
            return $this->_defaultPaymentMethod = \Stripe\PaymentMethod::retrieve($customer->invoice_settings->default_payment_method);
        }
        catch (\Exception $e)
        {
            return null;
        }
    }

    public function retrieveByStripeID($id = null)
    {
        if (isset($this->_stripeCustomer))
            return $this->_stripeCustomer;

        if (empty($id))
            $id = $this->getStripeId();

        if (empty($id))
            return false;

        try
        {
            $this->_stripeCustomer = $this->config->getStripeClient()->customers->retrieve($id, []);
            $this->setLastRetrieved(time());
            $this->save();

            if (!$this->_stripeCustomer || ($this->_stripeCustomer && isset($this->_stripeCustomer->deleted) && $this->_stripeCustomer->deleted))
                return false;

            return $this->_stripeCustomer;
        }
        catch (\Exception $e)
        {
            if (strpos($e->getMessage(), "No such customer") === 0)
            {
                return $this->createStripeCustomer();
            }
            else
            {
                $this->helper->addError('Could not retrieve customer profile: '.$e->getMessage());
                return false;
            }
        }
    }

    public function deletePaymentMethod($token, $fingerprint = null)
    {
        if (!$this->_stripeCustomer)
            $this->_stripeCustomer = $this->retrieveByStripeID($this->getStripeId());

        if (!$this->_stripeCustomer)
            throw new \Exception("Customer with ID " . $this->getStripeId() . " could not be retrieved from Stripe.");

        // Deleting a payment method
        if (strpos($token, "pm_") === 0)
        {
            if ($fingerprint)
            {
                $allMethods = $this->getSavedPaymentMethods(null, false);
                $newestMethod = null;
                foreach ($allMethods as $type => $methodList)
                {
                    foreach ($methodList as $method)
                    {
                        $type = $method->type;
                        if ($method->{$type}->fingerprint != $fingerprint)
                            continue;

                        if (!$newestMethod || $method->created > $newestMethod->created)
                        {
                            $newestMethod = $this->config->getStripeClient()->paymentMethods->detach($method->id, []);
                        }
                        else
                        {
                            $this->config->getStripeClient()->paymentMethods->detach($method->id, []);
                        }
                    }
                }

                return $newestMethod;
            }
            else
            {
                return $this->config->getStripeClient()->paymentMethods->detach($token, []);
            }
        }
        else if (strpos($token, "src_") === 0 || strpos($token, "card_") === 0)
        {
            return $this->config->getStripeClient()->customers->deleteSource($this->getStripeId(), $token);
        }

        // If we have received a src_ token from an older version of the module
        throw new \Exception("This payment method could not be deleted.");
    }

    public function getSavedPaymentMethods($types = null, $formatted = false)
    {
        if (!$types)
        {
            $types = \StripeIntegration\Payments\Helper\PaymentMethod::CAN_BE_SAVED_ON_SESSION;
        }

        if (!$this->getStripeId())
            return [];

        $methods = [];

        foreach ($types as $type)
        {
            try
            {
                $result = $this->config->getStripeClient()->customers->allPaymentMethods($this->getStripeId(), ['type' => $type, 'limit' => 30]);
                if (!empty($result->data))
                {
                    $methods[$type] = $result->data;
                }
            }
            catch (\Exception $e)
            {
                $this->helper->logError("Cannot retrieve saved payment methods for customer {$this->getStripeId()}: " . $e->getMessage());
            }
        }

        if ($formatted)
        {
            return $this->paymentMethodHelper->formatPaymentMethods($methods);
        }
        else
        {
            return $methods;
        }
    }

    public function getOpenInvoices($params = null)
    {
        if (!$this->getStripeId())
            return [];

        $params['customer'] = $this->getStripeId();
        $params['expand'] = ['data.subscription', 'data.subscription.default_payment_method'];
        $params['status'] = 'open';
        $params['limit'] =  100;

        return $this->config->getStripeClient()->invoices->all($params);
    }

    public function getUpcomingInvoices($params = null)
    {
        if (!$this->getStripeId())
            return [];

        $params['customer'] = $this->getStripeId();
        $params['expand'] = ['subscription', 'subscription.default_payment_method', 'lines.data.price.product'];

        return $this->config->getStripeClient()->invoices->upcoming($params);
    }

    public function getSubscriptionItems($subscriptionId)
    {
        if (empty($subscriptionId))
            return [];

        $params['subscription'] = $subscriptionId;
        $params['expand'] = ['data.price.product'];

        return $this->config->getStripeClient()->subscriptionItems->all($params);
    }

    public function getSubscriptions($params = null)
    {
        $subscriptions = [];

        if (!$this->getStripeId())
            return $subscriptions;

        $params['customer'] = $this->getStripeId();
        $params['limit'] = 100;
        $params['expand'] = ['data.default_payment_method'];

        $collection = \Stripe\Subscription::all($params);

        foreach ($collection->data as $subscription)
        {
            if (in_array($subscription->status, ['canceled', 'incomplete', 'incomplete_expired']))
                continue;

            $subscriptions[$subscription->id] = $subscription;
        }

        return $subscriptions;
    }

    public function getAllSubscriptions()
    {
        $subscriptions = [];

        if (!$this->getStripeId())
            return $subscriptions;

        $params['customer'] = $this->getStripeId();
        $params['status'] = 'all';
        $params['limit'] = 100;
        $params['expand'] = ['data.default_payment_method', 'data.items.data.price', 'data.plan.product'];

        $collection = \Stripe\Subscription::all($params);

        foreach ($collection->autoPagingIterator() as $subscription)
        {
            $subscriptions[$subscription->id] = $subscription;
        }

        return $subscriptions;
    }

    // Creates a customer if they don't exist
    // Updates a customer if they exist
    public function updateFromOrder($order)
    {
        if (!$this->getStripeId())
            return;

        $customer = $this->retrieveByStripeID();

        $data = $this->addressHelper->getStripeAddressFromMagentoAddress($order->getBillingAddress());
        $data['preferred_locales'] = [ $this->localeHelper->getCustomerPreferredLocale() ];

        if (!$order->getIsVirtual())
        {
            $data['shipping'] = $this->addressHelper->getStripeAddressFromMagentoAddress($order->getShippingAddress());
            if (!empty($data['shipping']['email']))
                unset($data['shipping']['email']);
        }

        $this->updateFromData($data);
    }

    public function updateFromData($data)
    {
        if (!$this->getStripeId())
            return;

        $this->_stripeCustomer = $this->config->getStripeClient()->customers->update($this->getStripeId(), $data);
    }

    public function attachPaymentMethod($paymentMethodId)
    {
        if (empty($paymentMethodId))
        {
            throw new \Exception("Invalid payment method ID");
        }

        $stripeCustomerId = $this->getStripeId();
        if (empty($stripeCustomerId))
        {
            throw new \Exception("Could not load customer object");
        }

        try
        {
            $stripePaymentMethod = DataHelper::getSingleton(\StripeIntegration\Payments\Model\Stripe\PaymentMethod::class);
            $paymentMethod = $stripePaymentMethod->fromPaymentMethodId($paymentMethodId)->getStripeObject();
        }
        catch (\Exception $e)
        {
            return $this->helper->dieWithError("Could not load payment method: " . $e->getMessage(), $e);
        }

        if (empty($paymentMethod->customer))
        {
            return $this->config->getStripeClient()->paymentMethods->attach($paymentMethodId, ['customer' => $this->getStripeId()]);
        }
        else if ($paymentMethod->customer != $this->getStripeId())
        {
            $this->helper->logError("Payment method $paymentMethodId belongs to {$paymentMethod->customer} but was used with customer " . $this->getStripeId());
            return $this->helper->dieWithError("Could not load payment method.");
        }

        return $paymentMethod;
    }

    // True if the customer is logged into their Magento account
    public function isLoggedIn()
    {
        return $this->customerSession->isLoggedIn();
    }

    public function fromStripeId($customerStripeId)
    {
        $this->load($customerStripeId, 'stripe_id');

        if (!$this->getId())
        {
            $this->syncWithStripe($customerStripeId);
        }

        $this->sessionManager->setStripeCustomerId($customerStripeId);

        return $this;
    }

    public function syncWithStripe($customerStripeId)
    {
        $this->_stripeCustomer = null;

        $this->setStripeId($customerStripeId);

        $customer = $this->helper->getMagentoCustomer();
        if ($customer)
        {
            $this->setCustomerId($customer->getEntityId());
        }

        $customerEmail = $this->helper->getCustomerEmail();
        if ($customerEmail)
        {
            $this->setCustomerEmail($customerEmail);
        }

        $this->setLastRetrieved(time());
        $this->setPk($this->config->getPublishableKey());
        $this->updateSessionId();
        $this->retrieveByStripeID($customerStripeId);
        $this->save();
    }
}
