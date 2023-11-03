define([], function() {
    'use strict';
    return function(target) {
        return target.extend({
            isVisible: function () {
                var msgs = this.messageContainer.errorMessages();
                for (var i = 0; i < msgs.length; i++)
                {
                    if (typeof msgs[i] == 'string' && msgs[i].indexOf("Authentication Required: ") >= 0)
                        return false;
                }
                return this.isHidden(this.messageContainer.hasMessages());
            }
        });
    };
});
