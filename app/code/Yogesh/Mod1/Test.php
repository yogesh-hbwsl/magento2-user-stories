<?php

namespace Yogesh\Mod1;

use Magento\Catalog\Api\Data\CategoryInterface;

class Test
{
    protected $category;
    protected $array;
    protected $string;

    public function __construct(CategoryInterface $category, array $array = [1, 2, 2], string $string = "Hi!!")
    {
        $this->category = $category;
        $this->array = $array;
        $this->string = $string;
    }


    public function displayParams()
    {

        echo 'Array: ' . print_r($this->array, true);
        echo 'String: ' . $this->string;
        exit;
    }
}
