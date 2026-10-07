<?php
/**
 * includes/components/shop/brand.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The two brand marks the storefront repeats: the seal, and the
 * sourcing trust line. Both live here so a change lands everywhere at once.
 *
 * The seal is the photographic mark from docs/brand/logo/ok-veggies-seal.jpg,
 * generated into assets/img/brand/ with a transparent surround so it sits on
 * white, forest or butter cream without a box around it. By the Owner's
 * one-logo decision of 7 October 2026 the seal is the only logo, at every
 * size, on every surface: hero, header, footer, admin, Pro, auth, print,
 * favicon and app icons. There are no derived marks and no minimum size; a
 * small ask is served from the nearest generated source so it stays crisp.
 * -----------------------------------------------------------------------------
 */

if (!function_exists('okv_seal')) {
    /**
     * The seal at a given rendered size in pixels, served from the nearest
     * generated source so a 120px stamp does not download the 640px file.
     *
     * $alt carries the mark's meaning. Pass an empty string where the brand
     * name is already written beside it in text, and the image is then hidden
     * from assistive technology rather than read out twice.
     */
    function okv_seal(int $size = 120, string $class = '', string $alt = 'OK Veggies seal'): void
    {
        // The seal is the one logo at every size (Owner's decision of 7
        // October 2026), so a small ask is honoured, only floored at 16px so
        // a stray zero can never request a nothing.
        $size = max(16, $size);
        // Serve roughly twice the rendered size so the seal stays crisp on a
        // phone's 2x screen without pulling the 640px file for a small mark.
        $stem = $size <= 80 ? 'seal-160' : ($size <= 200 ? 'seal-320' : 'seal-640');
        $png = $stem . '.png';
        $webp = $stem . '.webp';
        $decorative = trim($alt) === '';
        ?>
        <picture>
          <source type="image/webp" srcset="<?= okv_e(okv_asset('/assets/img/brand/' . $webp)) ?>">
          <img src="<?= okv_e(okv_asset('/assets/img/brand/' . $png)) ?>"
               alt="<?= okv_e($alt) ?>"
               width="<?= $size ?>" height="<?= $size ?>"
               class="<?= okv_e($class) ?>"
               decoding="async"
               <?= $decorative ? 'aria-hidden="true"' : '' ?>>
        </picture>
        <?php
    }
}

if (!function_exists('okv_sourced_note')) {
    /**
     * The sourcing trust line with its leaf marker: "Sourced Tuesday from Ogun
     * State, Jos" (bible 6.3). The sentence itself comes from okv_sourced_line,
     * so the product card, the product page and the combo cannot word the same
     * promise three ways. The leaf is line-only on the 24px icon grid with a
     * 2px rounded stroke (bible 6.9) and is decorative: the sentence carries
     * the meaning, so the line never depends on the icon or on colour.
     *
     * Renders nothing when the regions setting is blank, rather than promising
     * a farm we cannot name.
     */
    function okv_sourced_note(string $regions, string $day = '', string $class = ''): void
    {
        $line = okv_sourced_line($regions, $day);
        if ($line === '') {
            return;
        }
        ?>
        <p class="okv-trust-line <?= okv_e($class) ?>">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
               class="mt-0.5 flex-none" aria-hidden="true" focusable="false">
            <path d="M4 20c0-8 5.5-13.5 15-14.5C20 14 14.5 20 6 20H4Z"/>
            <path d="M4.5 19.5c2-4.5 5-7.5 9-9.5"/>
          </svg>
          <span><?= okv_e($line) ?></span>
        </p>
        <?php
    }
}
