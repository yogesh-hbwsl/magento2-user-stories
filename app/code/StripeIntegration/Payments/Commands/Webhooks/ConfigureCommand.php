<?php

namespace StripeIntegration\Payments\Commands\Webhooks;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class ConfigureCommand extends Command
{
    protected function configure()
    {
        $this->setName('stripe:webhooks:configure');
        $this->setDescription('Creates or updates webhook endpoints in all Stripe accounts.');
        $this->addOption("interactive", 'i', InputOption::VALUE_NONE, 'Allows you to select a preferred webhooks URL to configure per Stripe account.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $interactive = $input->getOption("interactive");

        $areaCode = $objectManager->create('StripeIntegration\Payments\Helper\AreaCode');
        $areaCode->setAreaCode();

        $webhooksSetup = $objectManager->create('StripeIntegration\Payments\Helper\WebhooksSetup');

        if ($interactive)
        {
            $exitCode = $webhooksSetup->configureManually($input, $output);
        }
        else
        {
            $exitCode = $webhooksSetup->configure();
        }

        foreach ($webhooksSetup->successMessages as $successMessage)
        {
            $output->writeln("<info>{$successMessage}</info>");
        }

        if (count($webhooksSetup->errorMessages))
        {
            foreach ($webhooksSetup->errorMessages as $errorMessage)
            {
                $output->writeln("<error>{$errorMessage}</error>");
            }

            return 1;
        }

        return (int)$exitCode;
    }
}
