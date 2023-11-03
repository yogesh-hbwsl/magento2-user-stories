<?php
namespace StripeIntegration\Payments\Plugin\Quote;

class ExpiredQuotesCollection
{
    public function afterGetExpiredQuotes($store, $quoteCollection)
    {
        try
        {
            $quoteCollection->addFieldToFilter('is_used_for_recurring_orders', ['neq' => true]);
            return $quoteCollection;
        }
        catch (\Exception $e)
        {
            return $quoteCollection;
        }
    }
}
