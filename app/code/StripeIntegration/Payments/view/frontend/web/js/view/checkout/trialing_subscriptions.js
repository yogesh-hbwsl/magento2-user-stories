define(
    [
        'ko',
        'Magento_Checkout/js/view/summary/abstract-total',
        'Magento_Checkout/js/model/quote',
        'Magento_Catalog/js/price-utils',
        'Magento_Checkout/js/model/totals',
        'mage/translate',
        'StripeIntegration_Payments/js/action/get-trialing-subscriptions',
        'Magento_Customer/js/customer-data'
    ],
    function (ko, Component, quote, priceUtils, totals, $t, getTrialingSubscriptions, customerData) {
        "use strict";
        return Component.extend({
            defaults: {
                isFullTaxSummaryDisplayed: window.checkoutConfig.isFullTaxSummaryDisplayed || false,
                template: 'StripeIntegration_Payments/checkout/trialing_subscriptions',
                trialingSubscriptions: ko.observable(window.checkoutConfig.payment.stripe_payments.trialingSubscriptions),
                fetching: ko.observable(false)
            },
            totals: quote.getTotals(),
            isTaxDisplayedInGrandTotal: window.checkoutConfig.includeTaxInGrandTotal || false,

            initialize: function ()
            {
                this._super();

                this.observe(['trialingSubscriptions']);
                this.trialingSubscriptions(window.checkoutConfig.payment.stripe_payments.trialingSubscriptions);

                this.getFormattedSubscriptionsPrice = ko.computed(function()
                {
                    var price = -this.getAmount('subscriptions_total');
                    return this.getFormattedPrice(price);
                }, this);

                this.getFormattedShipping = ko.computed(function()
                {
                    var price = -this.getAmount('shipping_total');
                    return this.getFormattedPrice(price);
                }, this);

                this.getFormattedTax = ko.computed(function()
                {
                    var price = -this.getAmount('tax_total');
                    return this.getFormattedPrice(price);
                }, this);

                this.getFormattedDiscount = ko.computed(function()
                {
                    var price = this.getAmount('discount_total');
                    return this.getFormattedPrice(price);
                }, this);

                this.hasTrialingSubscriptions = ko.computed(function()
                {
                    return this.getAmount('subscriptions_total') !== 0;
                }, this);

                this.hasShipping = ko.computed(function()
                {
                    return this.getAmount('shipping_total') !== 0;
                }, this);

                this.hasTax = ko.computed(function()
                {
                    return this.getAmount('tax_total') !== 0;
                }, this);

                this.hasDiscount = ko.computed(function()
                {
                    return this.getAmount('discount_total') !== 0;
                }, this);

                this.trialingSubscriptions(this.getTrialSubscriptions());

                var grandTotal = quote.totals().grand_total;

                quote.totals.subscribe(function (totals)
                {
                    if (grandTotal == quote.totals().grand_total)
                        return;

                    grandTotal = quote.totals().grand_total;

                    this.refresh(quote);
                }, this);
            },

            isDisplayed: function()
            {
                return this.isFullMode() && this.getPureValue() !== 0;
            },

            getTrialSubscriptions: function()
            {
                if (
                    window.checkoutConfig &&
                    window.checkoutConfig.payment &&
                    window.checkoutConfig.payment.stripe_payments &&
                    window.checkoutConfig.payment.stripe_payments.hasTrialSubscriptions &&
                    window.checkoutConfig.payment.stripe_payments.trialingSubscriptions
                )
                {
                    return window.checkoutConfig.payment.stripe_payments.trialingSubscriptions;
                }

                return null;
            },

            refresh: function(quote)
            {
                if (!this.getTrialSubscriptions())
                    return;

                if (this.fetching())
                    return;

                var self = this;
                this.fetching(true);

                getTrialingSubscriptions(quote)
                    .always(function()
                    {
                        self.fetching(false);
                    })
                    .done(function (subscriptions)
                    {
                        try {
                            var data = JSON.parse(subscriptions);
                            window.checkoutConfig.payment.stripe_payments.trialingSubscriptions = data;
                            self.trialingSubscriptions(data);
                        } catch (e) {
                            console.warn('Could not retrieve trial subscriptions: ' + e.message);
                            self.trialingSubscriptions(window.checkoutConfig.payment.stripe_payments.trialingSubscriptions);
                        }
                    })
                    .fail(function (xhr, textStatus, errorThrown)
                    {
                        console.warn(console.warn('Could not retrieve trial subscriptions: ' + xhr.responseText));
                    });
            },

            discountTitle: function()
            {
                return $t('Trial Discount');
            },

            shippingTitle: function()
            {
                return $t('Trial Shipping');
            },

            taxTitle: function()
            {
                return $t('Trial Tax');
            },

            getAmount: function(key)
            {
                var config = this.trialingSubscriptions();

                if (config == null)
                    return 0;

                if ((key in config) && !isNaN(config[key]))
                    return config[key];

                return 0;
            },

            getPureValue: function()
            {
                var price = this.getAmount('discount_total') -
                            this.getAmount('subscriptions_total') -
                            this.getAmount('shipping_total') -
                            this.getAmount('tax_total') +
                            this.getAmount('tax_inclusive');

                return Math.round(price * 10000) / 10000;
            },

            getBasePureValue: function()
            {
                var price = this.getAmount('base_discount_total') -
                            this.getAmount('base_subscriptions_total') -
                            this.getAmount('base_shipping_total') -
                            this.getAmount('base_tax_total') +
                            this.getAmount('base_tax_inclusive');

                return Math.round(price * 10000) / 10000;
            },

            getTaxAmount: function()
            {
                return this.getAmount('tax_total');
            },

            config: function()
            {
                return window.checkoutConfig.payment.stripe_payments;
            }
        });
    }
);
