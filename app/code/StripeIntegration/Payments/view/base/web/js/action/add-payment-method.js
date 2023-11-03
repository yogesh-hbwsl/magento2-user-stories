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
        return function (paymentMethodId, callback)
        {
            var serviceUrl = urlBuilder.build('rest/V1/stripe/payments/add_payment_method');

            var payload = {
                paymentMethodId: paymentMethodId
            };

            return storage.post(serviceUrl, JSON.stringify(payload)).always(callback);
        };
    }
);
