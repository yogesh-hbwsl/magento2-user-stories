<?php

namespace StripeIntegration\Payments\Test\Integration\Helper;

use Magento\Customer\Api\Data\GroupInterface;

class QuoteManagement extends \Magento\Quote\Model\QuoteManagement
{
    private $submitQuoteValidator;
    private $quoteIdMaskFactory;
    private $addressRepository;
    private $addressesToSync = [];
    private $request;
    private $remoteAddress;
    private $saleOperation;

    private function _init()
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $this->submitQuoteValidator = $objectManager->get(\Magento\Quote\Model\SubmitQuoteValidator::class);
        $this->remoteAddress = $objectManager->get(\Magento\Framework\HTTP\PhpEnvironment\RemoteAddress::class);
        $this->saleOperation = $objectManager->get(\Magento\Sales\Model\Order\Payment\Operations\SaleOperation::class);
    }

    public function mockOrder($quote, $paymentMethod = null, $orderData = [])
    {
        $this->_init();

        if ($paymentMethod) {
            $paymentMethod->setChecks(
                [
                    \Magento\Payment\Model\Method\AbstractMethod::CHECK_USE_CHECKOUT,
                    \Magento\Payment\Model\Method\AbstractMethod::CHECK_USE_FOR_COUNTRY,
                    \Magento\Payment\Model\Method\AbstractMethod::CHECK_USE_FOR_CURRENCY,
                    \Magento\Payment\Model\Method\AbstractMethod::CHECK_ORDER_TOTAL_MIN_MAX,
                    \Magento\Payment\Model\Method\AbstractMethod::CHECK_ZERO_TOTAL
                ]
            );
            $quote->getPayment()->setQuote($quote);

            $data = $paymentMethod->getData();
            $quote->getPayment()->importData($data);
        } else {
            $quote->collectTotals();
        }

        if ($quote->getCheckoutMethod() === self::METHOD_GUEST) {
            $quote->setCustomerId(null);
            $quote->setCustomerEmail($quote->getBillingAddress()->getEmail());
            if ($quote->getCustomerFirstname() === null && $quote->getCustomerLastname() === null) {
                $quote->setCustomerFirstname($quote->getBillingAddress()->getFirstname());
                $quote->setCustomerLastname($quote->getBillingAddress()->getLastname());
                if ($quote->getBillingAddress()->getMiddlename() === null) {
                    $quote->setCustomerMiddlename($quote->getBillingAddress()->getMiddlename());
                }
            }
            $quote->setCustomerIsGuest(true);
            $groupId = $quote->getCustomer()->getGroupId() ?: GroupInterface::NOT_LOGGED_IN_ID;
            $quote->setCustomerGroupId($groupId);
        }

        $remoteAddress = $this->remoteAddress->getRemoteAddress();
        if ($remoteAddress !== false) {
            $quote->setRemoteIp($remoteAddress);
            $quote->setXForwardedFor(
                $this->request->getServer('HTTP_X_FORWARDED_FOR')
            );
        }

        $this->eventManager->dispatch('checkout_submit_before', ['quote' => $quote]);

        if (!$quote->getAllVisibleItems()) {
            $quote->setIsActive(false);
            return null;
        }

        // $this->submitQuote()
        $order = $this->orderFactory->create();
        $this->submitQuoteValidator->validateQuote($quote);
        if (!$quote->getCustomerIsGuest()) {
            if ($quote->getCustomerId()) {
                $this->_prepareCustomerQuote($quote);
                $this->customerManagement->validateAddresses($quote);
            }
            $this->customerManagement->populateCustomerInfo($quote);
        }
        $addresses = [];
        $quote->reserveOrderId();
        if ($quote->isVirtual()) {
            $this->dataObjectHelper->mergeDataObjects(
                \Magento\Sales\Api\Data\OrderInterface::class,
                $order,
                $this->quoteAddressToOrder->convert($quote->getBillingAddress(), $orderData)
            );
        } else {
            $this->dataObjectHelper->mergeDataObjects(
                \Magento\Sales\Api\Data\OrderInterface::class,
                $order,
                $this->quoteAddressToOrder->convert($quote->getShippingAddress(), $orderData)
            );
            $shippingAddress = $this->quoteAddressToOrderAddress->convert(
                $quote->getShippingAddress(),
                [
                    'address_type' => 'shipping',
                    'email' => $quote->getCustomerEmail()
                ]
            );
            $shippingAddress->setData('quote_address_id', $quote->getShippingAddress()->getId());
            $addresses[] = $shippingAddress;
            $order->setShippingAddress($shippingAddress);
            $order->setShippingMethod($quote->getShippingAddress()->getShippingMethod());
        }
        $billingAddress = $this->quoteAddressToOrderAddress->convert(
            $quote->getBillingAddress(),
            [
                'address_type' => 'billing',
                'email' => $quote->getCustomerEmail()
            ]
        );
        $billingAddress->setData('quote_address_id', $quote->getBillingAddress()->getId());
        $addresses[] = $billingAddress;
        $order->setBillingAddress($billingAddress);
        $order->setAddresses($addresses);
        $order->setPayment($this->quotePaymentToOrderPayment->convert($quote->getPayment()));
        $order->setItems($this->resolveItems($quote));
        if ($quote->getCustomer()) {
            $order->setCustomerId($quote->getCustomer()->getId());
        }
        $order->setQuoteId($quote->getId());
        $order->setCustomerEmail($quote->getCustomerEmail());
        $order->setCustomerFirstname($quote->getCustomerFirstname());
        $order->setCustomerMiddlename($quote->getCustomerMiddlename());
        $order->setCustomerLastname($quote->getCustomerLastname());
        $this->submitQuoteValidator->validateOrder($order);

        $this->eventManager->dispatch(
            'sales_model_service_quote_submit_before',
            [
                'order' => $order,
                'quote' => $quote
            ]
        );

        // $order->place();
        $this->eventManager->dispatch('sales_order_place_before', ['order' => $order]);

        // $order->getPayment()->place()
        $payment = $order->getPayment();
        $this->eventManager->dispatch('sales_order_payment_place_start', ['payment' => $payment]);

        $payment->setAmountOrdered($order->getTotalDue());
        $payment->setBaseAmountOrdered($order->getBaseTotalDue());
        $payment->setShippingAmount($order->getShippingAmount());
        $payment->setBaseShippingAmount($order->getBaseShippingAmount());

        $methodInstance = $payment->getMethodInstance();
        $methodInstance->setStore($order->getStoreId());

        $orderState = \Magento\Sales\Model\Order::STATE_NEW;
        $orderStatus = $methodInstance->getConfigData('order_status');
        $isCustomerNotified = $order->getCustomerNoteNotify();

        // Do order payment validation on payment method level
        $methodInstance->validate();
        $action = $methodInstance->getConfigPaymentAction();

        if ($action) {
            if ($methodInstance->isInitializeNeeded()) {
                $stateObject = new \Magento\Framework\DataObject();
                // For method initialization we have to use original config value for payment action
                // $methodInstance->initialize($methodInstance->getConfigData('payment_action'), $stateObject);
                // @todo - mock the payment deeper
            } else {
                $orderState = \Magento\Sales\Model\Order::STATE_PROCESSING;

                // $payment->processAction($action, $order);
                $totalDue = $order->getTotalDue();
                $baseTotalDue = $order->getBaseTotalDue();

                switch ($action) {
                    case \Magento\Payment\Model\Method\AbstractMethod::ACTION_ORDER:
                        // $payment->_order($baseTotalDue);
                        break;
                    case \Magento\Payment\Model\Method\AbstractMethod::ACTION_AUTHORIZE:
                        // $payment->authorize(true, $baseTotalDue);
                        break;
                    case \Magento\Payment\Model\Method\AbstractMethod::ACTION_AUTHORIZE_CAPTURE:
                        $payment->setAmountAuthorized($totalDue);
                        $payment->setBaseAmountAuthorized($baseTotalDue);
                        if ($methodInstance instanceof \Magento\Payment\Model\SaleOperationInterface &&
                            $methodInstance->canSale())
                        {
                            // $payment->saleOperation->execute($payment);
                            // @todo - mock the payment deeper
                        }
                        else
                        {
                            // $payment->orderPaymentProcessor->capture($payment, $invoice = null);
                            // @todo - mock the payment deeper
                        }
                        break;
                    default:
                        break;
                }
            }
        }

        return $order;
    }
}
