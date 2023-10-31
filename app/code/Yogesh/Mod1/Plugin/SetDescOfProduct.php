<?php

namespace Yogesh\Mod1\Plugin;

use Magento\Catalog\Block\Product\View\Description;

class SetDescOfProduct
{
    protected $executed = false;
    public function afterGetProduct(Description $subject, $result)
    {

        if (!$this->executed) {
            $currentDesc = $result->getData('description');
            $newDesc = $currentDesc . "<br><p>Added Custom Description</p>";
            $result->setDescription($newDesc);
            $this->executed = true;
        }
        return $result;
    }
}
