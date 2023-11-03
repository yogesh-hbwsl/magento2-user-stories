<?php
namespace StripeIntegration\Payments\Plugin\SalesRule\Model;

use Magento\SalesRule\Model\Rule\DataProvider;
use StripeIntegration\Payments\Api\Data\CouponInterface;

/**
 * DataProviderPlugin - Include custom fields values in to the existing data provider
 */
class DataProviderPlugin
{
    /**
     * Assign the custom field values to the sales rule form
     *
     * @param DataProvider $subject
     * @param array<mixed> $result
     * @return array<mixed>
     */
    public function afterGetData(DataProvider $subject, $result)
    {
        if (is_array($result)) {
            $key = CouponInterface::EXTENSION_ATTRIBUTES_KEY;
            $code = CouponInterface::EXTENSION_CODE;
            foreach ($result as &$item) {
                if (isset($item[$key][$code])
                    && $item[$key][$code] instanceof CouponInterface
                ) {
                    /** @phpstan-ignore-next-line */
                    $item[$key][$code] = $item[$key][$code]->toArray();
                }
            }
        }

        return $result;
    }
}
