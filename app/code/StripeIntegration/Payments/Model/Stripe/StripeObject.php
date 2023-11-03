<?php

namespace StripeIntegration\Payments\Model\Stripe;

use StripeIntegration\Payments\Helper\Logger;

abstract class StripeObject
{
    public $lastError = null;
    public $expandParams = [];

    protected $objectSpace = null;
    protected ?\Stripe\StripeObject $object = null;
    protected $objectManager;
    protected $subscriptionsHelper;
    protected $config;
    protected $helper;
    protected $dataHelper;
    protected $requestCache;
    protected $compare;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Data $dataHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\RequestCache $requestCache,
        \StripeIntegration\Payments\Helper\Compare $compare
    )
    {
        $this->config = $config;
        $this->helper = $helper;
        $this->dataHelper = $dataHelper;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->requestCache = $requestCache;
        $this->compare = $compare;
        $this->objectManager = \Magento\Framework\App\ObjectManager::getInstance();
    }

    public function getStripeObject()
    {
        return $this->object;
    }

    public function lookupSingle($key)
    {
        $cacheKey = $this->objectSpace . "_" . $key;
        $item = $this->requestCache->get($cacheKey);
        if ($item)
        {
            $this->object = $item;
            return $item;
        }

        $items = $this->objectSpace()->all(['lookup_keys' => [$key], 'limit' => 1]);
        $this->object = $items->first();
        $this->requestCache->set($cacheKey, $this->object);
        return $this->object;
    }

    public function destroy()
    {
        if (!$this->object || empty($this->object->id))
            return;

        $this->objectSpace()->delete($this->object->id, []);
    }

    public function getType()
    {
        return $this->objectSpace;
    }

    public function objectSpace()
    {
        $client = $this->config->getStripeClient();

        if (strpos($this->objectSpace, ".") !== false)
        {
            $parts = explode(".", $this->objectSpace);
            foreach ($parts as $part)
                $client = $client->{$part};

            return $client;
        }
        else
        {
            return $client->{$this->objectSpace};
        }
    }

    public function getId()
    {
        if (empty($this->object->id))
            return null;

        return $this->object->id;
    }

    public function load($id)
    {
        $this->object = $this->getObject($id);
        return $this;
    }

    public function getStripeUrl()
    {
        if (empty($this->object))
            return null;

        if ($this->object->livemode)
            return "https://dashboard.stripe.com/{$this->objectSpace}/{$this->object->id}";
        else
            return "https://dashboard.stripe.com/test/{$this->objectSpace}/{$this->object->id}";
    }

    public function setExpandParams($params)
    {
        $this->expandParams = $params;
        return $this;
    }

    protected function upsert($id, $data)
    {
        $this->object = $this->getObject($id);

        if (!$this->object)
        {
            if (!empty($id))
            {
                $data["id"] = $id;
            }

            return $this->createObject($data);
        }
        else
            return $this->updateObject($id, $data);
    }

    protected function getObject($id)
    {
        if (empty($id))
            return null;

        try
        {
            $key = $this->objectSpace . "_" . $id;
            $this->object = $this->requestCache->get($key);

            if (empty($this->object))
            {
                $this->object = $this->objectSpace()->retrieve($id, ['expand' => $this->expandParams]);
                $this->requestCache->set($key, $this->object);
            }

            return $this->object;
        }
        catch (\Exception $e)
        {
            Logger::log($e->getMessage());
            return null;
        }
    }

    protected function setObject($object)
    {
        if (empty($object))
            throw new \Exception("Invalid Stripe object specified");

        $this->object = $object;

        return $this;
    }

    protected function createObject($data)
    {
        try
        {
            $this->lastError = null;
            $this->object = $this->objectSpace()->create($data);
            return $this->object;
        }
        catch (\Exception $e)
        {
            $this->lastError = $e->getMessage();
            Logger::log($e->getMessage());
            Logger::log($e->getTraceAsString());
            return $this->object = null;
        }
    }

    protected function updateObject($id, $data)
    {
        try
        {
            if ($this->compare->isDifferent($this->object, $data))
            {
                $this->object = $this->objectSpace()->update($id, $data);
                $this->requestCache->set($this->objectSpace . "_" . $id, $this->object);
            }

            return $this->object;
        }
        catch (\Exception $e)
        {
            Logger::log($e->getMessage());
            Logger::log($e->getTraceAsString());
            return $this->object = null;
        }
    }
}
