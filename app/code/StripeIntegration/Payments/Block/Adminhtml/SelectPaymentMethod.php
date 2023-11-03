<?php

namespace StripeIntegration\Payments\Block\Adminhtml;

use Magento\Framework\Phrase;
use StripeIntegration\Payments\Gateway\Response\FraudHandler;
use StripeIntegration\Payments\Helper\Logger;

class SelectPaymentMethod extends \Magento\Backend\Block\Widget\Form\Generic
{
    protected $_template = 'form/select_payment_method.phtml';
    private $initParams;
    private $helper;
    private $paymentMethodHelper;
    private $paymentsConfig;
    private $customer;
    private $sessionQuote;

    public function __construct(
        \StripeIntegration\Payments\Helper\InitParams $initParams,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper,
        \StripeIntegration\Payments\Model\Config $paymentsConfig,
        \Magento\Backend\Model\Session\Quote $sessionQuote,
        \Magento\Backend\Block\Template\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Data\FormFactory $formFactory,
        array $data = []
    ) {
        parent::__construct($context, $registry, $formFactory, $data);

        $this->initParams = $initParams;
        $this->helper = $helper;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->paymentsConfig = $paymentsConfig;
        $this->sessionQuote = $sessionQuote;
        $this->customer = $helper->getCustomerModel();
    }

    protected function initStripeCustomer()
    {
        // This is an order which the merchant might be trying to edit. It is saved in the session
        // and retrieved from the order edit page.
        $order = $this->sessionQuote->getOrder();
        if (!$order || !$order->getPayment() || !$order->getPayment()->getAdditionalInformation('customer_stripe_id'))
            return;

        $this->customer->load($order->getPayment()->getAdditionalInformation('customer_stripe_id'));
    }

    public function isOrderEdit()
    {
        $order = $this->sessionQuote->getOrder();
        return $order && $order->getPayment() && $order->getPayment()->getAdditionalInformation('payment_action') == "order";
    }

    public function getSavedPaymentMethods()
    {
        if ($this->isOrderEdit())
        {
            $order = $this->sessionQuote->getOrder();
            $stripeCustomerId = $order->getPayment()->getAdditionalInformation('customer_stripe_id');
            $paymentMethodId = $order->getPayment()->getAdditionalInformation('token');
            $this->customer->fromStripeId($stripeCustomerId);
            $paymentMethod = $this->paymentsConfig->getStripeClient()->paymentMethods->retrieve($paymentMethodId, []);
            return $this->paymentMethodHelper->formatPaymentMethods([
                $paymentMethod->type => [ $paymentMethod ]
            ]);
        }

        if (!$this->customer->getStripeId())
        {
            $this->customer->createStripeCustomer();
            return [];
        }
        else if (!$this->customer->getCustomerId())
        {
            // Guest customers
            $params = $this->customer->getParams();
            $this->customer->createNewStripeCustomer($params); // Misleading method name, this updates the customer object
        }

        $methods = $this->customer->getSavedPaymentMethods(null, true);

        return $methods;
    }

    public function getAddNewPaymentMethodURL()
    {
        $mode = $this->paymentsConfig->getStripeMode();

        if ($mode == "test")
            return "http://dashboard.stripe.com/test/customers/" . $this->customer->getStripeId();
        else
            return "http://dashboard.stripe.com/customers/" . $this->customer->getStripeId();
    }

    public function getStripeParams()
    {
        return $this->initParams->getAdminParams();
    }
}
