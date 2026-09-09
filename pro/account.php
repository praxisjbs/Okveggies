<?php
/**
 * pro/account.php
 * OK Veggies. Your business profile.
 * Shows only business and customer details already supported by the account
 * model. Branch management is not specified, so this screen does not invent it.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_business_customer();

$customer = Customer::current();
$business = Database::one(
    'SELECT business_name, business_type FROM business_customers WHERE user_id = :user_id',
    [':user_id' => (int) Customer::id()]
);

$okv_pro_title  = 'Account and Branches';
$okv_pro_note   = 'Your business details and the route to manage delivery addresses.';
$okv_pro_active = '/pro/account.php';
require __DIR__ . '/../includes/components/pro/header.php';

?>
<div class="grid gap-6 lg:grid-cols-2">
  <section class="okv-panel" aria-labelledby="business-details-heading">
    <div class="okv-panel-head">
      <div>
        <p class="okv-eyebrow">Business account</p>
        <h2 id="business-details-heading" class="okv-panel-title mt-1">Business details</h2>
      </div>
    </div>
    <div class="okv-panel-body">
      <dl class="space-y-3 text-sm">
        <div><dt class="text-ink-60">Business name</dt><dd class="mt-1 font-medium"><?= okv_e((string) ($business['business_name'] ?? 'Not added')) ?></dd></div>
        <div><dt class="text-ink-60">Business type</dt><dd class="mt-1"><?= okv_e((string) ($business['business_type'] ?? 'Not added')) ?></dd></div>
        <div><dt class="text-ink-60">Account contact</dt><dd class="mt-1"><?= okv_e(trim((string) ($customer['first_name'] ?? '') . ' ' . (string) ($customer['last_name'] ?? ''))) ?></dd></div>
        <div><dt class="text-ink-60">Email</dt><dd class="mt-1 break-words"><?= okv_e((string) ($customer['email'] ?? '')) ?></dd></div>
        <div><dt class="text-ink-60">Phone</dt><dd class="mt-1"><?= okv_e(Phone::display((string) ($customer['phone'] ?? ''))) ?></dd></div>
      </dl>
      <a href="/account.php" class="okv-btn mt-6 px-5">Manage account details</a>
    </div>
  </section>

  <section class="okv-panel" aria-labelledby="branches-heading">
    <div class="okv-panel-head">
      <div>
        <p class="okv-eyebrow">Delivery places</p>
        <h2 id="branches-heading" class="okv-panel-title mt-1">Branches</h2>
      </div>
    </div>
    <div class="okv-panel-body">
      <p class="text-sm text-ink">Branch management is not available on this screen.</p>
      <p class="mt-3 text-sm text-ink-60">Use the saved delivery addresses in Your account for the places we already deliver to.</p>
      <a href="/account.php" class="okv-btn-outline mt-6 px-5">Open delivery addresses</a>
    </div>
  </section>
</div>
<?php

require __DIR__ . '/../includes/components/pro/footer.php';
