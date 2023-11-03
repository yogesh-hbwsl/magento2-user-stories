<?php

namespace StripeIntegration\Payments\Plugin\Webapi\Controller;

class Rest
{
    private $display = false;

    public function afterDispatch(
        $subject,
        $response, // result
        \Magento\Framework\App\RequestInterface $request
    ) {
        if ($this->display)
        {
            $response->clearHeader('errorRedirectAction');
        }

        return $response;
    }

    public function setDisplay(bool $value)
    {
        $this->display = $value;
    }
}
