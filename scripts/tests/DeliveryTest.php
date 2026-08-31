<?php
$household = [1 => ['is_active' => 1, 'cutoff_time' => '16:00:00', 'minimum_lead_days' => 1], 3 => ['is_active' => 1, 'cutoff_time' => '16:00:00', 'minimum_lead_days' => 1]];
$business = [2 => ['is_active' => 1, 'cutoff_time' => '16:00:00', 'minimum_lead_days' => 1]];
okv_test_ok(Delivery::eligibleFromRules('2026-09-07', $household, [], new DateTimeImmutable('2026-09-06 15:59:00', new DateTimeZone('Africa/Lagos')))['eligible'], 'household Monday before cutoff is eligible');
okv_test_ok(!Delivery::eligibleFromRules('2026-09-07', $household, [], new DateTimeImmutable('2026-09-06 16:01:00', new DateTimeZone('Africa/Lagos')))['eligible'], 'after cutoff is unavailable');
okv_test_ok(!Delivery::eligibleFromRules('2026-09-08', $household, [], new DateTimeImmutable('2026-09-06 10:00:00', new DateTimeZone('Africa/Lagos')))['eligible'], 'household cannot use business Tuesday');
okv_test_ok(Delivery::eligibleFromRules('2026-09-08', $business, [], new DateTimeImmutable('2026-09-06 10:00:00', new DateTimeZone('Africa/Lagos')))['eligible'], 'business Tuesday is eligible');
okv_test_ok(!Delivery::eligibleFromRules('2026-09-07', $household, ['2026-09-07' => ['is_available' => 0]], new DateTimeImmutable('2026-09-06 10:00:00', new DateTimeZone('Africa/Lagos')))['eligible'], 'exception blocks a date');
okv_test_ok(Delivery::eligibleFromRules('2026-09-08', $household, ['2026-09-08' => ['is_available' => 1]], new DateTimeImmutable('2026-09-06 10:00:00', new DateTimeZone('Africa/Lagos')))['eligible'], 'emergency exception opens a weekday');
okv_test_eq('2026-10-01', Delivery::dateString(new DateTimeImmutable('2026-10-01 00:00:00', new DateTimeZone('Africa/Lagos'))), 'month rollover keeps local date');
okv_test_ok(!Delivery::zoneIsActive(['is_active' => 0]), 'inactive zone is refused');
