<?php


namespace Yogesh\Mod1\Plugin;

use Magento\Catalog\Model\Product;

class AppendOnSale
{
    public function afterGetName(Product $subject, $result)
    {
        if ($subject->getFinalPrice() < 60) {
            $result .= ' On Sale!';
        }

        return $result;
    }
}
