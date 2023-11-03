define([
    'ko',
    'uiComponent',
    'Magento_Checkout/js/model/totals',
    'Magento_Checkout/js/model/quote',
    'Magento_Customer/js/customer-data',
    'StripeIntegration_Payments/js/helper/subscriptions',
    'mage/translate',
    'jquery'
],
function (
    ko,
    Component,
    totals,
    quote,
    customerData,
    subscriptions,
    $t,
    $
)
{
    'use strict';

    return Component.extend({
        isDisplayed: ko.observable(false),
        isLoading: totals.isLoading,

        initialize: function()
        {
            this._super();

            this.isDisplayed(subscriptions.displaySidebar());

            // var self = this;

            // window.addEventListener('hashchange', function()
            // {
            //     self.isDisplayed(subscriptions.displaySidebar());
            // });
        },

        getConfig: function(key)
        {
            return subscriptions.getConfig(key);
        },

        cancelUpdate: function()
        {
            var cancelUrl = subscriptions.getCancelUrl();
            var yes = confirm($t("Are you sure you want to cancel the subscription update?"));
            if (yes)
            {
                $.mage.redirect(cancelUrl);
            }
        },

    });
});
