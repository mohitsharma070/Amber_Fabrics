<?php
final class DeliveryEstimateService
{
    public static function enabled(): bool { return (int)plugin_setting('conversion-mvp','delivery_estimates_enabled',1)===1; }
    public static function calculate(array $items,string $source='manual',?DateTimeImmutable $from=null): array
    {
        $min=max(0,(int)plugin_setting('conversion-mvp','default_dispatch_min_days',2));$max=max($min,(int)plugin_setting('conversion-mvp','default_dispatch_max_days',5));
        foreach($items as $item){$min=max($min,(int)($item['dispatch_min_days']??$min));$max=max($max,$min,(int)($item['dispatch_max_days']??$max));}
        $tmin=max(1,(int)plugin_setting('conversion-mvp','transit_min_days',3));$tmax=max($tmin,(int)plugin_setting('conversion-mvp','transit_max_days',7));$start=$from??new DateTimeImmutable('today',new DateTimeZone(date_default_timezone_get()));$ds=self::addBusinessDays($start,$min);$de=self::addBusinessDays($start,$max);
        return ['serviceability_status'=>$source!==''&&$source!=='manual'?'live':'estimated','estimated_dispatch_start'=>$ds->format('Y-m-d'),'estimated_dispatch_end'=>$de->format('Y-m-d'),'estimated_delivery_start'=>self::addBusinessDays($ds,$tmin)->format('Y-m-d'),'estimated_delivery_end'=>self::addBusinessDays($de,$tmax)->format('Y-m-d')];
    }
    public static function addBusinessDays(DateTimeImmutable $date,int $days):DateTimeImmutable{$r=$date;$n=0;while($n<max(0,$days)){$r=$r->modify('+1 day');if((int)$r->format('N')!==7)$n++;}return $r;}
    public static function formatRange(?string $start,?string $end):string{if(!$start||!$end)return'';$a=strtotime($start);$b=strtotime($end);if($a===false||$b===false)return'';return date('d M',$a).($start===$end?'':' - '.date('d M Y',$b));}
}
