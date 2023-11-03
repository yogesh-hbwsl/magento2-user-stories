<?php

namespace StripeIntegration\Payments\Controller\Payment;

use Magento\Framework\Exception\LocalizedException;
use StripeIntegration\Payments\Helper\Logger;

class Cancel extends \Magento\Framework\App\Action\Action
{
    protected $checkoutHelper;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Checkout\Helper\Data $checkoutHelper
    )
    {
        parent::__construct($context);

        $this->checkoutHelper = $checkoutHelper;
    }

    /**
     * @return void
     */
    public function execute()
    {
        $paymentMethodType = $this->getRequest()->getParam('payment_method');
        $session = $this->checkoutHelper->getCheckout();
        $lastRealOrderId = $session->getLastRealOrderId();

        switch ($paymentMethodType) {
            case 'stripe_checkout':
                $session->restoreQuote();
                $session->setLastRealOrderId($lastRealOrderId);
                return $this->_redirect('checkout');
            default:
                $this->_redirect('checkout/cart');
                break;
        }
    }
}
