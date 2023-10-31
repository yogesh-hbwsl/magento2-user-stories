<?php

namespace Yogesh\Mod1\Controller\AfterHtml;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\View\Result\PageFactory;

class Index implements ActionInterface
{
    protected $_pageFactory;
    public function __construct(PageFactory $pageFactory)
    {
        $this->_pageFactory = $pageFactory;
    }
    public function execute()
    {
        return $this->_pageFactory->create();
    }
}
