<?php
namespace StripeIntegration\Payments\Ui\Component\Product\Form\Element;

use Magento\Framework\Data\ValueSourceInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Ui\Component\Form\Element\Checkbox;

class UseConfigSettings extends Checkbox
{
    public function __construct(
        ContextInterface $context,
        $components = [],
        array $data = []
    ) {
        parent::__construct($context, $components, $data);
    }

    public function prepare()
    {
        $config = $this->getData('config');
        if (isset($config['keyInConfiguration'])
            && isset($config['valueFromConfig'])
            && $config['valueFromConfig'] instanceof ValueSourceInterface
        ) {
            $keyInConfiguration = $config['valueFromConfig']->getValue($config['keyInConfiguration']);
            $config['valueFromConfig'] = $keyInConfiguration;
        }
        $this->setData('config', (array)$config);

        parent::prepare();
    }
}
