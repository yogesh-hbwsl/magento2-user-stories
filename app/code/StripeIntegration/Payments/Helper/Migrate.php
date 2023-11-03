<?php

namespace StripeIntegration\Payments\Helper;

use Psr\Log\LoggerInterface;
use StripeIntegration\Payments\Helper\Logger;

class Migrate
{
    public $areaCode = null;

    public $methods = [
        // Old Stripe official module
        "stripecreditcards" => "stripe_payments",
        "stripesofort" => "stripe_payments",
        "stripebancontact" => "stripe_payments",
        "stripealipay" => "stripe_payments",
        "stripegiropay" => "stripe_payments",
        "stripeideal" => "stripe_payments",
        "stripeinstantcheckout" => "stripe_payments",
        "stripeprzelewy" => "stripe_payments",
        "stripesepa" => "stripe_payments",

        // Cryozonic modules
        "cryozonic_stripe" => "stripe_payments",
        "cryozonic_europayments_bancontact" => "stripe_payments",
        "cryozonic_europayments_giropay" => "stripe_payments",
        "cryozonic_europayments_ideal" => "stripe_payments",
        "cryozonic_europayments_multibanco" => "stripe_payments",
        "cryozonic_europayments_eps" => "stripe_payments",
        "cryozonic_europayments_p24" => "stripe_payments",
        "cryozonic_europayments_sepa" => "stripe_payments",
        "cryozonic_europayments_sofort" => "stripe_payments",
        "cryozonic_chinapayments_alipay" => "stripe_payments",
        "cryozonic_chinapayments_wechat" => "stripe_payments",

        // Versions 1.0.0 - 2.8.3
        "stripe_payments_ach" => "stripe_payments",
        "stripe_payments_alipay" => "stripe_payments",
        "stripe_payments_bancontact" => "stripe_payments",
        "stripe_payments_eps" => "stripe_payments",
        "stripe_payments_giropay" => "stripe_payments",
        "stripe_payments_ideal" => "stripe_payments",
        "stripe_payments_p24" => "stripe_payments",
        "stripe_payments_sepa" => "stripe_payments",
        "stripe_payments_sepa_credit" => "stripe_payments",
        "stripe_payments_sofort" => "stripe_payments",
        "stripe_payments_multibanco" => "stripe_payments",
        "stripe_payments_wechat" => "stripe_payments",
        "stripe_payments_fpx" => "stripe_payments",
        "stripe_payments_klarna" => "stripe_payments",
        "stripe_payments_paypal" => "stripe_payments",
        "stripe_payments_oxxo" => "stripe_payments",
    ];

    private $productRepository;
    private $objectManager;
    private $paymentsCollection;
    private $productCollectionFactory;
    private $productAction;

    public function __construct(
        \Magento\Sales\Model\ResourceModel\Order\Payment\Collection $paymentsCollection,
        \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollectionFactory,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        \Magento\Catalog\Model\Product\Action $productAction
    )
    {
        $this->paymentsCollection = $paymentsCollection;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->productRepository = $productRepository;
        $this->productAction = $productAction;
        $this->objectManager = \Magento\Framework\App\ObjectManager::getInstance();
    }

    public function initAreaCode()
    {
        if ($this->areaCode)
            return;

        $this->areaCode = $this->objectManager->get('StripeIntegration\Payments\Helper\AreaCode');
        $this->areaCode->setAreaCode();
    }

    public function orders()
    {
        $this->initAreaCode();
        $fromMethods = array_keys($this->methods);
        $collection = $this->paymentsCollection->addFieldToFilter("method", ["in" => $fromMethods]);
        // echo "\n";
        foreach ($collection as $entry)
        {
            $from = $entry->getMethod();
            $to = $this->methods[$from];
            // echo $entry->getEntityId() . ": $from => $to\n";
            $entry->setMethod($to);
            $entry->save();
        }
    }

    public function customers($setup)
    {
        $this->initAreaCode();
        $table = $setup->getTable('cryozonic_stripe_customers');
        if ($setup->tableExists('cryozonic_stripe_customers'))
        {
            $select = $setup->getConnection()->select()->from(['customers' => $setup->getTable('cryozonic_stripe_customers')]);
            $insertArray = [
                'id',
                'customer_id',
                'stripe_id',
                'last_retrieved',
                'customer_email',
                'session_id'
            ];
            $sqlQuery = $select->insertFromSelect(
                $setup->getTable('stripe_customers'),
                $insertArray,
                false
            );
            try
            {
                $setup->getConnection()->query($sqlQuery);
            }
            catch (\Exception $e)
            {
                // Integrity constraint violations
            }
        }
    }

    public function subscriptions($setup)
    {
        $this->initAreaCode();
        $subscriptionProducts = $this->productCollectionFactory->create();

        try
        {
            $subscriptionProducts->addAttributeToSelect('*')
                ->addAttributeToFilter('cryozonic_sub_enabled', 1)
                ->load();

            foreach ($subscriptionProducts as $subscriptionProduct)
            {
                $this->productAction->updateAttributes([ $subscriptionProduct->getId() ], [
                    "stripe_sub_enabled" => $subscriptionProduct->getCryozonicSubEnabled(),
                    "stripe_sub_interval" => $subscriptionProduct->getCryozonicSubInterval(),
                    "stripe_sub_interval_count" => $subscriptionProduct->getCryozonicSubIntervalCount(),
                    "stripe_sub_trial" => $subscriptionProduct->getCryozonicSubTrial(),
                    "stripe_sub_initial_fee" => $subscriptionProduct->getCryozonicSubInitialFee()
                ], 0);
            }
        }
        catch (\Exception $e)
        {
            // The cryozonic_sub_enabled attribute does not exist
        }
    }
}
