<?php

namespace Yogesh\Mod1\Controller\EmployeeForm;

use Exception;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Controller\ResultFactory;
use Yogesh\Mod1\Model\Employee;
use Magento\Framework\App\Cache\TypeListInterface;
use \Magento\Framework\App\Cache\Frontend\Pool;

class Index implements ActionInterface
{

    protected $_pageFactory;
    protected $_messageManager;
    protected $_resultFactory;
    protected $_request;
    protected $_model;
    protected $_cacheTypeList;
    protected $_cacheFrontendPool;

    public function __construct(
        PageFactory $pageFactory,
        ManagerInterface $messageManager,
        ResultFactory $resultFactory,
        Http $request,
        Employee $model,
        TypeListInterface $cacheTypeList,
        Pool $cacheFrontendPool
    ) {
        $this->_pageFactory = $pageFactory;
        $this->_messageManager = $messageManager;
        $this->_resultFactory = $resultFactory;
        $this->_request = $request;
        $this->_model = $model;
        $this->_cacheTypeList = $cacheTypeList;
        $this->_cacheFrontendPool = $cacheFrontendPool;
    }

    public function execute()
    {
        $post = $this->_request->getPostValue();
        if (!empty($post)) {
            try {
                $this->_model->setData($post);
                $this->_model->save();
                $this->_messageManager->addSuccessMessage("Data Saved Successfully.");

                $this->_cacheTypeList->cleanType('full_page');

                foreach ($this->_cacheFrontendPool as $cacheFrontend) {
                    $cacheFrontend->getBackend()->clean();
                }
            } catch (Exception $e) {
                $this->_messageManager->addErrorMessage($e, "We can\'t submit your request, Please try again.");
            }

            $resultRedirect = $this->_resultFactory->create(ResultFactory::TYPE_REDIRECT);
            $resultRedirect->setPath('mod1/employeeform/index');
            return $resultRedirect;
        }

        return $this->_pageFactory->create();
    }
}
