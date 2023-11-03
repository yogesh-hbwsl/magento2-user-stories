<?php

namespace StripeIntegration\Payments\Test\Integration\Unit\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ProductExtensionAttributesTest extends \PHPUnit\Framework\TestCase
{
    private $objectManager;
    private $productRepository;
    private $filterGroup;
    private $filterBuilder;
    private $searchCriteria;
    private $subscriptionOptionsFactory;
    private $subscriptionOptionsCollectionFactory;

    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->productRepository = $this->objectManager->get(ProductRepositoryInterface::class);
        $this->filterGroup = $this->objectManager->get(\Magento\Framework\Api\Search\FilterGroup::class);
        $this->filterBuilder = $this->objectManager->get(\Magento\Framework\Api\FilterBuilder::class);
        $this->searchCriteria = $this->objectManager->get(\Magento\Framework\Api\SearchCriteriaInterface::class);
        $this->subscriptionOptionsFactory = $this->objectManager->get(\StripeIntegration\Payments\Model\SubscriptionOptionsFactory::class);
        $this->subscriptionOptionsCollectionFactory = $this->objectManager->get(\StripeIntegration\Payments\Model\ResourceModel\SubscriptionOptions\CollectionFactory::class);
    }

    public function testExtensionAttributes()
    {
        $simpleProductsFilter = $this->filterBuilder
            ->setField('type_id')
            ->setConditionType('eq')
            ->setValue('simple')
            ->create();

        $this->filterGroup->setFilters([ $simpleProductsFilter ]);
        $this->searchCriteria->setFilterGroups([ $this->filterGroup ]);
        $products = $this->productRepository->getList($this->searchCriteria)->getItems();
        $this->assertNotEmpty($products);

        $productId = null;
        $subscriptionOptionsModel = $this->subscriptionOptionsFactory->create();

        foreach ($products as $product)
        {
            $productId = $product->getId();
            $subscriptionOptionsModel
                ->setProductId($productId)
                ->setStartOnSpecificDate(1)
                ->setFirstPayment('on_order_date')
                ->setProrateFirstPayment(1)
                ->setStartDate('2023-04-02')
                ->save();

            $extensionAttributes = $product->getExtensionAttributes();
            $this->assertEmpty($extensionAttributes->getSubscriptionOptions());

            $extensionAttributes->setSubscriptionOptions($subscriptionOptionsModel);
            $product->setExtensionAttributes($extensionAttributes);
            $product = $this->productRepository->save($product);
            break;
        }

        $entries = $this->subscriptionOptionsCollectionFactory->create()
            ->addFieldToSelect('*')
            ->addFieldToFilter('first_payment', ['eq' => 'on_order_date']);
        $this->assertEquals(1, $entries->getSize());

        //$product = $this->productRepository->getById($productId);
        //$subscriptionOptions = $product->getExtensionAttributes()->getSubscriptionOptions();
        $this->assertNotEmpty($entries);

        foreach ($entries as $entry) {
            $this->assertEquals($subscriptionOptionsModel->getProductId(), $entry->getProductId());
            $this->assertEquals($subscriptionOptionsModel->getStartOnSpecificDate(), $entry->getStartOnSpecificDate());
            $this->assertEquals($subscriptionOptionsModel->getFirstPayment(), $entry->getFirstPayment());
            $this->assertEquals($subscriptionOptionsModel->getProrateFirstPayment(), $entry->getProrateFirstPayment());
            $this->assertEquals($subscriptionOptionsModel->getStartDate(), $entry->getStartDate());
        }
    }
}
