define(
    [
        'ko',
        'mage/translate',
        'Magento_Checkout/js/view/summary/abstract-total',
        'Magento_Checkout/js/model/quote',
        'Magento_Catalog/js/price-utils',
        'Magento_Checkout/js/model/totals',
        'StripeIntegration_Payments/js/helper/subscriptions',
        'StripeIntegration_Payments/js/model/upcomingInvoice'
    ],
    function (
        ko,
        $t,
        Component,
        quote,
        priceUtils,
        totals,
        subscriptions,
        upcomingInvoice
    ) {
        "use strict";

        return Component.extend({
            defaults: {
                template: 'StripeIntegration_Payments/checkout/summary/prorations'
            },
            totals: quote.getTotals(),
            prorationAdjustment: ko.observable(0),
            baseProrationAdjustment: ko.observable(0),

            initialize: function()
            {
                this._super();
                upcomingInvoice.initialize();
                upcomingInvoice.onChange(this.onUpcomingInvoiceChanged.bind(this));
            },

            isDisplayed: function()
            {
                return subscriptions.isSubscriptionUpdate() && this.isFullMode() && this.getPureValue() !== 0;
            },

            getValue: function()
            {
                var price = this.getPureValue();
                return this.getFormattedPrice(price);
            },

            getPureValue: function()
            {
                var price = 0;
                if (subscriptions.isSubscriptionUpdate() && this.prorationAdjustment()) {
                    price = this.prorationAdjustment();
                }
                return price;
            },

            getBasePureValue: function()
            {
                var price = 0;
                if (subscriptions.isSubscriptionUpdate() && this.baseProrationAdjustment()) {
                    price = this.baseProrationAdjustment();
                }
                return price;
            },

            onUpcomingInvoiceChanged: function(result, outcome, response)
            {
                try
                {
                    var params = JSON.parse(result);

                    if (params && params.error)
                    {
                        return;
                    }

                    if (!params || !params.upcomingInvoice)
                        return;

                    if (!isNaN(params.upcomingInvoice.proration_adjustment))
                    {
                        this.prorationAdjustment(params.upcomingInvoice.proration_adjustment);
                    }

                    if (!isNaN(params.upcomingInvoice.base_proration_adjustment))
                    {
                        this.baseProrationAdjustment(params.upcomingInvoice.base_proration_adjustment);
                    }
                }
                catch (e)
                {
                    console.warn("Could not calculate sidebar proration amount");
                    console.warn(e);
                }
            },
        });
    }
);
