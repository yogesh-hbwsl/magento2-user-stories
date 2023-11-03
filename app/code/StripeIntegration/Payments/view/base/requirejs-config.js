/*jshint browser:true jquery:true*/
/*global alert*/
var config = {
    map: {
        '*': {
            'stripejs': 'https://js.stripe.com/v3/',
            'stripe_payments': 'StripeIntegration_Payments/js/stripe_payments'
        }
    },
    config: {
        mixins: {
            'Magento_Tax/js/view/checkout/summary/grand-total': {
                'StripeIntegration_Payments/js/mixins/checkout/summary/grand_total': true
            }
        }
    }
};
