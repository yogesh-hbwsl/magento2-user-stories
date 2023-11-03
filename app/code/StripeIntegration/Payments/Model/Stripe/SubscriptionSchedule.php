<?php

namespace StripeIntegration\Payments\Model\Stripe;

class SubscriptionSchedule extends StripeObject
{
    protected $objectSpace = 'subscriptionSchedules';

    public function getNextBillingTimestamp()
    {
        $nextPhase = $this->getNextPhase();
        if (empty($nextPhase->start_date))
            return null;

        return $nextPhase->start_date;
    }

    protected function getNextPhase()
    {
        if (empty($this->object->current_phase->end_date))
            return null;

        foreach ($this->object->phases as $phase)
        {
            if ($phase->start_date == $this->object->current_phase->end_date)
                return $phase;
        }

        return null;
    }
}