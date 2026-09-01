<?php
/**
 * includes/components/shop/brand.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The two brand marks the storefront repeats: the seal, and the
 * sourcing trust line. Both live here so a change lands everywhere at once.
 *
 * The seal is the photographic mark from docs/brand/logo/ok-veggies-seal.jpg,
 * generated into assets/img/brand/ with a transparent surround so it sits on
 * white, forest or butter cream without a box around it. CLAUDE.md reserves it
 * for places it can read at 120px or more: the hero, the footer, the auth
 * screens and print. Tight headers use the horizontal lockup instead, which is
 * why there is no small-size branch here.
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
        // 120px is the floor CLAUDE.md sets for the photographic seal. Below
        // that the ring's lettering stops reading and the lockup is the mark
        // to use, so a smaller request is lifted rather than honoured.
        $size = max(120, $size);
        // Serve roughly twice the rendered size so the stamp stays crisp on a
        // phone's 2x screen without pulling the 640px file for a small mark.
        $source = $size <= 160 ? 'seal-320.png' : 'seal-640.png';
        $decorative = trim($alt) === '';
        ?>
        <img src="<?= okv_e(okv_asset('/assets/img/brand/' . $source)) ?>"
             alt="<?= okv_e($alt) ?>"
             width="<?= $size ?>" height="<?= $size ?>"
             class="<?= okv_e($class) ?>"
             <?= $decorative ? 'aria-hidden="true"' : '' ?>>
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
