<?php
/**
 * includes/components/admin/footer.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Closes the admin document opened by header.php. Loads the core JS,
 * exposes the CSRF token and the permission set to the browser (UX gating only,
 * the server re-checks every action), and any per-page script named in
 * $okv_admin_script, which takes one path or an array of them.
 * -----------------------------------------------------------------------------
 */
if (!defined('OKV_BOOTSTRAPPED')) {
    exit;
}
?>
      </main>
    </div>
  </div>
  <?= Rbac::jsBootstrap() ?>
  <script>window.OKV=window.OKV||{};window.OKV.csrf=<?= json_encode(Csrf::token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
  <script src="<?= okv_e(okv_asset('/assets/js/okv.js')) ?>" defer></script>
  <script src="<?= okv_e(okv_asset('/assets/js/okv-rbac.js')) ?>" defer></script>
  <script src="<?= okv_e(okv_asset('/assets/js/admin-command-palette.js')) ?>" defer></script>
  <?php
  // One script, or several. A screen that reuses a shared module (the customer
  // picker, say) alongside its own needs both, and naming them in an array
  // beats copying the shared one into every page that wants it.
  foreach ((array) ($okv_admin_script ?? []) as $okv_admin_script_src):
      if ((string) $okv_admin_script_src === '') { continue; }
  ?>
  <script src="<?= okv_e(okv_asset((string) $okv_admin_script_src)) ?>" defer></script>
  <?php endforeach; ?>
  <script>
  (function () {
    var toggle = document.querySelector('[data-okv-nav-toggle]');
    var side = document.getElementById('okv-admin-sidebar');
    if (toggle && side) {
      toggle.addEventListener('click', function () {
        var hidden = side.classList.toggle('hidden');
        toggle.setAttribute('aria-expanded', String(!hidden));
      });
    }
  })();
  </script>
</body>
</html>
