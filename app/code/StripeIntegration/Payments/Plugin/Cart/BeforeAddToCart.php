<?php

namespace StripeIntegration\Payments\Plugin\Cart;

use Magento\Framework\Exception\LocalizedException;

class BeforeAddToCart
{
    private $helper;
    private $subscriptions;
    private $configurableProductFactory;

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptions,
        \Magento\ConfigurableProduct\Model\Product\Type\ConfigurableFactory $configurableProductFactory
    )
    {
        $this->helper = $helper;
        $this->subscriptions = $subscriptions;
        $this->configurableProductFactory = $configurableProductFactory;
    }

    public function beforeAddProduct(
        \Magento\Quote\Model\Quote $quote,
        \Magento\Catalog\Model\Product $product,
        $request = null,
        $processMode = \Magento\Catalog\Model\Product\Type\AbstractType::PROCESS_MODE_FULL
    )
    {
        $product = $this->getProductFromRequest($product, $request);
        $this->subscriptions->checkIfAddToCartIsSupported($quote, $product);
        return null;
    }

    protected function getProductFromRequest($addProduct, $request)
    {
        if (empty($request) || is_numeric($request))
        {
            return $addProduct;
        }

        if ($addProduct->getTypeId() != 'configurable')
        {
            return $addProduct;
        }

        $attributes = $request->getSuperAttribute();
        if (empty($attributes))
        {
            return $addProduct;
        }

        $product = $this->configurableProductFactory->create()->getProductByAttributes($attributes, $addProduct);
        if ($product)
        {
            return $product;
        }

        return $addProduct;
    }
}
