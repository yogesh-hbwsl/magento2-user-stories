define(
    [
        'mage/url',
        'mage/storage'
    ],
    function (
        urlBuilder,
        storage
    ) {
        'use strict';
        return function (callback)
        {
            var serviceUrl = urlBuilder.build('rest/V1/stripe/payments/list_payment_methods');

            return storage.get(serviceUrl).always(callback);
        };
    }
);
