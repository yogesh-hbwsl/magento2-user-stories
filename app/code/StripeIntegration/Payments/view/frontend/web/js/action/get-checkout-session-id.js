define(
    [
        'Magento_Checkout/js/model/url-builder',
        'mage/storage'
    ],
    function (urlBuilder, storage) {
        'use strict';

        return function ()
        {
            var serviceUrl = urlBuilder.createUrl('/stripe/payments/get_checkout_session_id', {});

            return storage.get(serviceUrl);
        };
    }
);
