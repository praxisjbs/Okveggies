<?php
/**
 * includes/components/shop/support_widget.php
 * OK Veggies component. The floating support button that sits bottom-right on
 * every storefront page (PRD 4.1). WhatsApp click-to-chat now. The contact form
 * that lands in admin as a contact message arrives with M9.
 */
if (!function_exists('okv_support_widget')) {
    function okv_support_widget(): void
    {
        $number = preg_replace('/\D+/', '', Settings::str('support_whatsapp_number', '2348000000000'));
        $message = rawurlencode('Hello OK Veggies, I have a question.');
        ?>
        <div class="fixed bottom-20 right-4 z-40 md:bottom-6 md:right-6">
          <a href="https://wa.me/<?= okv_e($number) ?>?text=<?= $message ?>"
             class="okv-btn h-14 w-14 rounded-full bg-foliage p-0 shadow-okv-3 hover:bg-foliage-hover"
             target="_blank" rel="noopener"
             aria-label="Chat with us on WhatsApp">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M12 2a10 10 0 0 0-8.6 15l-1.4 5 5.1-1.3A10 10 0 1 0 12 2Zm0 2a8 8 0 1 1-4.1 14.9l-.3-.2-3 .8.8-2.9-.2-.3A8 8 0 0 1 12 4Zm4.5 10.3c-.2-.1-1.4-.7-1.6-.8s-.4-.1-.5.1-.6.8-.8 1-.3.2-.5 0a6.5 6.5 0 0 1-1.9-1.2 7.2 7.2 0 0 1-1.3-1.7c-.1-.2 0-.4.1-.5l.4-.4.2-.4v-.4l-.8-1.8c-.2-.5-.4-.4-.5-.4h-.5a1 1 0 0 0-.7.3A2.8 2.8 0 0 0 6.6 10a5 5 0 0 0 1 2.6 11 11 0 0 0 4.2 3.7c.6.2 1 .4 1.4.5.6.2 1.1.1 1.5.1.5-.1 1.4-.6 1.6-1.1.2-.6.2-1 .1-1.1l-.4-.4Z"/></svg>
          </a>
        </div>
        <?php
    }
}
