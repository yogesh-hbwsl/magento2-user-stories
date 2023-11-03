define(
    [
        'ko',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Ui/js/model/messageList',
        'Magento_Checkout/js/model/quote',
        'Magento_Customer/js/model/customer',
        'StripeIntegration_Payments/js/action/post-update-cart',
        'StripeIntegration_Payments/js/action/post-restore-quote',
        'StripeIntegration_Payments/js/action/get-requires-action',
        'StripeIntegration_Payments/js/stripe',
        'mage/translate',
        'jquery',
        'Magento_Checkout/js/action/place-order',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_Checkout/js/action/redirect-on-success',
        'mage/storage',
        'Magento_CheckoutAgreements/js/model/agreement-validator',
        'Magento_Customer/js/customer-data',
    ],
    function (
        ko,
        Component,
        globalMessageList,
        quote,
        customer,
        updateCartAction,
        restoreQuoteAction,
        getRequiresAction,
        stripe,
        $t,
        $,
        placeOrderAction,
        additionalValidators,
        redirectOnSuccessAction,
        storage,
        agreementValidator,
        customerData
    ) {
        'use strict';

        return Component.extend({
            externalRedirectUrl: null,
            defaults: {
                template: 'StripeIntegration_Payments/payment/bank_transfers'
            },
            redirectAfterPlaceOrder: false,
            elements: null,
            initParams: null,
            paymentElement: null,
            zeroDecimalCurrencies: ['BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','UGX','VND','VUV','XAF','XOF','XPF'],

            initObservable: function ()
            {
                this._super()
                    .observe([
                        'paymentElement',
                        'isPaymentFormComplete',
                        'isLoading',
                        'stripePaymentsError',
                        'permanentError',
                        'isInitializing',
                        'isInitialized',
                        'useQuoteBillingAddress',
                        'paymentElementPaymentMethod',
                    ]);

                var self = this;

                this.isInitializing(true);
                this.isInitialized(false);
                this.useQuoteBillingAddress(false);

                var currentTotals = quote.totals();

                quote.paymentMethod.subscribe(function (method)
                {
                    if (method && method.method == this.getCode() && !this.isInitializing())
                    {
                        // We intentionally re-create the element because its container element may have changed
                        this.initPaymentForm();
                    }
                }, this);

                quote.billingAddress.subscribe(function(address)
                {
                    if (address && self.paymentElement && self.paymentElement.update && !self.isPaymentFormComplete())
                    {
                        // Remove the postcode & country fields if a billing address has been specified
                        self.paymentElement.update(self.getPaymentElementUpdateOptions());
                    }
                });

                return this;
            },


            getPaymentMethodId: function()
            {
                var paymentMethod = this.paymentElementPaymentMethod();
                if (paymentMethod && paymentMethod.id)
                {
                    return paymentMethod.id;
                }

                return null;
            },

            getStripeParam: function(param)
            {
                var params = this.getInitParams();

                if (!params)
                {
                    return null;
                }

                if (typeof params[param] != "undefined")
                {
                    return params[param];
                }

                return null;
            },

            getInitParams: function()
            {
                return window.checkoutConfig.payment.stripe_payments.initParams;
            },

            onPaymentElementContainerRendered: function()
            {
                var self = this;
                this.isLoading(true);
                stripe.initStripe(this.getInitParams(), function(err)
                {
                    if (err)
                        return self.crash(err);

                    self.initPaymentForm();
                });
            },

            onContainerRendered: function()
            {
                this.onPaymentElementContainerRendered();
            },

            crash: function(message)
            {
                this.isLoading(false);
                var userError = this.getStripeParam("userError");
                if (userError)
                    this.permanentError(userError);
                else
                    this.permanentError($t("Sorry, this payment method is not available. Please contact us for assistance."));

                console.error("Error: " + message);
            },

            softCrash: function(message)
            {
                var userError = this.getStripeParam("userError");
                if (userError)
                    this.showError(userError);
                else
                    this.showError($t("Sorry, this payment method is not available. Please contact us for assistance."));

                console.error("Error: " + message);
            },

            isCollapsed: function()
            {
                if (this.isChecked() == this.getCode())
                {
                    return false;
                }
                else
                {
                    return true;
                }
            },

            initPaymentForm: function()
            {
                this.isInitializing(false);
                this.isLoading(false);

                if (this.isCollapsed()) // Don't render PE with a height of 0
                    return;

                if (document.getElementById('stripe-payment-element-bank-transfers') === null)
                    return this.crash("Cannot initialize Payment Element on a DOM that does not contain a div.stripe-payment-element-bank-transfers.");

                if (!stripe.stripeJs)
                    return this.crash("Stripe.js could not be initialized.");

                try
                {
                    this.elements = stripe.stripeJs.elements(this.getElementsOptions());
                    this.paymentElement = this.elements.create('payment', this.getPaymentElementOptions());
                    this.paymentElement.mount('#stripe-payment-element-bank-transfers');
                    this.paymentElement.on('change', this.onChange.bind(this));
                }
                catch (e)
                {
                    this.crash(e.message);
                }
            },

            getElementsOptions: function()
            {
                var options = window.checkoutConfig.payment.stripe_payments_bank_transfers.elementOptions;

                options.amount = this.getElementsAmount();
                options.currency = this.getElementsCurrency();

                return options;
            },

            getPaymentElementOptions: function()
            {
                var options = {};

                var billingAddress = quote.billingAddress();

                if (billingAddress)
                {
                    try
                    {
                        this.useQuoteBillingAddress(true);

                        var hasState = (billingAddress.region || billingAddress.regionCode || billingAddress.regionId);

                        options.fields = {
                            billingDetails: {
                                name: 'never',
                                email: 'never',
                                phone: (billingAddress.telephone ? 'never' : 'auto'),
                                address: {
                                    line1: ((billingAddress.street.length > 0) ? 'never' : 'auto'),
                                    line2: ((billingAddress.street.length > 0) ? 'never' : 'auto'),
                                    city: billingAddress.city ? 'never' : 'auto',
                                    state: hasState ? 'never' : 'auto',
                                    country: billingAddress.countryId ? 'never' : 'auto',
                                    postalCode: billingAddress.postcode ? 'never' : 'auto'
                                }
                            }
                        };
                    }
                    catch (e)
                    {
                        this.useQuoteBillingAddress(false);

                        options.fields = {};
                        console.warn('Could not retrieve billing address: '  + e.message);
                    }

                    // Set the default billing address in order to enable the Link payment method
                    var billingDetails = this.getBillingDetails();

                    if (billingDetails)
                    {
                        options.defaultValues = {
                            billingDetails: billingDetails
                        };
                    }
                }
                else
                {
                    this.useQuoteBillingAddress(false);
                }

                return options;
            },

            getPaymentElementUpdateOptions: function()
            {
                var options = this.getPaymentElementOptions();

                if (options.wallets)
                {
                    delete options.wallets;
                }

                return options;
            },

            onChange: function(event)
            {
                this.isLoading(false);
                this.isPaymentFormComplete(event.complete);
            },

            getElementsAmount: function()
            {
                var totals = quote.totals();

                if (totals && totals.grand_total)
                {
                    var amount = totals.grand_total;
                    return this.convertToStripeAmount(amount, this.getElementsCurrency());
                }

                return 0;
            },

            getElementsCurrency: function()
            {
                var totals = quote.totals();
                if (totals && totals.quote_currency_code)
                {
                    var currency = totals.quote_currency_code;
                    return currency.toLowerCase();
                }

                return 'USD';
            },

            isBillingAddressSet: function()
            {
                return quote.billingAddress() && quote.billingAddress().canUseForBilling();
            },

            convertToStripeAmount: function(amount, currencyCode)
            {
                var code = currencyCode.toUpperCase();

                if (this.zeroDecimalCurrencies.indexOf(code) >= 0)
                {
                    return Math.round(amount);
                }
                else
                {
                    return Math.round(amount * 100);
                }
            },

            isPlaceOrderEnabled: function()
            {
                if (this.stripePaymentsError())
                    return false;

                if (this.permanentError())
                    return false;

                return this.isBillingAddressSet();
            },

            getAddressField: function(field)
            {
                if (!quote.billingAddress())
                    return null;

                var address = quote.billingAddress();

                if (!address[field] || address[field].length == 0)
                    return null;

                return address[field];
            },

            getBillingDetails: function()
            {
                var details = {};
                var address = {};

                if (this.getAddressField('city'))
                    address.city = this.getAddressField('city');

                if (this.getAddressField('countryId'))
                    address.country = this.getAddressField('countryId');

                if (this.getAddressField('postcode'))
                    address.postal_code = this.getAddressField('postcode');

                if (this.getAddressField('region'))
                    address.state = this.getAddressField('region');

                if (this.getAddressField('street'))
                {
                    var street = this.getAddressField('street');
                    address.line1 = street[0];

                    if (street.length > 1)
                        address.line2 = street[1];
                }

                if (Object.keys(address).length > 0)
                    details.address = address;

                if (this.getAddressField('telephone'))
                    details.phone = this.getAddressField('telephone');

                if (this.getAddressField('firstname'))
                    details.name = this.getAddressField('firstname') + ' ' + this.getAddressField('lastname');

                if (quote.guestEmail)
                    details.email = quote.guestEmail;
                else if (customerData.email)
                    details.email = customerData.email;

                if (Object.keys(details).length > 0)
                    return details;

                return null;
            },

            config: function()
            {
                return window.checkoutConfig.payment[this.getCode()];
            },

            placeOrder: function()
            {
                this.messageContainer.clear();

                if (!this.isPaymentFormComplete() && !this.getPaymentMethodId())
                    return this.showError($t('Please complete your payment details.'));

                if (!this.validate())
                    return;

                this.clearErrors();
                this.isPlaceOrderActionAllowed(false);
                this.isLoading(true);

                var params = { };

                if (this.useQuoteBillingAddress())
                {
                    params.payment_method_data = {
                        billing_details: {
                            address: this.getStripeFormattedAddress(quote.billingAddress()),
                            email: this.getBillingEmail(),
                            name: this.getNameFromAddress(quote.billingAddress()),
                            phone: this.getBillingPhone()
                        }
                    };
                }

                if (this.hasShipping())
                {
                    params.shipping = {
                        address: this.getStripeFormattedAddress(quote.shippingAddress()),
                        name: this.getNameFromAddress(quote.shippingAddress())
                    };
                }

                this.createPaymentMethod(this.onPaymentMethodCreatedForOrderPlacement.bind(this));

                return false;
            },

            hasShipping: function()
            {
                return (quote && quote.shippingMethod() && quote.shippingMethod().method_code);
            },

            createPaymentMethod: function(callback)
            {
                this.paymentElementPaymentMethod(null);

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

                this.elements.submit().then(function()
                {
                    stripe.stripeJs.createPaymentMethod(paymentMethodData).then(function(result)
                    {
                        if (result.error)
                        {
                            self.showError(result.error.message);
                            console.error(result.error.message);
                        }
                        else
                        {
                            self.paymentElementPaymentMethod(result.paymentMethod);
                            callback(result.paymentMethod);
                        }
                    });
                },
                function(result)
                {
                    if (result.error)
                    {
                        self.showError(result.error.message);
                        console.error(result.error.message);
                    }
                    else
                    {
                        self.showError("A payment submission error has occurred.");
                        console.error(result);
                    }
                });
            },

            onPaymentMethodCreatedForOrderPlacement: function(paymentMethod)
            {
                var placeNewOrder = this.placeNewOrder.bind(this);
                var self = this;

                try
                {
                    placeNewOrder();
                }
                catch (e)
                {
                    self.showError($t("The order could not be placed. Please contact us for assistance."));
                    console.error(e.message);
                }
            },

            placeNewOrder: function()
            {
                var self = this;

                this.isLoading(false); // Needed for the terms and conditions checkbox
                this.getPlaceOrderDeferredObject()
                    .fail(this.handlePlaceOrderErrors.bind(this))
                    .done(this.onOrderPlaced.bind(this))
                    .always(function(response, status, xhr)
                    {
                        if (status != "success")
                        {
                            self.isLoading(false);
                            self.isPlaceOrderEnabled(true);
                        }
                    });
            },

            onOrderPlaced: function(result, outcome, response)
            {
                this.isLoading(true);
                var self = this;
                getRequiresAction(function(clientSecret)
                {
                    try
                    {
                        if (clientSecret && clientSecret.length)
                        {
                            stripe.stripeJs.handleNextAction({
                              clientSecret: clientSecret
                            }).then(self.onConfirm.bind(self));
                        }
                        else
                        {
                            // No further actions are needed
                            self.onConfirm(null);
                        }
                    }
                    catch (e)
                    {
                        restoreQuoteAction();
                        self.showError("The order was placed but we could not confirm if the payment was successful.");
                        console.error(e);
                    }

                });
            },

            isSuccessful: function(stripeObject)
            {

                if (stripeObject.status == "requires_action" &&
                    stripeObject.next_action &&
                    stripeObject.next_action.type &&
                    stripeObject.next_action.type != "use_stripe_sdk"
                )
                {
                    // This is the case for vouchers, where an offline payment is required
                    return true;
                }

                return (['processing', 'requires_capture', 'succeeded'].indexOf(stripeObject.status) >= 0);
            },

            getConfirmParams: function()
            {
                var params = {
                    elements: this.elements,
                    confirmParams: {
                        return_url: this.getStripeParam("successUrl")
                    }
                };

                this.getPaymentElementOptions();
                if (this.useQuoteBillingAddress())
                {
                    params.confirmParams.payment_method_data = {
                        billing_details: {
                            address: this.getStripeFormattedAddress(quote.billingAddress()),
                            email: this.getBillingEmail(),
                            name: this.getNameFromAddress(quote.billingAddress()),
                            phone: this.getBillingPhone()
                        }
                    };
                }

                return params;
            },

            getStripeFormattedAddress: function(address)
            {
                var stripeAddress = {};

                stripeAddress.state = address.region ? address.region : null;
                stripeAddress.postal_code = address.postcode ? address.postcode : null;
                stripeAddress.country = address.countryId ? address.countryId : null;
                stripeAddress.city = address.city ? address.city : null;

                if (address.street && address.street.length > 0)
                {
                    stripeAddress.line1 = address.street[0];

                    if (address.street.length > 1)
                    {
                        stripeAddress.line2 = address.street[1];
                    }
                    else
                    {
                        stripeAddress.line2 = null;
                    }
                }
                else
                {
                    stripeAddress.line1 = null;
                    stripeAddress.line2 = null;
                }

                return stripeAddress;
            },

            getBillingEmail: function()
            {
                if (quote.guestEmail)
                {
                    return quote.guestEmail;
                }
                else if (window.checkoutConfig.customerData && window.checkoutConfig.customerData.email)
                {
                    return window.checkoutConfig.customerData.email;
                }

                return null;
            },

            getNameFromAddress: function(address)
            {
                if (!address)
                    return null;

                var parts = [];
                if (address.firstname)
                    parts.push(address.firstname);

                if (address.middlename)
                    parts.push(address.middlename);

                if (address.lastname)
                    parts.push(address.lastname);

                return parts.join(" ");
            },

            getBillingPhone: function()
            {
                var billingAddress = quote.billingAddress();
                if (!billingAddress)
                    return null;

                if (billingAddress.telephone)
                    return billingAddress.telephone;

                return null;
            },

            onConfirm: function(result)
            {
                this.isLoading(false);
                if (result && result.error)
                {
                    this.showError(result.error.message);
                }
                else
                {
                    customerData.invalidate(['cart']);
                    redirectOnSuccessAction.execute();
                }
            },

            /**
             * @return {*}
             */
            getPlaceOrderDeferredObject: function()
            {
                return placeOrderAction(this.getData(), this.messageContainer);
            },

            getClientSecretFromResponse: function(response)
            {
                if (typeof response != "string")
                {
                    return null;
                }

                if (response.indexOf("Authentication Required: ") >= 0)
                {
                    return response.substring("Authentication Required: ".length);
                }

                return null;
            },

            handleCardPayment: function(paymentIntent, done)
            {
                try
                {
                    stripe.stripeJs.handleCardPayment(paymentIntent.client_secret).then(function(result)
                    {
                        if (result.error)
                            return done(result.error.message);

                        return done();
                    });
                }
                catch (e)
                {
                    done(e.message);
                }
            },
            handleCardAction: function(paymentIntent, done)
            {
                try
                {
                    stripe.stripeJs.handleCardAction(paymentIntent.client_secret).then(function(result)
                    {
                        if (result.error)
                            return done(result.error.message);

                        return done();
                    });
                }
                catch (e)
                {
                    done(e.message);
                }
            },

            authenticateCustomer: function(clientSecret, done)
            {
                try
                {
                    stripe.stripeJs.handleNextAction({
                      clientSecret: clientSecret
                    }).then(function(result)
                    {
                        if (result.error)
                            return done(result.error.message);

                        done();
                    });
                }
                catch (e)
                {
                    done(e.message);
                }
            },

            handlePlaceOrderErrors: function (result)
            {
                if (result && result.responseJSON && result.responseJSON.message)
                {
                    var clientSecret = this.getClientSecretFromResponse(result.responseJSON.message);

                    if (clientSecret)
                    {
                        var self = this;
                        return this.authenticateCustomer(clientSecret, function(err)
                        {
                            if (err)
                                return self.showError(err);

                            self.placeNewOrder.bind(self)();
                        });
                    }
                    else
                    {
                        this.showError(result.responseJSON.message);
                    }
                }
                else
                {
                    this.showError($t("The order could not be placed. Please contact us for assistance."));

                    if (result && result.responseText)
                        console.error(result.responseText);
                    else
                        console.error(result);
                }
            },

            showError: function(message)
            {
                this.isLoading(false);
                this.isPlaceOrderEnabled(true);
                this.messageContainer.addErrorMessage({ "message": message });
            },

            validate: function(elm)
            {
                return agreementValidator.validate() && additionalValidators.validate();
            },

            getCode: function()
            {
                return 'stripe_payments_bank_transfers';
            },

            getData: function()
            {
                var data = {
                    'method': this.item.method,
                    'additional_data': {
                        'payment_method': this.getPaymentMethodId(),
                    }
                };

                return data;
            },

            clearErrors: function()
            {
                this.stripePaymentsError(null);
            }

        });
    }
);
