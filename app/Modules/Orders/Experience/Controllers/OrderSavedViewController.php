<?php

namespace App\Modules\Orders\Experience\Controllers;

use App\Modules\Kol\Application\SavedViewService;
use App\Modules\Kol\Infrastructure\Models\SavedView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class OrderSavedViewController
{
    public function __construct(private readonly SavedViewService $service) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules(requireName: true));
        $view = $this->service->create('orders', [
            ...$data,
            'visibility' => 'private',
            'filters_json' => $this->filters($data['filters_json'] ?? []),
        ], $request->user());

        return back()->with('success', '订单视图已保存');
    }

    public function update(Request $request, SavedView $savedView): RedirectResponse
    {
        $this->authorizeView($request, $savedView);
        $data = $request->validate($this->rules(requireName: false));
        if (array_key_exists('filters_json', $data)) {
            $data['filters_json'] = $this->filters($data['filters_json'] ?? []);
        }
        $data['visibility'] = 'private';
        $view = $this->service->update($savedView, $data, $request->user());

        return back()->with('success', '订单视图已更新');
    }

    public function destroy(Request $request, SavedView $savedView): RedirectResponse
    {
        $this->authorizeView($request, $savedView);
        $this->service->delete($savedView, $request->user());

        return back()->with('success', '订单视图已删除');
    }

    private function authorizeView(Request $request, SavedView $view): void
    {
        abort_unless($view->module === 'orders', 404);
        abort_unless($view->created_by === (int) $request->user()->getAuthIdentifier(), 403);
    }

    private function rules(bool $requireName): array
    {
        return [
            'name' => [$requireName ? 'required' : 'sometimes', 'string', 'max:100'],
            'filters_json' => ['sometimes', 'array'],
            'filters_json.search' => ['nullable', 'string', 'max:255'],
            'filters_json.source_type' => ['nullable', 'string', 'max:32'],
            'filters_json.order_status' => ['nullable', 'string', 'in:pending_shipment,shipped,completed,completed_delivered,cancelled'],
            'filters_json.view' => ['nullable', 'string', 'in:all,pending,exception,in_transit,shipped,return,loss,overweight,stockout'],
            'filters_json.workflow_status' => ['nullable', 'string', 'max:64'],
            'filters_json.grid_order_number' => ['nullable', 'string', 'max:80'],
            'filters_json.grid_sku' => ['nullable', 'string', 'max:80'],
            'filters_json.grid_quantity' => ['nullable', 'string', 'max:80'],
            'filters_json.grid_product_names' => ['nullable', 'string', 'max:80'],
            'filters_json.grid_source' => ['nullable', 'string', 'max:80'],
            'filters_json.grid_date' => ['nullable', 'string', 'max:80'],
            'filters_json.grid_recipient' => ['nullable', 'string', 'max:80'],
            'filters_json.grid_country' => ['nullable', 'string', 'max:80'],
            'filters_json.grid_warehouse' => ['nullable', 'string', 'max:80'],
            'filters_json.grid_status' => ['nullable', 'string', 'max:80'],
            'filters_json.grid_tracking' => ['nullable', 'string', 'max:80'],
            'filters_json.grid_owner' => ['nullable', 'string', 'max:80'],
            'filters_json.grid_city' => ['nullable', 'string', 'max:80'],
            'filters_json.grid_postal_code' => ['nullable', 'string', 'max:80'],
            'filters_json.grid_carrier' => ['nullable', 'string', 'max:80'],
            'filters_json.grid_external_number' => ['nullable', 'string', 'max:80'],
            'filters_json.grid_email' => ['nullable', 'string', 'max:80'],
            'is_pinned' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    private function filters(array $filters): array
    {
        return array_filter([
            'search' => $filters['search'] ?? '',
            'source_type' => $filters['source_type'] ?? '',
            'order_status' => $filters['order_status'] ?? '',
            'view' => $filters['view'] ?? 'all',
            'workflow_status' => $filters['workflow_status'] ?? '',
            'grid_order_number' => $filters['grid_order_number'] ?? '',
            'grid_sku' => $filters['grid_sku'] ?? '',
            'grid_quantity' => $filters['grid_quantity'] ?? '',
            'grid_product_names' => $filters['grid_product_names'] ?? '',
            'grid_source' => $filters['grid_source'] ?? '',
            'grid_date' => $filters['grid_date'] ?? '',
            'grid_recipient' => $filters['grid_recipient'] ?? '',
            'grid_country' => $filters['grid_country'] ?? '',
            'grid_warehouse' => $filters['grid_warehouse'] ?? '',
            'grid_status' => $filters['grid_status'] ?? '',
            'grid_tracking' => $filters['grid_tracking'] ?? '',
            'grid_owner' => $filters['grid_owner'] ?? '',
            'grid_city' => $filters['grid_city'] ?? '',
            'grid_postal_code' => $filters['grid_postal_code'] ?? '',
            'grid_carrier' => $filters['grid_carrier'] ?? '',
            'grid_external_number' => $filters['grid_external_number'] ?? '',
            'grid_email' => $filters['grid_email'] ?? '',
        ], fn ($value): bool => $value !== '' && $value !== null);
    }
}
