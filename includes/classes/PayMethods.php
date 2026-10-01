<?php
/**
 * includes/classes/PayMethods.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Which ways of paying apply to one order, for the Pay sheet.
 *
 * The sheet only ever shows a method that will actually work, so a customer is
 * never offered a button the server would refuse. The list is built from the
 * same rules the endpoints enforce:
 *
 *   paystack     a card payment is owing on the order (pay in full, the deposit
 *                of a deposit order, or a deposit opened on a pay on delivery
 *                order). One tap starts it.
 *   deposit      a pay on delivery order that has not been sourced: opens its
 *                deposit and starts the payment in one step.
 *   credit_line  a verified business with enough approved credit, on an order
 *                nothing has been paid on, no card attempt in flight, still Placed.
 *   repay        an order on the credit line that still owes: an ordinary Paystack
 *                charge against its account row, capped at what is open.
 *
 *   wallet       the customer holds wallet credit and this order owes money the
 *                wallet can pay: the whole amount when it covers it, otherwise
 *                what it holds, with the rest left for the card. Offered first,
 *                and the card button beside it says "by card" so the choice is
 *                plain. Every other method is still there for a customer who
 *                would rather keep their wallet. Withheld while a card attempt
 *                on the order is unresolved, like the credit line.
 *
 * The sheet renders whatever this returns.
 * -----------------------------------------------------------------------------
 */

final class PayMethods
{
    /**
     * @param array<string,mixed> $order    id, user_id, order_status, payment_option,
     *                                      payment_status, order_total_subunit,
     *                                      amount_paid_subunit, balance_due_subunit,
     *                                      deposit_required_subunit
     * @param ?array<string,mixed> $facility the viewer's credit facility, or null
     * @return list<array<string,mixed>>
     */
    public static function forOrder(array $order, ?array $facility = null, bool $viewerOwnsAccount = true): array
    {
        if ((string) ($order['order_status'] ?? '') === 'cancelled') {
            return [];
        }
        $orderId = (int) $order['id'];
        $option  = (string) ($order['payment_option'] ?? '');
        $methods = [];

        if ($option === 'on_account') {
            $repay = Payments::repayablePayment($orderId);
            if ($repay !== null) {
                $credit = OrderMoney::creditFor([$orderId])[$orderId] ?? ['open_subunit' => 0];
                $amount = Payments::dueFor($repay, $credit);
                if ($amount > 0) {
                    $methods[] = [
                        'key'            => 'repay',
                        'label'          => 'Repay ' . Money::format($amount),
                        'icon'           => 'card',
                        'primary'        => true,
                        'action'         => 'initialise',
                        'endpoint'       => '/api/v1/payments.php',
                        'payment_id'     => (int) $repay['id'],
                        'amount_subunit' => $amount,
                        'info'           => 'Pay by card, transfer or USSD on Paystack. Your credit limit is freed as soon as it clears.',
                    ];
                }
            }
            return self::withWallet($methods, $order, $viewerOwnsAccount, null);
        }

        $money = OrderMoney::describe($order, null);
        if ($money['settled']) {
            return [];
        }

        $pending = Payments::pendingOnlinePayment($orderId);
        if ($pending !== null) {
            $amount = Money::balance((int) $pending['expected_amount_subunit'], (int) $pending['paid_amount_subunit']);
            $isDeposit = (string) $pending['payment_type'] === 'deposit';
            $methods[] = [
                'key'            => 'paystack',
                'label'          => ($isDeposit ? 'Pay the deposit ' : 'Pay ') . Money::format($amount),
                'icon'           => 'card',
                'primary'        => true,
                'action'         => 'initialise',
                'endpoint'       => '/api/v1/payments.php',
                'payment_id'     => (int) $pending['id'],
                'amount_subunit' => $amount,
                'info'           => 'Card, bank transfer or USSD on Paystack. We never see your card details.',
            ];
        } elseif ($option === 'pay_on_delivery' && (string) $order['order_status'] === 'pending') {
            $deposit = SourcingGate::requiredCashSubunit(
                'pay_on_delivery',
                (int) $order['order_total_subunit'],
                (int) ($order['deposit_required_subunit'] ?? 0),
                Settings::depositPercentage()
            );
            if ($deposit > 0 && (int) ($order['amount_paid_subunit'] ?? 0) < $deposit) {
                $methods[] = [
                    'key'            => 'deposit',
                    'label'          => 'Pay the deposit ' . Money::format($deposit),
                    'icon'           => 'card',
                    'primary'        => true,
                    'action'         => 'start_deposit',
                    'endpoint'       => '/api/v1/payments.php',
                    'order_id'       => $orderId,
                    'amount_subunit' => $deposit,
                    'info'           => 'We source your order once the deposit is in. The rest is paid on delivery.',
                ];
            }
        }

        if ($viewerOwnsAccount
            && SourcingGate::creditShortcutOffered($order, $facility)
            && (string) $order['order_status'] === 'pending'
            && !Payments::hasAttemptInFlight($orderId)
        ) {
            $available = (int) ($facility['available_subunit'] ?? 0);
            $days      = (int) ($facility['days'] ?? 0);
            $methods[] = [
                'key'            => 'credit_line',
                'label'          => 'Use my credit line',
                'icon'           => 'handshake',
                'primary'        => $methods === [],
                'action'         => 'use_credit_line',
                'endpoint'       => '/api/v1/orders.php',
                'order_id'       => $orderId,
                'amount_subunit' => (int) $order['order_total_subunit'],
                'note'           => Money::format($available) . ' available',
                'info'           => 'We put this order on your credit line and you repay within ' . $days . ' days of delivery.',
            ];
        }

        return self::withWallet($methods, $order, $viewerOwnsAccount, $pending);
    }

