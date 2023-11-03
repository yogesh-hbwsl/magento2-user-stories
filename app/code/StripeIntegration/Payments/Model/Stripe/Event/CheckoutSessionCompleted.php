<?php

namespace StripeIntegration\Payments\Model\Stripe\Event;

class CheckoutSessionCompleted extends \StripeIntegration\Payments\Model\Stripe\Event
{
    private $startDateFactory;
    private $subscriptionScheduleModelFactory;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Data $dataHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\Webhooks $webhooksHelper,
        \StripeIntegration\Payments\Model\Subscription\StartDateFactory $startDateFactory,
        \StripeIntegration\Payments\Model\Subscription\ScheduleFactory $subscriptionScheduleModelFactory,
        \StripeIntegration\Payments\Helper\RequestCache $requestCache,
        \StripeIntegration\Payments\Helper\Compare $compare
    )
    {
        $this->startDateFactory = $startDateFactory;
        $this->subscriptionScheduleModelFactory = $subscriptionScheduleModelFactory;

        parent::__construct($config, $helper, $dataHelper, $subscriptionsHelper, $webhooksHelper, $requestCache, $compare);
    }

    public function process($arrEvent, array $object)
    {
        $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

        $quote = $this->helper->loadQuoteById($order->getQuoteId());
        if ($quote && $quote->getIsActive())
        {
            $quote->setIsActive(false);
            $this->helper->saveQuote($quote);
        }

        // A subscription with a start date might have been purchased
        $this->processSubscriptionPhases($order, $object);

        if (!empty($object['subscription']) && !empty($object['setup_intent']))
        {
            // A trial subscription has been purchased
            $subscription = $this->config->getStripeClient()->subscriptions->retrieve($object['subscription']);
            $this->webhooksHelper->processTrialingSubscriptionOrder($order, $subscription);
        }

        if (!empty($object['customer']))
        {
            $order->getPayment()->setAdditionalInformation('customer_stripe_id', $object['customer']);
            $order->getPayment()->save();
        }


        if (!empty($object['setup_intent']))
        {
            $setupIntent = $this->config->getStripeClient()->setupIntents->retrieve($object['setup_intent']);
            if (!empty($setupIntent->payment_method))
            {
                $order->getPayment()->setAdditionalInformation('token', $setupIntent->payment_method);
                $this->helper->setProcessingState($order, __("A payment method has been saved against the order."));
                $this->helper->saveOrder($order);
            }
        }
    }

    protected function processSubscriptionPhases($order, array $object)
    {
        if (empty($object['subscription']))
        {
            return false;
        }

        $subscription = $this->subscriptionsHelper->getSubscriptionFromOrder($order);
        if (!$subscription)
        {
            return false;
        }

        $startDateModel = $this->startDateFactory->create()->fromProfile($subscription['profile']);

        if (!$startDateModel->hasPhases())
        {
            return false;
        }

        $subscriptionScheduleModel = $this->subscriptionScheduleModelFactory->create([
            'subscriptionCreateParams' => [],
            'startDate' => $startDateModel,
        ]);
        $subscriptionScheduleModel->createFromSubscription($object['subscription'], $startDateModel);

        $order->getPayment()->setAdditionalInformation('subscription_schedule_id', $subscriptionScheduleModel->getId());
        $order->getPayment()->save(); // Saving the order instead could cause webhooks race conditions

        return true;
    }
}