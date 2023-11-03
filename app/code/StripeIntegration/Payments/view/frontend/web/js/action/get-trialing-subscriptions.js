define(
    [
        'Magento_Checkout/js/model/url-builder',
        'mage/storage',
        'Magento_Checkout/js/model/error-processor',
        'Magento_Checkout/js/model/full-screen-loader',
        'Magento_Checkout/js/model/quote'
    ],
    function (urlBuilder, storage, errorProcessor, fullScreenLoader, quote) {
        'use strict';
        return function (quote)
        {
            var serviceUrl = urlBuilder.createUrl('/stripe/payments/get_trialing_subscriptions', {});

            var payload = {
                billingAddress: quote.billingAddress()
            };

            if (quote.shippingAddress())
                payload.shippingAddress = quote.shippingAddress();

            if (quote.shippingMethod())
                payload.shippingMethod = quote.shippingMethod();

            var totals = quote.totals();
            if (typeof totals.coupon_code != "undefined" && totals.coupon_code && totals.coupon_code.length > 0)
                payload.couponCode = totals.coupon_code;

            return storage.post(serviceUrl, JSON.stringify(payload));
        };
    }
);
