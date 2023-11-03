<?php

namespace StripeIntegration\Payments\Plugin\Checkout;

use Magento\Framework\Exception\CouldNotSaveException;

class GuestPaymentInformationManagement
{
    private $cartManagement;
    private $checkoutSessionHelper;
    private $checkoutSessionModel;

    public function __construct(
        \Magento\Quote\Api\GuestCartManagementInterface $cartManagement,
        \StripeIntegration\Payments\Helper\CheckoutSession $checkoutSessionHelper,
        \StripeIntegration\Payments\Model\CheckoutSession $checkoutSessionModel
    ) {

        $this->cartManagement = $cartManagement;
        $this->checkoutSessionHelper = $checkoutSessionHelper;
        $this->checkoutSessionModel = $checkoutSessionModel;
    }

    public function afterSavePaymentInformation(
        \Magento\Checkout\Api\GuestPaymentInformationManagementInterface $subject,
        $result,
        $cartId,
        $email,
        \Magento\Quote\Api\Data\PaymentInterface $paymentMethod,
        \Magento\Quote\Api\Data\AddressInterface $billingAddress = null
    ) {
        $this->checkoutSessionModel->updateCustomerEmail($email);

        return $result;
    }
}
