<?php

namespace App\Modules\Logistics\Application\ShipOut;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Sandbox-only, owner/order-bound, expiring, encrypted PDF label stage.
 * The raw PDF/base64 never leaves the server except for a signed ShipOut POST.
 */
final class ShipOutSandboxLabelStage
{
    public function stage(int $orderId, int $actorId, UploadedFile $file, string $tracking, int $carrierId): array
    {
        if (app()->environment('production')) {
            throw new RuntimeException('SHIPOUT_SANDBOX_FORBIDDEN_IN_PRODUCTION');
        }
        if (! config('shipout.sandbox_dispatch_enabled')
            || ! in_array('6', explode(',', str_replace(' ', '', (string) config('shipout.sandbox_enabled_modes','9'))), true)) {
            throw new RuntimeException('SHIPOUT_SHIPPING_MODE_NOT_ENABLED');
        }
        if (! in_array($carrierId, [1,2,3,4], true)
            || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/+\-]{4,127}\z/', $tracking)
            || preg_match('/^(mock_|UATSHIPOUTVOID)/i', $tracking)) {
            throw new RuntimeException('SHIPOUT_LABEL_TRACKING_INVALID');
        }
        if (! $file->isValid() || $file->getSize() < 70 || $file->getSize() > 512*1024
            || strtolower((string) $file->getClientOriginalExtension()) !== 'pdf') {
            throw new RuntimeException('SHIPOUT_LABEL_PDF_INVALID');
        }
        $pdf = file_get_contents($file->getRealPath());
        if ($pdf === false || ! str_starts_with($pdf, '%PDF-')
            || ! str_contains(substr($pdf, -2048), '%%EOF')) {
            throw new RuntimeException('SHIPOUT_LABEL_PDF_INVALID');
        }
        // Reclaim encrypted PDFs from expired/consumed stages on each upload.
        $this->prune();
        $token = bin2hex(random_bytes(32));
        // Delete older unconsumed stages for THIS order/actor: changing label
        // invalidates its prior preflight fingerprint and upload token.
        DB::transaction(function () use ($orderId,$actorId,$token,$pdf,$tracking,$carrierId) {
            DB::table('shipout_label_stages')->where('order_id',$orderId)
                ->where('actor_id',$actorId)->whereNull('used_at')->delete();
            DB::table('shipout_label_stages')->insert([
                'order_id'=>$orderId,'actor_id'=>$actorId,
                'token_sha256'=>hash('sha256',$token),
                'encrypted_pdf_b64'=>Crypt::encryptString(base64_encode($pdf)),
                'tracking_number'=>$tracking,'carrier_id'=>$carrierId,
                'expires_at'=>now()->addMinutes(15),
                'created_at'=>now(),'updated_at'=>now(),
            ]);
        });
        return ['label_token'=>$token,'expires_in_seconds'=>900,
            'carrier_id'=>$carrierId,'tracking_number'=>$tracking,
            'pdf_size_bytes'=>strlen($pdf)];
    }

    /** @return array{tracking_number:string,carrier_id:int,pdf_b64:string} */
    public function resolve(int $orderId, int $actorId, string $token): array
    {
        if (! preg_match('/^[0-9a-f]{64}$/', $token)) {
            throw new RuntimeException('SHIPOUT_LABEL_TOKEN_INVALID');
        }
        $row=DB::table('shipout_label_stages')
            ->where('order_id',$orderId)->where('actor_id',$actorId)
            ->where('token_sha256',hash('sha256',$token))
            ->whereNull('used_at')->where('expires_at','>',now())->first();
        if (! $row) throw new RuntimeException('SHIPOUT_LABEL_TOKEN_INVALID');
        try { $pdf=Crypt::decryptString($row->encrypted_pdf_b64); }
        catch (\Throwable) { throw new RuntimeException('SHIPOUT_LABEL_TOKEN_INVALID'); }
        if (! str_starts_with(base64_decode($pdf,true) ?: '', '%PDF-')) {
            throw new RuntimeException('SHIPOUT_LABEL_TOKEN_INVALID');
        }
        return ['tracking_number'=>$row->tracking_number,
            'carrier_id'=>(int)$row->carrier_id,'pdf_b64'=>$pdf];
    }

    public function prune(): int
    {
        return DB::table('shipout_label_stages')
            ->where(function ($q) {
                $q->where('expires_at','<=',now())
                  ->orWhereNotNull('used_at');
            })->delete();
    }

    /** Atomic, fail closed before any network call. */
    public function consume(int $orderId, int $actorId, string $token): void
    {
        $count=DB::table('shipout_label_stages')
            ->where('order_id',$orderId)->where('actor_id',$actorId)
            ->where('token_sha256',hash('sha256',$token))
            ->whereNull('used_at')->where('expires_at','>',now())
            ->update(['used_at'=>now(),'updated_at'=>now()]);
        if ($count!==1) throw new RuntimeException('SHIPOUT_LABEL_TOKEN_INVALID');
    }
}
