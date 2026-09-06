<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Logistics\Application\HistoricalImport\HistoricalReviewRescueService;
use App\Modules\Logistics\Application\HistoricalImport\HistoricalShipmentImportService;
use App\Modules\Logistics\Infrastructure\Models\HistoricalImportBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class HistoricalImportController
{
    public function __construct(private readonly HistoricalShipmentImportService $service, private readonly HistoricalReviewRescueService $rescue) {}

    public function index(Request $request): Response
    {
        $batch = HistoricalImportBatch::query()->latest('id')->first();
        $page = max(1, (int) $request->integer('page', 1));
        return Inertia::render('Logistics/HistoricalImport', [
            'batch' => $batch ? $this->service->view($batch, $page) : null,
            'commitEnabled' => (bool) config('logistics.historical_import_commit_enabled', false),
            'reviewRescue' => $batch ? $this->rescue->plan($batch) : null,
            'rescueCommitEnabled' => (bool) config('logistics.historical_review_rescue_commit_enabled', false),
            'canOperate' => (bool) $request->user()?->can('logistics.operate'),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:65536']]);
        $batch = $this->service->preview($request->file('file'), $request->user());
        return response()->json(['batch' => $this->service->view($batch), 'reviewRescue' => $this->rescue->plan($batch)]);
    }

    public function commitReviewRescue(Request $request, HistoricalImportBatch $batch): JsonResponse
    {
        $batch = $this->rescue->commit($batch, $request->user());
        return response()->json(['batch' => $this->service->view($batch), 'reviewRescue' => $this->rescue->plan($batch)]);
    }

    public function commit(Request $request, HistoricalImportBatch $batch): JsonResponse
    {
        $batch = $this->service->commit($batch, $request->user());
        return response()->json(['batch' => $this->service->view($batch), 'reviewRescue' => $this->rescue->plan($batch)]);
    }
}
