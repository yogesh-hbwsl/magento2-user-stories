define(
    [
        'ko',
        'Magento_Checkout/js/model/quote',
        'StripeIntegration_Payments/js/action/get-upcoming-invoice',
    ],
    function (
        ko,
        quote,
        getUpcomingInvoiceAction
    ) {
        'use strict';

        return {
            upcomingInvoiceRequest: null,
            initialized: false,
            currentTotals: null,
            callbacks: [],

            initialize: function()
            {
                if (this.initialized)
                    return;

                this.initialized = true;

                this.watchTotals();
                getUpcomingInvoiceAction(this.upcomingInvoiceChanged.bind(this));
            },

            watchTotals: function()
            {
                this.currentTotals = quote.totals();
                var upcomingInvoiceChanged = this.upcomingInvoiceChanged.bind(this);
                var self = this;

                quote.totals.subscribe(function (totals)
                {
                    if (JSON.stringify(totals.total_segments) == JSON.stringify(self.currentTotals.total_segments))
                        return;

                    self.currentTotals = totals;

                    getUpcomingInvoiceAction(upcomingInvoiceChanged);
                }, self);
            },

            upcomingInvoiceChanged: function(result, outcome, response)
            {
                this.upcomingInvoiceRequest = {
                    result: result,
                    outcome: outcome,
                    response: response
                };

                for (var i = 0; i < this.callbacks.length; i++)
                {
                    this.callbacks[i](result, outcome, response);
                }
            },

            onChange: function(callback)
            {
                this.callbacks.push(callback);

                if (this.upcominInvoiceRequest)
                {
                    callback(this.upcominInvoiceRequest.result, this.upcominInvoiceRequest.outcome, this.upcominInvoiceRequest.response);
                }
            }
        };
    }
);
