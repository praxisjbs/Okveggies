<?php
/**
 * scripts/tests/OrderNumberTest.php
 * Order and kitchen-run numbers must format consistently, OKV26001 style.
 */

okv_test_eq('OKV26001',  OrderNumber::format('OKV', 26, 1),    'basic order number');
okv_test_eq('OKV26042',  OrderNumber::format('OKV', 26, 42),   'padded to three digits');
okv_test_eq('OKV26999',  OrderNumber::format('OKV', 26, 999),  'last three-digit number');
okv_test_eq('OKV261000', OrderNumber::format('OKV', 26, 1000), 'grows past 999');
okv_test_eq('KR26042',   OrderNumber::format('KR', 26, 42),    'kitchen-run prefix');
okv_test_eq('OKV26005',  OrderNumber::format('OKV', 2026, 5),  'four-digit year is reduced to two');
okv_test_eq('OKV27001',  OrderNumber::format('OKV', 2027, 1),  'year rolls over cleanly');
