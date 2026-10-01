<?php
/**
 * scripts/tests/TransferProofsTest.php
 * The pure rules of direct bank transfer: what counts as a receipt, when the
 * option is offered at all, and the words a customer is given. The database
 * half (submit, verify, decline, the gate) is covered by
 * scripts/tests/transfer_proofs_db_test.php.
 */

// -----------------------------------------------------------------------------
// The account number
// -----------------------------------------------------------------------------
okv_test_ok(TransferProofs::accountNumberIsValid('0123456789'), 'a 10 digit account number is valid');
okv_test_ok(!TransferProofs::accountNumberIsValid('012345678'),  'nine digits is not an account number');
okv_test_ok(!TransferProofs::accountNumberIsValid('01234567890'), 'eleven digits is not an account number');
okv_test_ok(!TransferProofs::accountNumberIsValid('01234 56789'), 'a space in the number is refused, the settings screen strips it first');
okv_test_ok(!TransferProofs::accountNumberIsValid('01234A6789'), 'a letter in the number is refused');
okv_test_ok(!TransferProofs::accountNumberIsValid(''),           'an empty number is refused');

// -----------------------------------------------------------------------------
// Which choices can be paid by transfer
// -----------------------------------------------------------------------------
okv_test_ok(TransferProofs::optionAllowsTransfer('pay_in_full'),      'pay in full can be paid by transfer');
okv_test_ok(TransferProofs::optionAllowsTransfer('deposit'),          'a deposit can be paid by transfer');
okv_test_ok(!TransferProofs::optionAllowsTransfer('pay_on_delivery'), 'pay on delivery is not paid by transfer here');
okv_test_ok(!TransferProofs::optionAllowsTransfer('on_account'),      'business credit is not paid by transfer');
okv_test_ok(!TransferProofs::optionAllowsTransfer(''),                'an empty choice is refused');

okv_test_ok(TransferProofs::methodIsValid('paystack'),       'paystack is a method');
okv_test_ok(TransferProofs::methodIsValid('bank_transfer'),  'bank_transfer is a method');
okv_test_ok(!TransferProofs::methodIsValid('cash'),          'cash is not a checkout method');
okv_test_ok(!TransferProofs::methodIsValid(''),              'an empty method is refused');

okv_test_eq(['pay_in_full', 'deposit', 'balance'], TransferProofs::PAYABLE_TYPES, 'a receipt can be uploaded for the full amount, the deposit or the balance, and nothing else');

// -----------------------------------------------------------------------------
// The receipt file. Extension, sniffed type and size must all agree.
// -----------------------------------------------------------------------------
$max = 5 * 1024 * 1024;
okv_test_eq(null, TransferProofs::receiptFileProblem('receipt.jpg',  'image/jpeg',       120000, $max), 'a jpg photo is a receipt');
okv_test_eq(null, TransferProofs::receiptFileProblem('receipt.JPEG', 'image/jpeg',       120000, $max), 'the extension is compared in lower case');
okv_test_eq(null, TransferProofs::receiptFileProblem('receipt.png',  'image/png',        120000, $max), 'a png screenshot is a receipt');
okv_test_eq(null, TransferProofs::receiptFileProblem('receipt.webp', 'image/webp',       120000, $max), 'a webp screenshot is a receipt');
okv_test_eq(null, TransferProofs::receiptFileProblem('receipt.pdf',  'application/pdf',  120000, $max), 'a pdf from the bank app is a receipt');
okv_test_eq(null, TransferProofs::receiptFileProblem('receipt.jpg',  'image/jpeg',       $max,   $max), 'a file exactly at the cap is accepted');
okv_test_eq('too_large',              TransferProofs::receiptFileProblem('receipt.jpg', 'image/jpeg', $max + 1, $max), 'a file over the cap is refused');
okv_test_eq('empty',                  TransferProofs::receiptFileProblem('receipt.jpg', 'image/jpeg', 0, $max),        'an empty file is refused');
okv_test_eq('unsupported_type',       TransferProofs::receiptFileProblem('receipt.jpg', 'text/html', 500, $max),       'a web page dressed as a jpg is refused on its real type');
okv_test_eq('unsupported_type',       TransferProofs::receiptFileProblem('shell.php',   'text/x-php', 500, $max),      'a php file is refused');
okv_test_eq('unsupported_extension',  TransferProofs::receiptFileProblem('receipt.exe', 'image/jpeg', 500, $max),      'an extension outside the whitelist is refused even when the bytes look like a photo');
okv_test_eq('unsupported_extension',  TransferProofs::receiptFileProblem('receipt', 'image/jpeg', 500, $max),          'no extension is refused');
okv_test_eq('disguised_type',         TransferProofs::receiptFileProblem('receipt.png', 'image/jpeg', 500, $max),      'a png name on jpeg bytes is refused');
okv_test_eq('disguised_type',         TransferProofs::receiptFileProblem('receipt.pdf', 'image/png', 500, $max),       'a pdf name on image bytes is refused');
okv_test_eq('unsupported_extension',  TransferProofs::receiptFileProblem('receipt.php.jpg.exe', 'image/jpeg', 500, $max), 'a double extension is judged on its last part');

