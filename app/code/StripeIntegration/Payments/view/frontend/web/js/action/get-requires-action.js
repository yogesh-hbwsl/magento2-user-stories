define(
    [
        'Magento_Checkout/js/model/url-builder',
        'Magento_Customer/js/customer-data',
        'mage/storage'
    ],
    function (urlBuilder, customerData, storage) {
        'use strict';

        var promise = null; // If this is set, the promise is not resolved

        return function (callback)
        {
            var serviceUrl = urlBuilder.createUrl('/stripe/payments/get_requires_action', {});

            customerData.invalidate(['cart']);

            if (!promise)
                promise = storage.get(serviceUrl);
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
