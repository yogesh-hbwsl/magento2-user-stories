<?php

namespace Yogesh\Mod1\Controller\TestController;

use Magento\Framework\App\ActionInterface;
use Yogesh\Mod1\Test;

class TestController implements ActionInterface
{
    protected $test;

    public function __construct(Test $test)
    {
        $this->test = $test;
    }

    public function execute()
    {

        $this->test->displayParams();
    }
}
