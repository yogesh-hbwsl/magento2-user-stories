<?php

namespace StripeIntegration\Payments\Observer;

use Magento\Framework\Event\ObserverInterface;
use StripeIntegration\Payments\Helper\Logger;
use StripeIntegration\Payments\Exception\WebhookException;

class QtyUpdateObserver implements ObserverInterface
{
    private $_eventManager;
    private $_stripeCustomer;
    private $config;
    private $helper;
    private $invoiceService;
    private $paymentsHelper;
    private $serializer;

    public function __construct(
        \StripeIntegration\Payments\Helper\InitialFee $helper,
        \StripeIntegration\Payments\Helper\Generic $paymentsHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Framework\Serialize\SerializerInterface $serializer
    )
    {
        $this->helper = $helper;
        $this->paymentsHelper = $paymentsHelper;
        $this->config = $config;
        $this->_stripeCustomer = $paymentsHelper->getCustomerModel();
        $this->_eventManager = $eventManager;
        $this->invoiceService = $invoiceService;
        $this->serializer = $serializer;
    }

    /**
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if (!$this->config->getConfigData("additional_info", "subscriptions"))
            return;

        $items = $observer->getCart()->getQuote()->getItems();
        foreach ($items as $item)
        {
            if (!empty($item->getQtyOptions()))
                $additionalOptions = $this->helper->getAdditionalOptionsForChildrenOf($item);
            else
                $additionalOptions = $this->helper->getAdditionalOptionsForProductId($item->getProductId(), $item);

            if (!empty($additionalOptions))
            {
                $data = $this->serializer->serialize($additionalOptions);

                if ($data)
                {
                    $item->addOption(array(
                        'product_id' => $item->getProductId(),
                        'code' => 'additional_options',
                        'value' => $data
                    ));
                }
            }
        }
    }
}
