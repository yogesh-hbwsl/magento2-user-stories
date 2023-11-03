<?php
namespace StripeIntegration\Payments\Model\Stripe\Source;

use Magento\Framework\Data\ValueSourceInterface;

class ConfigUpgradesDowngrades implements ValueSourceInterface
{
    private $config;

    public function __construct(
        \StripeIntegration\Payments\Model\Config $config
    )
    {
        $this->config = $config;
    }

    public function getValue($name)
    {
        return (bool)$this->config->getConfigData($name, "subscriptions");
    }
}
