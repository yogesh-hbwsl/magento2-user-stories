<?php
namespace StripeIntegration\Payments\Model\SubscriptionOptions;

use Magento\Framework\EntityManager\Operation\ExtensionInterface;
use StripeIntegration\Payments\Model\SubscriptionOptionsFactory as SubscriptionOptionsModelFactory;

class ReadHandler implements ExtensionInterface
{
    private SubscriptionOptionsModelFactory $subscriptionOptionsModelFactory;

    public function __construct(
        SubscriptionOptionsModelFactory $subscriptionOptionsModelFactory
    ) {
        $this->subscriptionOptionsModelFactory = $subscriptionOptionsModelFactory;
    }

    /**
     * Read the custom field values from custom table
     *
     * @param object $entity
     * @param array<mixed> $arguments
     * @return bool|object
     * @throws \Exception
     */
    public function execute($entity, $arguments = [])
    {
        $subscriptionOptionsModel = $this->subscriptionOptionsModelFactory->create()->load($entity->getId());

        if (!$subscriptionOptionsModel->getId())
        {
            return $entity;
        }

        $extensionAttributes = $entity->getExtensionAttributes();

        if (method_exists($extensionAttributes, 'setSubscriptionOptions'))
        {
            $extensionAttributes->setSubscriptionOptions($subscriptionOptionsModel);
            $entity->setExtensionAttributes($extensionAttributes);
        }

        return $entity;
    }
}
