<?php

namespace App\Modules\Kol\Application\SmartInput;

use App\Modules\Kol\Application\KolDuplicateIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

final class OnboardingDraft
{
    private const KEY = 'kol.onboarding.draft';

    public static function save(Request $request, array $data): string
    {
        $token = (string) Str::uuid();
        $request->session()->put(self::KEY, [
            'token' => $token, 'actor' => (int) $request->user()->getAuthIdentifier(),
            'expires' => now()->addMinutes(30)->timestamp,
            'payload' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)),
        ]);
        return $token;
    }

    public static function read(Request $request, mixed $token): ?array
    {
        if (!is_string($token) || !$token || !$request->user()->can('orders.create') || !$request->user()->can('kol.operate')) return null;
        $saved = $request->session()->get(self::KEY);
        if (!is_array($saved) || !hash_equals($saved['token'], $token)
            || $saved['actor'] !== (int) $request->user()->getAuthIdentifier()
            || $saved['expires'] < now()->timestamp) return null;
        try { return json_decode(Crypt::decryptString($saved['payload']), true, 32, JSON_THROW_ON_ERROR); }
        catch (Throwable) { return null; }
    }

    public static function bind(Request $request, string $token, int $kolId): void
    {
        if (self::read($request, $token)) $request->session()->put(self::KEY.'.kol_id', $kolId);
    }

    public static function readForKol(Request $request, mixed $token, int $kolId): ?array
    {
        $draft = self::read($request, $token);
        if (!$draft) return null;
        $bound = $request->session()->get(self::KEY.'.kol_id');
        if ($bound !== null) return (int) $bound === $kolId ? $draft : null;
        $smart = $draft['smart'];
        $handle = $smart['username_hint'] ?? (count($smart['social_accounts'] ?? []) === 1 ? $smart['social_accounts'][0]['handle'] : '');
        $existing = is_string($handle) && $handle !== '' ? KolDuplicateIdentity::find($handle, '') : null;
        return $existing && (int)$existing->getKey() === $kolId ? $draft : null;
    }

    public static function forget(Request $request): void
    {
        $request->session()->forget(self::KEY);
    }
}
