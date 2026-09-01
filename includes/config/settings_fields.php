<?php
/**
 * includes/config/settings_fields.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The registry behind the admin Settings screen (PRD Section 17.2).
 *
 * Only a key listed here renders on the screen, and only a key listed here can
 * be written by api/v1/settings.php. site_settings holds other rows, and that is
 * deliberate: a generic editor cannot know that a deposit percentage stops at
 * 100 or that a cutoff has to be a real time, and an allowlist means a crafted
 * key name can never insert a row of its own.
 *
 * Field shape:
 *   label       what the person sees above the input
 *   help        one line under it saying what the value actually does
 *   type        the validation rule: percent, days, time, money, bool, text,
 *               email or phone
 *   value_type  what goes in site_settings.value_type, so Settings::coerce
 *               reads it back as the right PHP type
 *   confirm     true when a change needs the confirmation step before it saves.
 *               Reserved for values that change what a customer pays or whether
 *               they can order at all
 *   max         longest allowed string, for the text and email types
 *   min / cap   inclusive range for percent, days and money (money in kobo)
 *   placeholder optional example, never a default
 *
 * Two seeded keys are on purpose absent: currency and order_number_prefix. Both
 * are set once at launch. Changing the prefix would orphan every order number
 * OrderNumber has already issued, and changing the currency would misread every
 * kobo figure already recorded. The screen shows them read-only and says why.
 * -----------------------------------------------------------------------------
 */

/** Largest minimum-order value the screen will accept: 1,000,000 naira in kobo. */
const OKV_SETTINGS_MAX_MIN_ORDER_SUBUNIT = 100000000;

$OKV_SETTINGS_GROUPS = [

    'order' => [
        'label'      => 'Order settings',
        'permission' => 'settings.order.edit',
        'note'       => 'What a customer pays up front, when the day closes, and how soon a delivery can be booked. A change here applies to every order placed after it, and never to an order already placed.',
        'fields'     => [

            'deposit_percentage_default' => [
                'label'      => 'Deposit percentage',
                'help'       => 'The share of an order a customer pays up front to confirm it. The balance is due on delivery.',
                'type'       => 'percent',
                'value_type' => 'int',
                'min'        => 0,
                'cap'        => 100,
                'confirm'    => true,
            ],

            'delivery_cutoff_time' => [
                'label'      => 'Daily cutoff time',
                'help'       => 'Orders placed after this time join the next delivery day. 24-hour clock, for example 16:00.',
                'type'       => 'time',
                'value_type' => 'string',
                'confirm'    => true,
            ],

            'delivery_min_lead_days' => [
                'label'      => 'Days of notice needed',
                'help'       => 'How many days ahead the earliest delivery date can be. 1 means a customer ordering today can pick tomorrow.',
                'type'       => 'days',
                'value_type' => 'int',
                'min'        => 0,
                'cap'        => 14,
                'confirm'    => true,
            ],

            'min_order_subunit' => [
                'label'      => 'Smallest order we accept',
                'help'       => 'A basket below this cannot check out. Set it to 0 to accept any basket.',
                'type'       => 'money',
                'value_type' => 'int',
                'min'        => 0,
                'cap'        => OKV_SETTINGS_MAX_MIN_ORDER_SUBUNIT,
                'confirm'    => true,
            ],

            'pay_on_delivery_requires_activation' => [
                'label'      => 'Pay on delivery needs an activated account',
                'help'       => 'On, a customer must verify their email before they can choose pay on delivery. Off, anyone can.',
                'type'       => 'bool',
                'value_type' => 'bool',
                'confirm'    => true,
            ],
        ],
    ],

    'site' => [
        'label'      => 'Site details',
        'permission' => 'settings.edit',
        'note'       => 'The words and contact details the storefront reads live. A change here shows on the shop the moment it saves.',
        'fields'     => [

            'business_name' => [
                'label'      => 'Business name',
                'help'       => 'Shown in the footer, on invoices and receipts, and in every email that goes out.',
                'type'       => 'text',
                'value_type' => 'string',
                'max'        => 120,
                'required'   => true,
            ],

            'business_tagline' => [
                'label'      => 'Tagline',
                'help'       => 'The line under the name. Keep it short enough to read on a phone.',
                'type'       => 'text',
                'value_type' => 'string',
                'max'        => 200,
            ],

            'source_day' => [
                'label'       => 'Sourcing day',
                'help'        => 'The day the produce comes in. It reads as "Sourced Tuesday from Ogun State, Jos". Leave it blank and the line reads "Sourced this week from ...".',
                'type'        => 'text',
                'value_type'  => 'string',
                'max'         => 40,
                'placeholder' => 'Tuesday',
            ],

            'source_regions' => [
                'label'       => 'Sourcing regions',
                'help'        => 'Where the produce comes from, as it should read in the sentence above. Separate two places with a comma.',
                'type'        => 'text',
                'value_type'  => 'string',
                'max'         => 200,
                'placeholder' => 'Ogun State, Jos',
            ],

            'support_email' => [
                'label'      => 'Support email',
                'help'       => 'Where a customer writes when something goes wrong. Shown in the footer and on the contact page.',
                'type'       => 'email',
                'value_type' => 'string',
                'max'        => 190,
                'required'   => true,
            ],

            'support_whatsapp_number' => [
                'label'       => 'Support WhatsApp number',
                'help'        => 'Digits in international form with no plus and no spaces. This is the number behind every chat button.',
                'type'        => 'phone',
                'value_type'  => 'string',
                'placeholder' => '2348000000000',
                'required'    => true,
            ],
        ],
    ],
];

/**
 * The keys that are real but not editable here, with the reason shown on screen.
 * They render read-only so nobody has to go looking for them in the database.
 */
$OKV_SETTINGS_FIXED = [
    'currency'            => 'Set once at launch. Every amount already recorded is in this currency, so changing it would misread the history.',
    'order_number_prefix' => 'Set once at launch. Order numbers already issued carry this prefix, and changing it would leave them orphaned.',
];
