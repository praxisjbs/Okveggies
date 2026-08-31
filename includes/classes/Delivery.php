<?php
/** Delivery day and zone eligibility, always evaluated in Africa/Lagos. */
final class Delivery
{
    private const TZ = 'Africa/Lagos';

    public static function allowedDaysFor(string $customerType): array
    {
        return Database::all('SELECT day_of_week, is_active, cutoff_time, minimum_lead_days FROM allowed_delivery_days WHERE customer_type = :type ORDER BY day_of_week', [':type' => $customerType]);
    }
    public static function zonesActive(): array
    {
        return Database::all('SELECT id, name, slug, area_note FROM delivery_zones WHERE is_active = 1 ORDER BY sort_order, name');
    }
    public static function isEligible(string $date, string $customerType, ?DateTimeImmutable $now = null): array
    {
        $rules=[]; foreach (self::allowedDaysFor($customerType) as $row) { $rules[(int)$row['day_of_week']]=$row; }
        $exceptions=[]; foreach (Database::all('SELECT exception_date, is_available, reason, replacement_date FROM delivery_date_exceptions WHERE exception_date = :date', [':date'=>$date]) as $row) {$exceptions[$row['exception_date']]=$row;}
        return self::eligibleFromRules($date,$rules,$exceptions,$now);
    }
    public static function nextEligibleDates(string $customerType, int $count = 14, ?DateTimeImmutable $now = null): array
    {
        $now=self::lagosNow($now); $out=[]; for($i=0;$i<90 && count($out)<$count;$i++){ $date=$now->setTime(0,0)->modify('+' . $i . ' days')->format('Y-m-d'); $check=self::isEligible($date,$customerType,$now); if($check['eligible']){$out[]=$check+['date'=>$date];} } return $out;
    }
    public static function eligibleFromRules(string $date, array $rules, array $exceptions, ?DateTimeImmutable $now = null): array
    {
        $now=self::lagosNow($now); $target=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone(self::TZ));
        if(!$target || $target->format('Y-m-d')!==$date)return ['eligible'=>false,'reason'=>'Choose a valid delivery date.'];
        $exception=$exceptions[$date]??null;
        if($exception && empty($exception['is_available'])) return ['eligible'=>false,'reason'=>trim((string)($exception['reason']??'')) ?: 'This delivery date is not available.','replacement_date'=>$exception['replacement_date']??null];
        $day=(int)$target->format('N'); $rule=$rules[$day]??null;
        if((!$rule || empty($rule['is_active'])) && !($exception && !empty($exception['is_available']))) return ['eligible'=>false,'reason'=>'We do not deliver on that day for this account.'];
        $lead=(int)($rule['minimum_lead_days']??1); $earliest=$now->setTime(0,0)->modify('+' . $lead . ' days');
        if($target < $earliest)return ['eligible'=>false,'reason'=>'Pick a delivery day with enough time for us to source and pack your order.'];
        $cutoff=(string)($rule['cutoff_time']??Settings::str('delivery_cutoff_time','16:00')); $previous=$target->modify('-1 day')->setTime((int)substr($cutoff,0,2),(int)substr($cutoff,3,2));
        if($now > $previous)return ['eligible'=>false,'reason'=>'The order cutoff for that delivery day has passed.'];
        return ['eligible'=>true,'reason'=>''];
    }
    public static function zoneIsActive(array $zone): bool { return !empty($zone['is_active']); }
    public static function dateString(DateTimeImmutable $date): string { return $date->setTimezone(new DateTimeZone(self::TZ))->format('Y-m-d'); }
    private static function lagosNow(?DateTimeImmutable $now): DateTimeImmutable { return ($now ?? new DateTimeImmutable('now',new DateTimeZone(self::TZ)))->setTimezone(new DateTimeZone(self::TZ)); }
}
