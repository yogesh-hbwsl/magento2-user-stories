<?php

namespace StripeIntegration\Payments\Controller\Webhooks;

use StripeIntegration\Payments\Helper\Logger;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;

class Index extends \Magento\Framework\App\Action\Action implements CsrfAwareActionInterface
{
    private $webhooks;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \StripeIntegration\Payments\Helper\Webhooks $webhooks
    )
    {
        parent::__construct($context);

        $this->webhooks = $webhooks;
    }

    /**
     * @return void
     */
    public function execute()
    {
        $this->webhooks->dispatchEvent();
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
