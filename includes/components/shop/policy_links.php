<?php
/**
 * includes/components/shop/policy_links.php
 * -----------------------------------------------------------------------------
 * OK Veggies. One helper for the link into the Delivery Policy, used by
 * checkout where the fee and cancellation rules are summarised.
 *
 * It mirrors okv_make_it_right_policy_url(): prefer the published legal page,
 * fall back to the public How It Works page, and never expose a draft. One
 * place, so checkout and the info sheets can never disagree about where the
 * policy lives.
 * -----------------------------------------------------------------------------
 */

if (!function_exists('okv_delivery_policy_url')) {
    /**
     * The Delivery Policy when it is published, otherwise How It Works.
     */
    function okv_delivery_policy_url(): string
    {
        try {
            if (ContentPages::findPublished('delivery-policy') !== null) {
                return '/delivery-policy';
            }
        } catch (Throwable $e) {
            error_log('content.delivery_policy_link failed: ' . $e->getMessage());
        }
        return '/how-it-works';
    }
}
