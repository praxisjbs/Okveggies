<?php
/** Shared support chooser and contact form for every customer-facing route. */

if (!function_exists('okv_contact_prefill')) {
    function okv_contact_prefill(): array
    {
        $customer = Customer::isLoggedIn() ? Customer::current() : null;
        return [
            'name' => $customer ? trim((string) ($customer['first_name'] ?? '') . ' ' . (string) ($customer['last_name'] ?? '')) : '',
            'email' => $customer ? (string) ($customer['email'] ?? '') : '',
            'phone' => $customer ? Phone::display((string) ($customer['phone'] ?? '')) : '',
        ];
    }
}

if (!function_exists('okv_contact_form')) {
    function okv_contact_form(string $context = 'contact_page'): void
    {
        $prefill = okv_contact_prefill();
        $token = ContactMessages::newSubmissionToken();
        $prefix = $context === 'support_widget' ? 'support' : 'contact';
        ?>
        <form action="/api/v1/contact.php" method="post" class="space-y-4" data-contact-form data-contact-context="<?= okv_e($context) ?>">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="submit">
          <input type="hidden" name="source" value="<?= okv_e($context) ?>">
          <input type="hidden" name="submission_token" value="<?= okv_e($token) ?>">
          <div class="sr-only" aria-hidden="true">
            <label for="<?= $prefix ?>-website">Leave this empty</label>
            <input id="<?= $prefix ?>-website" name="website" type="text" tabindex="-1" autocomplete="off">
          </div>
          <div class="okv-note bg-clay-tint text-sm" role="alert" aria-live="assertive" tabindex="-1" data-contact-error hidden></div>
          <div>
            <label class="okv-label" for="<?= $prefix ?>-name">Name</label>
            <input class="okv-input" id="<?= $prefix ?>-name" name="name" autocomplete="name" maxlength="150" value="<?= okv_e($prefill['name']) ?>" required>
          </div>
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="okv-label" for="<?= $prefix ?>-email">Email address</label>
              <input class="okv-input" id="<?= $prefix ?>-email" name="email" type="email" autocomplete="email" maxlength="254" value="<?= okv_e($prefill['email']) ?>" aria-describedby="<?= $prefix ?>-contact-help">
            </div>
            <div>
              <label class="okv-label" for="<?= $prefix ?>-phone">Phone number</label>
              <input class="okv-input" id="<?= $prefix ?>-phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" maxlength="30" value="<?= okv_e($prefill['phone']) ?>" aria-describedby="<?= $prefix ?>-contact-help">
            </div>
          </div>
          <p id="<?= $prefix ?>-contact-help" class="text-xs text-ink-60">Enter at least 1 way for us to reply.</p>
          <div>
            <label class="okv-label" for="<?= $prefix ?>-subject">Subject <span class="font-normal text-ink-60">(optional)</span></label>
            <input class="okv-input" id="<?= $prefix ?>-subject" name="subject" maxlength="200">
          </div>
          <div>
            <label class="okv-label" for="<?= $prefix ?>-message">How can we help?</label>
            <textarea class="okv-input min-h-32 py-3" id="<?= $prefix ?>-message" name="message" maxlength="5000" required></textarea>
          </div>
          <button class="okv-btn w-full justify-center" type="submit" data-contact-submit>Send message</button>
        </form>
        <section class="rounded-md bg-foliage-tint p-5 text-center" role="status" aria-live="polite" tabindex="-1" data-contact-success hidden>
          <h3 class="font-editorial text-okv-h6 text-ink">We have your message</h3>
          <p class="mt-2 text-sm text-ink-60">Thank you. A member of our team will reply using the details you provided.</p>
          <?php if ($context === 'support_widget'): ?>
            <button type="button" class="okv-btn-outline mt-4" data-support-close>Done</button>
          <?php else: ?>
            <a href="/shop.php" class="okv-btn-outline mt-4">Back to the shop</a>
          <?php endif; ?>
        </section>
        <?php
    }
}

if (!function_exists('okv_support_whatsapp_url')) {
    function okv_support_whatsapp_url(): string
    {
        $configured = Settings::str('support_whatsapp_number', '2348000000000');
        $normalised = Phone::normalize($configured);
        $number = $normalised !== null
            ? substr($normalised, 1)
            : (preg_replace('/\D+/', '', $configured) ?: '2348000000000');
        $message = 'Hello OK Veggies, I need help with an order or produce question.';
        return 'https://wa.me/' . rawurlencode($number) . '?text=' . rawurlencode($message);
    }
}

if (!function_exists('okv_support_widget')) {
    function okv_support_widget(): void
    {
        ?>
        <div class="fixed bottom-20 right-4 z-40 md:bottom-6 md:right-6" data-support-widget>
          <a href="/contact.php"
             class="okv-btn h-14 w-14 rounded-full bg-foliage p-0 shadow-okv-3 hover:bg-foliage-hover"
             aria-label="Open support options" aria-haspopup="dialog" aria-controls="okv-support-dialog"
             data-support-trigger>
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4Z"/><path d="M8 9h8M8 13h5"/></svg>
          </a>

          <div class="fixed inset-0 z-50 bg-ink/50 md:absolute md:inset-auto md:bottom-16 md:right-0 md:w-96 md:bg-transparent" id="okv-support-dialog" data-support-dialog hidden>
            <section class="absolute inset-x-0 bottom-0 max-h-screen overflow-y-auto rounded-t-xl bg-white p-5 shadow-okv-3 md:relative md:inset-auto md:rounded-xl md:border md:border-mist"
                     role="dialog" aria-modal="true" aria-labelledby="okv-support-heading" tabindex="-1" data-support-panel>
              <div class="flex items-start justify-between gap-4">
                <div>
                  <p class="okv-eyebrow">Support</p>
                  <h2 id="okv-support-heading" class="mt-1 font-editorial text-okv-h6 text-ink">How can we help?</h2>
                </div>
                <button type="button" class="okv-btn-text min-h-[44px]" aria-label="Close support" data-support-close>Close</button>
              </div>

              <div class="mt-5 space-y-3" data-support-choices>
                <a href="<?= okv_e(okv_support_whatsapp_url()) ?>" class="okv-btn w-full justify-center" target="_blank" rel="noopener">Chat on WhatsApp</a>
                <button type="button" class="okv-btn-outline w-full justify-center" data-support-contact>Contact us</button>
                <p class="text-center text-xs text-ink-60">WhatsApp opens in a new tab.</p>
              </div>

              <div class="mt-5" data-support-form hidden>
                <button type="button" class="okv-btn-text mb-3" data-support-back><span aria-hidden="true">&larr;</span> Support options</button>
                <?php okv_contact_form('support_widget'); ?>
              </div>
            </section>
          </div>
        </div>
        <script src="<?= okv_e(okv_asset('/assets/js/support-widget.min.js')) ?>" defer></script>
        <?php
    }
}
