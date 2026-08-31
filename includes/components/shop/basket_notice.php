<?php
/**
 * includes/components/shop/basket_notice.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The one sentence a customer sees after a basket action that ran
 * without JavaScript.
 *
 * api/v1/cart.php answers a plain form post with a 303 back to the page the
 * customer was on, carrying a short code on "?basket=". Any page that offers
 * an add, an update or a removal calls okv_basket_notice() so the customer is
 * told what happened, instead of the page quietly reloading.
 *
 * The codes are the ones cart_notice_for() and the success paths emit.
 * -----------------------------------------------------------------------------
 */
if (!defined('OKV_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Direct access is not allowed.');
}

/**
 * The tone and the sentence for a notice code, or null when the code is not
 * one of ours (a customer editing the URL should get nothing, not an error).
 *
 * @return array{0:string,1:string}|null
 */
function okv_basket_notice_message(string $code): ?array
{
    $messages = [
        'added'       => ['ok',   'Added to your basket.'],
        'updated'     => ['ok',   'Basket updated.'],
        'removed'     => ['ok',   'Item removed from your basket.'],
        'rounded'     => ['ok',   'We rounded your quantity up to the nearest step we can pack.'],
        'repriced'    => ['warn', Basket::REPRICED_NOTICE],
        'minimum'     => ['warn', 'That is below the smallest quantity we can pack for that item. The minimum is shown on the line.'],
        'ceiling'     => ['warn', Basket::quantityMessage('over_ceiling')],
        'invalid'     => ['warn', Basket::quantityMessage('invalid_quantity')],
        'missing'     => ['warn', 'That item is no longer in your basket.'],
        'unavailable' => ['warn', 'That item is not available this week.'],
        'expired'     => ['warn', 'Your session expired. Please try that again.'],
        'error'       => ['warn', 'We could not update your basket. Please try again.'],
    ];

    return $messages[$code] ?? null;
}

/**
 * Render the notice for the code on the current URL, if there is one. Pass a
 * code to render a specific one. Safe to call on every shop page.
 */
function okv_basket_notice(?string $code = null, string $class = 'mt-6'): void
{
    $code    = $code ?? (string) okv_input('basket', '');
    $message = okv_basket_notice_message($code);
    if ($message === null) {
        return;
    }
    [$tone, $text] = $message;
    $palette = $tone === 'ok'
        ? 'border-foliage bg-foliage-tint text-forest'
        : 'border-gold bg-gold-tint text-gold-ink';
    ?>
    <p class="<?= okv_e($class) ?> rounded-md border px-4 py-3 text-sm <?= $palette ?>" role="status" data-basket-notice><?= okv_e($text) ?></p>
    <?php
}
