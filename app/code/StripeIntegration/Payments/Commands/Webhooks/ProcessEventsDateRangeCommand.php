<?php

namespace StripeIntegration\Payments\Commands\Webhooks;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProcessEventsDateRangeCommand extends Command
{
    protected function configure()
    {
        $this->setName('stripe:webhooks:process-events-date-range');
        $this->setDescription('Process or resend webhook events that were triggered between two dates.');
        $this->addArgument('from_date', \Symfony\Component\Console\Input\InputArgument::REQUIRED);
        $this->addArgument('to_date', \Symfony\Component\Console\Input\InputArgument::OPTIONAL);
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new \Symfony\Component\Console\Style\SymfonyStyle($input, $output);
        $fromDate = strtotime($input->getArgument("from_date"));
        $fromDateReadable = date('jS F Y h:i:s A', $fromDate);
        if ($input->getArgument("to_date"))
        {
            $toDate = strtotime($input->getArgument("to_date"));
        }
        else
        {
            $toDate = time();
        }

        $toDateReadable = date('jS F Y h:i:s A', $toDate);

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        $areaCode = $objectManager->create('StripeIntegration\Payments\Helper\AreaCode');
        $areaCode->setAreaCode();

        $webhookEventHelper = $objectManager->get(\StripeIntegration\Payments\Helper\WebhookEvent::class);
        $webhooks = $objectManager->get(\StripeIntegration\Payments\Helper\Webhooks::class);
        $webhooks->setOutput($output);
        $webhookEventHelper->initStripeClientForStore($io);
        $events = $webhookEventHelper->getEventRange($fromDate, $toDate);

        if (!empty($events))
        {
            $count = $events->count();
            $output->writeln(">>> Fount $count events between $fromDateReadable - $toDateReadable");
            foreach ($events->autoPagingIterator() as $event)
            {
                $output->writeln(">>> Processing event {$event->id}");
                $webhooks->dispatchEvent($event);
            }
        }
        else
        {
            $output->writeln(">>> No events found between $fromDateReadable - $toDateReadable");
        }

        return 0;
    }
}
