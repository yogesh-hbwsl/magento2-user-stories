<?php

namespace StripeIntegration\Payments\Model\Method;

use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NotFoundException;
use Magento\Payment\Gateway\Command\CommandPoolInterface;
use Magento\Payment\Gateway\Config\ValueHandlerPoolInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectFactory;
use Magento\Payment\Gateway\Validator\ValidatorPoolInterface;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Api\Data\CartInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\Validator\Exception;
use Magento\Payment\Observer\AbstractDataAssignObserver;

class Express extends \StripeIntegration\Payments\Model\PaymentMethod
{
    protected $_code                 = "stripe_payments_express";

    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if (!$this->config->initStripe())
            return false;

        if (!empty($quote) && $quote->getPayment() && $quote->getIsWalletButton())
            return true;

        return false;
    }

    public function getConfigPaymentAction()
    {
        return $this->config->getPaymentAction();
    }
}
