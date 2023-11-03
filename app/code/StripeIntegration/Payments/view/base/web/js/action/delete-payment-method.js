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
        return function (paymentMethodId, fingerprint, callback)
        {
            var serviceUrl = urlBuilder.build('rest/V1/stripe/payments/delete_payment_method');

            var payload = {
                paymentMethodId: paymentMethodId
            };

            if (fingerprint)
            {
                payload.fingerprint = fingerprint;
            }

            return storage.post(serviceUrl, JSON.stringify(payload)).always(callback);
        };
    }
);
