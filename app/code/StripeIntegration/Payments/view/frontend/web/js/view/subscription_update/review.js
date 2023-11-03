define(
    [
        'ko',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Ui/js/model/messageList',
        'Magento_Checkout/js/model/quote',
        'Magento_Customer/js/model/customer',
        'StripeIntegration_Payments/js/helper/subscriptions',
        'StripeIntegration_Payments/js/model/upcomingInvoice',
        'mage/translate',
        'jquery',
        'Magento_Checkout/js/action/place-order',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_CheckoutAgreements/js/model/agreement-validator',
        'Magento_CheckoutAgreements/js/model/agreements-assigner'
    ],
    function (
        ko,
        Component,
        globalMessageList,
        quote,
        customer,
        subscriptions,
        upcomingInvoice,
        $t,
        $,
        placeOrderAction,
        additionalValidators,
        agreementValidator,
        agreementsAssigner
    ) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'StripeIntegration_Payments/subscription_update/review',
            },
            currentTotals: null,
            newPrice: ko.observable("--"),
            prorationFee: ko.observable("--"),
            unusedTime: ko.observable("--"),
            isPlaceOrderEnabled: ko.observable(false),
            credit: ko.observable(null),

            initObservable: function ()
            {
                this._super()
                    .observe([
                        'isLoading',
                        'stripePaymentsError',
                        'permanentError',
                        'userError'
                    ]);

                var self = this;

                this.initParams = window.checkoutConfig.payment.stripe_payments.initParams;

                upcomingInvoice.initialize();
                var onUpcomingInvoiceChanged = this.onUpcomingInvoiceChanged.bind(this);
                upcomingInvoice.onChange(onUpcomingInvoiceChanged);

                this.hasProrationFee = ko.computed(function(){
                    return self.prorationFee() && self.prorationFee() != "--";
                });

                return this;
            },

            getConfig: function(key)
            {
                return subscriptions.getConfig(key);
            },

            crash: function(message)
            {
                this.isLoading(false);
                if (this.userError())
                    this.showError(this.userError());
                else
                    this.permanentError($t("Sorry, this payment method is not available. Please contact us for assistance."));

                console.error("Error: " + message);
            },

            softCrash: function(message)
            {
                this.isLoading(false);
                if (this.userError())
                    this.showError(this.userError());
                else
                    this.showError($t("Sorry, this payment method is not available. Please contact us for assistance."));

                console.error("Error: " + message);
            },

            onChange: function(event)
            {
                this.isLoading(false);
            },

            placeOrder: function()
            {
                if (!this.validate())
                    return;

                var self = this;
                this.isPlaceOrderEnabled(false);
                this.isLoading(true);

                this.getPlaceOrderDeferredObject()
                    .fail(this.handlePlaceOrderErrors.bind(this))
                    .done(this.onOrderPlaced.bind(this))
                    .always(function(){
                        self.isLoading(false);
                        self.isPlaceOrderEnabled(true);
                    });
            },

            getPlaceOrderDeferredObject: function()
            {
                return placeOrderAction(this.getData(), this.messageContainer);
            },

            handlePlaceOrderErrors: function (result)
            {
                if (result && result.responseJSON && result.responseJSON.message)
                    this.showError(result.responseJSON.message);
                else
                {
                    this.showError($t("Sorry, the subscription could not be updated. Please contact us for assistance."));

                    if (result && result.responseText)
                        console.error(result.responseText);
                    else
                        console.error(result);
                }
            },

            onOrderPlaced: function(result, outcome, response)
            {
                if (result && !isNaN(result))
                {
                    $.mage.redirect(subscriptions.getSuccessUrl());
                    return;
                }

                try
                {
                    var jsonResponse = JSON.parse(result);
                    if (jsonResponse && jsonResponse.error)
                    {
                        return this.showError(jsonResponse.error);
                    }
                    else
                    {
                        console.warn("The order could not be placed. The server response was: " + result);
                        return this.showError($t("Sorry, the subscription could not be updated. Please contact us for assistance."));
                    }
                }
                catch (e)
                {
                    console.warn("The order could not be placed. The error was: " + e);
                        return this.showError($t("Sorry, the subscription could not be updated. Please contact us for assistance."));
                }
            },

            getData: function()
            {
                var data = {
                    'method': "stripe_payments",
                    'additional_data': {
                        'is_subscription_update': true
                    }
                };

                agreementsAssigner(data);

                return data;
            },

            showError: function(message)
            {
                this.isLoading(false);
                this.messageContainer.addErrorMessage({ "message": message });
            },

            validate: function(elm)
            {
                return agreementValidator.validate() && additionalValidators.validate();
            },

            getCode: function()
            {
                return 'stripe_payments';
            },

            clearErrors: function()
            {
                this.stripePaymentsError(null);
            },

            onUpcomingInvoiceChanged: function(result, outcome, response)
            {
                try
                {
                    var params = JSON.parse(result);

                    this.resetTotals();

                    if (params && params.error)
                    {
                        this.userError(params.error);
                        return this.softCrash(params.error);
                    }

                    if (!params || !params.upcomingInvoice)
                        return this.softCrash("Could not retrieve upcoming invoice");

                    if (params.upcomingInvoice.new_price &&
                        params.upcomingInvoice.new_price.label &&
                        params.upcomingInvoice.new_price.label.length > 0)
                    {
                        this.newPrice(params.upcomingInvoice.new_price.label);
                    }

                    if (params.upcomingInvoice.proration_fee &&
                        params.upcomingInvoice.proration_fee.label &&
                        params.upcomingInvoice.proration_fee.label.length > 0)
                    {
                        this.prorationFee(params.upcomingInvoice.proration_fee.label);
                    }

                    if (params.upcomingInvoice.unused_time &&
                        params.upcomingInvoice.unused_time.label &&
                        params.upcomingInvoice.unused_time.label.length > 0)
                    {
                        this.unusedTime(params.upcomingInvoice.unused_time.label);
                    }

                    if (params.upcomingInvoice.credit)
                    {
                        this.credit(params.upcomingInvoice.credit);
                    }

                    this.isPlaceOrderEnabled(true);
                }
                catch (e)
                {
                    console.warn("Could not calculate subscription update prices");
                    console.warn(e);
                }
            },

            resetTotals: function()
            {
                this.newPrice("--");
                this.prorationFee("--");
                this.unusedTime("--");
            },

            getCancelUrl: function()
            {
                return subscriptions.getCancelUrl();
            }

        });
    }
);
