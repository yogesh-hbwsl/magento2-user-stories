<?php

namespace StripeIntegration\Payments\Controller\Adminhtml\Configure;

use StripeIntegration\Payments\Helper\Logger;

class Webhooks extends \Magento\Framework\App\Action\Action
{
    private $resultJsonFactory;
    private $webhooksSetup;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory,
        \StripeIntegration\Payments\Helper\WebhooksSetup $webhooksSetup
    )
    {
        parent::__construct($context);

        $this->resultJsonFactory = $resultJsonFactory;
        $this->webhooksSetup = $webhooksSetup;
    }

    public function execute()
    {
        $this->webhooksSetup->configure();
        $result = $this->resultJsonFactory->create();
        return $result->setData(['success' => true, 'errors' => count($this->webhooksSetup->errorMessages)]);
    }
}
