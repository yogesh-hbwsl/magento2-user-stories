<?php

namespace StripeIntegration\Payments\Observer;

use Magento\Framework\Event\ObserverInterface;
use StripeIntegration\Payments\Helper\Logger;
use StripeIntegration\Payments\Exception\WebhookException;

class SetOrderTemplateVars implements ObserverInterface
{
    public $config;

    private $helper;
    private $paymentsHelper;
    private $_stripeCustomer;
    private $_eventManager;
    private $invoiceService;

    public function __construct(
        \StripeIntegration\Payments\Helper\InitialFee $helper,
        \StripeIntegration\Payments\Helper\Generic $paymentsHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Framework\Event\ManagerInterface $eventManager
    )
    {
        $this->helper = $helper;
        $this->paymentsHelper = $paymentsHelper;
        $this->config = $config;
        $this->_stripeCustomer = $paymentsHelper->getCustomerModel();
        $this->_eventManager = $eventManager;
        $this->invoiceService = $invoiceService;
    }

    /**
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $data = $observer->getEvent()->getTransport();
        $order = $data->getOrder();

        if (!$order->getPayment())
            return;

        if ($order->getPayment()->getMethod() != "stripe_payments")
            return;

        if (empty($this->paymentsHelper->orderComments[$order->getIncrementId()]))
            return;

        if (!$this->config->isSubscriptionsEnabled())
            return $this;

        if (!empty($this->paymentsHelper->orderComments[$order->getIncrementId()]))
        {
            $comment = $this->paymentsHelper->orderComments[$order->getIncrementId()];
            $orderData = $data->getOrderData();
            $orderData['email_customer_note'] = $comment;
            $data["order_data"] = $orderData;
        }
    }
}
