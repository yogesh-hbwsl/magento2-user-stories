<?php

namespace StripeIntegration\Payments\Model\Subscription;

use Magento\Framework\Exception\CouldNotSaveException;

class Schedule
{
    private $subscriptionCreateParams;
    private $startDate;
    private $config;
    private $schedule;
    private $subscription;
    private $expandParams = [];

    public function __construct(
        array $subscriptionCreateParams,
        \StripeIntegration\Payments\Model\Subscription\StartDate $startDate,
        \StripeIntegration\Payments\Model\Config $config
    )
    {
        $this->subscriptionCreateParams = $subscriptionCreateParams;
        $this->startDate = $startDate;
        $this->config = $config;
    }

    public function create()
    {
        if (!empty($this->subscriptionCreateParams['expand']))
        {
            $this->expandParams = $this->subscriptionCreateParams['expand'];
        }

        $this->schedule = $this->config->getStripeClient()->subscriptionSchedules->create([
            'customer' => $this->subscriptionCreateParams['customer'],
            'default_settings' => [
                'default_payment_method' => $this->subscriptionCreateParams['default_payment_method'],
                'description' => $this->subscriptionCreateParams['description'],
            ],
            'start_date' => 'now',
            'phases' => $this->startDate->getPhases($this->subscriptionCreateParams),
        ]);

        return $this;
    }

    public function createFromSubscription($subscriptionId)
    {
        $subscription = $this->config->getStripeClient()->subscriptions->retrieve(
            $subscriptionId,
            ['expand' => $this->expandParams]
        );

        $items = [];
        foreach ($subscription->items->data as $item)
        {
            $items[] = [
                'price' => $item->price->id,
                'quantity' => $item->quantity,
            ];
        }

        $phases = [
            [
                'start_date' => $subscription->current_period_start,
                'items' => $items,
                'end_date' => $this->startDate->getStartDateTimestamp(),
                'proration_behavior' => 'none',
            ],
            [
                'items' => $items,
                'billing_cycle_anchor' => 'phase_start',
                'proration_behavior' => 'none'
            ]
        ];

        if (!empty($subscription->schedule))
        {
            $this->schedule = $this->config->getStripeClient()->subscriptionSchedules->retrieve(
                $subscription->schedule,
                ['expand' => $this->expandParams]
            );
        }
        else
        {
            $this->schedule = $this->config->getStripeClient()->subscriptionSchedules->create([
                'from_subscription' => $subscriptionId,
            ]);
        }

        $this->schedule = $this->config->getStripeClient()->subscriptionSchedules->update($this->schedule->id, [
            'phases' => $phases,
        ]);

        return $this;
    }

    public function finalize()
    {
        $subscription = $this->getSubscription();
        if (is_string($subscription->latest_invoice))
        {
            $invoiceId = $subscription->latest_invoice;
        }
        else
        {
            $invoiceId = $subscription->latest_invoice->id;
        }

        // Finalize the invoice
        $this->config->getStripeClient()->invoices->finalizeInvoice($invoiceId);

        $this->subscription = null; // Reset this because the invoice has a payment intent now

        return $this;
    }

    public function getSubscription()
    {
        if (empty($this->schedule->subscription))
        {
            throw new \Exception("The subscription schedule has not been created yet.");
        }

        if (!empty($this->subscription))
        {
            return $this->subscription;
        }

        return $this->subscription = $this->config->getStripeClient()->subscriptions->retrieve(
            $this->schedule->subscription,
            ['expand' => $this->expandParams]
        );
    }

    public function getId()
    {
        return $this->schedule->id;
    }
}