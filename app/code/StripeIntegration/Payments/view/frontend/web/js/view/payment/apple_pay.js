define(
    [
        'ko',
        'jquery',
        'uiComponent',
        'StripeIntegration_Payments/js/view/payment/method-renderer/stripe_payments',
        'StripeIntegration_Payments/js/helper/subscriptions',
        'stripe_payments_express',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_CheckoutAgreements/js/model/agreement-validator',
        'Magento_Checkout/js/action/select-payment-method',
        'Magento_Checkout/js/checkout-data',
        'Magento_Checkout/js/model/quote',
        'mage/translate',
        'Magento_Ui/js/model/messageList'
    ],
    function (
        ko,
        $,
        Component,
        paymentMethod,
        subscriptions,
        stripeExpress,
        additionalValidators,
        agreementValidator,
        selectPaymentMethod,
        checkoutData,
        quote,
        $t,
        globalMessageList
    ) {
        'use strict';

        return Component.extend({
            defaults: {
                // template: 'StripeIntegration_Payments/payment/apple_pay_top',
                stripePaymentsShowApplePaySection: false,
                isPRAPIrendered: false
            },

            initObservable: function ()
            {
                this._super()
                    .observe([
                        'stripePaymentsShowApplePaySection',
                        'isPaymentRequestAPISupported'
                    ]);

                if (subscriptions.isSubscriptionUpdate())
                    return this;

                var self = this;

                stripeExpress.onPaymentSupportedCallbacks.push(function()
                {
                    self.isPaymentRequestAPISupported(true);
                    self.stripePaymentsShowApplePaySection(true);
                });

                var currentTotals = quote.totals();

                quote.totals.subscribe(function (totals)
                {
                    if (JSON.stringify(totals.total_segments) == JSON.stringify(currentTotals.total_segments))
                        return;

                    currentTotals = totals;

                    if (!self.isPRAPIrendered)
                        return;

                    self.initPRAPI();
                }, this);

                quote.paymentMethod.subscribe(function(method)
                {
                    if (method != null)
                    {
                        $(".payment-method.stripe-payments.mobile").removeClass("_active");
                    }
                }, null, 'change');

                return this;
            },

            markPRAPIready: function()
            {
                this.isPRAPIrendered = true;
                this.initPRAPI();
            },

            initPRAPI: function()
            {
                if (!this.config().enabled)
                    return;

                var self = this;
                var params = self.config().initParams;
                stripeExpress.initStripeExpress('#payment-request-button', params, 'checkout', self.config().buttonConfig,
                    function (paymentRequestButton, paymentRequest, params, prButton) {
                        stripeExpress.initCheckoutWidget(paymentRequestButton, paymentRequest, prButton, self.beginApplePay.bind(self));
                    }
                );
            },

            prapiTitle: function()
            {
                return this.config().prapiTitle;
            },

            showApplePaySection: function()
            {
                return this.isPaymentRequestAPISupported;
            },

            config: function()
            {
                return window.checkoutConfig.payment.wallet_button;
            },

            beginApplePay: function(ev)
            {
                if (!this.validate())
                {
                    ev.preventDefault();
                }
            },

            validate: function(region)
            {
                var agreementsConfig = window.checkoutConfig ? window.checkoutConfig.checkoutAgreements : {},
                    agreementsInputPath = '.payment-method.stripe-payments.mobile div.checkout-agreements input';
                var isValid = true;

                if (!agreementsConfig.isEnabled || $(agreementsInputPath).length === 0) {
                    return true;
                }

                $(agreementsInputPath).each(function (index, element)
                {
                    if (!$.validator.validateSingleElement(element, {
                        errorElement: 'div',
                        hideError: false
                    })) {
                        isValid = false;
                    }
                });

                return isValid;
            },

            showError: function(message)
            {
                document.getElementById('checkout').scrollIntoView(true);
                globalMessageList.addErrorMessage({ "message": message });
            }
        });
    }
);
