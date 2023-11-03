define(
    [
        'ko',
        'uiComponent',
        'StripeIntegration_Payments/js/action/list-payment-methods',
        'StripeIntegration_Payments/js/action/add-payment-method',
        'StripeIntegration_Payments/js/action/delete-payment-method',
        'StripeIntegration_Payments/js/stripe',
        'Magento_Ui/js/model/messageList',
        'Magento_Customer/js/model/customer',
        'mage/translate',
        'jquery',
        'mage/storage',
        'Magento_Customer/js/customer-data',
        'Magento_Ui/js/model/messages',
        'uiLayout'
    ],
    function (
        ko,
        Component,
        listPaymentMethodsAction,
        addPaymentMethodAction,
        deletePaymentMethodAction,
        stripe,
        globalMessageList,
        customer,
        $t,
        $,
        storage,
        customerData,
        messagesModel,
        layout
    ) {
        'use strict';

        return Component.extend({
            externalRedirectUrl: null,
            defaults: {
                template: 'StripeIntegration_Payments/setup_element',
            },
            elements: null,
            initParams: null,

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
                        'isInitialized',
                        'savedPaymentMethods',
                        'processingSavedPaymentMethods'
                    ]);

                var self = this;

                this.isPaymentFormVisible(false);
                this.isOrderPlaced(false);
                this.isInitialized(false);
                this.processingSavedPaymentMethods(false);

                this.hasPaymentMethods = ko.computed(this.hasPaymentMethodsComputed.bind(this));

                this.messageContainer = new messagesModel();

                var messagesComponent = {
                    parent: this.name,
                    name: this.name + '.messages',
                    displayArea: 'messages',
                    component: 'Magento_Ui/js/view/messages',
                    config: {
                        messageContainer: this.messageContainer,
                        autoHideTimeOut: -1
                    }
                };

                layout([messagesComponent]);

                return this;
            },

            getStripeParam: function(param)
            {
                if (typeof window.initParams == "undefined")
                    return null;

                if (typeof window.initParams[param] == "undefined")
                    return null;

                return window.initParams[param];
            },

            onPaymentElementContainerRendered: function()
            {
                var self = this;
                this.isLoading(true);
                this.listPaymentMethods();
                var initParams = window.initParams;

                stripe.initStripe(initParams, function(err)
                {
                    if (err)
                        return self.crash(err);

                    self.initSetupElement(initParams);
                });
            },

            onContainerRendered: function()
            {
                this.onPaymentElementContainerRendered();
            },

            crash: function(message)
            {
                this.isLoading(false);
                this.permanentError($t("Sorry, an error has occurred. Please contact us for assistance."));
                console.error("Error: " + message);
            },

            softCrash: function(message)
            {
                this.showError($t("Sorry, an error has occurred. Please contact us for assistance."));
                this.stripePaymentsError(message);
                console.error("Error: " + message);
            },

            initSetupElement: function(params)
            {
                if (document.getElementById('stripe-setup-element') === null)
                    return this.crash("Cannot initialize Payment Element on a DOM that does not contain a div.stripe-setup-element.");

                if (!stripe.stripeJs)
                    return this.crash("Stripe.js could not be initialized.");

                var elements = this.elements = stripe.stripeJs.elements({
                    mode: "setup",
                    setup_future_usage: "on_session",
                    locale: params.locale,
                    currency: params.currency,
                    appearance: this.getStripePaymentElementOptions(),
                    paymentMethodCreation: "manual"
                });

                this.paymentElement = elements.create('payment');
                this.paymentElement.mount('#stripe-setup-element');
                this.paymentElement.on('change', this.onChange.bind(this));
                this.isLoading(false);
            },

            onChange: function(event)
            {
                this.isLoading(false);
                this.isPaymentFormComplete(event.complete);
            },

            getStripePaymentElementOptions: function()
            {
                return {
                  theme: 'stripe',
                  variables: {
                    colorText: '#32325d',
                    fontFamily: '"Open Sans","Helvetica Neue", Helvetica, Arial, sans-serif',
                  },
                };
            },

            getAddressField: function(field)
            {
                var address = [];

                if (typeof address[field] == "undefined")
                    return null;

                if (typeof address[field] !== "string" && typeof address[field] !== "object")
                    return null;

                if (address[field].length == 0)
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

                if (customerData.email)
                    details.email = customerData.email;

                if (Object.keys(details).length > 0)
                    return details;

                return null;
            },

            config: function()
            {
                return self.initParams;
            },

            onClick: function(result, outcome, response)
            {
                if (!this.isPaymentFormComplete())
                    return this.showError($t('Please complete the payment method details.'));

                this.clearErrors();

                this.isLoading(true);
                var onPaymentMethodCreated = this.onPaymentMethodCreated.bind(this);
                var onFail = this.onFail.bind(this);

                this.createPaymentMethod(onPaymentMethodCreated, onFail);
            },

            createPaymentMethod: function(onPaymentMethodCreated, onFail)
            {
                var paymentMethodData = {
                    elements: this.elements,
                    params: {}
                };

                this.elements.submit().then(function()
                {
                    stripe.stripeJs.createPaymentMethod(paymentMethodData).then(onPaymentMethodCreated, onFail);
                }, onFail);

            },

            onPaymentMethodCreated: function(result)
            {
                var self = this;

                if (result.error)
                {
                    this.showError(result.error.message);
                }
                else
                {
                    addPaymentMethodAction(result.paymentMethod.id, function(response, status, xhr)
                    {
                        self.isLoading(false);
                        if (status == "success")
                        {
                            try
                            {
                                var data = JSON.parse(response);

                                var methods = self.savedPaymentMethods();
                                if (!methods)
                                {
                                    methods = [];
                                }

                                var isDuplicate = false;
                                var newMethods = [];

                                for (var i in methods)
                                {
                                    if (methods[i].fingerprint != data.fingerprint)
                                    {
                                        newMethods.push(methods[i]);
                                    }
                                    else
                                    {
                                        isDuplicate = true;
                                    }
                                }

                                newMethods.push(data);

                                self.savedPaymentMethods(newMethods);

                                if (isDuplicate)
                                {
                                    self.showSuccessMessage($t("An existing payment method has been updated."));
                                }
                                else
                                {
                                    self.showSuccessMessage($t("The payment method has been saved."));
                                }
                                self.clearFormData();
                            }
                            catch (e)
                            {
                                console.warn(e);
                                self.showError($t("The payment method could not be saved: %1").replace("%1", e.message));
                            }
                        }
                        else if (response && response.responseJSON && response.responseJSON.message)
                        {
                            self.showError(response.responseJSON.message);
                        }
                        else
                        {
                            self.showError("Sorry, the payment methods could not be added.");
                            console.warn(response);
                        }
                    });
                }
            },

            clearFormData: function()
            {
                this.paymentElement.clear();
                $('html, body').animate({ scrollTop: $("#my-saved-payment-methods-table").offset().top - 100}, 500);
            },

            onFail: function(result)
            {
                this.showError("Could not set up the payment method. Please try again.");
                console.error(result);
            },

            showError: function(message)
            {
                this.isLoading(false);
                this.messageContainer.addErrorMessage({ "message": message });
            },

            showSuccessMessage: function(message)
            {
                this.isLoading(false);
                this.messageContainer.addSuccessMessage({ "message": message });
            },

            validate: function(elm)
            {
                return true;
            },

            getCode: function()
            {
                return 'stripe_payments';
            },

            clearErrors: function()
            {
                this.messageContainer.clear();
                this.stripePaymentsError(null);
            },

            hasPaymentMethodsComputed: function()
            {
                return this.savedPaymentMethods() && this.savedPaymentMethods().length > 0;
            },

            removePaymentMethod: function(fingerprint)
            {
                var methods = this.savedPaymentMethods();
                if (!methods)
                {
                    methods = [];
                }

                var newMethods = [];

                for (var i in methods)
                {
                    if (methods[i].fingerprint != fingerprint)
                    {
                        newMethods.push(methods[i]);
                    }
                }

                return newMethods;
            },

            deletePaymentMethod: function(paymentMethod)
            {
                var sure = confirm($t("Are you sure you want to delete this payment method?"));

                if (!sure)
                    return;

                var self = this;
                this.processingSavedPaymentMethods(true);
                deletePaymentMethodAction(paymentMethod.id, paymentMethod.fingerprint, function(response, status, xhr)
                {
                    self.processingSavedPaymentMethods(false);
                    if (status == "success")
                    {
                        try
                        {
                            var data = JSON.parse(response);
                            self.showSuccessMessage(data);

                            var newMethods = self.removePaymentMethod(paymentMethod.fingerprint);
                            self.savedPaymentMethods(newMethods);
                        }
                        catch (e)
                        {
                            self.showError($t("The payment methods could not be deleted: %1").replace("%1", e.message));
                        }
                    }
                    else
                    {
                        self.showError($t("The payment methods could not be deleted: %1").replace("%1", response));
                    }
                });
            },

            listPaymentMethods: function()
            {
                var self = this;
                this.processingSavedPaymentMethods(true);

                listPaymentMethodsAction(function(response, status, xhr)
                {
                    self.processingSavedPaymentMethods(false);
                    if (status == "success")
                    {
                        try
                        {
                            var data = JSON.parse(response);
                            var methods = [];

                            for (var fingerprint in data)
                            {
                                methods.push(data[fingerprint]);
                            }

                            self.savedPaymentMethods(methods);
                        }
                        catch (e)
                        {
                            console.warn(e);
                            console.warn(response);
                        }
                    }
                });
            }

        });
    }
);
