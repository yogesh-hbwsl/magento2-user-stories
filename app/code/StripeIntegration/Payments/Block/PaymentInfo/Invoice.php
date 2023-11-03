<?php

namespace StripeIntegration\Payments\Block\PaymentInfo;

use Magento\Framework\Phrase;
use Magento\Payment\Block\ConfigurableInfo;
use StripeIntegration\Payments\Gateway\Response\FraudHandler;
use StripeIntegration\Payments\Helper\Logger;

class Invoice extends ConfigurableInfo
{
    protected $_template = 'paymentInfo/invoice.phtml';
    protected $_invoice = null;
    protected $_customerUrl = null;
    private $paymentsConfig;
    private $api;
    private $country;
    private $info;
    private $registry;
    private $customerFactory;
    private $invoiceFactory;
    private $helper;

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Payment\Gateway\ConfigInterface $config,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Model\Config $paymentsConfig,
        \StripeIntegration\Payments\Model\Stripe\InvoiceFactory $invoiceFactory,
        \StripeIntegration\Payments\Model\Stripe\CustomerFactory $customerFactory,
        \StripeIntegration\Payments\Helper\Api $api,
        \Magento\Directory\Model\Country $country,
        \Magento\Payment\Model\Info $info,
        \Magento\Framework\Registry $registry,
        array $data = []
    ) {
        parent::__construct($context, $config, $data);

        $this->helper = $helper;
        $this->paymentsConfig = $paymentsConfig;
        $this->invoiceFactory = $invoiceFactory;
        $this->api = $api;

        $this->country = $country;
        $this->info = $info;
        $this->registry = $registry;
        $this->customerFactory = $customerFactory;
    }

    public function getInvoice()
    {
        if ($this->_invoice)
            return $this->_invoice;

        $info = $this->getInfo();
        $invoiceId = $info->getAdditionalInformation('invoice_id');
        $invoice = $this->invoiceFactory->create()->load($invoiceId);
        return $this->_invoice = $invoice;
    }

    public function getCustomerUrl()
    {
        if ($this->_customerUrl)
            return $this->_customerUrl;

        $invoice = $this->getInvoice();
        $url = $this->helper->getStripeUrl($invoice->getStripeObject()->livemode, 'customers', $invoice->getStripeObject()->customer);
        return $this->_customerUrl = $url;
    }

    public function getDateDue()
    {
        $invoice = $this->getInvoice()->getStripeObject();

        $date = $invoice->due_date;

        return date('j M Y', $date);
    }

    public function getStatus()
    {
        $invoice = $this->getInvoice()->getStripeObject();

        return ucfirst($invoice->status);
    }
}
