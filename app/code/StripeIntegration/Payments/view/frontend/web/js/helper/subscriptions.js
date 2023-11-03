define(
    [
        'ko'
    ],
    function (
        ko
    ) {
        'use strict';

        return {
            isSubscriptionUpdate: function()
            {
                return !!(window.checkoutConfig &&
                    window.checkoutConfig.payment &&
                    window.checkoutConfig.payment.stripe_payments &&
                    window.checkoutConfig.payment.stripe_payments.subscriptionUpdateDetails);
            },

            getConfig: function(key)
            {
                var config = null;

                if (window.checkoutConfig && window.checkoutConfig.payment && window.checkoutConfig.payment.stripe_payments)
                {
                    config = window.checkoutConfig.payment.stripe_payments;
                }

                if (!config || !config.subscriptionUpdateDetails)
                {
                    return null;
                }

                if (!config.subscriptionUpdateDetails[key])
                {
                    return "--";
                }

                return config.subscriptionUpdateDetails[key];
            },

            getSuccessUrl: function()
            {
                return this.getConfig("success_url");
            },

            getCancelUrl: function()
            {
                return this.getConfig("cancel_url");
            },

            displaySidebar: function()
            {
                return this.isSubscriptionUpdate();
                    // && !window.checkoutConfig.payment.stripe_payments.subscriptionUpdateDetails.is_virtual
                    // && window.location.href.indexOf('#payment') < 0;
            }
        };
    }
);
