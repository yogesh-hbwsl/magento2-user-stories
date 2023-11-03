<?php

namespace StripeIntegration\Payments\Model\Stripe\Event;

use StripeIntegration\Payments\Exception\WebhookException;

class InvoiceUpcoming extends \StripeIntegration\Payments\Model\Stripe\Event
{
    protected $paymentsHelper;
    protected $subscriptionsHelper;
    protected $config;
    protected $recurringOrderHelper;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Data $dataHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\Webhooks $webhooksHelper,
        \StripeIntegration\Payments\Helper\Generic $paymentsHelper,
        \StripeIntegration\Payments\Helper\RecurringOrder $recurringOrderHelper,
        \StripeIntegration\Payments\Helper\RequestCache $requestCache,
        \StripeIntegration\Payments\Helper\Compare $compare
    )
    {
        $this->config = $config;
        $this->paymentsHelper = $paymentsHelper;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->recurringOrderHelper = $recurringOrderHelper;

        parent::__construct($config, $helper, $dataHelper, $subscriptionsHelper, $webhooksHelper, $requestCache, $compare);
    }

    public function process($object)
    {
        $metadata = null;

        foreach ($object['lines']['data'] as $lineItem)
        {
            if ($lineItem['type'] == "subscription" && !empty($lineItem['metadata']['Order #']))
            {
                $metadata = $lineItem['metadata'];
            }
        }

        if (!$metadata)
        {
            throw new WebhookException("No metadata found", 202);
        }

        /**
         * An easy way to test this in development is to do the following:
         * 1. Place a new subscription order for any subscription product
         * 2. Find the subscription from the Stripe dashboard, and copy the metadata and subscription ID to the 4 variables below
         * 3. Find a historical invoice.upcoming event from https://dashboard.stripe.com/test/events?type=invoice.upcoming
         * 4. Use the command bin/magento stripe:webhooks:process-event -f <event_id>
         *
         * Because you have overwritten the data below, the event will be processed on the new subscription created on step 1
         * and not on the original subscription from the event ID that you used.
         */
        // $metadata['Order #'] = "2000002394";
        // $metadata['SubscriptionProductIDs'] = "2044";
        // $metadata['Type'] = "SubscriptionsTotal";
        // $object['subscription'] = "sub_1NL157HLyfDWKHBqHEC9wLdt";

        // Fetch the subscription, expanding its discount
        $subscription = $this->config->getStripeClient()->subscriptions->retrieve(
            $object['subscription'],
            ['expand' => ['discount']]
        );

        // Initialize Stripe to match the store of the original order
        $originalOrder = $this->paymentsHelper->loadOrderByIncrementId($metadata['Order #']);
        $mode = ($object['livemode'] ? "live" : "test");
        $this->config->reInitStripe($originalOrder->getStoreId(), $originalOrder->getOrderCurrencyCode(), $mode);

        // Get the tax percent from the original order
        $originalSubscriptions = $this->subscriptionsHelper->getSubscriptionsFromOrder($originalOrder);
        if (count($originalSubscriptions) < 1)
        {
            throw new WebhookException("No subscriptions found in original order");
        }
        $originalSubscription = array_pop($originalSubscriptions);
        $originalOrderItem = $originalSubscription['order_item'];
        $originalTaxPercent = $originalOrderItem->getTaxPercent();

        // Get the upcoming invoice of the subscription
        $upcomingInvoice = $this->config->getStripeClient()->invoices->upcoming([
            'subscription' => $object['subscription']
        ]);
        $invoiceDetails = $this->recurringOrderHelper->getInvoiceDetails($upcomingInvoice, $originalOrder);

        // Create a recurring order quote, without saving the quote or the order
        $quote = $this->recurringOrderHelper->createQuoteFrom($originalOrder);
        $this->recurringOrderHelper->setQuoteCustomerFrom($originalOrder, $quote);
        $this->recurringOrderHelper->setQuoteAddressesFrom($originalOrder, $quote);
        $this->recurringOrderHelper->setQuoteItemsFrom($originalOrder, $invoiceDetails, $quote);
        $this->recurringOrderHelper->setQuoteShippingMethodFrom($originalOrder, $quote);
        $this->recurringOrderHelper->setQuoteDiscountFrom($originalOrder, $quote, $subscription->discount);
        $this->recurringOrderHelper->setQuotePaymentMethodFrom($originalOrder, $quote);
        $quote->setTotalsCollectedFlag(false)->collectTotals();
        $quote->setIsActive(false);
        $this->paymentsHelper->saveQuote($quote);

        // Check if the tax percent has changed for the subscription item
        $newTaxPercent = $this->getNewTaxPercent($quote, $originalOrderItem);

        if ($newTaxPercent === null)
        {
            throw new WebhookException("The new tax percent could not be calculated");
        }

        // If the tax percentage has changed, update the subscription price to match it
        if ($originalTaxPercent != $newTaxPercent)
        {
            if (!empty($upcomingInvoice->discount))
            {
                throw new WebhookException("This subscription cannot be changed because it's upcoming invoice includes a discount coupon.");
            }

            $subscription = $this->config->getStripeClient()->subscriptions->retrieve($object['subscription']);
            $this->updateSubscriptionPriceFromQuote($subscription, $quote);
        }
    }

    protected function getNewTaxPercent($quote, $originalOrderItem)
    {
        foreach ($quote->getAllItems() as $quoteItem)
        {
            if ($quoteItem->getProductId() == $originalOrderItem->getProductId())
            {
                return $quoteItem->getTaxPercent();
            }
        }

        return null;
    }

    protected function updateSubscriptionPriceFromQuote($originalSubscription, $quote, $prorate = false)
    {
        $params = $this->getSubscriptionParamsFromQuote($quote);

        if (empty($params['items']))
        {
            throw new WebhookException("Could not update subscription price.");
        }

        $deletedItems = [];
        foreach ($originalSubscription->items->data as $lineItem)
        {
            $deletedItems[] = [
                "id" => $lineItem['id'],
                "deleted" => true
            ];
        }

        $items = array_merge($deletedItems, $params['items']);
        $updateParams = [
            'items' => $items
        ];

        if (!$prorate)
        {
            $updateParams["proration_behavior"] = "none";
        }

        return $this->config->getStripeClient()->subscriptions->update($originalSubscription->id, $updateParams);
    }

    protected function getSubscriptionParamsFromQuote($quote)
    {
        $subscription = $this->subscriptionsHelper->getSubscriptionFromQuote($quote);

        $params = [
            'items' => $this->getSubscriptionItemsFromQuote($quote, $subscription)
        ];

        return $params;
    }

    protected function getSubscriptionItemsFromQuote($quote, $subscription)
    {
        if (empty($subscription))
        {
            throw new WebhookException("No subscription specified");
        }

        $recurringPrice = $this->subscriptionsHelper->createSubscriptionPriceForSubscription($subscription);

        $items = [];

        $items[] = [
            "price" => $recurringPrice->id,
            "quantity" => 1
        ];

        return $items;
    }
}