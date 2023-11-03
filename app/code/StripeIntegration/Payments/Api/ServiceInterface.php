<?php

namespace StripeIntegration\Payments\Api;

interface ServiceInterface
{
    /**
     * Returns the Stripe Checkout redirect URL
     *
     * @api
     * @return string Redirect Url
     */
    public function redirect_url();

    /**
     * Estimate Shipping by Address
     *
     * @api
     * @param mixed $address
     *
     * @return string
     */
    public function estimate_cart($address);

    /**
     * Set billing address from data object
     *
     * @api
     * @param mixed $data
     *
     * @return string
     */
    public function set_billing_address($data);

    /**
     * Apply Shipping Method
     *
     * @api
     * @param mixed $address
     * @param string|null $shipping_id
     *
     * @return string
     */
    public function apply_shipping($address, $shipping_id = null);

    /**
     * Place Order
     *
     * @api
     * @param mixed $result
     * @param mixed $location
     *
     * @return string
     */
    public function place_order($result, $location);

    /**
     * Add to Cart
     *
     * @api
     * @param string $request
     * @param string|null $shipping_id
     *
     * @return string
     */
    public function addtocart($request, $shipping_id = null);

    /**
     * Get Cart Contents
     *
     * @api
     * @return string
     */
    public function get_cart();

    /**
     * Get PR API params to initialize Stripe Express buttons
     *
     * @api
     * @param string $type
     *
     * @return mixed Json object with params
     */
    public function get_prapi_params($type);

    /**
     * Get Trialing Subscription data
     *
     * @api
     * @param mixed $billingAddress
     * @param mixed|null $shippingAddress
     * @param mixed|null $shippingMethod
     * @param string|null $couponCode
     * @return string
     */
    public function get_trialing_subscriptions($billingAddress = null, $shippingAddress = null, $shippingMethod = null, $couponCode = null);

    /**
     * Get Stripe Checkout available payment methods for the ative customer quote
     *
     * @api
     * @param mixed $billingAddress
     * @param mixed|null $shippingAddress
     * @param mixed|null $shippingMethod
     * @param string|null $couponCode
     *
     * @return string
     */
    public function get_checkout_payment_methods($billingAddress, $shippingAddress = null, $shippingMethod = null, $couponCode = null);

    /**
     * Get Stripe Checkout session ID, only if it is still valid/open/non-expired
     *
     * @api
     *
     * @return string
     */
    public function get_checkout_session_id();

    /**
     * Restores the quote of the last placed order
     *
     * @api
     *
     * @return mixed
     */
    public function restore_quote();

    /**
     * After a payment failure, and before placing the order for a 2nd time, we call the update_cart method to check if anything
     * changed between the quote and the previously placed order. If it has, we cancel the old order and place a new one.
     *
     * @api
     * @param int|null $quoteId
     * @param mixed|null $data
     *
     * @return mixed
     */
    public function update_cart($quoteId = null, $data = null);

    /**
     * If the last payment requires further action, this returns the client secret of the object that requires action
     *
     * @api
     *
     * @return mixed|null
     */
    public function get_requires_action();

    /**
     * Places a multishipping order
     *
     * @api
     * @param int|null $quoteId
     *
     * @return mixed|null $result
     */
    public function place_multishipping_order($quoteId = null);

    /**
     * Finalizes a multishipping order after a card is declined or customer authentication fails and redirects the customer to the results or success page
     *
     * @api
     * @param int|null $quoteId
     * @param string|null $error
     *
     * @return mixed|null $result
     */
    public function finalize_multishipping_order($quoteId = null, $error = null);

    /**
     * Returns the params needed to initialize the Payment Element component at the specified site section
     *
     * @api
     * @param string $section
     *
     * @return string|null
     */
    public function get_init_params($section);

    /**
     * For subscription updates, it retrieves totals with prorations for the subscription update
     *
     * @api
     *
     * @return mixed|null
     */
    public function get_upcoming_invoice();

    /**
     * Add a new saved payment method by ID
     *
     * @api
     * @param string $paymentMethodId
     *
     * @return mixed $paymentMethod
     */
    public function add_payment_method($paymentMethodId);

    /**
     * Delete a saved payment method by ID
     *
     * @api
     * @param string $paymentMethodId
     * @param string $fingerprint
     *
     * @return mixed $result
     */
    public function delete_payment_method($paymentMethodId, $fingerprint = null);

    /**
     * List a customer's saved payment methods
     *
     * @api
     * @return mixed $result
     */
    public function list_payment_methods();

    /**
     * Cancels the last order placed by the customer, if it's quote ID matches the currently active quote
     *
     * @api
     * @param string $errorMessage
     *
     * @return mixed $result
     */
    public function cancel_last_order($errorMessage);

    /**
     * Get Module Configuration for Stripe initialization
     * @api
     * @return mixed $result
     */
    public function getModuleConfiguration();
}
