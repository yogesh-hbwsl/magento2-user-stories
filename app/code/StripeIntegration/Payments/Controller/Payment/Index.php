<?php

namespace StripeIntegration\Payments\Controller\Payment;

use Magento\Framework\Exception\LocalizedException;
use StripeIntegration\Payments\Helper\Logger;

class Index extends \Magento\Framework\App\Action\Action
{
    /**
     * @var \Magento\Framework\View\Result\PageFactory
     */
    protected $resultPageFactory;

    /**
     * @var \Magento\Checkout\Helper\Data
     */
    protected $checkoutHelper;

    /**
     * @var \Magento\Sales\Model\OrderFactory
     */
    protected $orderFactory;

    /**
     * @var \StripeIntegration\Payments\Helper\Generic
     */
    protected $helper;

    /**
     * @var \Magento\Sales\Model\Service\InvoiceService
     */
    protected $invoiceService;
    private $checkoutSession;
    private $paymentIntentHelper;
    private $checkoutSessionFactory;
    private $config;
    private $paymentElement;
    private $paymentIntentModel;
    private $session;

    /**
     * Payment constructor.
     *
     * @param \Magento\Framework\App\Action\Context       $context
     * @param \Magento\Framework\View\Result\PageFactory  $resultPageFactory
     * @param \Magento\Checkout\Helper\Data               $checkoutHelper
     * @param \Magento\Sales\Model\OrderFactory           $orderFactory
     * @param \StripeIntegration\Payments\Helper\Generic    $helper
     * @param \Magento\Sales\Model\Service\InvoiceService $invoiceService
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Magento\Checkout\Helper\Data $checkoutHelper,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\CheckoutSession $checkoutSession,
        \StripeIntegration\Payments\Helper\PaymentIntent $paymentIntentHelper,
        \StripeIntegration\Payments\Model\CheckoutSessionFactory $checkoutSessionFactory,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\PaymentElement $paymentElement,
        \StripeIntegration\Payments\Model\PaymentIntent $paymentIntentModel,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService
    )
    {
        $this->resultPageFactory = $resultPageFactory;
        parent::__construct($context);

        $this->checkoutHelper = $checkoutHelper;
        $this->orderFactory = $orderFactory;

        $this->helper = $helper;
        $this->checkoutSession = $checkoutSession;
        $this->paymentIntentHelper = $paymentIntentHelper;
        $this->checkoutSessionFactory = $checkoutSessionFactory;
        $this->config = $config;
        $this->paymentElement = $paymentElement;
        $this->paymentIntentModel = $paymentIntentModel;

        $this->invoiceService = $invoiceService;
    }

    public function execute()
    {
        $paymentMethodType = $this->getRequest()->getParam('payment_method');
        $this->session = $this->checkoutHelper->getCheckout();

        if ($paymentMethodType == 'stripe_checkout')
            return $this->returnFromStripeCheckout();
        else
            return $this->returnFromPaymentElement();
    }

    private function error($message, $order = null)
    {
        $this->session->restoreQuote();

        if ($order)
        {
            $this->session->setLastRealOrderId($order->getIncrementId());
            $order->addStatusHistoryComment($message);
            $this->helper->cancelOrCloseOrder($order, true, true);
            $this->helper->saveOrder($order);
        }

        $this->messageManager->addError($message);
        $this->_redirect('checkout/cart');
    }

    private function returnFromPaymentElement()
    {
        $paymentIntentId = $this->getRequest()->getParam('payment_intent');

        if (empty($paymentIntentId))
        {
            // The customer was redirected here right from the checkout page, rather than an external URL.
            // This can happen when 3DS was performed on the checkout page, and the redirect is necessary to de-activate the quote.
            return $this->success();
        }

        $paymentIntent = $this->config->getStripeClient()->paymentIntents->retrieve($paymentIntentId, []);

        $this->paymentElement->load($paymentIntentId, 'payment_intent_id');
        $orderIncrementId = $this->paymentElement->getOrderIncrementId();

        // This should also never happen, but we are gracefully handling the case if it does.
        if (empty($orderIncrementId))
            return $this->success();

        $order = $this->orderFactory->create()->loadByIncrementId($orderIncrementId);
        if (!$order->getId())
            return $this->error(__("Your order #%1 could not be placed. Please contact us for assistance.", $orderIncrementId));

        if ($this->paymentIntentHelper->isSuccessful($paymentIntent) ||
            $this->paymentIntentHelper->requiresOfflineAction($paymentIntent))
        {
            return $this->success($order);
        }
        else
        {
            return $this->error(__('Payment failed. Please try placing the order again.'), $order);
        }
    }

    private function returnFromStripeCheckout()
    {
        $sessionId = $this->session->getStripePaymentsCheckoutSessionId();
        if (empty($sessionId))
            return $this->error(__("Your order was placed successfully, but your browser session has expired. Please check your email for an order confirmation."));

        $checkoutSessionModel = $this->checkoutSessionFactory->create()->load($sessionId, "checkout_session_id");
        $incrementId = $checkoutSessionModel->getOrderIncrementId();
        if (empty($incrementId))
            return $this->error(__("Cannot resume checkout session. Please contact us for help."));

        $order = $this->orderFactory->create()->loadByIncrementId($incrementId);
        if (!$order->getId())
            return $this->error(__("Your order #%1 could not be placed. Please contact us for assistance.", $incrementId));

        // Retrieve payment intent
        try
        {
            /** @var \Stripe\Checkout\Session $session */
            $session = $this->config->getStripeClient()->checkout->sessions->retrieve($sessionId, ['expand' => ['payment_intent', 'subscription.latest_invoice']]);

