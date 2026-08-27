<?php
/**
 * includes/components/admin/footer.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Closes the admin document opened by header.php. Loads the core JS,
 * exposes the CSRF token and the permission set to the browser (UX gating only,
 * the server re-checks every action), and any per-page script named in
 * $okv_admin_script.
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
  <?php if (!empty($okv_admin_script)): ?>
  <script src="<?= okv_e(okv_asset($okv_admin_script)) ?>" defer></script>
  <?php endif; ?>
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
