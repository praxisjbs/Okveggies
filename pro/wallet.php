<?php
/**
 * pro/wallet.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The business side of the wallet: the credit OK Veggies has given
 * this business account, what is left, and each entry with its credit note.
 * It is separate from the credit line: a wallet is money the business is owed
 * and can spend, the credit line is money it may owe.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_business_customer();
require_once __DIR__ . '/../includes/components/shop/wallet_panel.php';

$view = Wallet::view((int) Customer::id());
$view['cashout_flag'] = (string) okv_input('cashout', '');

$okv_pro_title  = 'Wallet';
$okv_pro_note   = 'Credit from OK Veggies that you can spend on any order. It is separate from your credit line.';
$okv_pro_active = '/pro/wallet.php';
require __DIR__ . '/../includes/components/pro/header.php';
?>

<div class="space-y-6">
  <?php okv_wallet_panel($view); ?>
</div>

<?php require __DIR__ . '/../includes/components/pro/footer.php'; ?>
