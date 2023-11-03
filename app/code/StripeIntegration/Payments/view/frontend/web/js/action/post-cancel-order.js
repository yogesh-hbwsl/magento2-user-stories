define(
    [
        'Magento_Checkout/js/model/url-builder',
        'Magento_Customer/js/customer-data',
        'mage/storage'
    ],
    function (urlBuilder, customerData, storage) {
        'use strict';

        var promise = null; // If this is set, the promise is not resolved

        return function (errorMessage, callback)
        {
            var serviceUrl = urlBuilder.createUrl('/stripe/payments/cancel_last_order', {});

            var payload = {
                errorMessage: errorMessage
            };

            customerData.invalidate(['cart']);

            if (!promise)
                promise = storage.post(serviceUrl, JSON.stringify(payload));
            else
                return promise.always(callback); // Stack multiple callbacks onto the promise

            return promise.always(function(result, outcome, response)
            {
                promise = null; // Marks it as resolved
                callback(result, outcome, response);
            });
        };
    }
);
