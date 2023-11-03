define([
    'StripeIntegration_Payments/js/helper/subscriptions',
    'mage/translate',
],
function(
    subscriptions,
    $t
) {
    'use strict';
    return function(target) {
        return target.extend({
            /**
             * Returns payment group title
             *
             * @param {Object} group
             * @returns {String}
             */
            getGroupTitle: function (group)
            {
                if (subscriptions.isSubscriptionUpdate())
                    return $t("Subscription Update Review");

                var title = group().title;

                if (group().isDefault() && this.paymentGroupsList().length > 1) {
                    title = this.defaultGroupTitle;
                }

                return title;
            },
        });
    };
});
