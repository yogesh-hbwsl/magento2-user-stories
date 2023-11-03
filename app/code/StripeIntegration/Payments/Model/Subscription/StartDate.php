<?php

namespace StripeIntegration\Payments\Model\Subscription;

class StartDate
{
    private $startDateTimestamp = null;
    private $firstPayment = null;
    private $prorateFirstPayment = null;
    private $profile = null;

    public function fromProfile($profile): StartDate
    {
        if (empty($profile['start_on_specific_date']) || empty($profile['start_date']) || !is_string($profile['start_date']))
        {
            return $this;
        }

        // Check if $profile['start_date'] is in the format of '2021-01-01'
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $profile['start_date']))
        {
            return $this;
        }

        // Bring the start date to the future
        $startDateTimestamp = strtotime($profile['start_date']);
        $minutesSinceMidnight = time() - strtotime('today');
        $startDateTimestamp += $minutesSinceMidnight;
        $intervalCount = $profile['interval_count'];
        $intervalUnit = $profile['interval'];
        if ($intervalCount > 1)
        {
            $intervalUnit .= 's';
        }
        $now = time();

        while ($startDateTimestamp < $now)
        {
            $startDateTimestamp = strtotime("+{$intervalCount} {$intervalUnit}", $startDateTimestamp);
        }

        $this->startDateTimestamp = $startDateTimestamp;
        $this->firstPayment = $profile['first_payment'];
        $this->prorateFirstPayment = $profile['prorate_first_payment'];
        $this->profile = $profile;

        return $this;
    }

    public function isValid()
    {
        return is_numeric($this->startDateTimestamp)
            && $this->startDateTimestamp >= strtotime("today")
            && in_array($this->firstPayment, ['on_order_date', 'on_start_date'])
            && in_array($this->prorateFirstPayment, [0, 1])
            && $this->profile;
    }

    public function isCompatibleWithTrials($hasOneTimePayment)
    {
        if (!$this->isValid())
        {
            return true;
        }

        $hasPhases = $this->hasPhases();
        $startDateParams = $this->getParams($hasOneTimePayment);
        $hasStartDate = !empty($startDateParams);

        if ($hasPhases)
        {
            return false;
        }

        if ($hasStartDate)
        {
            return false;
        }

        return true;
    }

    public function getParams(bool $hasOneTimePayment, bool $isStripeCheckout = false)
    {
        $params = [];

        if (!$this->isValid())
            return $params;

        if ($this->firstPayment == 'on_order_date')
        {
            if ($this->prorateFirstPayment)
            {
                $params['billing_cycle_anchor'] = $this->startDateTimestamp;
                $params['proration_behavior'] = 'create_prorations';
            }
            else
            {
                // In the case of collecting the first payment on the order date, and the second payment
                // on the start date, we create the subscription on order date, and set the billing_cycle_anchor
                // after the first payment is collected.
            }
        }
        else // on_start_date
        {
            if (!$hasOneTimePayment)
            {
                // Standalone subscription, will start on the start date
                $params['billing_cycle_anchor'] = $this->startDateTimestamp;
                $params['proration_behavior'] = 'none';
            }
            else
            {
                // Matches on
                // a) Mixed cart with a subscription start date and a regular product
                // b) Subscription with a start date and an initial fee
                // We start the subscription immediately with a trial, so that
                // the payment method is 3DS authenticated only once for both the regular payment
                // and the subscription payment.
                if ($isStripeCheckout)
                {
                    $params['trial_period_days'] = $this->getDaysUntilStartDate();
                }
                else
                {
                    $params['trial_end'] = $this->startDateTimestamp;
                }
                $params['metadata'] = [
                    'Start Date' => date('Y-m-d H:i:s', $this->startDateTimestamp),
                ];
            }
        }

        return $params;
    }

    public function getPhases($subscriptionCreateParams)
    {
        if (!$this->hasPhases())
            throw new \Exception("The subscription has no phases.");

        $phases = [
            [
                'items' => $subscriptionCreateParams['items'],
                'metadata' => $subscriptionCreateParams['metadata'],
                'end_date' => $this->startDateTimestamp,
                'proration_behavior' => 'none',
            ],
            [
                'items' => $subscriptionCreateParams['items'],
                'billing_cycle_anchor' => 'phase_start',
                'proration_behavior' => 'none'
            ]
        ];

        if (!empty($subscriptionCreateParams['add_invoice_items']))
        {
            $phases[0]['add_invoice_items'] = $subscriptionCreateParams['add_invoice_items'];
        }

        if (!empty($subscriptionCreateParams['coupon']))
        {
            $phases[0]['coupon'] = $subscriptionCreateParams['coupon'];
        }

        return $phases;
    }

    public function hasPhases()
    {
        return ($this->isValid() &&
            ($this->firstPayment == 'on_order_date' && !$this->prorateFirstPayment));
    }

    public function getStartDateTimestamp()
    {
        return $this->startDateTimestamp;
    }

    public function getDaysUntilStartDate($startDateTimestamp = null)
    {
        if (!$this->isValid())
            return 0;

        if (!$startDateTimestamp)
            $startDateTimestamp = $this->startDateTimestamp;

        $days = ($startDateTimestamp - strtotime('today')) / 86400;
        return round($days);
    }
}