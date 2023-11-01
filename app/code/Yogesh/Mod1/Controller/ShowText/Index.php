<?php

namespace Yogesh\Mod1\Controller\ShowText;

use Magento\Framework\App\ActionInterface;
use Yogesh\Mod1\Helper\Data;

class Index implements ActionInterface
{
    public $data;

    public function __construct(Data $data)
    {
        $this->data = $data;
    }

    public function execute()
    {
        if ($this->data->getState()) {
            if (empty($this->data->getText())) {
                echo "Text field is empty";
            } else {
                echo $this->data->getText();
            }
        } else {
            echo "Not enabled";
        }
        exit;
    }
}
