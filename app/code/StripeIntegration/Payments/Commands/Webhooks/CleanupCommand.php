<?php

namespace StripeIntegration\Payments\Commands\Webhooks;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CleanupCommand extends Command
{
    protected function configure()
    {
        $this->setName('stripe:webhooks:cleanup');
        $this->setDescription('Removes products named "Webhook Ping"');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new \Symfony\Component\Console\Style\SymfonyStyle($input, $output);
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        $areaCode = $objectManager->create('StripeIntegration\Payments\Helper\AreaCode');
        $areaCode->setAreaCode();

        $webhooksSetup = $objectManager->create('StripeIntegration\Payments\Helper\WebhooksSetup');
        $config = $objectManager->create('StripeIntegration\Payments\Model\Config');
        $keys = $webhooksSetup->getAllActiveAPIKeys();

        foreach ($keys as $secretKey => $publishableKey)
        {
            $config->initStripeFromSecretKey($secretKey);
            $stripe = $config->getStripeClient();
            $products = $stripe->products->all(['limit' => 100]);
            $io->progressStart($products->count());
            try
            {
                foreach ($products->autoPagingIterator() as $product)
                {
                    if ($product->name == "Webhook Ping")
                    {
                        $product->delete();
                    }
                    $io->progressAdvance();
                }
            }
            catch (\Exception $e)
            {
                $io->note($e->getMessage());
            }
            $io->progressFinish();
        }

        return 0;
    }
}
