<?php

namespace StripeIntegration\Payments\Block\Customer;

use Magento\Framework\App\DefaultPathInterface;
use Magento\Framework\View\Element\Html\Link\Current;
use Magento\Framework\View\Element\Template\Context;
use StripeIntegration\Payments\Model\Config as StripeConfigModel;

class PaymentMethodLink extends Current
{
    private $stripeConfigModel;

    public function __construct(
        Context $context,
        DefaultPathInterface $defaultPath,
        StripeConfigModel $stripeConfigModel,
        array $data = []
    ) {
        parent::__construct($context, $defaultPath, $data);
        $this->stripeConfigModel = $stripeConfigModel;
    }

    protected function _toHtml()
    {
        if ($this->stripeConfigModel->getSavePaymentMethod()) {
            return parent::_toHtml();
        } else {
            return '';
        }
    }
}
