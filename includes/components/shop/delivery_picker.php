<?php
/** Reusable checkout delivery-day select. */
if (!function_exists('okv_delivery_picker')) {
    function okv_delivery_picker(string $customerType, string $field = 'delivery_date'): void
    {
        $dates = Delivery::nextEligibleDates($customerType);
        ?><label class="okv-label" for="<?= okv_e($field) ?>">Delivery day</label><select id="<?= okv_e($field) ?>" name="<?= okv_e($field) ?>" class="okv-input" required><?php foreach ($dates as $date): ?><option value="<?= okv_e($date['date']) ?>"><?= okv_e(date('l jS F', strtotime($date['date']))) ?></option><?php endforeach; ?></select><p class="mt-2 text-sm text-ink-60">We only show days with enough time to source and pack your order.</p><?php
    }
}
