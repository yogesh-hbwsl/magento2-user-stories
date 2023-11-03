<?php

namespace StripeIntegration\Payments\Test\Integration\Mock\StripeIntegration\Payments\Commands\Subscriptions;

class MigrateSubscriptionPriceCommand extends \StripeIntegration\Payments\Commands\Subscriptions\MigrateSubscriptionPriceCommand
{
    protected function migrateOrder($order, $output)
    {
        $this->initStripeFrom($order);

        if (!$this->config->isInitialized())
        {
            $output->writeln("Could not migrate order #" . $order->getIncrementId() . " because Stripe could not be initialized for store " . $order->getStore()->getName());
            return;
        }

        $migrated = $this->subscriptionSwitch->run($order, $this->fromProduct, $this->toProduct);
        if ($migrated)
            $output->writeln("Successfully migrated order #" . $order->getIncrementId());
    }
}
