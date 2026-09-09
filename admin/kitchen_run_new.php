<?php
/**
 * admin/kitchen_run_new.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Type in a kitchen list that arrived some other way (PRD 8.1).
 *
 * Almost no kitchen list arrives through the customer's own form. It arrives as
 * a WhatsApp message, a photograph of a page from a notebook, or read down the
 * phone while somebody writes it out. The quote workshop next door could price,
 * approve and convert a run all day, and none of it could start, because only a
 * signed-in customer could make one exist. This is that missing first step.
 *
 * It saves as Submitted, exactly as a customer's own list does, so what follows
 * is the ordinary path: price it, the customer approves it or a colleague
 * records the approval they gave on the phone, and it becomes an order. Nothing
 * downstream knows or cares that it was typed in, except the trail, which says
 * so plainly.
 *
 * Same two steps as the phone-order screen, and for the same reason: which
 * delivery days we can offer depends on who the customer is, so they are chosen
 * first and the page comes back knowing them.
 *
 * Every write posts to api/v1/kitchen_runs.php, which re-checks the permission
 * on the server. The gates on this page are UX only.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/bootstrap.php';
Rbac::requirePermission('kitchen_runs.create');

$canAddCustomer = Rbac::can('customers.create');

// --- Step one. Whose list is this? ------------------------------------------
$search   = StaffCustomers::cleanSearch((string) okv_input('customer_q', ''));
$matches  = $search !== '' ? StaffCustomers::search($search, 20) : [];
$customer = StaffCustomers::find((int) okv_input('user_id', 0));
if (!$customer && count($matches) === 1) {
    $customer = StaffCustomers::find((int) $matches[0]['id']);
}

// --- Step two ---------------------------------------------------------------
$customerType  = $customer ? (string) $customer['user_type'] : 'household';
$eligibleDates = $customer ? Delivery::nextEligibleDates($customerType, 21) : [];
$zones         = Delivery::zonesActive();
$lastAddress   = $customer ? StaffCustomers::lastAddress((int) $customer['id']) : null;
$units         = Database::all('SELECT id, name FROM units_of_measurement ORDER BY id');
$products      = Database::all(
    'SELECT p.id, p.name, p.current_price_subunit, u.name AS unit_name
       FROM products p
       JOIN units_of_measurement u ON u.id = p.unit_id
      WHERE p.is_active = 1 AND p.current_price_subunit > 0
      ORDER BY p.name'
);

$lineSlots = 10;

$errorCode = trim((string) okv_input('error', ''));
$notice    = $errorCode !== ''
    ? ['tone' => 'bad', 'text' => KitchenRuns::message($errorCode)]
    : null;

$okv_admin_title  = 'Type in a kitchen list';
$okv_admin_note   = 'A list that came on WhatsApp, on the phone or on paper. It saves as a request waiting for a price, exactly like one a customer sends.';
$okv_admin_crumbs = [
    ['label' => 'Orders',       'href' => '/admin/orders.php'],
    ['label' => 'Kitchen Runs', 'href' => '/admin/kitchen_runs.php'],
];
require __DIR__ . '/../includes/components/admin/header.php';
?>
<div class="space-y-8" data-run-builder>

  <?php if ($notice): ?>
    <p class="rounded-xl border border-tomato bg-tomato-tint px-4 py-3 text-sm text-ink" role="alert">
      <?= okv_e($notice['text']) ?>
    </p>
  <?php endif; ?>

  <!-- 1. Whose list is it. -->
  <section class="okv-card" aria-labelledby="who-heading">
    <h2 id="who-heading" class="font-display text-xl font-bold text-ink">1. Whose list is it</h2>

    <?php if ($customer): ?>
      <div class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-foliage bg-foliage-tint p-4">
        <div>
          <p class="font-semibold text-ink">
            <?= okv_e(trim($customer['first_name'] . ' ' . $customer['last_name'])) ?>
            <span class="okv-badge okv-badge-neutral ml-1"><?= okv_e($customerType === 'business' ? 'Business' : 'Household') ?></span>
          </p>
          <p class="mt-1 text-sm text-ink-60">
            <?= okv_e(Phone::display((string) $customer['phone'])) ?>
            <?php if (StaffCustomers::isPlaceholderEmail((string) $customer['email'])): ?>
              . No email address, so they will not get the quote by email. Send it on WhatsApp.
            <?php endif; ?>
          </p>
        </div>
        <a class="okv-btn-outline px-4" href="/admin/kitchen_run_new.php">Choose someone else</a>
      </div>
    <?php else: ?>
      <p class="mt-1 text-sm text-ink-60">Search by name, phone number or email. A run belongs to a customer, so the quote and the approval have somewhere to go.</p>

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
                    . <?= okv_e($match['user_type'] === 'business' ? 'Business' : 'Household') ?>
                  </p>
                </div>
                <a class="okv-btn-outline-sm inline-flex items-center" href="?user_id=<?= (int) $match['id'] ?>">Choose</a>
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
            <input type="hidden" name="return_to" value="/admin/kitchen_run_new.php">
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
                <option value="business">Business</option>
                <option value="household">Household</option>
              </select>
            </div>
            <div>
              <label class="okv-label" for="new-business">Business name</label>
              <input class="okv-input" id="new-business" name="business_name" maxlength="200" placeholder="Only for a business">
            </div>
            <div class="sm:col-span-2">
              <button class="okv-btn min-h-[44px]">Add the customer and carry on</button>
            </div>
          </form>
        </details>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <?php if ($customer): ?>
  <form method="POST" action="/api/v1/kitchen_runs.php" enctype="multipart/form-data" class="space-y-8" data-run-form>
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="staff_submit">
    <input type="hidden" name="user_id" value="<?= (int) $customer['id'] ?>">

    <!-- 2. The list. -->
    <section class="okv-card" aria-labelledby="list-heading">
      <h2 id="list-heading" class="font-display text-xl font-bold text-ink">2. The list</h2>
      <p class="mt-1 text-sm text-ink-60">
        Type it exactly as they sent it. A shop item takes its name and unit from the catalogue.
        Anything else, pomo, meat, oil, is typed in with its own unit. You put the prices on it next,
        in the quote workshop.
      </p>

      <div class="mt-4 grid gap-3 md:grid-cols-3">
        <div>
          <label class="okv-label" for="arrived-by">How it reached us</label>
          <select class="okv-input" id="arrived-by" name="arrived_by">
            <option value="whatsapp">On WhatsApp</option>
            <option value="phone">By phone</option>
            <option value="walk_in">In person</option>
            <option value="email">By email</option>
          </select>
        </div>
        <div>
          <label class="okv-label" for="pricing-mode">Who prices it</label>
          <select class="okv-input" id="pricing-mode" name="pricing_mode">
            <option value="by_us">We do. They gave quantities only.</option>
            <option value="by_customer">They did. They gave a target price per item.</option>
            <option value="already_priced">It came fully priced. We are only confirming it.</option>
          </select>
        </div>
        <div>
          <label class="okv-label" for="attachment">A photo or PDF of the list</label>
          <input class="okv-input" id="attachment" type="file" name="attachment"
                 accept="image/jpeg,image/png,application/pdf">
          <p class="mt-1 text-sm text-ink-60">Optional. Keep the original beside what you typed.</p>
        </div>
      </div>

      <div class="mt-4 space-y-3" data-run-rows>
        <?php for ($i = 0; $i < $lineSlots; $i++): ?>
          <fieldset class="grid gap-3 border-t border-mist pt-3 md:grid-cols-12" data-run-row>
            <legend class="sr-only">Line <?= $i + 1 ?></legend>

            <div class="md:col-span-4">
              <label class="okv-label" for="run-<?= $i ?>-product">A shop item</label>
              <select class="okv-input" id="run-<?= $i ?>-product" name="items[<?= $i ?>][product_id]" data-run-product>
                <option value="">Not from the shop</option>
                <?php foreach ($products as $product): ?>
                  <option value="<?= (int) $product['id'] ?>">
                    <?= okv_e($product['name']) ?>,
                    <?= okv_e(Money::format((int) $product['current_price_subunit'])) ?> per <?= okv_e($product['unit_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="md:col-span-3">
              <label class="okv-label" for="run-<?= $i ?>-name">Or write it out</label>
              <input class="okv-input" id="run-<?= $i ?>-name" name="items[<?= $i ?>][item_name]"
                     maxlength="200" placeholder="Pomo" data-run-name>
            </div>

            <div class="md:col-span-2">
              <label class="okv-label" for="run-<?= $i ?>-qty">Quantity</label>
              <input class="okv-input" id="run-<?= $i ?>-qty" name="items[<?= $i ?>][quantity]"
                     inputmode="decimal" placeholder="6">
            </div>

            <div class="md:col-span-2">
              <label class="okv-label" for="run-<?= $i ?>-unit">Unit</label>
              <select class="okv-input" id="run-<?= $i ?>-unit" name="items[<?= $i ?>][unit_id]">
                <option value="">Choose</option>
                <?php foreach ($units as $unit): ?>
                  <option value="<?= (int) $unit['id'] ?>"><?= okv_e($unit['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="md:col-span-1">
              <label class="okv-label" for="run-<?= $i ?>-price">Price</label>
              <input class="okv-input" id="run-<?= $i ?>-price" name="items[<?= $i ?>][unit_price]"
                     inputmode="decimal" placeholder="Blank">
            </div>
          </fieldset>
        <?php endfor; ?>
      </div>

      <div class="mt-4">
        <button type="button" class="okv-btn-outline px-4 hidden" data-run-add>Add another line</button>
      </div>
    </section>

    <!-- 3. Where and when. -->
    <section class="okv-card" aria-labelledby="where-heading">
      <h2 id="where-heading" class="font-display text-xl font-bold text-ink">3. Where and when</h2>
      <p class="mt-1 text-sm text-ink-60">
        The day and the area they asked for. You can still change both when you price it, because a
        list can sit a while waiting on a price.
      </p>

      <div class="mt-4 grid gap-3 md:grid-cols-2">
        <div>
          <label class="okv-label" for="run-date">Delivery day</label>
          <?php if (!$eligibleDates): ?>
            <p class="rounded-md border border-tomato bg-tomato-tint px-3 py-2 text-sm text-ink">
              There is no day we can deliver to this customer in the next three weeks. Fix that on the
              <a class="underline" href="/admin/delivery.php">Delivery screen</a> first.
            </p>
          <?php else: ?>
            <select class="okv-input" id="run-date" name="preferred_delivery_date" required>
              <?php foreach ($eligibleDates as $date): ?>
                <option value="<?= okv_e($date['date']) ?>">
                  <?= okv_e(date('l jS F', strtotime((string) $date['date']))) ?>
                </option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
        </div>

        <div>
          <label class="okv-label" for="run-zone">Area</label>
          <select class="okv-input" id="run-zone" name="delivery_zone_id" required>
            <option value="">Choose the area</option>
            <?php foreach ($zones as $zone): ?>
              <option value="<?= (int) $zone['id'] ?>"><?= okv_e($zone['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="okv-label" for="run-recipient">Who receives it</label>
          <input class="okv-input" id="run-recipient" name="recipient_name" maxlength="150" required
                 value="<?= okv_e($lastAddress['recipient_name'] ?? trim($customer['first_name'] . ' ' . $customer['last_name'])) ?>">
        </div>

        <div>
          <label class="okv-label" for="run-phone">Phone on the day</label>
          <input class="okv-input" id="run-phone" name="recipient_phone" inputmode="tel" maxlength="30" required
                 value="<?= okv_e($lastAddress['recipient_phone'] ?? $customer['phone']) ?>">
        </div>

        <div class="md:col-span-2">
          <label class="okv-label" for="run-address">Street address</label>
          <input class="okv-input" id="run-address" name="address_line_1" maxlength="255" required
                 value="<?= okv_e($lastAddress['address_line_1'] ?? '') ?>">
        </div>

        <div class="md:col-span-2">
          <label class="okv-label" for="run-address-2">Flat, floor or estate</label>
          <input class="okv-input" id="run-address-2" name="address_line_2" maxlength="255"
                 value="<?= okv_e($lastAddress['address_line_2'] ?? '') ?>">
        </div>

        <div>
          <label class="okv-label" for="run-city">City</label>
          <input class="okv-input" id="run-city" name="city" maxlength="120" required
                 value="<?= okv_e($lastAddress['city'] ?? 'Lagos') ?>">
        </div>

        <div>
          <label class="okv-label" for="run-state">State</label>
          <input class="okv-input" id="run-state" name="state" maxlength="120" required
                 value="<?= okv_e($lastAddress['state'] ?? 'Lagos') ?>">
        </div>

        <div class="md:col-span-2">
          <label class="okv-label" for="run-landmark">Landmark</label>
          <input class="okv-input" id="run-landmark" name="landmark" maxlength="255"
                 value="<?= okv_e($lastAddress['landmark'] ?? '') ?>">
        </div>
      </div>
    </section>

    <!-- 4. The budget, and what they said. -->
    <section class="okv-card" aria-labelledby="budget-heading">
      <h2 id="budget-heading" class="font-display text-xl font-bold text-ink">4. Budget and notes</h2>

      <label class="mt-3 flex items-start gap-2 text-sm text-ink">
        <input type="checkbox" name="is_open_budget" value="1" class="mt-1 min-h-[20px] min-w-[20px]">
        <span>
          <span class="font-medium">Open budget.</span>
          They said to source it whatever it costs. Only tick this when we are setting the prices.
        </span>
      </label>

      <div class="mt-4 grid gap-3 md:grid-cols-2">
        <div>
          <label class="okv-label" for="run-cap">Spend cap (naira)</label>
          <input class="okv-input" id="run-cap" name="spend_cap" inputmode="decimal" placeholder="Optional">
          <p class="mt-1 text-sm text-ink-60">Only on an open-budget list. We source up to this and no further.</p>
        </div>
        <div>
          <label class="okv-label" for="run-ceiling">Budget they mentioned (naira)</label>
          <input class="okv-input" id="run-ceiling" name="budget_ceiling" inputmode="decimal" placeholder="Optional">
          <p class="mt-1 text-sm text-ink-60">Only on an open-budget list. What they said they had in mind.</p>
        </div>
        <div class="md:col-span-2">
          <label class="okv-label" for="run-note">What they said</label>
          <textarea class="okv-input min-h-[96px] py-3" id="run-note" name="customer_note" rows="3"
                    maxlength="2000" placeholder="Their words. Ripe plantain, no scent leaf, deliver before noon."></textarea>
        </div>
      </div>

      <div class="mt-5 flex flex-wrap items-center gap-3">
        <button class="okv-btn min-h-[44px] px-6"<?= $eligibleDates && $zones ? '' : ' disabled' ?>>Save the list</button>
        <a class="okv-btn-text" href="/admin/kitchen_runs.php">Cancel</a>
      </div>
      <p class="mt-2 text-sm text-ink-60">
        It saves as waiting for a price. The customer is emailed to say we have it, then you price it in
        the quote workshop.
      </p>
    </section>
  </form>
  <?php endif; ?>
</div>
<?php
$okv_admin_script = [
    '/assets/js/admin-customer-picker.js',
    '/assets/js/admin-run-new.js',
];
require __DIR__ . '/../includes/components/admin/footer.php';
