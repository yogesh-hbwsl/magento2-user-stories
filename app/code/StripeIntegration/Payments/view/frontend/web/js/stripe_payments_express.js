/*global define*/
define(
    [
        'jquery',
        'mage/url',
        'mage/storage',
        'Magento_Ui/js/modal/alert',
        'mage/translate',
        'Magento_Customer/js/customer-data',
        'StripeIntegration_Payments/js/stripe'
    ],
    function (
        jQuery,
        urlBuilder,
        storage,
        alert,
        $t,
        customerData,
        stripe
    ) {
        'use strict';

        return {
            shippingAddress: [],
            shippingMethod: null,
            onPaymentSupportedCallbacks: [],
            PRAPIEvent: null,
            paymentRequest: null,

            getApplePayParams: function(type, callback)
            {
                var serviceUrl = urlBuilder.build('/rest/V1/stripe/payments/get_prapi_params', {}),
                    payload = {type: type},
                    self = this;

                return storage.post(
                    serviceUrl,
                    JSON.stringify(payload),
                    false
                )
                .fail(function (xhr, textStatus, errorThrown)
                {
                    console.error("Could not retrieve initialization params for Apple Pay");
                })
                .done(function (response)
                {
                    if (typeof response === 'string') {
                        response = JSON.parse(response);
                    }

                    callback(response);
                });
            },

            /**
             * Init Stripe Express
             * @param elementId
             * @param apiKey
             * @param paramsType
             * @param settings
             * @param callback
             */
            initStripeExpress: function (elementId, stripeParams, paramsType, settings, callback)
            {
                var self = this;

                this.getApplePayParams(paramsType, function(params)
                {
                    if (!params || params.length == 0)
                        return;

                    if (params.total.amount == 0 && params.displayItems.length == 0)
                        return;

                    stripe.initStripe(stripeParams, function (err)
                    {
                        if (err)
                        {
                            self.showError(self.maskError(err));
                            return;
                        }
                        self.initPaymentRequestButton(elementId, stripeParams.locale, params, settings, callback);
                    });
                });
            },

            maskError: function(err)
            {
                var errLowercase = err.toLowerCase();
                var pos1 = errLowercase.indexOf("Invalid API key provided".toLowerCase());
                var pos2 = errLowercase.indexOf("No API key provided".toLowerCase());
                if (pos1 === 0 || pos2 === 0)
                    return 'Invalid Stripe API key provided.';

                return err;
            },

            initPaymentRequestButton: function(elementId, locale, params, settings, callback)
            {
                // Init Payment Request
                var paymentRequest,
                    paymentRequestButton = jQuery(elementId),
                    self = this,
                    prButton = null;

                try {
                    if (typeof settings === 'string')
                        settings = JSON.parse(settings);

                    if (!params.country)
                        throw { message: 'Cannot display Wallet Button because there is no Country Code. You can set a default one from Magento Admin > Stores > Configuration > General > Country Options > Default Country.'};

                    this.paymentRequest = paymentRequest = stripe.stripeJs.paymentRequest(params);
                    var elements = stripe.stripeJs.elements({
                        locale: locale
                    });
                    prButton = elements.create('paymentRequestButton', {
                        paymentRequest: paymentRequest,
                        style: {
                            paymentRequestButton: settings
                        }
                    });
                }
                catch (e)
                {
                    console.warn(e.message);
                    return;
                }

                paymentRequest.canMakePayment().then(function(result)
                {
                    stripe.canMakePaymentResult = result;
                    if (result)
                    {
                        // The mini cart may be empty
                        if (document.getElementById(elementId.substr(1)))
                        {
                            prButton.mount(elementId);

                            for (var i = 0; i < self.onPaymentSupportedCallbacks.length; i++)
                                self.onPaymentSupportedCallbacks[i]();
                        }
                    }
                    else {
                        paymentRequestButton.hide();
                    }
                });

                prButton.on('ready', function () {
                    callback(paymentRequestButton, paymentRequest, params, prButton);
                });
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

            /**
             * Place Order
             * @param result
             * @param callback
             */
            placeOrder: function (result, location, callback) {
                var serviceUrl = urlBuilder.build('/rest/V1/stripe/payments/place_order', {}),
                    payload = {
                        result: result,
                        location: location
                    },
                    self = this;

                return storage.post(
                    serviceUrl,
                    JSON.stringify(payload),
                    false
                ).fail(function (xhr, textStatus, errorThrown)
                {
                    try
                    {
                        var response = JSON.parse(xhr.responseText);

                        var clientSecret = self.getClientSecretFromResponse(response.message);

                        if (clientSecret)
                        {
                            self.closePaysheet("success");

                            return stripe.authenticateCustomer(clientSecret, function(err)
                            {
                                if (err)
                                    return callback(err, { message: err }, result);

                                self.placeOrder(result, location, callback);
                            });
                        }
                        else
                            callback(response.message, response, result);
                    }
                    catch (e)
                    {
                        return self.showError(xhr.responseText);
                    }
                }).done(function (response) { // @todo - this should be success, we dont want to callback() on failure
                    if (typeof response === 'string')
                    {
                        try
                        {
                            response = JSON.parse(response);
                        }
                        catch (e)
                        {
                            return self.showError(response);
                        }
                    }

                    callback(null, response, result);
                });
            },

            /**
             * Add Item to Cart
             * @param request
             * @param shipping_id
             * @param callback
             */
            addToCart: function(request, shipping_id, callback)
            {
                var serviceUrl = urlBuilder.build('/rest/V1/stripe/payments/addtocart', {}),
                    payload = {request: request, shipping_id: shipping_id},
                    self = this;

                return storage.post(
                    serviceUrl,
                    JSON.stringify(payload),
                    false
                ).fail(function (xhr, textStatus, errorThrown)
                {
                    self.parseFailedResponse.apply(self, [ xhr.responseText, callback ]);
                }
                ).done(function (response) {
                    customerData.invalidate(['cart']);
                    customerData.reload(['cart'], true);
                    self.processResponseWithPaymentIntent(response, callback);
                });
            },

            /**
             * Get Cart Contents
             * @param callback
             * @returns {*}
             */
            getCart: function(callback) {
                var serviceUrl = urlBuilder.build('/rest/V1/stripe/payments/get_cart', {}),
                    self = this;

                return storage.get(
                    serviceUrl,
                    null,
                    false
                ).fail(function (xhr, textStatus, errorThrown)
                {
                    self.parseFailedResponse.apply(self, [ xhr.responseText, callback ]);
                }
                ).done(function (response) {
                    if (typeof response === 'string') {
                        response = JSON.parse(response);
                    }

                    callback(null, response);
                });
            },

            getShippingAddressFrom: function(prapiShippingAddress)
            {
                if (!prapiShippingAddress)
                    return null;

                // For some countries like Japan, the PRAPI does not set the City, only the region
                if (prapiShippingAddress.city.length == 0 && prapiShippingAddress.region.length > 0)
                    prapiShippingAddress.city = prapiShippingAddress.region;

                return prapiShippingAddress;
            },

            /**
             * Estimate Shipping for Cart
             * @param address
             * @param callback
             * @returns {*}
             */
            estimateShippingCart: function(address, callback) {
                var serviceUrl = urlBuilder.build('/rest/V1/stripe/payments/estimate_cart', {}),
                    payload = {address: address},
                    self = this;

                return storage.post(
                    serviceUrl,
                    JSON.stringify(payload),
                    false
                ).fail(function (xhr, textStatus, errorThrown)
                {
                    self.parseFailedResponse.apply(self, [ xhr.responseText, callback ]);
                }
                ).done(function (response) {
                    self.processResponseWithPaymentIntent(response, callback);
                });
            },

            parseFailedResponse: function(responseText, callback)
            {
                try
                {
                    var response = JSON.parse(responseText);
                    callback(response.message);
                }
                catch (e)
                {
                    callback(responseText);
                }
            },

            /**
             * Apply Shipping and Return Totals
             * @param address
             * @param shipping_id
             * @param callback
             * @returns {*}
             */
            applyShipping: function(address, shipping_id, callback) {
                var serviceUrl = urlBuilder.build('/rest/V1/stripe/payments/apply_shipping', {}),
                    payload = {address: address, shipping_id: shipping_id},
                    self = this;

                return storage.post(
                    serviceUrl,
                    JSON.stringify(payload),
                    false
                ).fail(function (xhr, textStatus, errorThrown)
                {
                    self.parseFailedResponse.apply(self, [ xhr.responseText, callback ]);
                }
                ).done(function (response) {
                    self.processResponseWithPaymentIntent(response, callback);
                });
            },

            processResponseWithPaymentIntent: function(response, callback)
            {
                try
                {
                    if (typeof response === 'string') {
                        response = JSON.parse(response);
                    }

                    callback(null, response.results);
                }
                catch (e)
                {
                    callback(e.message, response);
                }
            },

            onShippingAddressChange: function(ev)
            {
                var self = this;

                this.shippingAddress = this.getShippingAddressFrom(ev.shippingAddress);
                this.estimateShippingCart(this.shippingAddress, function (err, shippingOptions)
                {
                    if (err)
                        return self.showError(err);

                    if (shippingOptions.length < 1) {
                        ev.updateWith({status: 'invalid_shipping_address'});
                        return;
                    }

                    self.shippingMethod = null;
                    if (shippingOptions.length > 0) {
                        // Apply first shipping method
                        var shippingOption = shippingOptions[0];
                        self.shippingMethod = shippingOption.hasOwnProperty('id') ? shippingOption.id : null;
                    }

                    self.applyShipping(self.shippingAddress, self.shippingMethod, function (err, response)
                    {
                        if (err)
                            return self.showError(err);

                        // Update order lines
                        var result = Object.assign({status: 'success', shippingOptions: shippingOptions}, response);
                        ev.updateWith(result);
                    });
                });
            },

            onShippingOptionChange: function(ev)
            {
                var shippingMethod = ev.shippingOption.hasOwnProperty('id') ? ev.shippingOption.id : null;
                this.applyShipping(this.shippingAddress, shippingMethod, function (err, response)
                {
                    if (err) {
                        ev.updateWith({status: 'fail'});
                        return;
                    }

                    // Update order lines
                    var result = Object.assign({status: 'success'}, response);
                    ev.updateWith(result);
                });
            },

            onPaymentMethod: function(paymentRequestButton, location, result)
            {
                this.onPaymentRequestPaymentMethod.call(this, result, paymentRequestButton, location);
            },

            initCheckoutWidget: function (paymentRequestButton, paymentRequest, prButton, onClick)
            {
                prButton.on('click', onClick);
                paymentRequest.on('shippingaddresschange', this.onShippingAddressChange.bind(this));
                paymentRequest.on('shippingoptionchange', this.onShippingOptionChange.bind(this));
                paymentRequest.on('paymentmethod', this.onPaymentMethod.bind(this, paymentRequestButton, 'checkout'));
            },

            /**
             * Init Widget for Cart Page
             * @param paymentRequestButton
             * @param paymentRequest
             * @param params
             * @param prButton
             */
            initCartWidget: function (paymentRequestButton, paymentRequest, params, prButton)
            {
                paymentRequest.on('shippingaddresschange', this.onShippingAddressChange.bind(this));
                paymentRequest.on('shippingoptionchange', this.onShippingOptionChange.bind(this));
                paymentRequest.on('paymentmethod', this.onPaymentMethod.bind(this, paymentRequestButton, 'cart'));
            },

            /**
             * Init Widget for Mini cart
             * @param paymentRequestButton
             * @param paymentRequest
             * @param params
             * @param prButton
             */
            initMiniCartWidget: function (paymentRequestButton, paymentRequest, params, prButton)
            {
                var self = this;

                prButton.on('click', function(ev) {
                    // ev.preventDefault();

                    paymentRequestButton.addClass('disabled');
                    self.getCart(function (err, result)
                    {
                        paymentRequestButton.removeClass('disabled');
                        if (err)
                            return self.showError(err);

                        // ev.updateWith(result);
                    });
                });

                paymentRequest.on('shippingaddresschange', this.onShippingAddressChange.bind(this));
                paymentRequest.on('shippingoptionchange', this.onShippingOptionChange.bind(this));
                paymentRequest.on('paymentmethod', this.onPaymentMethod.bind(this, paymentRequestButton, 'minicart'));
            },

            /**
             * Init Widget for Single Product Page
             * @param paymentRequestButton
             * @param paymentRequest
             * @param params
             * @param prButton
             */
            initProductWidget: function (paymentRequestButton, paymentRequest, params, prButton) {
                var self = this,
                    form = jQuery('#product_addtocart_form'),
                    request = [];

                prButton.on('click', function(ev)
                {
                    var validator = form.validation({radioCheckboxClosest: '.nested'});

                    if (!validator.valid())
                    {
                        ev.preventDefault();
                        return;
                    }

                    // We don't want to preventDefault for applePay because we cannot use
                    // paymentRequest.show() with applePay. Expecting Stripe to fix this.
                    if (!stripe.canMakePaymentResult.applePay)
                        ev.preventDefault();

                    // Add to Cart
                    request = form.serialize();
                    paymentRequestButton.addClass('disabled');
                    self.addToCart(request, self.shippingMethod, function (err, result) {
                        paymentRequestButton.removeClass('disabled');
                        if (err)
                            return self.showError(err);

                        try
                        {
                            paymentRequest.update(result);
                            paymentRequest.show();
                        }
                        catch (e)
                        {
                            console.warn(e.message);
                        }
                    });
                });

                paymentRequest.on('shippingaddresschange', this.onShippingAddressChange.bind(this));
                paymentRequest.on('shippingoptionchange', this.onShippingOptionChange.bind(this));
                paymentRequest.on('paymentmethod', this.onPaymentMethod.bind(this, paymentRequestButton, 'product'));
            },

            onPaymentRequestPaymentMethod: function(result, paymentRequestButton, location)
            {
                this.PRAPIEvent = result;
                var success = this.onPaymentPlaced.bind(this, result, paymentRequestButton, location);
                var error = this.showError.bind(this);

                return success();
            },

            closePaysheet: function(withResult)
            {
                try
                {
                    if (this.PRAPIEvent)
                        this.PRAPIEvent.complete(withResult);
                    else if (this.paymentRequest)
                        this.paymentRequest.abort();
                }
                catch (e)
                {
                    // Will get here if we already closed it
                }
            },

            showError: function(message)
            {
                this.closePaysheet('success'); // Simply hide the modal

                alert({
                    title: $t('Error'),
                    content: message,
                    actions: {
                        always: function (){}
                    }
                });
            },

            onPaymentPlaced: function(result, paymentRequestButton, location)
            {
                var self = this;
                paymentRequestButton.addClass('disabled');
                result.shippingAddress = this.getShippingAddressFrom(result.shippingAddress);
                this.placeOrder(result, location, function (err, response, result)
                {
                    if (err)
                    {
                        paymentRequestButton.removeClass('disabled');
                        self.showError(response.message);
                    }
                    else if (response.hasOwnProperty('redirect'))
                    {
                        customerData.invalidate(['cart']);
                        window.location = response.redirect;
                    }
                });
            },

            bindConfigurableProductOptions: function(elementId, stripeParams, productId, buttonConfig)
            {
                var self = this;
                var options = jQuery("#product-options-wrapper .configurable select.super-attribute-select");
                var params = {
                    elementId: elementId,
                    stripeParams: stripeParams,
                    buttonConfig: buttonConfig,
                    productId: productId
                };

                options.each(function(index)
                {
                    var onConfigurableProductChanged = self.onConfigurableProductChanged.bind(self, this, params);
                    jQuery(this).change(onConfigurableProductChanged);
                });
            },

            onConfigurableProductChanged: function(element, params)
            {
                var self = this;

                if (element.value)
                {
                    var apiParams = 'product:' + params.productId + ':' + element.value;
                    this.initStripeExpress(params.elementId, params.stripeParams, apiParams, params.buttonConfig,
                        function(paymentRequestButton, paymentRequest, params, prButton) {
                            self.initProductWidget(paymentRequestButton, paymentRequest, params, prButton);
                        }
                    );
                }
            }
        };
    }
);
