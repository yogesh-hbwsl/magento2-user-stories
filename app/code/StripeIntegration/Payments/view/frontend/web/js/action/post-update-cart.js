define(
    [
        'Magento_Checkout/js/model/url-builder',
        'mage/storage',
        'Magento_Customer/js/customer-data'
    ],
    function (urlBuilder, storage, customerData) {
        'use strict';
        return function (data, callback)
        {
            var serviceUrl = urlBuilder.createUrl('/stripe/payments/update_cart', {});

            // This API call may inactivate the customer cart
            customerData.invalidate(['cart']);

            if (data)
            {
                return storage.post(
                    serviceUrl,
                    JSON.stringify({ data: data })
                ).always(callback);
            }
            else
            {

                return storage.post(serviceUrl).always(callback);
            }
        };
    }
);
