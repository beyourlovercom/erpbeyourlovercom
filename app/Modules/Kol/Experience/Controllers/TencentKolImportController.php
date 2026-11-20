<?php

namespace App\Modules\Kol\Experience\Controllers;

use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Kol\Application\Reconciliation\KolReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TencentKolImportController
{
    public function __construct(
        private readonly KolReconciliationService $reconciliation,
        private readonly AffiliateWpGateway $gateway,
    ) {}

    public function index(Request $request): Response
    {
        $connection = (string) ($this->gateway->descriptor()['connection_key'] ?? '');

        return Inertia::render('Kol/AffiliateWp/TencentImport', [
            'batches' => $this->reconciliation->workspace($connection, $request->user(), true),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => 'required|file|mimes:xlsx|max:65536',
            'sheet_name' => 'required|string|max:120',
            'email_column' => 'nullable|string|max:191',
            'profile_url_column' => 'nullable|string|max:191',
            'platform_column' => 'nullable|string|max:191',
            'handle_column' => 'nullable|string|max:191',
            'owner_column' => 'nullable|string|max:191',
            'owner_confirmed' => 'required|boolean',
        ]);
        $connection = (string) ($this->gateway->descriptor()['connection_key'] ?? '');
        $mapping = [
            'email' => $data['email_column'] ?? null,
            'profile_url' => $data['profile_url_column'] ?? null,
            'platform' => $data['platform_column'] ?? null,
            'handle' => $data['handle_column'] ?? null,
            'owner' => $data['owner_column'] ?? null,
        ];
        $batch = $this->reconciliation->previewTencent(
            $request->file('file'), $connection, $request->user(),
            $data['sheet_name'], $mapping, (bool) $data['owner_confirmed'],
        );

        return response()->json(['batch' => [
            'id' => $batch->id, 'original_filename' => $batch->original_filename,
            'checksum' => $batch->checksum, 'total_rows' => $batch->total_rows,
            'distinct_source_identities' => $batch->distinct_source_identities,
            'summary' => $batch->preview_summary,
            'rows' => $batch->rows->map(fn ($row): array => [
                'source_row_number' => $row->source_row_number,
                'identity' => $row->normalized_identity,
                'kol' => $row->kol ? ['id' => $row->kol->id, 'display_name' => $row->kol->display_name] : null,
                'affiliate_account_id' => $row->matched_affiliate_account_id,
                'source_owner' => ($row->raw_source ?? [])['Belongs to'] ?? null,
                'status' => $row->reconciliation_status, 'message' => $row->message,
            ])->values()->all(),
        ]]);
    }
}
