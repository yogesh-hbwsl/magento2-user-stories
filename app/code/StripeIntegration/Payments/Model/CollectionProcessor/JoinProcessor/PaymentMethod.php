<?php
declare(strict_types=1);

namespace StripeIntegration\Payments\Model\CollectionProcessor\JoinProcessor;

use Magento\Framework\Api\ExtensionAttribute\JoinProcessorInterface;
use Magento\Framework\Api\SearchCriteria\CollectionProcessor\JoinProcessor\CustomJoinInterface;
use Magento\Framework\Api\ExtensionAttribute\JoinDataInterfaceFactory;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Sales\Api\Data\OrderInterface;

class PaymentMethod implements CustomJoinInterface
{
    /**
     * @var JoinDataInterfaceFactory
     */
    private $joinDataFactory;

    /**
     * @var JoinProcessorInterface
     */
    private $joinProcessor;

    /**
     * OrderTransaction constructor.
     *
     * @param JoinDataInterfaceFactory $joinDataFactory
     * @param JoinProcessorInterface $joinProcessor
     */
    public function __construct(
        JoinDataInterfaceFactory $joinDataFactory,
        JoinProcessorInterface $joinProcessor
    ) {
        $this->joinDataFactory = $joinDataFactory;
        $this->joinProcessor = $joinProcessor;
    }

    /**
     * @inheritDoc
     */
    public function apply(AbstractDb $collection)
    {
        $joinData = $this->joinDataFactory->create();
        $joinData->setJoinField(OrderInterface::ENTITY_ID)
            ->setReferenceTable('stripe_payment_methods')
            ->setReferenceField('order_id')
            ->setReferenceTableAlias('stripe_payment_methods')
            ->setSelectFields([]);
        $collection->joinExtensionAttribute($joinData, $this->joinProcessor);
        return true;
    }
}
