<?php
/**
 * includes/components/shop/activation_banner.php
 * OK Veggies component. A calm sticky bar that shows for a signed-in customer
 * whose email is not verified yet, with one clear next step. It disappears the
 * moment the account is activated. Include it once near the top of a page body
 * and call okv_activation_banner(). Built in M1 Part 2.
 */

if (!function_exists('okv_activation_banner')) {
    function okv_activation_banner(): void
    {
        if (!Customer::isLoggedIn() || Customer::isActivated()) {
            return;
        }
        ?>
        <div class="sticky top-0 z-40 bg-forest text-white" role="region" aria-label="Activate your account">
          <div class="okv-container flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-2.5">
            <p class="text-sm">
              Activate your account so you can pay on delivery. We sent a 6 digit code to your email.
            </p>
            <a href="/public/auth/activate.php"
               class="inline-flex items-center min-h-[36px] px-4 rounded-md bg-white text-forest text-sm font-semibold
                      transition duration-botanical ease-botanical hover:bg-forest-tint">
              Activate now
            </a>
          </div>
        </div>
        <?php
    }
}
