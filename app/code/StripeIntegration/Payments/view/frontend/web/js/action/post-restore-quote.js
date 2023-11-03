define(
    [
        'Magento_Checkout/js/model/url-builder',
        'mage/storage',
        'Magento_Customer/js/customer-data',
        'Magento_Checkout/js/model/quote'
    ],
    function (
        urlBuilder,
        storage,
        customerData,
        quote
    ) {
        'use strict';
        return function (callback)
        {
            var serviceUrl = urlBuilder.createUrl('/stripe/payments/restore_quote', {});

            customerData.invalidate(['cart']);

            return storage.post(serviceUrl).always(callback);
        };
    }
);