// -----------------------------------------------------------------------------
// Words. Every refusal has plain copy, and none of it leaks a code or an exception.
// -----------------------------------------------------------------------------
foreach (['missing', 'empty', 'too_large', 'unsupported_type', 'unsupported_extension', 'disguised_type', 'unreadable_image', 'unreadable_pdf', 'upload_failed'] as $code) {
    $message = TransferProofs::receiptProblemMessage($code);
    okv_test_ok($message !== '' && !str_contains($message, '_'), "the '$code' refusal has a plain sentence");
    okv_test_ok(!str_contains($message, "\u{2014}"), "the '$code' refusal has no em dash");
}
okv_test_ok(TransferProofs::receiptProblemMessage('something_new') !== '', 'an unknown code still gets a plain sentence');
okv_test_ok(str_contains(TransferProofs::receiptProblemMessage('too_large'), '5MB'), 'the size message names the real cap');

// -----------------------------------------------------------------------------
// Labels
// -----------------------------------------------------------------------------
okv_test_eq('Payment in full', TransferProofs::typeLabel('pay_in_full'),     'pay_in_full reads as Payment in full');
okv_test_eq('Deposit',         TransferProofs::typeLabel('deposit'),         'deposit reads as Deposit');
okv_test_eq('Balance',         TransferProofs::typeLabel('balance'),         'balance reads as Balance');
okv_test_eq('Pay on delivery', TransferProofs::typeLabel('pay_on_delivery'), 'pay_on_delivery reads as Pay on delivery');
okv_test_eq('Something else',  TransferProofs::typeLabel('something_else'),  'an unknown type is made readable rather than shown raw');

// -----------------------------------------------------------------------------
// The proof states never collide with the ones ManualPayments already uses
// -----------------------------------------------------------------------------
okv_test_ok(TransferProofs::PROOF_SUBMITTED !== ManualPayments::PROOF_PENDING,  'submitted is not the staff pending state, so the two queues cannot mix');
okv_test_ok(TransferProofs::PROOF_VERIFIED === ManualPayments::PROOF_APPROVED,  'a verified receipt uses the existing approved state, so reversals and reports read it');
okv_test_ok(TransferProofs::PROOF_DECLINED !== ManualPayments::PROOF_REJECTED,  'declined is its own state, distinct from a colleague being questioned');
okv_test_ok(TransferProofs::TXN_AWAITING !== Payments::TXN_SUCCESS, 'an awaiting transaction is never a successful one');
okv_test_ok(!in_array(TransferProofs::TXN_AWAITING, [Payments::TXN_INITIALIZED, Payments::TXN_UNKNOWN], true), 'the Paystack sweep never picks a receipt awaiting review up');
