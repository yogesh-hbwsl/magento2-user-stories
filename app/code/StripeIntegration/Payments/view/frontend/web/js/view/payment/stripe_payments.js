define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list',
        'Magento_Checkout/js/model/payment/method-group',
        'StripeIntegration_Payments/js/helper/subscriptions'
    ],
    function (
        Component,
        rendererList,
        methodGroup,
        subscriptions
    ) {
        'use strict';

        var subscriptionUpdateComponent = null;
        if (subscriptions.isSubscriptionUpdate())
        {
            subscriptionUpdateComponent = 'StripeIntegration_Payments/js/view/subscription_update/review';
        }

        rendererList.push(
            {
                type: 'stripe_payments',
                component: subscriptionUpdateComponent || 'StripeIntegration_Payments/js/view/payment/method-renderer/stripe_payments'
            },
            {
                type: 'stripe_payments_checkout',
                component: subscriptionUpdateComponent || 'StripeIntegration_Payments/js/view/payment/method-renderer/checkout'
            },
            {
                type: 'stripe_payments_bank_transfers',
                component: subscriptionUpdateComponent || 'StripeIntegration_Payments/js/view/payment/method-renderer/bank_transfers'
            }
        );

        // Add view logic here if needed
        return Component.extend({});
    }
);
