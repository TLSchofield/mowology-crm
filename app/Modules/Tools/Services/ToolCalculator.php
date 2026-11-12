<?php
declare(strict_types=1);

final class ToolCalculator
{
    private const FIELDS = [
        'labour-cost' => ['wage','vacation','stat','cpp','ei','wcb','billable','truck','rate'],
        'markup-margin' => ['mode','percent','cost'],
        'break-even' => ['truck_payment','insurance','phone_software','yard_storage','own_pay','other','rate','cost_per_billed_hour'],
        'mow-price' => ['onsite','crew','drive','cost_paid','margin','minimum'],
    ];

    public static function tools(): array { return array_keys(self::FIELDS); }
    public static function allowlist(string $tool, array $input): array
    {
        if (!isset(self::FIELDS[$tool])) throw new InvalidArgumentException('Unknown tool');
        $out = [];
        foreach (self::FIELDS[$tool] as $key) {
            if ($key === 'mode') { $out[$key] = (($input[$key] ?? '') === 'margin') ? 'margin' : 'markup'; continue; }
            if (isset($input[$key]) && $input[$key] !== '' && is_numeric($input[$key])) $out[$key] = (float)$input[$key];
        }
        return $out;
    }

    public function calculate(string $tool, array $raw): array
    {
        $i = self::allowlist($tool, $raw);
        if ($tool === 'labour-cost') return $this->labour($i);
        if ($tool === 'markup-margin') return $this->markup($i);
        if ($tool === 'break-even') return $this->breakEven($i);
        if ($tool === 'mow-price') return $this->mow($i);
        throw new InvalidArgumentException('Unknown tool');
    }

    private function need(array $i, string $key, float $min, float $max): float
    {
        if (!isset($i[$key]) || $i[$key] < $min || $i[$key] > $max) throw new InvalidArgumentException('Invalid ' . $key);
        return (float)$i[$key];
    }
    private function optional(array $i, string $key, float $min, float $max): ?float
    {
        if (!isset($i[$key])) return null;
        return $this->need($i, $key, $min, $max);
    }
    private function pack(string $tool, array $inputs, array $result): array
    { return ['tool' => $tool, 'version' => 1, 'inputs' => $inputs, 'result' => $result]; }

    private function labour(array $i): array
    {
        $wage=$this->need($i,'wage',0.01,1000); $vac=$this->need($i,'vacation',0,100); $stat=$this->need($i,'stat',0,100);
        $cpp=$this->need($i,'cpp',0,100); $ei=$this->need($i,'ei',0,100); $wcb=$this->need($i,'wcb',0,100);
        $bill=$this->need($i,'billable',0.01,100); $truck=$this->need($i,'truck',0,10000); $rate=$this->optional($i,'rate',0,10000);
        $withLeave=$wage*(1+($vac+$stat)/100); $withPayroll=$withLeave*(1+($cpp+$ei+$wcb)/100);
        $burden=(1+($vac+$stat)/100)*(1+($cpp+$ei+$wcb)/100)-1; $paid=$wage*(1+$burden)+$truck; $billed=$paid/($bill/100);
        return $this->pack('labour-cost',$i,['burden'=>$burden,'withLeave'=>$withLeave,'withPayroll'=>$withPayroll,'costPaid'=>$paid,'costBilled'=>$billed,'rate'=>$rate,'difference'=>$rate===null?null:abs($rate-$billed)]);
    }
    private function markup(array $i): array
    {
        $pct=$this->need($i,'percent',0,100000); $cost=$this->optional($i,'cost',0,100000000); $mode=$i['mode'] ?? 'markup';
        if ($mode==='margin') { if ($pct>=100) throw new InvalidArgumentException('Margin must be below 100'); $margin=$pct/100; $markup=$margin/(1-$margin); }
        else { $markup=$pct/100; $margin=$markup/(1+$markup); }
        $price=$cost===null?null:$cost*(1+$markup);
        return $this->pack('markup-margin',$i,['mode'=>$mode,'markup'=>$markup,'margin'=>$margin,'price'=>$price,'profit'=>$price===null?null:$price-$cost]);
    }
    private function breakEven(array $i): array
    {
        $fixed=0.0; foreach (['truck_payment','insurance','phone_software','yard_storage','own_pay','other'] as $k) $fixed += $this->optional($i,$k,0,100000000) ?? 0;
        $rate=$this->need($i,'rate',0,1000000); $cost=$this->need($i,'cost_per_billed_hour',0,1000000); $contribution=$rate-$cost;
        return $this->pack('break-even',$i,['fixed'=>$fixed,'contribution'=>$contribution,'hours'=>$contribution>0?$fixed/$contribution:null,'perWeek'=>$contribution>0?($fixed/$contribution)*12/52:null]);
    }
    private function mow(array $i): array
    {
        $onsite=$this->need($i,'onsite',0.01,100000); $crew=$this->need($i,'crew',1,1000); $drive=$this->need($i,'drive',0,100000);
        $cost=$this->need($i,'cost_paid',0,1000000); $margin=$this->need($i,'margin',0,99.99); $minimum=$this->optional($i,'minimum',0,1000000) ?? 0;
        $labour=($onsite+$drive)*$crew*$cost/60; $price=$labour/(1-$margin/100); $final=max($price,$minimum);
        return $this->pack('mow-price',$i,['labour'=>$labour,'price'=>$price,'final'=>$final,'perOnsiteMinute'=>$final/$onsite,'minimumApplied'=>$minimum>$price]);
    }
}
