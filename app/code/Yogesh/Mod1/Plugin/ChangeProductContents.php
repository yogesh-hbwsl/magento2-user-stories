<?php

namespace Yogesh\Mod1\Plugin;

use Magento\Catalog\Model\Product;

class ChangeProductContents
{

    function afterGetName(Product $subject, $result)
    {
        $result .= " Name Changed";
        return $result;
    }
}
