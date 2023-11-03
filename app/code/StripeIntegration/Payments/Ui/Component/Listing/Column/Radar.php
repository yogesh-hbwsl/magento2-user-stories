<?php
namespace StripeIntegration\Payments\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Ui\Component\Listing\Columns\Column;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use StripeIntegration\Payments\Helper\Data as StripeHelperData;

class Radar extends Column
{
    /**
     * @var Escaper
     */
    protected $escaper;

    /**
     * @var StripeHelperData
     */
    protected $stripeHelperData;

    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param Escaper $escaper
     * @param StripeHelperData $stripeHelperData
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        Escaper $escaper,
        StripeHelperData $stripeHelperData,
        array $components = [],
        array $data = []
    ) {
        $this->escaper = $escaper;
        $this->stripeHelperData = $stripeHelperData;
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
                $riskScore = null;
                if (isset($item[StripeHelperData::RISK_SCORE_COLUMN_NAME]) && ($item[StripeHelperData::RISK_SCORE_COLUMN_NAME] !== null)) {
                    $riskScore = $item[StripeHelperData::RISK_SCORE_COLUMN_NAME];
                }

                $riskLevel = 'NA';
                if (isset($item[StripeHelperData::RISK_LEVEL_COLUMN_NAME]) && ($item[StripeHelperData::RISK_LEVEL_COLUMN_NAME] !== 'NA')) {
                    $riskLevel = $item[StripeHelperData::RISK_LEVEL_COLUMN_NAME];
                }

                $radarElementClass = $this->stripeHelperData->getRiskElementClass($riskScore, $riskLevel);

                $returnHtml = '<div class="admin__stripe-radar stripe-payment-risk-'.$radarElementClass.'"'.$item[StripeHelperData::RISK_SCORE_COLUMN_NAME].'>';
                if ($riskScore !== null) {
                    $returnHtml .= '<span class="stripe-payment-risk-score"><span class="score-value">'.$this->escaper->escapeHtml($riskScore).'</span></span>';
                } else {
                    $returnHtml .= '<span class="stripe-payment-risk-score"><span class="score-value"><img src="'.$this->escaper->escapeHtmlAttr($this->stripeHelperData->getNoRiskIcon()).'" width="16px" /></span></span>';
                }
                $returnHtml .= '</div>';

                $item[$this->getData('name')] = $returnHtml;
            }
        }

        return $dataSource;
    }
}
