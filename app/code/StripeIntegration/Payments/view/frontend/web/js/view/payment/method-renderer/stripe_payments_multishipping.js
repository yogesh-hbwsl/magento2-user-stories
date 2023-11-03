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
        'StripeIntegration_Payments/js/view/checkout/trialing_subscriptions',
        'StripeIntegration_Payments/js/stripe',
        'stripe_payments_express',
        'mage/translate',
        'mage/url',
        'jquery',
        'Magento_Checkout/js/action/place-order',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_Checkout/js/action/redirect-on-success',
        'mage/storage',
        'mage/url',
        'Magento_CheckoutAgreements/js/model/agreement-validator',
        'Magento_Customer/js/customer-data',
        'Magento_Checkout/js/model/payment-service'
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
        trialingSubscriptions,
        stripe,
        stripeExpress,
        $t,
        url,
        $,
        placeOrderAction,
        additionalValidators,
        redirectOnSuccessAction,
        storage,
        urlBuilder,
        agreementValidator,
        customerData,
        paymentService
    ) {
        'use strict';

        return Component.extend({
            externalRedirectUrl: null,
            defaults: {
                template: 'StripeIntegration_Payments/payment/element',
                stripePaymentsShowApplePaySection: false
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
                        'isPaymentFormVisible',
                        'isLoading',
                        'stripePaymentsError',
                        'permanentError',
                        'isOrderPlaced',
                        'isInitializing',
                        'isInitialized',
                        'useQuoteBillingAddress',
                        'cvcToken',
                        'paymentElementPaymentMethod',

                        // Saved payment methods dropdown
                        'dropdownOptions',
                        'selection',
                        'isDropdownOpen'
                    ]);

                var self = this;

                this.isPaymentFormVisible(false);
                this.isOrderPlaced(false);
                this.isInitializing(true);
                this.isInitialized(false);
                this.useQuoteBillingAddress(false);
                this.cvcToken(null);
                this.collectCvc = ko.computed(this.shouldCollectCvc.bind(this));
                this.isAmex = ko.computed(this.isAmexSelected.bind(this));
                this.cardCvcElement = null;

                var currentTotals = quote.totals();
                var currentShippingAddress = quote.shippingAddress();
                var currentBillingAddress = quote.billingAddress();

                quote.totals.subscribe(function (totals)
                {
                    if (!totals || !totals.grand_total || !totals.quote_currency_code)
                    {
                        return;
                    }

                    if (!currentTotals || !currentTotals.grand_total || !currentTotals.quote_currency_code)
                    {
                        currentTotals = totals;
                        return;
                    }

                    var amount1 = totals.grand_total;
                    var amount2 = currentTotals.grand_total;
                    var currency1 = totals.quote_currency_code;
                    var currency2 = currentTotals.quote_currency_code;

                    if (amount1 === amount2 && currency1 === currency2)
                    {
                        return;
                    }

                    currentTotals = totals;

                    self.onQuoteTotalsChanged.bind(self)();
                    self.isOrderPlaced(false);
                }, this);

                quote.paymentMethod.subscribe(function (method)
                {
                    if (method.method == this.getCode() && !this.isInitializing())
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

            initSavedPaymentMethods: function()
            {
                // If it is already initialized, do not re-initialize
                if (this.dropdownOptions())
                {
                    return;
                }

                var options = [];
                var methods = this.getStripeParam("savedMethods");
                if (methods)
                {
                    for (var i in methods)
                    {
                        if (methods.hasOwnProperty(i))
                        {
                            // We do this because some themes and libraries extend all objects with their own methods
                            options.push(methods[i]);
                        }
                    }
                }

                if (options.length > 0)
                {
                    this.isPaymentFormVisible(false);
                    this.selection(options[0]);
                }
                else
                {
                    this.isPaymentFormVisible(true);
                    this.selection(false);
                }

                this.dropdownOptions(options);
            },

            shouldCollectCvc: function()
            {
                var selection = this.selection();

                if (!selection)
                    return false;

                if (selection.type != 'card')
                    return false;

                return !!selection.cvc;
            },

            isAmexSelected: function()
            {
                var selection = this.selection();

                if (!selection)
                    return false;

                if (selection.type != 'card')
                    return false;

                return (selection.brand == "amex");
            },

            newPaymentMethod: function()
            {
                this.messageContainer.clear();

                this.selection({
                    type: 'new',
                    value: 'new',
                    icon: false,
                    label: $t('New payment method')
                });
                this.isDropdownOpen(false);
                this.isPaymentFormVisible(true);
                if (!this.isInitialized())
                {
                    this.onContainerRendered();
                    this.isInitialized(true);
                }
            },

            getPaymentMethodId: function()
            {
                var selection = this.selection();

                if (selection && typeof selection.value != "undefined" && selection.value != "new")
                {
                    return selection.value;
                }

                var paymentMethod = this.paymentElementPaymentMethod();
                if (paymentMethod && paymentMethod.id)
                {
                    return paymentMethod.id;
                }

                return null;
            },

            toggleDropdown: function()
            {
                this.isDropdownOpen(!this.isDropdownOpen());
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

            onQuoteTotalsChanged: function()
            {
                if (!this.elements || !this.elements.update)
                {
                    return;
                }

                try
                {
                    this.elements.update(this.getElementsOptions(true));
                }
                catch (e)
                {
                    this.elements.update(this.getElementsOptions(false));
                }
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

                    self.initSavedPaymentMethods();
                    self.initPaymentForm();
                });
            },

            onContainerRendered: function()
            {
                this.onPaymentElementContainerRendered();
            },

            getCardCVCOptions: function()
            {
                return {
                  style: {
                    base: {
                  //     iconColor: '#c4f0ff',
                  //     color: '#fff',
                  //     fontWeight: '500',
                  //     fontFamily: 'Roboto, Open Sans, Segoe UI, sans-serif',
                      fontSize: '16px',
                  //     fontSmoothing: 'antialiased',
                  //     ':-webkit-autofill': {
                  //       color: '#fce883',
                  //     },
                  //     '::placeholder': {
                  //       color: '#87BBFD',
                  //     },
                  //   },
                  //   invalid: {
                  //     iconColor: '#FFC7EE',
                  //     color: '#FFC7EE',
                    },
                  },
                };
            },

            onCvcContainerRendered: function()
            {
                var self = this;
                var params = this.getInitParams();

                stripe.initStripe(params, function(err)
                {
                    if (err)
                        return self.crash(err);

                    var options = {};
                    if (params && params.locale)
                    {
                        options.locale = params.locale;
                    }

                    try
                    {
                        var elements = stripe.stripeJs.elements(options);
                        self.cardCvcElement = elements.create('cardCvc', self.getCardCVCOptions());
                        self.cardCvcElement.mount('#stripe-card-cvc-element');
                        self.cardCvcElement.on('change', self.onCvcChange.bind(self));
                    }
                    catch (e)
                    {
                        this.crash(e.message);
                    }
                });
            },

            onCvcChange: function(event)
            {
                if (event.error)
                    this.selection().cvcError = event.error.message;
                else
                    this.selection().cvcError = null;
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

                if (document.getElementById('stripe-payment-element') === null)
                    return this.crash("Cannot initialize Payment Element on a DOM that does not contain a div.stripe-payment-element.");

                if (!stripe.stripeJs)
                    return this.crash("Stripe.js could not be initialized.");

                if (this.getStripeParam("isOrderPlaced"))
                    this.isOrderPlaced(true);

                try
                {
                    try
                    {
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
                }
                catch (e)
                {
                    this.crash(e.message);
                }
            },

            getElementsOptions: function(filterPaymentMethods)
            {
                var options = window.checkoutConfig.payment.stripe_payments.elementOptions;

                if (!filterPaymentMethods && options.payment_method_types)
                    delete options.payment_method_types;

                if (options.mode != "setup")
                {
                    options.amount = this.getElementsAmount();
                    options.currency = this.getElementsCurrency();
                }

                return options;
            },

            getPaymentElementOptions: function()
            {
                var options = {};

                var params = this.getInitParams();
                if (params && typeof params.wallets != "undefined" && params.wallets)
                    options.wallets = params.wallets;

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

                if (params.layout)
                {
                    options.layout = params.layout;
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

            isActive: function(parents)
            {
                return true;
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
                this.cvcToken(null);

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

                var self = this;

                if (this.isSavedCardSelected() && this.selection().cvc)
                {
                    stripe.stripeJs.createToken('cvc_update', this.cardCvcElement).then(function(result)
                    {
                        if (result.error)
                        {
                            self.showError(result.error.message);
                        }
                        else if (result.token)
                        {
                            self.cvcToken(result.token.id);
                            self.placeOrderWithSavedPaymentMethod.bind(self)();
                        }
                        else
                        {
                            self.showError('Could not perform CVC check.');
                        }
                    });
                }
                else if (this.isSavedPaymentMethodSelected())
                {
                    this.placeOrderWithSavedPaymentMethod();
                }
                else
                {
                    this.createPaymentMethod(this.onPaymentMethodCreatedForOrderPlacement.bind(this));
                }

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

            isSavedPaymentMethodSelected: function()
            {
                var selectedMethodType = this.getSelectedMethod("type");

                if (!selectedMethodType) // There is no saved PMs dropdown
                    return false;

                if (selectedMethodType != 'new') // A saved PMs is selected
                    return true;

                return false; // New PM is selected
            },

            isSavedCardSelected: function()
            {
                var selectedMethodType = this.getSelectedMethod("type");

                if (!selectedMethodType) // There is no saved PMs dropdown
                    return false;

                if (selectedMethodType == 'card') // A saved PMs is selected
                    return true;

                return false; // New PM is selected
            },

            placeOrderWithSavedPaymentMethod: function()
            {
                var self = this;
                var placeNewOrder = this.placeNewOrder.bind(this);

                if (this.isOrderPlaced()) // The order was already placed but either 3D Secure failed or the customer pressed the back button from an external payment page
                {
                    updateCartAction(this.getData(), this.onCartUpdated.bind(this));
                }
                else
                {
                    try
                    {
                        placeNewOrder();
                    }
                    catch (e)
                    {
                        this.showError($t("The order could not be placed. Please contact us for assistance."));
                        console.error(e.message);
                    }
                }
            },

            onPaymentMethodCreatedForOrderPlacement: function(paymentMethod)
            {
                var placeNewOrder = this.placeNewOrder.bind(this);
                var self = this;

                if (self.isOrderPlaced()) // The order was already placed but either 3D Secure failed or the customer pressed the back button from an external payment page
                {
                    updateCartAction(this.getData(), this.onCartUpdated.bind(this));
                }
                else
                {
                    try
                    {
                        placeNewOrder();
                    }
                    catch (e)
                    {
                        self.showError($t("The order could not be placed. Please contact us for assistance."));
                        console.error(e.message);
                    }
                }
            },

            onCartUpdated: function(result, outcome, response)
            {
                var placeNewOrder = this.placeNewOrder.bind(this);
                var onOrderPlaced = this.onOrderPlaced.bind(this);
                try
                {
                    var data = JSON.parse(result);
                    if (data.error)
                    {
                        this.showError(data.error);
                    }
                    else if (data.redirect)
                    {
                        $.mage.redirect(data.redirect);
                    }
                    else if (data.placeNewOrder)
                    {
                        placeNewOrder();
                    }
                    else
                    {
                        onOrderPlaced();
                    }
                }
                catch (e)
                {
                    this.showError($t("The order could not be placed. Please contact us for assistance."));
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

            getSelectedMethod: function(param)
            {
                var selection = this.selection();
                if (!selection)
                    return null;

                if (typeof selection[param] == "undefined")
                    return null;

                return selection[param];
            },

            // Called when:
            // - A brand new order has just been placed
            // - After updateCartAction() with placeNewOrder == false
            onOrderPlaced: function(result, outcome, response)
            {
                if (!this.isOrderPlaced() && isNaN(result))
                {
                    return this.softCrash("The order was placed but the response from the server did not include a numeric order ID.");
                }
                else
                {
                    this.isOrderPlaced(true);
                }

                this.isLoading(true);
                var self = this;
                var handleNextActions = this.handleNextActions.bind(this);
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

            // Called when:
            // - A brand new order has just been placed
            // - After updateCartAction() with placeNewOrder == false
            handleNextActions: function(stripeObject)
            {
                if (!this.isOrderPlaced())
                {
                    return this.softCrash("Cannot handleNextActions without placing the order first");
                }

                var self = this;

                if (this.isSuccessful(stripeObject))
                {
                    this.onConfirm(null);
                }
                else if (stripeObject.status == "requires_action")
                {
                    // Non-card based confirms may redirect the customer externally. We restore the quote just before it in case the
                    // customer clicks the back button on the browser before authenticating the payment.
                    restoreQuoteAction(function()
                    {
                        stripe.stripeJs.handleNextAction({
                          clientSecret: stripeObject.client_secret
                        }).then(self.onConfirm.bind(self));
                    });
                }
                else if (stripeObject.status == "requires_confirmation")
                {
                    // This should only hit when a payment failed with a saved PM, and then the customer switched to PaymentElement to enter a new payment method
                    restoreQuoteAction(function()
                    {
                        // We pass null because we do not want to update the PM. It has already been updated with stripe.updatePaymentIntent
                        updateCartAction(self.getData(), self.onCartUpdated.bind(self));
                    });
                }
                else if (stripeObject.status == "requires_payment_method")
                {
                    restoreQuoteAction(function()
                    {
                        updateCartAction(self.getData(), self.onCartUpdated.bind(self));
                    });
                }
                else
                {
                    restoreQuoteAction(function()
                    {
                        self.showError($t("The order could not be placed. Please contact us for assistance."));
                        console.error("Could not finalize order bacause the payment intent is in status " + stripeObject.status);
                    });
                }
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
                    var successUrl = this.getStripeParam("successUrl");
                    $.mage.redirect(successUrl);
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
                return this.validateCvc() && agreementValidator.validate() && additionalValidators.validate();
            },

            validateCvc: function()
            {
                if (!this.selection())
                    return true;

                if (this.selection().type != "card")
                    return true;

                if (this.selection().cvc != 1)
                    return true;

                if (typeof this.selection().cvcError == "undefined")
                {
                    this.showError($t("Please enter your card's security code."));
                    return false;
                }
                else if (!this.selection().cvcError)
                {
                    return true;
                }
                else
                {
                    this.showError(this.selection().cvcError);
                    return false;
                }

                return true;
            },

            getCode: function()
            {
                return 'stripe_payments';
            },

            getData: function()
            {
                var data = {
                    'method': this.item.method,
                    'additional_data': {
                        'payment_element': true,
                        'payment_method': this.getPaymentMethodId(),
                        'manual_authentication': 'card'
                    }
                };

                if (this.cvcToken())
                {
                    data.additional_data.cvc_token = this.cvcToken();
                }

                return data;
            },

            clearErrors: function()
            {
                this.stripePaymentsError(null);
            }

        });
    }
);
