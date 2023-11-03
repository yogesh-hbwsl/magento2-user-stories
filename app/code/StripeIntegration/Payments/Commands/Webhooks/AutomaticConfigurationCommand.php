<?php

namespace StripeIntegration\Payments\Commands\Webhooks;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class AutomaticConfigurationCommand extends Command
{
    private $resourceConfig;

    public function __construct(
        \Magento\Config\Model\ResourceModel\Config $resourceConfig
    )
    {
        $this->resourceConfig = $resourceConfig;

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('stripe:webhooks:automatic-configuration');
        $this->setDescription('Enable or disable automatic webhooks configuration.');
        $this->addArgument('enabled', \Symfony\Component\Console\Input\InputArgument::REQUIRED);
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $enabled = $input->getArgument("enabled");

        $areaCode = $objectManager->create('StripeIntegration\Payments\Helper\AreaCode');
        $areaCode->setAreaCode();

        if ($enabled)
        {
            $this->resourceConfig->saveConfig("stripe_settings/automatic_webhooks_configuration", 1, 'default', 0);
            $output->writeln("Enabled automatic webhooks configuration.");
        }
        else
        {
            $this->resourceConfig->saveConfig("stripe_settings/automatic_webhooks_configuration", 0, 'default', 0);
            $output->writeln("Disabled automatic webhooks configuration.");
        }

        return 0;
    }
}
