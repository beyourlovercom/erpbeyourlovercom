<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Logistics\Application\FulfillmentPlanCostEstimator;
use App\Modules\Logistics\Application\LogisticsRateDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LogisticsRateCenterController
{
    public function index(Request $request): Response
    {
        $input = $request->validate(['version_id' => 'nullable|integer|min:1', 'view' => 'nullable|in:books,bands,zones', 'search' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|in:20,50,100']);
        $versions = DB::table('logistics_rate_versions as v')->join('logistics_rate_books as b', 'b.id', '=', 'v.book_id')
            ->join('logistics_rate_source_files as f', 'f.id', '=', 'v.source_file_id')
            ->select('v.id', 'b.display_name', 'b.supplier_name', 'v.version_number', 'v.status', 'v.effective_from', 'v.effective_until', 'v.created_at', 'v.notes', 'f.original_filename', 'f.sha256')
            ->orderByDesc('v.id')->get()->map(function ($row) {
                $row->notes = json_decode($row->notes ?? '[]', true);

                return $row;
            });
        $version = isset($input['version_id']) ? $versions->firstWhere('id', (int) $input['version_id']) : $versions->first();
        if (isset($input['version_id'])) {
            abort_unless($version, 404);
        }
        $view = $input['view'] ?? 'books';
        $search = $input['search'] ?? '';
        $size = (int) ($input['per_page'] ?? 50);
        if ($view === 'bands') {
            $query = DB::table('logistics_rate_bands as r')->join('logistics_rate_components as c', 'c.id', '=', 'r.component_id')
                ->where('c.version_id', $version?->id ?? 0)
                ->select('r.id', 'c.warehouse_code', 'c.carrier', 'c.service', 'c.destination_country', 'c.destination_zone', 'c.component_type', 'c.currency', 'c.weight_unit',
                    'c.pricing_mode', 'c.review_status', 'c.source_sheet', 'r.source_cells', 'r.min_weight', 'r.max_weight', 'r.fixed_amount', 'r.per_weight_amount', 'r.per_extra_item_amount');
            if ($search !== '') {
                $query->where(fn ($q) => $q->where('c.warehouse_code', 'like', '%'.$search.'%')->orWhere('c.destination_country', 'like', '%'.$search.'%')->orWhere('c.carrier', 'like', '%'.$search.'%')->orWhere('c.service', 'like', '%'.$search.'%'));
            }
            $rows = $query->orderBy('c.id')->orderBy('r.sequence')->paginate($size);
        } elseif ($view === 'zones') {
            $query = DB::table('logistics_rate_zones')->where('version_id', $version?->id ?? 0)->select('id', 'warehouse_code', 'carrier', 'service', 'destination_country', 'postal_start', 'postal_end', 'zone_label', 'review_status', 'source_sheet', 'source_cells');
            if ($search !== '') {
                $query->where(fn ($q) => $q->where('postal_start', 'like', '%'.$search.'%')->orWhere('postal_end', 'like', '%'.$search.'%')->orWhere('warehouse_code', 'like', '%'.$search.'%')->orWhere('zone_label', 'like', '%'.$search.'%'));
            }
            $rows = $query->orderBy('id')->paginate($size);
        } else {
            $rows = null;
        }

        return Inertia::render('Logistics/RateCenter/Index', [
            'versions' => $versions, 'selectedVersion' => $version, 'view' => $view, 'search' => $search, 'rows' => $rows,
            'canImport' => (bool) ($request->user()?->can('logistics.operate') && $request->user()?->can('system.permissions.manage')),
        ]);
    }

    public function preview(Request $request, LogisticsRateDraftService $service): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xls,xlsx', 'max:16384']]);

        return response()->json($service->preview($request->file('file')));
    }

    public function draft(Request $request, LogisticsRateDraftService $service): JsonResponse
    {
        $input = $request->validate(['file' => ['required', 'file', 'mimes:xls,xlsx', 'max:16384'], 'sha256' => 'required|regex:/^[a-f0-9]{64}$/D',
            'previous_version_id' => 'nullable|integer|min:1', 'effective_from' => 'nullable|date_format:Y-m-d']);

        return response()->json($service->save($request->file('file'), $input['sha256'], isset($input['previous_version_id']) ? (int) $input['previous_version_id'] : null, $input['effective_from'] ?? null, (int) $request->user()->getAuthIdentifier()));
    }

    public function download(int $version): StreamedResponse
    {
        $source = DB::table('logistics_rate_versions as v')->join('logistics_rate_source_files as f', 'f.id', '=', 'v.source_file_id')->where('v.id', $version)->select('f.*')->first();
        abort_unless($source && preg_match('~^logistics-rates/[a-f0-9]{64}\.xlsx?$~D', $source->private_storage_path), 404);
        abort_unless(Storage::disk('local')->exists($source->private_storage_path), 404);

        return Storage::disk('local')->download($source->private_storage_path, basename($source->original_filename));
    }

    public function quote(Request $request, FulfillmentPlanCostEstimator $estimator): JsonResponse
    {
        $input = $request->validate(['version_id' => 'required|integer|min:1', 'warehouse_code' => 'required|string|max:32', 'carrier' => 'required|string|max:64', 'service' => 'required|string|max:96', 'country' => 'required|string|size:2', 'postal_code' => 'nullable|string|max:32',
            'as_of' => 'required|date_format:Y-m-d', 'handling_policy' => 'required|in:supplier,internal', 'packages' => 'required|array|min:1|max:100',
            'packages.*.weight_kg' => 'required|numeric|gt:0|max:1000', 'packages.*.weight_basis' => 'required|in:measured_packaged,estimated_packaged,bare',
            'packages.*.dimensions_cm' => 'required|array|size:3', 'packages.*.dimensions_cm.*' => 'required|numeric|gt:0|max:10000',
            'packages.*.item_count' => 'required|integer|min:1|max:10000', 'packages.*.eligibility' => 'required|in:confirmed,unknown,denied']);
        $input['version_id'] = (int) $input['version_id'];
        $input['postal_code'] = $input['postal_code'] ?? '';
        foreach ($input['packages'] as &$package) {
            $package['item_count'] = (int) $package['item_count'];
        }
        unset($package);

        return response()->json($estimator->quoteVersioned($input));
    }
}
