<?php

namespace App\Modules\Kol\Application\SmartInput;

use Illuminate\Support\Facades\Process;
use Throwable;

final class DevShapeBridge
{
    private const FIELDS = ['recipient_name','country','province_state','city','address_line_1','address_line_2','postal_code','email','phone','recipient_customs_id'];
    private const KINDS = ['text','number','postal_like','phone_like','email_like','mixed','unknown_label','other'];

    public static function enabled(): bool
    {
        return app()->environment('local', 'testing') && config('kol_smart_input.shape_enabled') === true;
    }

    private static function keys(array $v, array $expected): bool
    {
        $keys = array_keys($v);
        sort($keys); sort($expected);
        return $keys === $expected;
    }

    public static function valid(array $payload): bool
    {
        if (!self::keys($payload, ['lines','missing']) || !is_array($payload['lines']) || !array_is_list($payload['lines']) || count($payload['lines']) < 1 || count($payload['lines']) > 24) return false;
        foreach ($payload['lines'] as $i => $line) {
            if (!is_array($line) || !self::keys($line,['index','label','kind']) || $line['index'] !== $i || !in_array($line['kind'],self::KINDS,true) || ($line['label'] !== null && !in_array($line['label'], self::FIELDS,true))) return false;
        }
        $missing = $payload['missing'];
        if (!is_array($missing) || !array_is_list($missing) || count($missing)<1 || count($missing)>9) return false;
        foreach ($missing as $field) if ($field === 'recipient_customs_id' || !in_array($field,self::FIELDS,true)) return false;
        return count(array_unique($missing)) === count($missing);
    }

    public static function validAssignments(array $p, mixed $assignments): bool
    {
        if (!self::valid($p) || !is_array($assignments) || !array_is_list($assignments) || count($assignments)>24) return false;
        $fields=[]; $indexes=[];
        foreach ($assignments as $a) {
            if (!is_array($a) || !self::keys($a,['index','field']) || !is_int($a['index']) || !in_array($a['field'],$p['missing'],true) || isset($fields[$a['field']]) || isset($indexes[$a['index']])) return false;
            $line=$p['lines'][$a['index']]??null;
            if (!$line || in_array($line['kind'],['other','unknown_label'],true)) return false;
            $field=$a['field'];$kind=['email'=>'email_like','phone'=>'phone_like','postal_code'=>'postal_like'][$field]??null;
            if ($line['label']!==null ? $line['label']!==$field : $kind!==$line['kind']) return false;
            if (count(array_filter($p['lines'],fn($l)=>$l['label']===$field))>1 || ($kind!==null && count(array_filter($p['lines'],fn($l)=>$l['kind']===$kind))>1)) return false;
            $fields[$field]=true;$indexes[$a['index']]=true;
        }
        return true;
    }

    public function suggest(array $payload): array
    {
        $fallback=['source'=>'local_rules','reason'=>'worker_unavailable'];
        if (!self::enabled() || !self::valid($payload)) return $fallback;
        $node=config('kol_smart_input.node');
        $client=config('kol_smart_input.shape_client');
        $key=config('kol_smart_input.key_path');
        if (!is_string($node)||!$node||!is_string($client)||!$client||!is_string($key)||!$key) return $fallback;
        try {
            $result=Process::timeout(55)->env(['BYL_AI_KEY_PATH'=>$key])->input(json_encode($payload,JSON_THROW_ON_ERROR))->run([$node,$client]);
            if (!$result->successful()) return $fallback;
            $v=json_decode($result->output(),true,32,JSON_THROW_ON_ERROR);
            if (($v['source']??null)!=='shape_review'||!self::validAssignments($payload,$v['assignments']??null)) return $fallback;
            return ['source'=>'shape_review','assignments'=>$v['assignments']];
        } catch (Throwable) { return $fallback; }
    }
}