    /**
     * Put the wallet in front of the other methods when it can pay something on
     * this order, and word the card buttons as the alternative.
     *
     * @param list<array<string,mixed>> $methods
     * @param ?array<string,mixed>      $pending the unpaid Paystack row, when there is one
     * @return list<array<string,mixed>>
     */
    private static function withWallet(array $methods, array $order, bool $viewerOwnsAccount, ?array $pending): array
    {
        // The wallet is the signed-in viewer's, and only ever offered against their
        // own order. Some callers do not select user_id, so an order without one
        // is taken to be the viewer's, which $viewerOwnsAccount already asserts.
        $viewer = Customer::id() === null ? 0 : (int) Customer::id();
        $owner  = (int) ($order['user_id'] ?? 0);
        $userId = $owner > 0 ? $owner : $viewer;
        if (!$viewerOwnsAccount || $userId < 1 || $userId !== $viewer || (string) ($order['order_status'] ?? '') === 'cancelled') {
            return $methods;
        }
        $orderId = (int) $order['id'];
        $option  = (string) ($order['payment_option'] ?? '');
        $balance = Wallet::balance($userId);
        // Withheld while a card attempt is unresolved, exactly as the credit line
        // is: that charge could still arrive for the full amount, and the wallet
        // would then have paid part of the same money twice.
        if ($balance < 1 || Payments::hasAttemptInFlight($orderId)) {
            return $methods;
        }

        $apply = 0;
        $due   = 0;
        $kind  = 'pay';
        $preview = Wallet::preview($orderId, $option, $userId);
        if ($preview !== null) {
            $apply = $preview['apply_subunit'];
            $due   = $preview['due_subunit'];
            $kind  = $option === 'on_account' ? 'repay' : ($pending !== null && (string) $pending['payment_type'] === 'deposit' ? 'deposit' : 'pay');
        } elseif ($option === 'pay_on_delivery' && (string) ($order['order_status'] ?? '') === 'pending' && $pending === null) {
            // The deposit has not been opened yet. The wallet method opens it and
            // pays it in one step, the same as the card deposit button does.
            $deposit = SourcingGate::requiredCashSubunit(
                'pay_on_delivery',
                (int) $order['order_total_subunit'],
                (int) ($order['deposit_required_subunit'] ?? 0),
                Settings::depositPercentage()
            );
            if ($deposit > 0 && (int) ($order['amount_paid_subunit'] ?? 0) < $deposit) {
                $due   = $deposit;
                $apply = Wallet::planSpend($balance, $deposit);
                $kind  = 'deposit';
            }
        }
        if ($apply < 1) {
            return $methods;
        }

        $covers = $apply >= $due;
        $noun   = $kind === 'deposit' ? 'the deposit ' : '';
        $wallet = [
            'key'            => 'wallet',
            'label'          => $covers
                ? ($kind === 'repay' ? 'Repay ' : 'Pay ' . $noun) . Money::format($apply) . ' from wallet'
                : 'Use ' . Money::format($apply) . ' from wallet',
            'icon'           => 'wallet',
            'primary'        => true,
            'action'         => 'use_wallet',
            'endpoint'       => '/api/v1/payments.php',
            'order_id'       => $orderId,
            'fields'         => $covers ? [] : ['then' => 'card'],
            'amount_subunit' => $apply,
            'note'           => $covers
                ? 'Wallet balance ' . Money::format($balance)
                : Money::format($due - $apply) . ' more by card',
            'info'           => 'Your wallet holds credit from OK Veggies. It is spent first, and anything left is paid by card.',
        ];

        foreach ($methods as &$method) {
            $method['primary'] = false;
            if (in_array($method['key'], ['paystack', 'repay', 'deposit'], true)) {
                $method['label'] .= ' by card';
            }
        }
        unset($method);
        array_unshift($methods, $wallet);
        return $methods;
    }
}
