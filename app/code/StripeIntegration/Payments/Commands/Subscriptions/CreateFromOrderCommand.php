<?php

namespace StripeIntegration\Payments\Commands\Subscriptions;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class CreateFromOrderCommand extends Command
{
    protected $store = null;
    protected $config = null;
    protected $helper = null;
    protected $subscriptionsHelper = null;
    private $objectManager;
    private $resource;
    private $orderRepository;
    private $orderFactory;
    private $paymentIntentModel;
    private $output;
    private $stripeCustomerModel;
    private $storeManager;

    public function __construct(
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\App\ResourceConnection $resource,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Sales\Api\Data\OrderInterfaceFactory $orderFactory
    )
    {
        $this->storeManager = $storeManager;
        $this->objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->resource = $resource;
        $this->orderRepository = $orderRepository;
        $this->orderFactory = $orderFactory;

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('stripe:subscriptions:create-from-order');
        $this->setDescription('Creates a subscription in Stripe based on the items of an existing order.');
        $this->addArgument('order_increment_id', InputArgument::REQUIRED);
        $this->addArgument('first_billing_date', InputArgument::REQUIRED);
        $this->addArgument('customer_id', InputArgument::OPTIONAL);
        $this->addArgument('payment_method_id', InputArgument::OPTIONAL);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $areaCode = $this->objectManager->get('StripeIntegration\Payments\Helper\AreaCode');
        $areaCode->setAreaCode();

        $orderIncrementId = $input->getArgument("order_increment_id");
        $startDate = strtotime($input->getArgument("first_billing_date"));
        $startDateReadable = date('jS F Y h:i:s A', $startDate);
        $paymentMethodId = $input->getArgument("payment_method_id");
        $customerId = $input->getArgument("customer_id");

        if ($startDate < time() + 60)
        {
            throw new \Exception("The first billing date must be today or a future date. You specified: $startDateReadable");
        }

        $this->output = $output;

        $output->writeln("Creating new subscription from order #$orderIncrementId, starting on $startDateReadable");

        $this->createSubscriptionFromOrder($orderIncrementId, $startDate, $customerId, $paymentMethodId);

        return 0;
    }

    protected function initStripeFrom($order)
    {
        $mode = $this->config->getConfigData("mode", "basic", $order->getStoreId());
        $this->config->reInitStripe($order->getStoreId(), $order->getOrderCurrencyCode(), $mode);
    }

    protected function createSubscriptionFromOrder($orderIncrementId, $firstBillingDate, $customerId = null, $paymentMethodId = null)
    {
        $this->stripeCustomerModel = $this->objectManager->get('StripeIntegration\Payments\Model\StripeCustomer');
        $this->config = $this->objectManager->get('StripeIntegration\Payments\Model\Config');

        $order = $this->orderFactory->create()->loadByIncrementId($orderIncrementId);
        $this->initStripeFrom($order);

        $stripeCustomerModel = $this->loadOrCreateCustomer($order, $customerId);
        $paymentMethod = $this->getPaymentMethod($order, $paymentMethodId);

        if (!$paymentMethod)
        {
            $this->output->writeln("- The customer has no default payment method, and none was specified. Will create the subscription without payment details.");
        }

        // It's important for these to be loaded after the Stripe customer has been created
        $this->subscriptionsHelper = $this->objectManager->get('StripeIntegration\Payments\Helper\Subscriptions');
        $this->paymentIntentModel = $this->objectManager->get('StripeIntegration\Payments\Model\PaymentIntent');

        $subscription = $this->subscriptionsHelper->getSubscriptionFromOrder($order);

        if (empty($subscription))
        {
            throw new \Exception("This order does not include any subscriptions.");
        }

        $subscription = $this->subscriptionsHelper->createSubscriptionFromOrder($order, $stripeCustomerModel, $paymentMethod ? $paymentMethod->id : null, $firstBillingDate);
        $order->addStatusToHistory(false, "Subscription {$subscription->id} has been successfully created for this order via the command line.", false);
        $currentMethod = $order->getPayment()->getMethod();
        if ($currentMethod != "stripe_payments")
        {
            $order->addStatusToHistory(false, "Switching payment method from $currentMethod to stripe_payments", false);
            $order->getPayment()->setMethod("stripe_payments");
        }

        $order->getPayment()->setAdditionalInformation("subscription_id", $subscription->id);
        if ($paymentMethod)
        {
            $order->getPayment()->setAdditionalInformation("token", $paymentMethod->id);
        }

        $this->orderRepository->save($order);

        $this->output->writeln("- Subscription {$subscription->id} has been created successfully");
    }

    protected function loadOrCreateCustomer($order, $customerId)
    {
        if (!$customerId)
        {
            if ($order->getPayment()->getAdditionalInformation("customer_stripe_id"))
            {
                $customerId = $order->getPayment()->getAdditionalInformation("customer_stripe_id");
                $this->stripeCustomerModel->load($customerId, "stripe_id");

                if (!$this->stripeCustomerModel->existsInStripe())
                {
                    $this->stripeCustomerModel->createStripeCustomer($order);
                    $customerId = $this->stripeCustomerModel->getStripeId();
                    $this->output->writeln("- Created new Stripe customer with ID $customerId");
                }
                else
                {
                    $this->output->writeln("- Using existing Stripe customer with ID $customerId");
                }
            }
            else
            {
                $this->stripeCustomerModel->createStripeCustomer($order);
                $customerId = $this->stripeCustomerModel->getStripeId();
                $this->output->writeln("- Created new Stripe customer with ID $customerId");
            }
        }
        else
        {
            $this->stripeCustomerModel->load($customerId, "stripe_id");

            if (!$this->stripeCustomerModel->existsInStripe())
            {
                $this->stripeCustomerModel->createStripeCustomer($order);
                $customerId = $this->stripeCustomerModel->getStripeId();
                $this->output->writeln("- Created new Stripe customer with ID $customerId");
            }
            else
            {
                $this->output->writeln("- Using provided Stripe customer with ID $customerId");
            }
        }

        $order->getPayment()->setAdditionalInformation("customer_stripe_id", $customerId);
        $this->orderRepository->save($order);

        return $this->stripeCustomerModel;
    }

    protected function getPaymentMethod($order, $paymentMethodId)
    {
        if ($paymentMethodId)
        {
            $this->output->writeln("- Using payment method $paymentMethodId");
            try
            {
                return $this->config->getStripeClient()->paymentMethods->retrieve($paymentMethodId, []);
            }
            catch (\Exception $e)
            {
                throw new \Exception("Payment method $paymentMethodId cannot be used: " . $e->getMessage());
            }
        }
        else if ($order->getPayment()->getAdditionalInformation("token"))
        {
            $paymentMethodId = $order->getPayment()->getAdditionalInformation("token");
            $this->output->writeln("- Using existing payment method with ID $paymentMethodId");
            try
            {
                return $this->config->getStripeClient()->paymentMethods->retrieve($paymentMethodId, []);
            }
            catch (\Exception $e)
            {
                $this->output->writeln("-- Payment method $paymentMethodId cannot be used: " . $e->getMessage());
            }
        }

        return $this->stripeCustomerModel->getDefaultPaymentMethod();
    }
}
