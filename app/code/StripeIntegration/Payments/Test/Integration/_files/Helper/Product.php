<?php

function setCustomAttribute(&$product, $code, $value)
{
    $product->setData($code, $value);
    $product->getResource()->saveAttribute($product, $code);
}

function setBundleProductItems($bundleProduct)
{
    $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
    if ($bundleProduct->getBundleOptionsData())
    {
        $options = [];
        foreach ($bundleProduct->getBundleOptionsData() as $key => $optionData)
        {
            if (!(bool) $optionData['delete'])
            {
                $option = $objectManager->create('Magento\Bundle\Api\Data\OptionInterface');
                $option->setData($optionData);
                $option->setSku($bundleProduct->getSku());
                $option->setOptionId(null);

                $links = [];
                $bundleLinks = $bundleProduct->getBundleSelectionsData();
                if (!empty($bundleLinks[$key]))
                {
                    foreach ($bundleLinks[$key] as $linkData)
                    {
                        if (!(bool) $linkData['delete'])
                        {
                            /** @var \Magento\Bundle\Api\Data\LinkInterface$link */
                            $link = $objectManager->create('Magento\Bundle\Api\Data\LinkInterface');
                            $link->setData($linkData);
                            $linkProduct = $objectManager->get('\Magento\Catalog\Api\ProductRepositoryInterface')->getById($linkData['product_id']);
                            $link->setSku($linkProduct->getSku());
                            $link->setQty($linkData['selection_qty']);
                            if (isset($linkData['selection_can_change_qty']))
                            {
                                $link->setCanChangeQuantity($linkData['selection_can_change_qty']);
                            }
                            $links[] = $link;
                        }
                    }
                    $option->setProductLinks($links);
                    $options[] = $option;
                }
            }
        }
        $extension = $bundleProduct->getExtensionAttributes();
        $extension->setBundleProductOptions($options);
        $bundleProduct->setExtensionAttributes($extension);
    }
    $bundleProduct->save();
}

function saveSubscriptionOption($data)
{
    $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
    $subscriptionOptionsFactory = $objectManager->get(\StripeIntegration\Payments\Model\SubscriptionOptionsFactory::class);
    $subscriptionOptionsFactory->create()->setData($data)->save();
}
