<?php

namespace StripeIntegration\Payments\Plugin\Tax;

class Config
{
    private $taxCalculation;

    // Disabled constructor:
    // Loading the product or cart while switching store currency seems to create an infinite recursion.
    // We are disabling the check and forcing CALC_ROW_BASE for now until a solution is found.

    public function __construct(
        \StripeIntegration\Payments\Model\Tax\Calculation $taxCalculation
    )
    {
        $this->taxCalculation = $taxCalculation;
    }

    public function aroundGetAlgorithm(
        $subject,
        \Closure $proceed,
        $storeId = null
    ) {
        $algorithm = $proceed($storeId);

        if (!empty($this->taxCalculation->method))
            return $this->taxCalculation->method;

        return $algorithm;
    }
}
