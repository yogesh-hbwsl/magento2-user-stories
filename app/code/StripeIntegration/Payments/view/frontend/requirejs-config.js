/*jshint browser:true jquery:true*/
/*global alert*/
var config = {
    map: {
        '*': {
            'stripejs': 'https://js.stripe.com/v3/',
            'stripe_payments': 'StripeIntegration_Payments/js/stripe_payments',
            'stripe_payments_express': 'StripeIntegration_Payments/js/stripe_payments_express'
        }
    },
    config: {
        mixins: {
            'Magento_Ui/js/view/messages': {
                'StripeIntegration_Payments/js/mixins/messages-mixin': true
            },
            'Magento_Checkout/js/view/payment/list': {
                'StripeIntegration_Payments/js/mixins/checkout/payment/list': true
            },
            'MSP_ReCaptcha/js/ui-messages-mixin': {
                'StripeIntegration_Payments/js/mixins/messages-mixin': true
            }
        }
    }
};
