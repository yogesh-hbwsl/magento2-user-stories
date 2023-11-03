<?php

namespace StripeIntegration\Payments\Block\Multishipping;

use StripeIntegration\Payments\Model\Config as StripeConfig;

// Payment method form in the multi-shipping page
class Billing extends \Magento\Payment\Block\Form\Cc
{
    protected $_template = 'multishipping/billing/payment_element.phtml';
    private $formKey;
    private $initParams;
    private $helper;
    private $serializer;
    private $stripeConfig;

    public function __construct(
        \StripeIntegration\Payments\Helper\InitParams $initParams,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \Magento\Framework\Serialize\SerializerInterface $serializer,
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Payment\Model\Config $paymentConfig,
        \Magento\Framework\Data\Form\FormKey $formKey,
        StripeConfig $stripeConfig,
        array $data = []
    ) {
        $this->initParams = $initParams;
        $this->helper = $helper;
        $this->stripeConfig = $stripeConfig;

        parent::__construct($context, $paymentConfig, $data);
        $this->formKey = $formKey;
    }

    public function getFormKey()
    {
         return $this->formKey->getFormKey();
    }

    public function getInitParams()
    {
        try
        {
            $customer = $this->helper->getCustomerModel();

            if (!$customer->existsInStripe())
                $customer->createStripeCustomerIfNotExists();

            return $this->initParams->getMultishippingParams();
        }
        catch (\Exception $e)
        {
            $this->helper->logError($e->getMessage(), $e->getTraceAsString());
            return $this->serializer->serialize([]);
        }
    }

    public function getCaptureMethod()
    {
        return $this->stripeConfig->getCaptureMethod();
    }
}
