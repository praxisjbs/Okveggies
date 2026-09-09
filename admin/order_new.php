<?php
/**
 * admin/order_new.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Take an order over the phone (PRD Sections 9 and 17.2).
 *
 * Somebody rings and says what they want. This is the screen a colleague fills
 * in while they are still on the line, and it is shaped around that call rather
 * than around the database.
 *
 * It runs in two steps, because everything after the first depends on it. Who
 * the order is for decides which delivery days we can offer them, whether they
 * may order on account, and what address to start from. So step one picks the
 * customer and the page reloads with them chosen; step two is the order itself.
 * Reloading rather than doing it all with JavaScript is deliberate: the whole
 * screen works with JavaScript switched off, which matters on a shop machine
 * with a bad connection more than a smooth transition does.
 *
 * The line editor takes anything. A catalogue product at this week's price, a
 * combo, or a line typed in for something we are sourcing that is not in the
 * catalogue at all. A price left blank on a catalogue line means "our price";
 * a price typed over it is honoured and written to the order's own history, so
 * a discount given on the phone is never invisible afterwards.
 *
 * Every write posts to an endpoint that re-checks the permission on the server.
 * The gates on this page are UX only.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/bootstrap.php';
Rbac::requirePermission('orders.create');

$canAddCustomer = Rbac::can('customers.create');

// --- Step one. Who is this for? ---------------------------------------------
$search   = StaffCustomers::cleanSearch((string) okv_input('customer_q', ''));
$matches  = $search !== '' ? StaffCustomers::search($search, 20) : [];
$customer = StaffCustomers::find((int) okv_input('user_id', 0));

// One match and a colleague who typed a search: open it, rather than making
// them click a list of one while somebody waits on the phone.
if (!$customer && count($matches) === 1) {
    $customer = StaffCustomers::find((int) $matches[0]['id']);
}

// --- Step two. Everything that depends on knowing the customer --------------
$customerType   = $customer ? (string) $customer['user_type'] : 'household';
$eligibleDates  = $customer ? Delivery::nextEligibleDates($customerType, 21) : [];
$zones          = Delivery::zonesActive();
$lastAddress    = $customer ? StaffCustomers::lastAddress((int) $customer['id']) : null;
$depositPercent = Settings::depositPercentage();

$creditApproved = false;
if ($customer && $customerType === 'business') {
    $credit = Database::one(
        'SELECT credit_status FROM business_customers WHERE user_id = :id',
        [':id' => (int) $customer['id']]
    );
    $creditApproved = ($credit['credit_status'] ?? '') === 'approved';
}

// What a colleague can sell. Only what is on sale and carries a price, because
// an order line with no price behind it is a mistake waiting to be found on the
// delivery day.
$products = Database::all(
    'SELECT p.id, p.name, p.current_price_subunit, u.name AS unit_name
       FROM products p
       JOIN units_of_measurement u ON u.id = p.unit_id
      WHERE p.is_active = 1 AND p.current_price_subunit > 0
      ORDER BY p.name'
);
$combos = Database::all(
    'SELECT id, name, price_subunit FROM combo_packages
      WHERE is_active = 1 AND price_subunit > 0
      ORDER BY name'
);

// How many line slots to draw. Enough for an ordinary call, and the button adds
// more where JavaScript is on.
$lineSlots = 8;

// A refusal that came back from api/v1/orders.php or api/v1/customers.php.
$errorCode = trim((string) okv_input('error', ''));
$notice    = null;
if ($errorCode === 'customer_failed') {
    $notice = ['tone' => 'bad', 'text' => 'We could not save that customer. Please try again.'];
} elseif ($errorCode !== '') {
    // Both classes answer to a code. Whichever one owns this code has the words.
    $fromCustomer = StaffCustomers::message($errorCode);
    $notice = ['tone' => 'bad', 'text' => str_starts_with($fromCustomer, 'We could not save')
        ? ManualOrder::message($errorCode)
        : $fromCustomer];
}

$returnTo = '/admin/order_new.php';

$okv_admin_title  = 'New order';
$okv_admin_note   = 'Build an order for a customer who is on the phone. It becomes an ordinary order, with the same trail and the same emails.';
$okv_admin_crumbs = [['label' => 'Orders', 'href' => '/admin/orders.php']];
require __DIR__ . '/../includes/components/admin/header.php';
?>
<div class="space-y-8" data-order-builder>

  <?php if ($notice): ?>
    <p class="rounded-xl border px-4 py-3 text-sm <?= $notice['tone'] === 'good' ? 'border-foliage bg-foliage-tint text-ink' : 'border-tomato bg-tomato-tint text-ink' ?>" role="alert">
      <?= okv_e($notice['text']) ?>
    </p>
  <?php endif; ?>

  <!-- ---------------------------------------------------------------------
       1. Who is this order for.
       --------------------------------------------------------------------- -->
  <section class="okv-card" aria-labelledby="who-heading">
    <h2 id="who-heading" class="font-display text-xl font-bold text-ink">1. Who is it for</h2>

    <?php if ($customer): ?>
      <div class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-foliage bg-foliage-tint p-4">
        <div>
          <p class="font-semibold text-ink">
            <?= okv_e(trim($customer['first_name'] . ' ' . $customer['last_name'])) ?>
            <span class="okv-badge okv-badge-neutral ml-1"><?= okv_e($customerType === 'business' ? 'Business' : 'Household') ?></span>
          </p>
          <p class="mt-1 text-sm text-ink-60">
            <?= okv_e(Phone::display((string) $customer['phone'])) ?>
            <?php if (!StaffCustomers::isPlaceholderEmail((string) $customer['email'])): ?>
              . <?= okv_e($customer['email']) ?>
            <?php else: ?>
              . No email address, so they will not get the order emails.
            <?php endif; ?>
          </p>
        </div>
        <a class="okv-btn-outline px-4" href="/admin/order_new.php">Choose someone else</a>
      </div>
    <?php else: ?>
      <p class="mt-1 text-sm text-ink-60">Search by name, phone number or email. Every order belongs to a customer, so their order trail, their emails and their history all have somewhere to go.</p>

      <form method="GET" class="mt-4 flex flex-wrap items-end gap-2">
        <label class="text-sm text-ink-60 grow sm:grow-0">Name, phone or email
          <input class="okv-input mt-1 sm:w-80" name="customer_q" value="<?= okv_e($search) ?>"
                 required autofocus placeholder="Adaeze, or 0803..." data-customer-search>
        </label>
        <button class="okv-btn-outline min-h-[44px]">Search</button>
      </form>

      <div data-customer-results>
        <?php if ($search !== '' && !$matches): ?>
          <p class="mt-4 rounded-md border border-clay bg-clay-tint px-3 py-2 text-sm text-ink" role="status">
            Nobody matches <?= okv_e($search) ?>. Add them below.
          </p>
        <?php elseif ($matches): ?>
          <ul class="mt-4 divide-y divide-mist">
            <?php foreach ($matches as $match): ?>
              <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                <div>
                  <p class="font-medium text-ink"><?= okv_e(trim($match['first_name'] . ' ' . $match['last_name'])) ?></p>
                  <p class="text-sm text-ink-60">
                    <?= okv_e(Phone::display((string) $match['phone'])) ?>
                    . <?= (int) $match['order_count'] ?> <?= (int) $match['order_count'] === 1 ? 'order' : 'orders' ?>
                    . <?= okv_e($match['user_type'] === 'business' ? 'Business' : 'Household') ?>
                  </p>
                </div>
                <a class="okv-btn-outline-sm inline-flex items-center"
                   href="?user_id=<?= (int) $match['id'] ?>">Choose</a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <?php if ($canAddCustomer): ?>
        <details class="mt-5 rounded-lg border border-mist p-4" <?= $search !== '' && !$matches ? 'open' : '' ?>>
          <summary class="cursor-pointer text-sm font-medium text-forest min-h-[44px] inline-flex items-center">
            This is a new customer
          </summary>
          <form method="POST" action="/api/v1/customers.php" class="mt-4 grid gap-3 sm:grid-cols-2">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="return_to" value="<?= okv_e($returnTo) ?>">
            <div>
              <label class="okv-label" for="new-first">First name</label>
              <input class="okv-input" id="new-first" name="first_name" maxlength="100" required>
            </div>
            <div>
              <label class="okv-label" for="new-last">Last name</label>
              <input class="okv-input" id="new-last" name="last_name" maxlength="100" required>
            </div>
            <div>
              <label class="okv-label" for="new-phone">Phone number</label>
              <input class="okv-input" id="new-phone" name="phone" inputmode="tel" maxlength="30" required placeholder="08031234567">
            </div>
            <div>
              <label class="okv-label" for="new-email">Email address</label>
              <input class="okv-input" id="new-email" name="email" type="email" maxlength="255" placeholder="Leave blank if they have none">
            </div>
            <div>
              <label class="okv-label" for="new-type">Household or business</label>
              <select class="okv-input" id="new-type" name="customer_type">
                <option value="household">Household</option>
                <option value="business">Business</option>
              </select>
            </div>
            <div>
              <label class="okv-label" for="new-business">Business name</label>
              <input class="okv-input" id="new-business" name="business_name" maxlength="200" placeholder="Only for a business">
            </div>
            <div class="sm:col-span-2">
              <button class="okv-btn min-h-[44px]">Add the customer and carry on</button>
              <p class="mt-2 text-sm text-ink-60">
                They get an account with no password. They set one themselves through
                "Forgot your password" whenever they first want to sign in.
              </p>
            </div>
          </form>
        </details>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <?php if ($customer): ?>
  <form method="POST" action="/api/v1/orders.php" class="space-y-8" data-order-form>
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="user_id" value="<?= (int) $customer['id'] ?>">
    <input type="hidden" name="record_token" value="<?= okv_e(ManualPayments::newToken()) ?>">

    <!-- -------------------------------------------------------------------
         2. What they are ordering.
         ------------------------------------------------------------------- -->
    <section class="okv-card" aria-labelledby="lines-heading">
      <h2 id="lines-heading" class="font-display text-xl font-bold text-ink">2. What they are ordering</h2>
      <p class="mt-1 text-sm text-ink-60">
        Pick a product or a combo and leave the price alone to sell at this week's price, or type over it
        for a price agreed on the call. Type a line in by hand for anything not in the catalogue.
      </p>

      <div class="mt-4 space-y-3" data-line-rows>
        <?php for ($i = 0; $i < $lineSlots; $i++): ?>
          <fieldset class="grid gap-3 border-t border-mist pt-3 md:grid-cols-12" data-line-row>
            <legend class="sr-only">Line <?= $i + 1 ?></legend>

            <!-- One control carries both what kind of line this is and which
                 item it points at, so the two can never disagree. A separate
                 "kind" select would let them, the moment JavaScript is off. -->
            <div class="md:col-span-5">
              <label class="okv-label" for="line-<?= $i ?>-item">Item</label>
              <select class="okv-input" id="line-<?= $i ?>-item" name="lines[<?= $i ?>][item]" data-line-item>
                <option value="">Nothing on this line</option>
                <?php if ($products): ?>
                  <optgroup label="Products">
                    <?php foreach ($products as $product): ?>
                      <option value="product:<?= (int) $product['id'] ?>"
                              data-price="<?= okv_e(Money::format((int) $product['current_price_subunit'], false, false)) ?>">
                        <?= okv_e($product['name']) ?>,
                        <?= okv_e(Money::format((int) $product['current_price_subunit'])) ?> per <?= okv_e($product['unit_name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </optgroup>
                <?php endif; ?>
                <?php if ($combos): ?>
                  <optgroup label="Combos">
                    <?php foreach ($combos as $combo): ?>
                      <option value="combo:<?= (int) $combo['id'] ?>"
                              data-price="<?= okv_e(Money::format((int) $combo['price_subunit'], false, false)) ?>">
                        <?= okv_e($combo['name']) ?>, <?= okv_e(Money::format((int) $combo['price_subunit'])) ?>
                      </option>
                    <?php endforeach; ?>
                  </optgroup>
                <?php endif; ?>
                <optgroup label="Not in the catalogue">
                  <option value="custom">Type it in by hand</option>
                </optgroup>
              </select>
            </div>

            <div class="md:col-span-2" data-line-field="custom">
              <label class="okv-label" for="line-<?= $i ?>-name">Typed item</label>
              <input class="okv-input" id="line-<?= $i ?>-name" name="lines[<?= $i ?>][item_name]"
                     maxlength="180" placeholder="Pomo">
            </div>

            <div class="md:col-span-1" data-line-field="custom">
              <label class="okv-label" for="line-<?= $i ?>-unit">Unit</label>
              <input class="okv-input" id="line-<?= $i ?>-unit" name="lines[<?= $i ?>][unit_name]"
                     maxlength="80" placeholder="kg">
            </div>

            <div class="md:col-span-1">
              <label class="okv-label" for="line-<?= $i ?>-qty">Quantity</label>
              <input class="okv-input" id="line-<?= $i ?>-qty" name="lines[<?= $i ?>][quantity]"
                     inputmode="decimal" placeholder="1" data-line-quantity>
            </div>

            <div class="md:col-span-2">
              <label class="okv-label" for="line-<?= $i ?>-price">Price each</label>
              <input class="okv-input" id="line-<?= $i ?>-price" name="lines[<?= $i ?>][unit_price]"
                     inputmode="decimal" placeholder="Our price" data-line-price>
            </div>

            <p class="md:col-span-1 flex items-end pb-3 font-mono text-sm font-medium text-ink" data-line-total aria-live="polite"></p>
          </fieldset>
        <?php endfor; ?>
      </div>

      <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
        <button type="button" class="okv-btn-outline px-4 hidden" data-add-line>Add another line</button>
        <p class="text-base font-semibold text-ink">
          Order total <span class="font-mono" data-order-total>&#8358;0</span>
        </p>
      </div>
    </section>

    <!-- -------------------------------------------------------------------
         3. Where and when.
         ------------------------------------------------------------------- -->
    <section class="okv-card" aria-labelledby="where-heading">
      <h2 id="where-heading" class="font-display text-xl font-bold text-ink">3. Where and when</h2>
      <p class="mt-1 text-sm text-ink-60">
        Only the days we deliver to a <?= okv_e($customerType === 'business' ? 'business' : 'household') ?> are offered,
        and the cutoff is already taken into account. The delivery fee is never charged here: the team confirms
        it with the customer once the area is known.
      </p>

      <div class="mt-4 grid gap-3 md:grid-cols-2">
        <div>
          <label class="okv-label" for="delivery-date">Delivery day</label>
          <?php if (!$eligibleDates): ?>
            <p class="rounded-md border border-tomato bg-tomato-tint px-3 py-2 text-sm text-ink">
              There is no day we can deliver to this customer in the next three weeks. Check the delivery days
              on the <a class="underline" href="/admin/delivery.php">Delivery screen</a> first.
            </p>
          <?php else: ?>
            <select class="okv-input" id="delivery-date" name="delivery_date" required>
              <?php foreach ($eligibleDates as $date): ?>
                <option value="<?= okv_e($date['date']) ?>">
                  <?= okv_e(date('l jS F', strtotime((string) $date['date']))) ?>
                </option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
        </div>

        <div>
          <label class="okv-label" for="delivery-zone">Area</label>
          <select class="okv-input" id="delivery-zone" name="delivery_zone_id" required>
            <option value="">Choose the area</option>
            <?php foreach ($zones as $zone): ?>
              <option value="<?= (int) $zone['id'] ?>"><?= okv_e($zone['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!$zones): ?>
            <p class="mt-1 text-sm text-tomato">
              No area is active. Add one on the <a class="underline" href="/admin/delivery.php">Delivery screen</a>.
            </p>
          <?php endif; ?>
        </div>

        <div>
          <label class="okv-label" for="recipient-name">Who receives it</label>
          <input class="okv-input" id="recipient-name" name="recipient_name" maxlength="150"
                 value="<?= okv_e($lastAddress['recipient_name'] ?? trim($customer['first_name'] . ' ' . $customer['last_name'])) ?>">
        </div>

        <div>
          <label class="okv-label" for="recipient-phone">Phone on the day</label>
          <input class="okv-input" id="recipient-phone" name="recipient_phone" inputmode="tel" maxlength="30"
                 value="<?= okv_e($lastAddress['recipient_phone'] ?? $customer['phone']) ?>">
        </div>

        <div class="md:col-span-2">
          <label class="okv-label" for="address-1">Street address</label>
          <input class="okv-input" id="address-1" name="address_line_1" maxlength="255" required
                 value="<?= okv_e($lastAddress['address_line_1'] ?? '') ?>">
        </div>

        <div class="md:col-span-2">
          <label class="okv-label" for="address-2">Flat, floor or estate</label>
          <input class="okv-input" id="address-2" name="address_line_2" maxlength="255"
                 value="<?= okv_e($lastAddress['address_line_2'] ?? '') ?>">
        </div>

        <div>
          <label class="okv-label" for="address-city">City</label>
          <input class="okv-input" id="address-city" name="city" maxlength="120" required
                 value="<?= okv_e($lastAddress['city'] ?? 'Lagos') ?>">
        </div>

        <div>
          <label class="okv-label" for="address-state">State</label>
          <input class="okv-input" id="address-state" name="state" maxlength="120" required
                 value="<?= okv_e($lastAddress['state'] ?? 'Lagos') ?>">
        </div>

        <div class="md:col-span-2">
          <label class="okv-label" for="address-landmark">Landmark</label>
          <input class="okv-input" id="address-landmark" name="landmark" maxlength="255"
                 value="<?= okv_e($lastAddress['landmark'] ?? '') ?>"
                 placeholder="What the driver should look for">
        </div>

        <div class="md:col-span-2">
          <label class="okv-label" for="order-note">Anything they asked for</label>
          <input class="okv-input" id="order-note" name="customer_note" maxlength="1000"
                 placeholder="Ripe plantain please. Call before you come.">
        </div>
      </div>
    </section>

    <!-- -------------------------------------------------------------------
         4. How they are paying.
         ------------------------------------------------------------------- -->
    <section class="okv-card" aria-labelledby="pay-heading">
      <h2 id="pay-heading" class="font-display text-xl font-bold text-ink">4. How they are paying</h2>
      <p class="mt-1 text-sm text-ink-60">Nothing is charged by creating the order. This records the arrangement, and the money is recorded when it arrives.</p>

      <div class="mt-4 grid gap-3 md:grid-cols-2">
        <label class="block rounded-md border border-mist p-4">
          <input type="radio" name="payment_option" value="pay_in_full" checked class="min-h-[20px] min-w-[20px]">
          <span class="ml-1 font-medium text-ink">Paying in full</span>
          <span class="mt-1 block text-sm text-ink-60">The whole amount is due before we deliver.</span>
        </label>

        <label class="block rounded-md border border-mist p-4">
          <input type="radio" name="payment_option" value="deposit" class="min-h-[20px] min-w-[20px]">
          <span class="ml-1 font-medium text-ink">A deposit now</span>
          <span class="mt-1 block text-sm text-ink-60">
            <?= okv_e(rtrim(rtrim(number_format($depositPercent, 2, '.', ''), '0'), '.')) ?>% now, the balance on delivery.
          </span>
        </label>

        <label class="block rounded-md border border-mist p-4">
          <input type="radio" name="payment_option" value="pay_on_delivery" class="min-h-[20px] min-w-[20px]">
          <span class="ml-1 font-medium text-ink">Paying on delivery</span>
          <span class="mt-1 block text-sm text-ink-60">Nothing now. Our team collects it at the door.</span>
        </label>

        <label class="block rounded-md border border-mist p-4 <?= $creditApproved ? '' : 'opacity-60' ?>">
          <input type="radio" name="payment_option" value="on_account" class="min-h-[20px] min-w-[20px]" <?= $creditApproved ? '' : 'disabled' ?>>
          <span class="ml-1 font-medium text-ink">On account</span>
          <span class="mt-1 block text-sm text-ink-60">
            <?= $creditApproved ? 'Drawn against their approved credit limit.' : 'Only for a business approved for credit.' ?>
          </span>
        </label>
      </div>

      <!-- Money already sent, recorded in the same breath as the order. It goes
           through the ordinary manual payment path, so it lands in the proof
           queue on the Payments screen and reverses the same way. -->
      <details class="mt-5 rounded-lg border border-mist p-4">
        <summary class="cursor-pointer text-sm font-medium text-forest min-h-[44px] inline-flex items-center">
          They have already sent money
        </summary>
        <div class="mt-4 grid gap-3 md:grid-cols-2">
          <div>
            <label class="okv-label" for="paid-method">How it arrived</label>
            <select class="okv-input" id="paid-method" name="paid_method">
              <option value="transfer">Bank transfer</option>
              <option value="cash">Cash</option>
            </select>
          </div>
          <div>
            <label class="okv-label" for="paid-amount">Amount received (naira)</label>
            <input class="okv-input" id="paid-amount" name="paid_amount" inputmode="decimal" placeholder="Leave blank if nothing yet">
          </div>
          <div>
            <label class="okv-label" for="paid-reference">Transaction reference</label>
            <input class="okv-input" id="paid-reference" name="paid_reference" maxlength="150" placeholder="Needed for a transfer">
          </div>
          <div>
            <label class="okv-label" for="paid-payer">Who paid</label>
            <input class="okv-input" id="paid-payer" name="paid_payer_name" maxlength="150" placeholder="Optional">
          </div>
          <p class="md:col-span-2 text-sm text-ink-60">
            This credits the order straight away and goes into the proof queue for review, exactly like money
            recorded on the Payments screen. A screenshot can be attached there afterwards.
          </p>
        </div>
      </details>

      <div class="mt-5 flex flex-wrap items-center gap-3">
        <label class="okv-label sr-only" for="order-channel">How the order reached us</label>
        <select class="okv-input w-auto" id="order-channel" name="channel">
          <option value="phone">By phone</option>
          <option value="whatsapp">On WhatsApp</option>
          <option value="walk_in">In person</option>
        </select>
        <button class="okv-btn min-h-[44px] px-6"<?= $eligibleDates && $zones ? '' : ' disabled' ?>>Create the order</button>
        <a class="okv-btn-text" href="/admin/orders.php">Cancel</a>
      </div>
    </section>
  </form>
  <?php endif; ?>
</div>
<?php
$okv_admin_script = [
    '/assets/js/admin-customer-picker.js',
    '/assets/js/admin-order-new.js',
];
require __DIR__ . '/../includes/components/admin/footer.php';
