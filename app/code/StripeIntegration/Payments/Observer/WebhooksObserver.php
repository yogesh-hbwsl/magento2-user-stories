<?php

namespace StripeIntegration\Payments\Observer;

use Magento\Framework\Event\ObserverInterface;
use StripeIntegration\Payments\Exception\WebhookException;
use StripeIntegration\Payments\Exception\MissingOrderException;
use StripeIntegration\Payments\Model\Stripe\Event;

class WebhooksObserver implements ObserverInterface
{
    protected $webhooksHelper;

    // Event processors
    protected $invoicePaymentSucceeded;
    protected $checkoutSessionCompleted;
    protected $chargeCaptured;
    protected $checkoutSessionExpired;
    protected $paymentIntentProcessing;
    protected $reviewClosed;
    protected $customerSubscriptionUpdated;
    protected $customerSubscriptionCreated;
    protected $customerSubscriptionDeleted;
    protected $invoiceVoided;
    protected $chargeRefunded;
    protected $paymentIntentCanceled;
    protected $paymentIntentPaymentFailed;
    protected $paymentIntentPartiallyFunded;
    protected $setupIntentSucceeded;
    protected $sourceChargeable;
    protected $sourceCanceled;
    protected $sourceFailed;
    protected $chargeSucceeded;
    protected $invoicePaid;
    protected $invoiceUpcoming;

    public function __construct(
        \StripeIntegration\Payments\Helper\Webhooks $webhooksHelper,
        Event\InvoicePaymentSucceeded $invoicePaymentSucceeded,
        Event\CheckoutSessionCompleted $checkoutSessionCompleted,
        Event\ChargeCaptured $chargeCaptured,
        Event\CheckoutSessionExpired $checkoutSessionExpired,
        Event\PaymentIntentProcessing $paymentIntentProcessing,
        Event\ReviewClosed $reviewClosed,
        Event\CustomerSubscriptionUpdated $customerSubscriptionUpdated,
        Event\CustomerSubscriptionCreated $customerSubscriptionCreated,
        Event\CustomerSubscriptionDeleted $customerSubscriptionDeleted,
        Event\InvoiceVoided $invoiceVoided,
        Event\ChargeRefunded $chargeRefunded,
        Event\PaymentIntentCanceled $paymentIntentCanceled,
        Event\PaymentIntentPaymentFailed $paymentIntentPaymentFailed,
        Event\PaymentIntentPartiallyFunded $paymentIntentPartiallyFunded,
        Event\SetupIntentSucceeded $setupIntentSucceeded,
        Event\SourceChargeable $sourceChargeable,
        Event\SourceCanceled $sourceCanceled,
        Event\SourceFailed $sourceFailed,
        Event\ChargeSucceeded $chargeSucceeded,
        Event\InvoicePaid $invoicePaid,
        Event\InvoiceUpcoming $invoiceUpcoming
    )
    {
        $this->webhooksHelper = $webhooksHelper;
        $this->invoicePaymentSucceeded = $invoicePaymentSucceeded;
        $this->checkoutSessionCompleted = $checkoutSessionCompleted;
        $this->chargeCaptured = $chargeCaptured;
        $this->checkoutSessionExpired = $checkoutSessionExpired;
        $this->paymentIntentProcessing = $paymentIntentProcessing;
        $this->reviewClosed = $reviewClosed;
        $this->customerSubscriptionUpdated = $customerSubscriptionUpdated;
        $this->customerSubscriptionCreated = $customerSubscriptionCreated;
        $this->customerSubscriptionDeleted = $customerSubscriptionDeleted;
        $this->invoiceVoided = $invoiceVoided;
        $this->chargeRefunded = $chargeRefunded;
        $this->paymentIntentCanceled = $paymentIntentCanceled;
        $this->paymentIntentPaymentFailed = $paymentIntentPaymentFailed;
        $this->paymentIntentPartiallyFunded = $paymentIntentPartiallyFunded;
        $this->setupIntentSucceeded = $setupIntentSucceeded;
        $this->sourceChargeable = $sourceChargeable;
        $this->sourceCanceled = $sourceCanceled;
        $this->sourceFailed = $sourceFailed;
        $this->chargeSucceeded = $chargeSucceeded;
        $this->invoicePaid = $invoicePaid;
        $this->invoiceUpcoming = $invoiceUpcoming;
    }

    /**
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $eventName = $observer->getEvent()->getName();
        $arrEvent = $observer->getData('arrEvent');
        $stdEvent = $observer->getData('stdEvent');
        $object = $observer->getData('object');

        switch ($eventName)
        {
            case 'stripe_payments_webhook_checkout_session_expired':

                $this->checkoutSessionExpired->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_payment_intent_processing':

                $this->paymentIntentProcessing->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_checkout_session_completed':

                // Called when placing a trial subscription order with Stripe Checkout
                // Performs order post processing after a successful setup intent
                $this->checkoutSessionCompleted->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_charge_captured':

                // Creates an invoice for an order when the payment is captured from the Stripe dashboard
                $this->chargeCaptured->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_review_closed':

                $this->reviewClosed->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_customer_subscription_updated':

                $this->customerSubscriptionUpdated->process($arrEvent, $object, $stdEvent);
                break;

            case 'stripe_payments_webhook_customer_subscription_created':

                $this->customerSubscriptionCreated->process($arrEvent, $object, $stdEvent);
                break;

            case 'stripe_payments_webhook_customer_subscription_deleted':

                $this->customerSubscriptionDeleted->process($arrEvent, $object, $stdEvent);
                break;

            case 'stripe_payments_webhook_invoice_upcoming':

                $this->invoiceUpcoming->process($object);
                break;

            case 'stripe_payments_webhook_invoice_voided':
            case 'stripe_payments_webhook_invoice_marked_uncollectible':

                $this->invoiceVoided->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_charge_refunded':

                $this->chargeRefunded->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_setup_intent_canceled':
            case 'stripe_payments_webhook_payment_intent_canceled':

                $this->paymentIntentCanceled->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_payment_intent_succeeded':

                break;

            case 'stripe_payments_webhook_setup_intent_setup_failed':
            case 'stripe_payments_webhook_payment_intent_payment_failed':

                $this->paymentIntentPaymentFailed->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_payment_intent_partially_funded':

                $this->paymentIntentPartiallyFunded->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_payment_method_attached':

                $this->webhooksHelper->deduplicatePaymentMethod($object);
                break;

            case 'stripe_payments_webhook_setup_intent_succeeded':

                $this->setupIntentSucceeded->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_source_chargeable':

                $this->sourceChargeable->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_source_canceled':

                $this->sourceCanceled->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_source_failed':

                $this->sourceFailed->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_charge_succeeded':

                $this->chargeSucceeded->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_invoice_payment_succeeded':

                // Recurring subscription payments
                $this->invoicePaymentSucceeded->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_invoice_paid':

                $this->invoicePaid->process($arrEvent, $object);
                break;

            case 'stripe_payments_webhook_invoice_payment_failed':
                //$this->paymentFailed($event);
                break;

            default:
                # code...
                break;
        }
    }
}
