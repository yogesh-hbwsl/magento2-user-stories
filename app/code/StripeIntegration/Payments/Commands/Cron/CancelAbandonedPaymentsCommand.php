<?php

namespace StripeIntegration\Payments\Commands\Cron;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CancelAbandonedPaymentsCommand extends Command
{
    protected function configure()
    {
        $this->setName('stripe:cron:cancel-abandoned-payments');
        $this->setDescription('Cancels pending Magento orders and incomplete payments of a specific age.');
        $this->addArgument('min_age_minutes', \Symfony\Component\Console\Input\InputArgument::REQUIRED);
        $this->addArgument('max_age_minutes', \Symfony\Component\Console\Input\InputArgument::REQUIRED);
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new \Symfony\Component\Console\Style\SymfonyStyle($input, $output);
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        $areaCode = $objectManager->create('StripeIntegration\Payments\Helper\AreaCode');
        $areaCode->setAreaCode();

        $cron = $objectManager->create('StripeIntegration\Payments\Cron\WebhooksPing');
        $minAgeMinutes = $input->getArgument("min_age_minutes");
        $maxAgeMinutes = $input->getArgument("max_age_minutes");

        if (!is_numeric($minAgeMinutes) || $minAgeMinutes < 0)
        {
            throw new \Exception("Invalid minimum age.");
        }

        if ($minAgeMinutes < (2*60))
        {
            throw new \Exception("Minimum age must be at least 2 hours.");
        }

        if (!is_numeric($maxAgeMinutes))
        {
            throw new \Exception("Invalid maximum age.");
        }

        if ($maxAgeMinutes <= (2*60))
        {
            throw new \Exception("Maximum age must be larger than 2 hours.");
        }

        $cron->cancelAbandonedPayments($minAgeMinutes, $maxAgeMinutes, $output);

        return 0;
    }
}
