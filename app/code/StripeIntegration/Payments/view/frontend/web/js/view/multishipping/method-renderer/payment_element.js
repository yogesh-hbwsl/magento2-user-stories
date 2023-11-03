define(
    [
        'ko',
        'StripeIntegration_Payments/js/view/payment/method-renderer/stripe_payments_multishipping',
        'StripeIntegration_Payments/js/stripe',
        'Magento_Ui/js/model/messageList',
        'Magento_Checkout/js/model/quote',
        'Magento_Customer/js/model/customer',
        'Magento_Checkout/js/action/set-payment-information',
        'mage/translate',
        'mage/url',
        'jquery',
        'Magento_Checkout/js/model/payment/additional-validators',
        'mage/storage',
        'mage/url',
        'Magento_CheckoutAgreements/js/model/agreement-validator',
        'Magento_Customer/js/customer-data',
        'Magento_Ui/js/modal/alert',
        'domReady!'
    ],
    function (
        ko,
        Component,
        stripe,
        globalMessageList,
        quote,
        customer,
        setPaymentInformationAction,
        $t,
        url,
        $,
        additionalValidators,
        storage,
        urlBuilder,
        agreementValidator,
        customerData,
        alert
    ) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'StripeIntegration_Payments/multishipping/payment_element',
                continueSelector: '#payment-continue',
                cardElement: null,
                token: ko.observable(null),
                params: null,
                captureMethod: 'automatic'
            },

            initObservable: function ()
            {
                this._super();

                $(this.continueSelector).click(this.onContinue.bind(this));

                return this;
            },

            onPaymentElementContainerRendered: function()
            {
                var self = this;
                this.isLoading(true);

                this.params = window.initParams;

                stripe.initStripe(this.params, function(err)
                {
                    if (err)
                        return self.crash(err);

                    self.initSavedPaymentMethods.bind(self)();
                    self.initPaymentForm.bind(self)();
                });
            },

            onContainerRendered: function()
            {
                this.onPaymentElementContainerRendered();
                this.isInitialized(true);
            },

            initPaymentForm: function()
            {
                this.isInitializing(false);
                this.isLoading(false);

                if (this.isCollapsed()) // Don't render PE with a height of 0
                    return;

                if (document.getElementById('stripe-payment-element') === null)
                    return this.crash("Cannot initialize Card Element on a DOM that does not contain a div.stripe-card-element.");

                if (!stripe.stripeJs)
                    return this.crash("Stripe.js could not be initialized.");

                try
                {
                    var elementOptions = this.getElementsOptions(true);
                    elementOptions.setupFutureUsage = 'on_session';
                    elementOptions.captureMethod = this.captureMethod;
                    this.elements = stripe.stripeJs.elements(this.getElementsOptions(true));
                }
                catch (e)
                {
                    console.warn("Could not filter Stripe payment method types: " + e.message);
                    this.elements = stripe.stripeJs.elements(this.getElementsOptions(false));
                }

                this.paymentElement = this.elements.create('payment', this.getPaymentElementOptions());
                this.paymentElement.mount('#stripe-payment-element');
                this.paymentElement.on('change', this.onChange.bind(this));

            },

            onSetPaymentMethodFail: function(result)
            {
                this.token(null);
                this.isLoading(false);
                console.error(result);
            },

            onContinue: function(e)
            {
                // If we already have a tokenized payment method, don't do anything
                if (this.token())
                    return;

                var self = this;

                if (!this.isStripeMethodSelected())
                    return;

                e.preventDefault();
                e.stopPropagation();

                if (!this.validatePaymentMethod())
                    return;

                this.isLoading(true);

                if (this.getSelectedMethod("type") && this.getSelectedMethod("type") != "new")
                {
                    self.token(this.getSelectedMethod("value"));
                    setPaymentInformationAction(this.messageContainer, this.getData()).then(function(){
                        $(self.continueSelector).click();
                    }).fail(self.onSetPaymentMethodFail.bind(self));
                }
                else
                {
                    this.createPaymentMethod(function(err)
                    {
                        if (err)
                            return self.showError(err);

                        $(self.continueSelector).click();
                    });
                }
            },

            createPaymentMethod: function(done)
            {
                var self = this;

                var paymentMethodData = {
                    elements: this.elements,
                    params: {}
                };

                var confirmParams = this.getConfirmParams();
                var billingDetails = null;
                if (confirmParams &&
                    confirmParams.confirmParams &&
                    confirmParams.confirmParams.payment_method_data &&
                    confirmParams.confirmParams.payment_method_data.billing_details
                )
                {
                    billingDetails = confirmParams.confirmParams.payment_method_data.billing_details;
                }

                if (billingDetails)
                {
                    paymentMethodData.params.billing_details = confirmParams.confirmParams.payment_method_data.billing_details;
                }
                else
                {
                    return this.showError($t("Please specify a billing address."));
                }

                this.elements.submit().then(function() {
                    stripe.stripeJs.createPaymentMethod(paymentMethodData).then(function(result)
                    {
                        if (result.error)
                        {
                            self.showError(result.error.message);
                            console.error(result.error.message);
                        }
                        else
                        {
                            self.token(result.paymentMethod.id);

                            setPaymentInformationAction(self.messageContainer, self.getData()).then(function()
                            {
                                done();
                            }).fail(self.onSetPaymentMethodFail.bind(self));
                        }
                    });
                });

            },

            getData: function()
            {
                var data = {
                    'method': this.item.method,
                    'additional_data': {
                        'payment_element': true,
                        'cc_stripejs_token': this.token(),
                        'manual_authentication': 'card'
                    }
                };

                return data;
            },

            showError: function(message)
            {
                this.isLoading(false);
                alert({ content: message });
            },

            validatePaymentMethod: function ()
            {
                var methods = $('[name^="payment["]'), isValid = false;

                if (methods.length === 0)
                    this.showError( $.mage.__('We can\'t complete your order because you don\'t have a payment method set up.') );
                else if (methods.filter('input:radio:checked').length)
                    return true;
                else
                    this.showError( $.mage.__('Please choose a payment method.') );

                return isValid;
            },

            isStripeMethodSelected: function()
            {
                var methods = $('[name^="payment["]');

                if (methods.length === 0)
                    return false;

                var stripe = methods.filter(function(index, value)
                {
                    if (value.id == "p_method_stripe_payments")
                        return value;
                });

                if (stripe.length == 0)
                    return false;

                return stripe[0].checked;
            }
        });
    }
);
