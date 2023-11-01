<?php

namespace Yogesh\Mod1\Controller\EmployeeForm;

use Exception;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Controller\ResultFactory;
use Yogesh\Mod1\Model\Employee;

class Index implements ActionInterface
{

    protected $_pageFactory;
    protected $_messageManager;
    protected $_resultFactory;
    protected $_request;
    protected $_model;

    public function __construct(
        PageFactory $pageFactory,
        ManagerInterface $messageManager,
        ResultFactory $resultFactory,
        Http $request,
        Employee $model
    ) {
        $this->_pageFactory = $pageFactory;
        $this->_messageManager = $messageManager;
        $this->_resultFactory = $resultFactory;
        $this->_request = $request;
        $this->_model = $model;
    }

    public function execute()
    {
        $post = $this->_request->getPostValue();
        if (!empty($post)) {
            try {
                $this->_model->setData($post);
                $this->_model->save();
                $this->_messageManager->addSuccessMessage("Data Saved Successfully.");
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
