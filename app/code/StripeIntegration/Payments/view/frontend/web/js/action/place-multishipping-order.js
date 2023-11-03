define(
    [
        'jquery',
        'Magento_Checkout/js/model/url-builder',
        'mage/storage',
        'Magento_Customer/js/customer-data',
        'Magento_Ui/js/modal/alert'
    ],
    function ($, urlBuilder, storage, customerData, alert) {
        'use strict';

        var showError = function(message, e)
        {
            alert( { content: message });

            if (typeof e != "undefined")
                console.error(e);
        };

        return function (callback, onAuthenticationRequired)
        {
            customerData.invalidate(['cart']);

            var serviceUrl = urlBuilder.createUrl('/stripe/payments/place_multishipping_order', {});

            return storage.post(serviceUrl)
            .then(function(result, b, c)
            {
                var response = null;

                try
                {
                    response = JSON.parse(result);
                }
                catch (e)
                {
                    return showError("Sorry, a server side error has occurred.", e);
                }

                if (response.error)
                    return showError(response.error, response.error);

                if (response.redirect)
                    return $.mage.redirect(response.redirect);

                if (response.authenticate)
                    return onAuthenticationRequired(response.authenticate);

                return showError(response, response);
            })
            .fail(function(result)
            {
                return showError("Sorry, a server side error has occurred.", result);
            })
            .always(callback);
        };
    }
);
