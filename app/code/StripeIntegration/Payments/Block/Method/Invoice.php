<?php

namespace StripeIntegration\Payments\Block\Method;

use Magento\Framework\Phrase;
use Magento\Payment\Block\ConfigurableInfo;
use StripeIntegration\Payments\Gateway\Response\FraudHandler;
use StripeIntegration\Payments\Helper\Logger;

class Invoice extends ConfigurableInfo
{
    protected $_template = 'form/invoice.phtml';
    private $helper;
    private $api;
    private $country;
    private $info;
    private $registry;
    private $paymentsConfig;

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Payment\Gateway\ConfigInterface $config,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Model\Config $paymentsConfig,
        \StripeIntegration\Payments\Helper\Api $api,
        \Magento\Directory\Model\Country $country,
        \Magento\Payment\Model\Info $info,
        \Magento\Framework\Registry $registry,
        array $data = []
    ) {
        parent::__construct($context, $config, $data);

        $this->helper = $helper;
        $this->api = $api;
        $this->country = $country;
        $this->info = $info;
        $this->registry = $registry;
        $this->paymentsConfig = $paymentsConfig;
    }

    public function getDaysDue()
    {
        return 7;
    }
}
