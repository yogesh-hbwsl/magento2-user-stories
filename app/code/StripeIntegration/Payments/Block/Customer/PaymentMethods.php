<?php

namespace StripeIntegration\Payments\Block\Customer;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\View\Element;
use StripeIntegration\Payments\Helper\Logger;

class PaymentMethods extends \Magento\Framework\View\Element\Template
{
    private $paymentMethodHelper;
    private $config;
    private $stripeCustomer;
    private $initParams;
    private $helper;
    private $serializer;

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Framework\Serialize\SerializerInterface $serializer,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper,
        \StripeIntegration\Payments\Helper\InitParams $initParams,
        \StripeIntegration\Payments\Model\Config $config,
        array $data = []
    ) {
        $this->serializer = $serializer;
        $this->stripeCustomer = $helper->getCustomerModel();
        $this->helper = $helper;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->initParams = $initParams;
        $this->config = $config;

        parent::__construct($context, $data);
    }

    public function getSavedPaymentMethods()
    {
        try
        {
            return $this->stripeCustomer->getSavedPaymentMethods(null, true);
        }
        catch (\Exception $e)
        {
            $this->helper->addError($e->getMessage());
            $this->helper->logError($e->getMessage());
            $this->helper->logError($e->getTraceAsString());
        }
    }

    public function getInitParams()
    {
        try
        {
            $customer = $this->helper->getCustomerModel();

            if (!$customer->existsInStripe())
                $customer->createStripeCustomerIfNotExists();

            return $this->initParams->getMyPaymentMethodsParams($customer->getStripeId());
        }
        catch (\Exception $e)
        {
            $this->helper->logError($e->getMessage(), $e->getTraceAsString());
            return $this->serializer->serialize([]);
        }
    }
}
