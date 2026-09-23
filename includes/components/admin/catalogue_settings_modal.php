<?php
/**
 * includes/components/admin/catalogue_settings_modal.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Modal dialog for managing produce categories and units of
 * measurement from the Products catalogue screen.
 *
 * Tabbed interface:
 *   1. Categories: list, add, and inline edit (slug is immutable to preserve URLs).
 *   2. Units of measurement: list, add, and inline edit with product-usage safety confirmation.
 * -----------------------------------------------------------------------------
 */
if (!defined('OKV_BOOTSTRAPPED')) {
    http_response_code(500);
    exit;
}
?>
<div id="catalogue-settings-modal"
     class="fixed inset-0 z-40 bg-ink/40 p-4 flex items-end sm:items-center justify-center"
     hidden
     data-catalogue-settings-modal
     data-csrf="<?= okv_e(Csrf::token()) ?>">
  <section class="bg-white rounded-lg shadow-okv-3 w-full max-w-2xl max-h-[88vh] flex flex-col overflow-hidden"
           role="dialog"
           aria-modal="true"
           aria-labelledby="catalogue-settings-title">

    <!-- Modal Header --------------------------------------------------------->
    <div class="px-6 py-4 border-b border-mist flex items-start justify-between gap-4 bg-white shrink-0">
      <div>
        <h2 id="catalogue-settings-title" class="font-display font-extrabold text-lg text-ink">Catalogue settings</h2>
        <p class="text-sm text-ink-60 mt-0.5">Manage produce categories and selling units.</p>
      </div>
      <button type="button"
              class="okv-btn-text px-2 min-h-[44px] inline-flex items-center"
              data-catalogue-settings-close
              aria-label="Close settings modal">
        Close
      </button>
    </div>

    <!-- Tab bar -------------------------------------------------------------->
    <div class="border-b border-mist px-6 bg-forest-tint/30 shrink-0">
      <div class="flex gap-2" role="tablist" aria-label="Catalogue settings tabs">
        <button type="button"
                role="tab"
                id="tab-btn-categories"
                aria-controls="tab-panel-categories"
                aria-selected="true"
                data-tab-btn="categories"
                class="min-h-[44px] px-3 text-sm font-medium border-b-2 -mb-px border-forest text-forest transition-colors">
          Categories
        </button>
        <button type="button"
                role="tab"
                id="tab-btn-units"
                aria-controls="tab-panel-units"
                aria-selected="false"
                data-tab-btn="units"
                class="min-h-[44px] px-3 text-sm font-medium border-b-2 -mb-px border-transparent text-ink-60 hover:text-ink transition-colors">
          Units of measurement
        </button>
      </div>
    </div>

    <!-- Scrollable content body ---------------------------------------------->
    <div class="p-6 overflow-y-auto flex-1 space-y-6">

      <!-- Categories Tab Panel ------------------------------------------------->
      <div id="tab-panel-categories"
           role="tabpanel"
           aria-labelledby="tab-btn-categories"
           data-tab-panel="categories">

        <div class="flex items-center justify-between gap-4 mb-4">
          <p class="text-sm text-ink-60">Categories organise produce across the shop and navigation.</p>
          <button type="button"
                  class="okv-btn-sm shrink-0"
                  data-category-add-toggle>
            Add a category
          </button>
        </div>

        <!-- Add Category Form (hidden until toggled) -->
        <div class="mb-5 rounded-md border border-mist bg-forest-tint/20 p-4" data-category-add-container hidden>
          <h3 class="text-sm font-semibold text-ink mb-3">Add a new category</h3>
          <form data-category-add-form class="space-y-3">
            <div class="okv-note-bad text-sm" data-category-add-error hidden role="alert" aria-live="polite"></div>
            <div>
              <label for="new-cat-name" class="okv-label">Name</label>
              <input type="text" id="new-cat-name" name="name" required maxlength="120" class="okv-input" placeholder="e.g. Leafy Greens">
            </div>
            <div>
              <label for="new-cat-description" class="okv-label">Description <span class="font-normal text-ink-40">optional</span></label>
              <textarea id="new-cat-description" name="description" rows="2" maxlength="2000" class="okv-input" placeholder="Brief summary of items in this category."></textarea>
            </div>
            <div>
              <label class="inline-flex items-center gap-2 min-h-[44px] cursor-pointer">
                <input type="checkbox" name="is_active" value="1" checked class="rounded border-mist text-forest focus:ring-gold">
                <span class="text-sm">Active on the shop</span>
              </label>
            </div>
            <div class="flex flex-wrap items-center gap-2 pt-2">
              <button type="submit" class="okv-btn-sm" data-category-add-submit>Save category</button>
              <button type="button" class="okv-btn-text" data-category-add-cancel>Cancel</button>
            </div>
          </form>
        </div>

        <!-- Categories List -->
        <div data-category-list class="space-y-3">
          <p class="text-sm text-ink-60 py-4 text-center">Loading categories...</p>
        </div>
      </div>

      <!-- Units of Measurement Tab Panel --------------------------------------->
      <div id="tab-panel-units"
           role="tabpanel"
           aria-labelledby="tab-btn-units"
           data-tab-panel="units"
           hidden>

        <div class="flex items-center justify-between gap-4 mb-4">
          <p class="text-sm text-ink-60">Selling units define how produce is measured and priced.</p>
          <button type="button"
                  class="okv-btn-sm shrink-0"
                  data-unit-add-toggle>
            Add a unit
          </button>
        </div>

        <!-- Add Unit Form (hidden until toggled) -->
        <div class="mb-5 rounded-md border border-mist bg-forest-tint/20 p-4" data-unit-add-container hidden>
          <h3 class="text-sm font-semibold text-ink mb-3">Add a new unit of measurement</h3>
          <form data-unit-add-form class="space-y-3">
            <div class="okv-note-bad text-sm" data-unit-add-error hidden role="alert" aria-live="polite"></div>
            <div class="grid gap-3 sm:grid-cols-2">
              <div>
                <label for="new-unit-name" class="okv-label">Name</label>
                <input type="text" id="new-unit-name" name="name" required maxlength="80" class="okv-input" placeholder="e.g. Bag">
              </div>
              <div>
                <label for="new-unit-symbol" class="okv-label">Symbol</label>
                <input type="text" id="new-unit-symbol" name="symbol" required maxlength="20" class="okv-input font-mono" placeholder="e.g. bag">
              </div>
            </div>
            <div class="space-y-2">
              <label class="flex items-start gap-2 min-h-[44px] cursor-pointer">
                <input type="checkbox" name="allows_decimal" value="1" class="mt-1 rounded border-mist text-forest focus:ring-gold">
                <span class="text-sm">
                  <span class="font-medium text-ink">Allow decimal quantities</span>
                  <span class="block text-ink-60">Permit fractions like 0.5 or 1.5 in customer orders.</span>
                </span>
              </label>
              <label class="flex items-start gap-2 min-h-[44px] cursor-pointer">
                <input type="checkbox" name="is_active" value="1" checked class="mt-1 rounded border-mist text-forest focus:ring-gold">
                <span class="text-sm">
                  <span class="font-medium text-ink">Active for new products</span>
                  <span class="block text-ink-60">Available when creating or editing produce.</span>
                </span>
              </label>
            </div>
            <div class="flex flex-wrap items-center gap-2 pt-2">
              <button type="submit" class="okv-btn-sm" data-unit-add-submit>Save unit</button>
              <button type="button" class="okv-btn-text" data-unit-add-cancel>Cancel</button>
            </div>
          </form>
        </div>

        <!-- Units List -->
        <div data-unit-list class="space-y-3">
          <p class="text-sm text-ink-60 py-4 text-center">Loading units...</p>
        </div>
      </div>

    </div>
  </section>
</div>
