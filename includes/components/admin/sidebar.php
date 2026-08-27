<?php
/**
 * includes/components/admin/sidebar.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Admin sidebar, rendered from includes/config/nav.php so the nav
 * and any future command palette can never disagree about what a user can reach.
 * Each item names the permission it needs; an item the current user cannot use
 * is not rendered, and a whole section is dropped when all of its items are
 * hidden. This is a real server-side gate, not only the UX one in okv-rbac.js.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../config/nav.php';

if (!function_exists('okv_admin_nav_icon')) {
    /** A small line icon (24px, 2px stroke) for a Heroicon-style name. */
    function okv_admin_nav_icon(string $name): string
    {
        $paths = [
            'home'      => '<path d="M3 10.5 12 4l9 6.5"/><path d="M5 9.5V20h14V9.5"/>',
            'clipboard' => '<rect x="6" y="4" width="12" height="16" rx="2"/><path d="M9 4h6v3H9z"/>',
            'list'      => '<path d="M8 6h12M8 12h12M8 18h12"/><path d="M4 6h.01M4 12h.01M4 18h.01"/>',
            'squares'   => '<rect x="4" y="4" width="7" height="7" rx="1"/><rect x="13" y="4" width="7" height="7" rx="1"/><rect x="4" y="13" width="7" height="7" rx="1"/><rect x="13" y="13" width="7" height="7" rx="1"/>',
            'leaf'      => '<path d="M5 19c0-8 6-13 14-13 0 8-6 13-14 13Z"/><path d="M5 19c3-4 6-6 10-8"/>',
            'tag'       => '<path d="M4 12V5a1 1 0 0 1 1-1h7l8 8-8 8-8-8Z"/><circle cx="8.5" cy="8.5" r="1.5"/>',
            'users'     => '<circle cx="9" cy="8" r="3"/><path d="M3 20c0-3 3-5 6-5s6 2 6 5"/><path d="M16 6a3 3 0 0 1 0 6"/><path d="M17 15c2 0 4 2 4 5"/>',
            'card'      => '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18"/>',
            'scale'     => '<path d="M12 4v16M6 20h12"/><path d="m6 8 6-2 6 2"/><path d="M4 13 6 8l2 5a2 2 0 0 1-4 0Z"/><path d="M16 13l2-5 2 5a2 2 0 0 1-4 0Z"/>',
            'truck'     => '<path d="M3 7h11v8H3z"/><path d="M14 10h4l3 3v2h-7z"/><circle cx="7" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/>',
            'heart'     => '<path d="M12 20s-7-4.5-7-9a4 4 0 0 1 7-2.6A4 4 0 0 1 19 11c0 4.5-7 9-7 9Z"/>',
            'chat'      => '<path d="M5 5h14v10H9l-4 4V5Z"/>',
            'cog'       => '<circle cx="12" cy="12" r="3"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"/>',
            'shield'    => '<path d="M12 3 5 6v6c0 4 3 6.5 7 9 4-2.5 7-5 7-9V6Z"/>',
        ];
        $inner = $paths[$name] ?? '<rect x="5" y="5" width="14" height="14" rx="2"/>';
        return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" '
             . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
    }
}

if (!function_exists('okv_admin_current_path')) {
    function okv_admin_current_path(): string
    {
        return (string) parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
    }
}

if (!function_exists('okv_admin_nav_is_active')) {
    function okv_admin_nav_is_active(string $href): bool
    {
        $path = okv_admin_current_path();
        if ($href === '/admin/') {
            return $path === '/admin/' || $path === '/admin' || $path === '/admin/index.php';
        }
        return $path === $href;
    }
}

// Uniquely named so nothing leaks into the including page's scope.
$okv_sb_me   = Database::one('SELECT first_name, last_name FROM users WHERE id = :id', [':id' => (int) Rbac::userId()]);
$okv_sb_name = trim(((string) ($okv_sb_me['first_name'] ?? '')) . ' ' . ((string) ($okv_sb_me['last_name'] ?? '')));
if ($okv_sb_name === '') { $okv_sb_name = 'Signed in'; }
$okv_sb_role = in_array('owner', Rbac::roles(), true) ? 'Owner' : (in_array('manager', Rbac::roles(), true) ? 'Manager' : 'Staff');
?>
<aside id="okv-admin-sidebar"
       class="hidden md:flex md:flex-col md:w-64 md:shrink-0 bg-forest text-white md:min-h-screen">
  <div class="flex items-center gap-2 px-5 h-16 border-b border-white/10">
    <span class="font-display font-extrabold text-lg">OK Veggies</span>
  </div>

  <nav class="flex-1 overflow-y-auto py-4" aria-label="Admin">
    <?php foreach ($OKV_ADMIN_NAV as $group):
        $visible = array_filter($group['items'], static fn($it) => Rbac::can($it['permission']));
        if (!$visible) { continue; }
    ?>
      <p class="px-5 mt-4 mb-1 text-[11px] uppercase tracking-[0.14em] text-white/50"><?= okv_e($group['heading']) ?></p>
      <?php foreach ($visible as $item):
          $active = okv_admin_nav_is_active($item['href']);
      ?>
        <a href="<?= okv_e($item['href']) ?>"
           class="flex items-center gap-3 mx-2 px-3 py-2 rounded-md text-sm font-medium transition-colors duration-botanical <?= $active ? 'bg-white/15 text-white' : 'text-white/85 hover:bg-white/10 hover:text-white' ?>"
           <?= $active ? 'aria-current="page"' : '' ?>>
          <?= okv_admin_nav_icon($item['icon']) ?>
          <span><?= okv_e($item['label']) ?></span>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>

  <div class="border-t border-white/10 px-5 py-4">
    <p class="text-sm font-medium leading-tight"><?= okv_e($okv_sb_name) ?></p>
    <p class="text-xs text-white/60"><?= okv_e($okv_sb_role) ?></p>
    <div class="mt-3 flex items-center gap-4">
      <a href="/admin/account.php" class="text-xs text-white/80 hover:text-white underline underline-offset-2">Your account</a>
      <form method="POST" action="/api/v1/auth.php" class="inline">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="logout">
        <button type="submit" class="text-xs text-white/80 hover:text-white underline underline-offset-2">Sign out</button>
      </form>
    </div>
  </div>
</aside>
