<?php
namespace StripeIntegration\Payments\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Ui\Component\Listing\Columns\Column;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use StripeIntegration\Payments\Helper\PaymentMethod as StripeHelperPaymentMethod;
use StripeIntegration\Payments\Model\ResourceModel\StripePaymentMethod as ResourceStripePaymentMethod;
use StripeIntegration\Payments\Model\StripePaymentMethodFactory;
use Magento\Framework\Serialize\Serializer\Json;

class PaymentMethod extends Column
{
    /**
     * @var Escaper
     */
    protected $escaper;

    /**
     * @var StripeHelperPaymentMethod
     */
    protected $stripeHelperPaymentMethod;

    protected $stripePaymentMethodFactory;

    protected $resourceStripePaymentMethod;

    protected $json;

    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param Escaper $escaper
     * @param StripeHelperPaymentMethod $stripeHelperPaymentMethod
     * @param ResourceStripePaymentMethod $resourceStripePaymentMethod
     * @param StripePaymentMethodFactory $stripePaymentMethodFactory
     * @param Json $json
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        Escaper $escaper,
        StripeHelperPaymentMethod $stripeHelperPaymentMethod,
        ResourceStripePaymentMethod $resourceStripePaymentMethod,
        StripePaymentMethodFactory $stripePaymentMethodFactory,
        Json $json,
        array $components = [],
        array $data = []
    ) {
        $this->escaper = $escaper;
        $this->stripeHelperPaymentMethod = $stripeHelperPaymentMethod;
        $this->stripePaymentMethodFactory = $stripePaymentMethodFactory;
        $this->resourceStripePaymentMethod = $resourceStripePaymentMethod;
        $this->json = $json;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * Prepare Data Source
     *
     * @param array<mixed> $dataSource
     * @return array<mixed>
     */
    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as & $item) {

                $modelClass = $this->stripePaymentMethodFactory->create();
                $this->resourceStripePaymentMethod->load($modelClass, $item['entity_id'], 'order_id');

                $data = $modelClass->getData();

                $paymentIcon = $paymentName = '';
                $walletIcon = $walletName = '';
                $walletHtml = '';
                if (isset($data['payment_method_type']) && $data['payment_method_type']) {
                    if ($data['payment_method_type'] === 'card') {
                        $cardData = $this->json->unserialize($data['payment_method_card_data']);
                        $paymentIcon = $this->stripeHelperPaymentMethod->getIconFromPaymentType('card', $cardData['card_type']);
                        $paymentName = __("•••• %1", $cardData['card_data']);

                        if (isset($cardData['wallet']) && $cardData['wallet']) {
                            $walletIcon = $this->stripeHelperPaymentMethod->getIconFromPaymentType($cardData['wallet']);
                            $walletName = $this->stripeHelperPaymentMethod->getPaymentMethodName($cardData['wallet']);
                        }
                    } else {
                        $paymentIcon = $this->stripeHelperPaymentMethod->getIconFromPaymentType($data['payment_method_type']);
                        $paymentName = $this->stripeHelperPaymentMethod->getPaymentMethodName($data['payment_method_type']);
                    }
                }

                if ($walletIcon) {
                    $walletHtml = '<img src="'.$walletIcon.'" class="stripe-payment-method-icon stripe-payment-method-icon-wallet" title="'.$walletName.'">';
                }
                $resultHtml = '<span class="stripe-payment-method">';
                $resultHtml .= $walletHtml;
                $resultHtml .= '<img src="'.$paymentIcon.'" class="stripe-payment-method-icon" title="'.$paymentName.'"></span>';
                $item[$this->getData('name')] = $resultHtml;
            }
        }

        return $dataSource;
    }
}
