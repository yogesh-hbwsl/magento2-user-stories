<?php

namespace StripeIntegration\Payments\Commands\Subscriptions;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class MigrateSubscriptionPriceCommand extends Command
{
    protected $store = null;
    protected $config = null;
    protected $helper = null;
    protected $subscriptionSwitch;
    protected $fromProduct;
    protected $toProduct;
    private $fromProductId;
    private $toProductId;
    private $storeManager;
    private $objectManager;
    private $resource;
    private $subscriptionHelper;

    public function __construct(
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\App\ResourceConnection $resource
    )
    {
        $this->storeManager = $storeManager;
        $this->objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->resource = $resource;

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('stripe:subscriptions:migrate-subscription-price');
        $this->setDescription('Switches existing subscriptions from one plan to a new one with different pricing.');
        $this->addArgument('original_product_id', InputArgument::REQUIRED);
        $this->addArgument('new_product_id', InputArgument::REQUIRED); // This can be the same as the original product ID
        $this->addArgument('starting_order_id', InputArgument::OPTIONAL);
        $this->addArgument('ending_order_id', InputArgument::OPTIONAL);
    }

    protected function init($input)
    {
        $areaCode = $this->objectManager->create('StripeIntegration\Payments\Helper\AreaCode');
        $areaCode->setAreaCode();

        $this->config = $this->objectManager->create('StripeIntegration\Payments\Model\Config');
        $this->helper = $this->objectManager->create('StripeIntegration\Payments\Helper\Generic');
        $this->subscriptionHelper = $this->objectManager->create('StripeIntegration\Payments\Helper\Subscriptions');
        $this->subscriptionSwitch = $this->objectManager->create('StripeIntegration\Payments\Helper\SubscriptionSwitch');

        $this->fromProductId = $input->getArgument("original_product_id");
        $this->toProductId = $input->getArgument("new_product_id");

        $this->fromProduct = $this->helper->loadProductById($this->fromProductId);
        $this->toProduct = $this->helper->loadProductById($this->toProductId);

        if (!$this->fromProduct || !$this->fromProduct->getId())
            throw new \Exception("No such product with ID " . $this->fromProductId);

        if (!$this->toProduct || !$this->toProduct->getId())
            throw new \Exception("No such product with ID " . $this->toProductId);

        if (!$this->subscriptionHelper->isSubscriptionOptionEnabled($this->fromProduct->getId()))
            throw new \Exception($this->fromProduct->getName() . " is not a subscription product");

        if (!$this->subscriptionHelper->isSubscriptionOptionEnabled($this->toProduct->getId()))
            throw new \Exception($this->toProduct->getName() . " is not a subscription product");

        if ($this->fromProduct->getTypeId() == "virtual" && $this->toProduct->getTypeId() == "simple")
            throw new \Exception("It is not possible to migrate Virtual subscriptions to Simple subscriptions because we don't have a shipping address.");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln("Loading ...");

        $this->init($input);

        $orders = $this->getOrders($input);

        foreach ($orders as $order)
        {
            $this->migrateOrder($order, $output);
        }

        return 0;
    }

    protected function migrateOrder($order, $output)
    {
        $this->initStripeFrom($order);

        if (!$this->config->isInitialized())
        {
            $output->writeln("Could not migrate order #" . $order->getIncrementId() . " because Stripe could not be initialized for store " . $order->getStore()->getName());
            return;
        }

        try
        {
            $migrated = $this->subscriptionSwitch->run($order, $this->fromProduct, $this->toProduct);
            if ($migrated)
                $output->writeln("Successfully migrated order #" . $order->getIncrementId());
        }
        catch (\Exception $e)
        {
            $output->writeln("Could not migrate order #" . $order->getIncrementId() . ": " . $e->getMessage());
        }
    }

    public function initStripeFrom($order)
    {
        $mode = $this->config->getConfigData("mode", "basic", $order->getStoreId());
        $this->config->reInitStripe($order->getStoreId(), $order->getOrderCurrencyCode(), $mode);
    }

    protected function getOrders($input)
    {
        $orderCollection = $this->objectManager->create('\Magento\Sales\Model\ResourceModel\Order\Collection');

        $fromOrderId = $input->getArgument('starting_order_id');
        $toOrderId = $input->getArgument('ending_order_id');

        if (!empty($fromOrderId) && !is_numeric($fromOrderId))
            throw new \Exception("Error: starting_order_id is not a number");

        if (!empty($toOrderId) && !is_numeric($toOrderId))
            throw new \Exception("Error: ending_order_id is not a number");

        if (!empty($fromOrderId))
            $orderCollection->addAttributeToFilter('entity_id', array('gteq' => (int)$fromOrderId));

        if (!empty($toOrderId))
            $orderCollection->addAttributeToFilter('entity_id', array('lteq' => (int)$toOrderId));

        $orderCollection->addAttributeToSelect('*')
            ->getSelect()
            ->join(
                ['payment' => $this->resource->getTableName('sales_order_payment')],
                "payment.parent_id = main_table.entity_id",
                []
            )
            ->where("payment.method IN ('stripe_payments', 'stripe_payments_express', 'stripe_payments_checkout')");

        $orders = $orderCollection;

        if ($orders->count() == 0)
            throw new \Exception("Could not find any orders to process");

        return $orders;
    }
}