            if (empty($session->id))
                return $this->error(__('The checkout session for order #%1 could not be retrieved from Stripe', $incrementId), $order);

            if ($session->status == "complete")
            {
                // Paid subscriptions and normal orders
                return $this->stripeCheckoutSuccess($session, $order);
            }
            else if (!empty($session->payment_intent))
            {
                // Regular orders
                switch ($session->payment_intent->status) {
                    case 'succeeded':
                    case 'processing':
                    case 'requires_capture': // Authorize Only mode
                        return $this->stripeCheckoutSuccess($session, $order);
                    default:
                        break;
                }
            }

            if (!empty($session->payment_intent->last_payment_error->message))
                $error = __('Payment failed: %1. Please try placing the order again.', trim($session->payment_intent->last_payment_error->message, "."));
            else
                $error = __('Payment failed. Please try placing the order again.');

            return $this->error($error, $order);
        }
        catch (\Exception $e)
        {
            $this->helper->logError($e->getMessage(), $e->getTraceAsString());
            return $this->error(__("Your order #%1 could not be placed. Please contact us for assistance.", $incrementId));
        }
    }

    protected function stripeCheckoutSuccess($session, $order)
    {
        if (!empty($session->subscription->latest_invoice->payment_intent))
        {
            $this->config->getStripeClient()->paymentIntents->update($session->subscription->latest_invoice->payment_intent,
              ['description' => $this->helper->getOrderDescription($order)]
            );
        }

        return $this->success($order);
    }

    protected function success($order = null)
    {
        $quote = $this->session->getQuote();

        if ($quote && $quote->getId())
        {
            $quote->setIsActive(false);
            $this->helper->saveQuote($quote);
        }

        if (!$this->session->getLastRealOrderId() && $order)
            $this->session->setLastRealOrderId($order->getIncrementId());

        $checkoutSession = $this->helper->getCheckoutSession();
        $subscriptionReactivateDetails = $checkoutSession->getSubscriptionReactivateDetails();
        $redirectUrl = '';

        if ($subscriptionReactivateDetails) {
            if (isset($subscriptionReactivateDetails['success_url'])
                && $subscriptionReactivateDetails['success_url']) {
                $redirectUrl = $subscriptionReactivateDetails['success_url'];
            }
            $checkoutSession->setSubscriptionReactivateDetails([]);
        }

        if ($redirectUrl) {
            return $this->_redirect($redirectUrl);
        }

        return $this->_redirect('checkout/onepage/success');
    }
}
