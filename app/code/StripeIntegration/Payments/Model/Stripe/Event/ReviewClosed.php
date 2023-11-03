<?php

namespace StripeIntegration\Payments\Model\Stripe\Event;

class ReviewClosed extends \StripeIntegration\Payments\Model\Stripe\Event
{
    protected $eventManager;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Data $dataHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\Webhooks $webhooksHelper,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \StripeIntegration\Payments\Helper\RequestCache $requestCache,
        \StripeIntegration\Payments\Helper\Compare $compare
    )
    {
        $this->eventManager = $eventManager;

        parent::__construct($config, $helper, $dataHelper, $subscriptionsHelper, $webhooksHelper, $requestCache, $compare);
    }
    public function process($arrEvent, $object)
    {
        if (empty($object['payment_intent']))
            return;

        $orders = $this->webhooksHelper->loadOrderFromEvent($arrEvent, true);

        foreach ($orders as $order)
        {
            $this->webhooksHelper->detectRaceCondition($order->getIncrementId(), ['charge.refunded']);
        }

        foreach ($orders as $order)
        {
            $this->eventManager->dispatch(
                'stripe_payments_review_closed_before',
                ['order' => $order, 'object' => $object]
            );

            if ($object['reason'] == "approved")
            {
                if ($order->canUnhold())
                    $order->unhold();

                $comment = __("The payment has been approved via Stripe.");
                $order->addStatusToHistory(false, $comment, $isCustomerNotified = false);
                $this->helper->saveOrder($order);
            }
            else if ($object['reason'] == "refunded_as_fraud")
            {
                if ($order->canUnhold())
                    $order->unhold();

                $comment = __("The payment has been rejected as fraudulent via Stripe.");
                $order->setState($order::STATE_PAYMENT_REVIEW);
                $order->addStatusToHistory($order::STATUS_FRAUD, $comment, $isCustomerNotified = false);
                $this->helper->saveOrder($order);
            }
            else
            {
                $comment = __("The payment was canceled through Stripe with reason: %1.", ucfirst(str_replace("_", " ", $object['reason'])));
                $order->addStatusToHistory(false, $comment, $isCustomerNotified = false);
                $this->helper->saveOrder($order);
            }

            $this->eventManager->dispatch(
                'stripe_payments_review_closed_after',
                ['order' => $order, 'object' => $object]
            );
        }

    }
}