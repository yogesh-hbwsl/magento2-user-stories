<?php

namespace StripeIntegration\Payments\Block\PaymentInfo;

class BankTransfers extends \Magento\Payment\Block\ConfigurableInfo
{
    protected $_template = 'paymentInfo/bank_transfers.phtml';

    protected $helper;
    protected $paymentsConfig;
    protected $paymentMethodHelper;
    // Payment Method
    protected $stripePaymentMethodObject;
    protected $stripePaymentMethodModelFactory;
    protected $stripePaymentMethodModel;
    // Payment Intent
    protected $stripePaymentIntentObject;
    protected $stripePaymentIntentModelFactory;
    protected $stripePaymentIntentModel;
    protected $country;
    protected $info;
    protected $registry;

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Payment\Gateway\ConfigInterface $config,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper,
        \StripeIntegration\Payments\Model\Config $paymentsConfig,
        \StripeIntegration\Payments\Model\Stripe\PaymentMethodFactory $stripePaymentMethodModelFactory,
        \StripeIntegration\Payments\Model\Stripe\PaymentIntentFactory $stripePaymentIntentModelFactory,
        \Magento\Directory\Model\Country $country,
        \Magento\Payment\Model\Info $info,
        \Magento\Framework\Registry $registry,
        array $data = []
    ) {
        parent::__construct($context, $config, $data);

        $this->helper = $helper;
        $this->paymentsConfig = $paymentsConfig;
        $this->country = $country;
        $this->info = $info;
        $this->registry = $registry;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->stripePaymentMethodModelFactory = $stripePaymentMethodModelFactory;
        $this->stripePaymentIntentModelFactory = $stripePaymentIntentModelFactory;
    }

    public function getPaymentMethod()
    {
        if (!empty($this->stripePaymentMethodObject))
            return $this->stripePaymentMethodObject;

        $paymentIntent = $this->getPaymentIntent();

        if (empty($paymentIntent->payment_method))
        {
            return null;
        }
        else if (is_string($paymentIntent->payment_method))
        {
            $stripePaymentMethodModel = $this->stripePaymentMethodModelFactory->create()
                ->fromPaymentMethodId($paymentIntent->payment_method);

            return $this->stripePaymentMethodObject = $stripePaymentMethodModel->getStripeObject();
        }
        else
        {
            return $this->stripePaymentMethodObject = $paymentIntent->payment_method;
        }

        return null;
    }


    public function getPaymentIntent()
    {
        if (!empty($this->stripePaymentIntentObject))
            return $this->stripePaymentIntentObject;

        $transactionId = $this->getTransactionId();
        if ($transactionId && strpos($transactionId, "pi_") === 0)
        {
            $stripePaymentIntentModel = $this->stripePaymentIntentModelFactory->create()
                ->setExpandParams(['payment_method'])
                ->fromPaymentIntentId($transactionId);

            return $this->stripePaymentIntentObject = $stripePaymentIntentModel->getStripeObject();
        }

        return null;
    }

    public function getPaymentMethodIconUrl($format = null)
    {
        $method = $this->getPaymentMethod();

        if (!$method)
            return null;

        return $this->paymentMethodHelper->getIcon($method, $format);
    }


    public function getPaymentMethodName($hideLast4 = false)
    {
        $paymentMethod = $this->getPaymentMethod();

        if (!$paymentMethod)
            return null;

        return $this->paymentMethodHelper->getPaymentMethodName($paymentMethod->type);
    }

    public function getFormattedAmountRemaining()
    {
        /** @var \Stripe\PaymentIntent $paymentIntent */
        $paymentIntent = $this->getPaymentIntent();

        $amountRemaining = 0;
        $currency = $paymentIntent->currency;
        if (!empty($paymentIntent->next_action->display_bank_transfer_instructions->amount_remaining))
        {
            /** @var \Stripe\StripeObject $instructions */
            $instructions = $paymentIntent->next_action->display_bank_transfer_instructions;
            $amountRemaining = $instructions->amount_remaining;
            $currency = $instructions->currency;
        }

        return $this->helper->formatStripePrice($amountRemaining, $currency);
    }

    public function getFormattedAmountRefunded()
    {
        $paymentIntent = $this->getPaymentIntent();

        $amountRefunded = 0;
        $currency = $paymentIntent->currency;
        if (empty($paymentIntent->charges->data))
        {
            return null;
        }

        foreach ($paymentIntent->charges->data as $charge)
        {
            if ($charge->refunded)
            {
                $amountRefunded += $charge->amount_refunded;
                $currency = $charge->currency;
            }
        }

        return $this->helper->formatStripePrice($amountRefunded, $currency);
    }

    public function getTransactionId()
    {
        $transactionId = $this->getInfo()->getLastTransId();
        return $this->helper->cleanToken($transactionId);
    }

    public function getIbanDetails()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (empty($paymentIntent->next_action->display_bank_transfer_instructions->financial_addresses[0]->iban))
            return null;

        $details = $paymentIntent->next_action->display_bank_transfer_instructions->financial_addresses[0]->iban;

        $countryName = null;
        if ($details->country)
        {
            $country = $this->country->loadByCode($details->country);
            $countryName = $country->getName();
        }

        return [
            'account_holder_name' => $details->account_holder_name ?? null,
            'bic' => $details->bic ?? null,
            'country' => $countryName,
            'iban' => $details->iban ?? null,
        ];
    }

    public function getReference()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (empty($paymentIntent->next_action->display_bank_transfer_instructions->reference))
            return null;

        return $paymentIntent->next_action->display_bank_transfer_instructions->reference;
    }

    public function getHostedInstructionsUrl()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (empty($paymentIntent->next_action->display_bank_transfer_instructions->hosted_instructions_url))
            return null;

        return $paymentIntent->next_action->display_bank_transfer_instructions->hosted_instructions_url;
    }

    public function getCustomerId()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (isset($paymentIntent->customer) && !empty($paymentIntent->customer))
            return $paymentIntent->customer;

        return null;
    }

    public function getPaymentId()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (isset($paymentIntent->id))
            return $paymentIntent->id;

        return null;
    }

    public function getMode()
    {
        $paymentIntent = $this->getPaymentIntent();

        if ($paymentIntent->livemode)
            return "";

        return "test/";
    }
}
