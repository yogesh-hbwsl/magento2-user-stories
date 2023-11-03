<?php

namespace StripeIntegration\Payments\Model\ResourceModel\Webhook;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected $_idFieldName = 'id';

    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\Webhook', 'StripeIntegration\Payments\Model\ResourceModel\Webhook');
    }

    public function findFromRequest($request)
    {
        if (!isset($_SERVER['HTTP_STRIPE_SIGNATURE']))
        {
            return null;
        }

        $webhooks = $this->getAllWebhooks();

        foreach ($webhooks as $webhook)
        {
            $signingSecret = $webhook->getSecret();
            if (empty($signingSecret))
                continue;

            try
            {
                // throws SignatureVerificationException
                $event = \Stripe\Webhook::constructEvent($request->getContent(), $_SERVER['HTTP_STRIPE_SIGNATURE'], $signingSecret);

                // Success
                return $webhook;
            }
            catch(\Exception $e)
            {
                continue;
            }
        }

        return null;
    }

    public function findStaleWebhooks()
    {
        $fourHoursAgo = time() - 4 * 60 * 60;

        $collection = $this
            ->addFieldToSelect('*')
            ->addFieldToFilter('last_event', ['lt' => $fourHoursAgo]);

        return $collection;
    }

    public function getWebhooks($storeCode, $publishableKey)
    {
        $collection = $this
            ->addFieldToSelect('*')
            ->addFieldToFilter('store_code', ['eq' => $storeCode])
            ->addFieldToFilter('publishable_key', ['eq' => $publishableKey]);

        return $collection;
    }

    public function getAllWebhooks($current = false)
    {
        $collection = $this
            ->addFieldToSelect('*');

        if ($current)
            $collection->addFieldToFilter('config_version', ['eq' => \StripeIntegration\Payments\Helper\WebhooksSetup::VERSION]);

        return $collection;
    }

    public function pong($publishableKey)
    {
        $collection = $this
            ->addFieldToSelect('*')
            ->addFieldToFilter('publishable_key', ['eq' => $publishableKey]);

        foreach ($collection as $webhook)
        {
            $webhook->setLastEvent(time());
            if (!$webhook->getActive())
                $webhook->setActive(1);
        }

        $collection->save();
    }
}
