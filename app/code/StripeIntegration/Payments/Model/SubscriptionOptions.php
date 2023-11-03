<?php

namespace StripeIntegration\Payments\Model;

use Magento\Framework\Api\ExtensibleDataInterface;
use StripeIntegration\Payments\Api\Data\SubscriptionOptionsInterface;
use StripeIntegration\Payments\Helper\Data as DataHelper;

class SubscriptionOptions extends \Magento\Framework\Model\AbstractModel implements SubscriptionOptionsInterface
{
    private $config;

    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\ResourceModel\SubscriptionOptions');
    }

    public function getUpgradesDowngrades()
    {
        if ($this->getUpgradesDowngradesUseConfig())
        {
            return $this->getConfig()->getConfigData("upgrade_downgrade", "subscriptions");
        }
        else
        {
            return $this->getData("upgrades_downgrades");
        }
    }

    public function getProrateUpgrades()
    {
        if ($this->getProrateUpgradesUseConfig())
        {
            return $this->getConfig()->getConfigData("prorations_upgrades", "subscriptions");
        }
        else
        {
            return $this->getData("prorate_upgrades");
        }
    }

    public function getProrateDowngrades()
    {
        if ($this->getProrateDowngradesUseConfig())
        {
            return $this->getConfig()->getConfigData("prorations_downgrades", "subscriptions");
        }
        else
        {
            return $this->getData("prorate_downgrades");
        }
    }

    protected function getConfig()
    {
        if (!isset($this->config))
            $this->config = DataHelper::getSingleton(\StripeIntegration\Payments\Model\Config::class);

        return $this->config;
    }
}
