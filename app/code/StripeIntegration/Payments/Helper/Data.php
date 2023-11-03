<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Framework\View\Asset\Repository;

/**
 * MINIMAL DEPENDENCIES HELPER
 * No dependencies on other helper classes.
 * This class can be injected into installation scripts, cron jobs, predispatch observers etc.
 */
class Data
{
    const RISK_LEVEL_NORMAL = 'Normal';
    const RISK_LEVEL_ELEVATED = 'Elevated';
    const RISK_LEVEL_HIGHEST = 'Highest';
    const RISK_LEVEL_NA = 'NA';
    const RISK_SCORE_COLUMN_NAME = "stripe_radar_risk_score";
    const RISK_LEVEL_COLUMN_NAME = "stripe_radar_risk_level";

    /**
     * @var Repository
     */
    protected $assetRepository;

    private $appState;
    private $storeManager;
    private $scopeConfig;
    private $dateTime;

    public function __construct(
        \Magento\Framework\App\State $appState,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Stdlib\DateTime $dateTime,
        Repository $assetRepository
    ) {
        $this->appState = $appState;
        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
        $this->dateTime = $dateTime;
        $this->assetRepository = $assetRepository;
    }

    public function cleanToken($token)
    {
        if (empty($token))
            return null;

        return preg_replace('/-.*$/', '', $token);
    }

    public function isAdmin()
    {
        $areaCode = $this->appState->getAreaCode();

        return $areaCode == \Magento\Backend\App\Area\FrontNameResolver::AREA_CODE;
    }

    public function getConfigData($field)
    {
        $storeId = $this->storeManager->getStore()->getId();

        return $this->scopeConfig->getValue($field, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function isMOTOError(\Stripe\ErrorObject $error)
    {
        if (empty($error->code))
            return false;

        if (empty($error->param))
            return false;

        if ($error->code != "parameter_unknown")
            return false;

        if ($error->param != "payment_method_options[card][moto]")
            return false;

        return true;
    }

    public function convertToSetupIntentConfirmParams($paymentIntentConfirmParams)
    {
        $confirmParams = $paymentIntentConfirmParams;

        if (!empty($confirmParams['payment_method_options']))
        {
            foreach ($confirmParams['payment_method_options'] as $key => $value)
            {
                if (isset($confirmParams['payment_method_options'][$key]['setup_future_usage']))
                    unset($confirmParams['payment_method_options'][$key]['setup_future_usage']);

                if (!in_array($key, \StripeIntegration\Payments\Helper\PaymentMethod::SETUP_INTENT_PAYMENT_METHOD_OPTIONS))
                    unset($confirmParams['payment_method_options'][$key]);

                if (empty($confirmParams['payment_method_options'][$key]))
                    unset($confirmParams['payment_method_options'][$key]);
            }

            if (empty($confirmParams['payment_method_options']))
                unset($confirmParams['payment_method_options']);
        }

        return $confirmParams;
    }

    public function getBuyRequest($orderItem)
    {
        if (!$orderItem || !$orderItem->getId())
            return null;

        $productOptions = $orderItem->getProductOptions();
        if (!$productOptions)
            return null;

        if (empty($productOptions['info_buyRequest']))
            return null;

        return new \Magento\Framework\DataObject($productOptions['info_buyRequest']);
    }

    public function getConfigurableProductBuyRequest($orderItem)
    {
        if (!$orderItem || !$orderItem->getId())
            return null;

        $productOptions = $orderItem->getProductOptions();
        if (!$productOptions)
            return null;

        $buyRequest = isset($productOptions['info_buyRequest']) ? $productOptions['info_buyRequest'] : null;

        if (!$buyRequest)
            return null;

        $buyRequest['qty'] = $orderItem->getQtyOrdered();

        // Extract the configurable item options
        $configurableItemOptions = isset($productOptions['attributes_info']) ? $productOptions['attributes_info'] : null;

        if (!$configurableItemOptions)
            return $buyRequest;

        // Add the configurable item options to buyRequest
        $superAttribute = [];
        foreach ($configurableItemOptions as $option) {
            if (isset($option['attribute_id']) && isset($option['value'])) {
                $superAttribute[$option['attribute_id']] = $option['value'];
            }
        }

        if (!empty($superAttribute)) {
            $buyRequest['super_attribute'] = $superAttribute;
        }

        return $buyRequest;
    }

    public function dbTime()
    {
        return $this->dateTime->formatDate(true);
    }

    public function areArrayValuesTheSame(array $array1, array $array2)
    {
        $combined = array_merge($array1, $array2);
        $unique = array_unique($combined);

        if (count($unique) != count($array1))
            return false;

        if (count($unique) != count($array2))
            return false;

        return true;

    }

    /**
     * get not available risk data icon
     */
    public function getNoRiskIcon()
    {
        return $this->assetRepository->getUrl("StripeIntegration_Payments::svg/risk_data_na.svg");
    }

    public function getRiskElementClass($riskScore = null, $riskLevel = 'NA')
    {
        $returnClass = 'na';
        if ($riskScore === null) {
            return $returnClass;
        }
        if ($riskScore >= 0 && $riskScore < 6 ) {
            $returnClass = 'normal';
        }
        if (($riskScore >= 6 && $riskScore < 66) || ($riskLevel === self::RISK_LEVEL_NORMAL)) {
            $returnClass = 'normal';
        }
        if (($riskScore >= 66 && $riskScore < 76) || ($riskLevel === self::RISK_LEVEL_ELEVATED)) {
            $returnClass = 'elevated';
        }
        if (($riskScore >= 76) || ($riskLevel === self::RISK_LEVEL_HIGHEST)) {
            $returnClass = 'highest';
        }

        return $returnClass;
    }

    public function setRiskDataToOrder($paymentIntentResponse, $order, $array = false)
    {
        if ($array) {
            if (isset($paymentIntentResponse['outcome']['risk_score']) && $paymentIntentResponse['outcome']['risk_score'] >= 0) {
                $order->setStripeRadarRiskScore($paymentIntentResponse['outcome']['risk_score']);
            }
            if (isset($paymentIntentResponse['outcome']['risk_level'])) {
                $order->setStripeRadarRiskLevel($paymentIntentResponse['outcome']['risk_level']);
            }
        } else {
            if (isset($paymentIntentResponse->charges->data[0]->outcome->risk_score) && $paymentIntentResponse->charges->data[0]->outcome->risk_score >= 0) {
                $order->setStripeRadarRiskScore($paymentIntentResponse->charges->data[0]->outcome->risk_score);
            }
            if (isset($paymentIntentResponse->charges->data[0]->outcome->risk_level)) {
                $order->setStripeRadarRiskLevel($paymentIntentResponse->charges->data[0]->outcome->risk_level);
            }
        }
    }

    public static function getSingleton($className)
    {
        // Used to avoid circular dependency issues
        return \Magento\Framework\App\ObjectManager::getInstance()->get($className);
    }
}
