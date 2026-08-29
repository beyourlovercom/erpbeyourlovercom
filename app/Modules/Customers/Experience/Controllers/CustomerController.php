<?php

namespace App\Modules\Customers\Experience\Controllers;

use App\Modules\Audit\Infrastructure\Models\AuditRecord;
use App\Modules\Customers\Application\CustomerService;
use App\Modules\Finance\Application\EcbFxService;
use App\Modules\Customers\Domain\CountryCodeNormalizer;
use App\Modules\Customers\Domain\Exceptions\InvalidCompanyAttributeException;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Customers\Infrastructure\Models\CustomerAddress;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class CustomerController
{
    private const SOURCES = [
        'manual' => '手工录入',
        'website' => '网站',
        'woocommerce' => 'WooCommerce',
        'csv_import' => 'CSV 导入',
        'external_platform' => '其他平台',
    ];

    public function __construct(
        private readonly CustomerService $customers,
        private readonly EcbFxService $fx,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'country_code', 'source_type', 'archived']);
        $paginator = $this->customers->list($filters);

        $customerIds = collect($paginator->items())->pluck('id');

        $latestOrderDates = $customerIds->isNotEmpty()
            ? DB::table('orders')
                ->whereIn('customer_id', $customerIds)
                ->where('order_kind', 'customer')
                ->select('customer_id')
                ->selectRaw('MAX(order_date) as latest_order_date')
                ->groupBy('customer_id')
                ->get()->keyBy('customer_id')
            : collect();

        $lifetimeRevenueBatch = $customerIds->isNotEmpty()
            ? DB::table('orders')
                ->join('order_items', 'order_items.order_id', '=', 'orders.id')
                ->whereIn('orders.customer_id', $customerIds)
                ->where('orders.order_kind', 'customer')
                ->select('orders.customer_id')
                ->selectRaw('COALESCE(orders.currency, \'USD\') as currency')
                ->selectRaw('SUM(order_items.line_revenue) as revenue')
                ->groupBy('orders.customer_id', 'currency')
                ->get()->groupBy('customer_id')
            : collect();

        $now = now();
        $monthStart = $now->copy()->startOfMonth()->toDateString();
        $monthEnd = $now->copy()->endOfMonth()->toDateString();

        $totalCustomers = DB::table('customers')->whereNull('archived_at')->count();
        $newThisMonth = DB::table('customers')
            ->whereNull('archived_at')
            ->whereDate('created_at', '>=', $monthStart)
            ->whereDate('created_at', '<=', $monthEnd)
            ->count();
        $ordersThisMonth = DB::table('orders')
            ->where('order_kind', 'customer')
            ->whereNotNull('customer_id')
            ->whereDate('order_date', '>=', $monthStart)
            ->whereDate('order_date', '<=', $monthEnd)
            ->count();
        $revenueThisMonth = DB::table('orders')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.order_kind', 'customer')
            ->whereNotNull('orders.customer_id')
            ->whereDate('orders.order_date', '>=', $monthStart)
            ->whereDate('orders.order_date', '<=', $monthEnd)
            ->selectRaw('COALESCE(orders.currency, \'USD\') as currency')
            ->selectRaw('COALESCE(SUM(order_items.line_revenue), 0) as revenue')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(fn (object $row): array => ['currency' => $row->currency, 'revenue' => (float) $row->revenue])
            ->values()->all();

        return Inertia::render('Customers/Library/Index', [
            'customers' => $paginator->through(function (Customer $customer) use ($latestOrderDates, $lifetimeRevenueBatch): array {
                $data = $this->customerData($customer);
                $latestDate = $latestOrderDates->get($customer->id);
                $data['latest_order_date'] = $latestDate ? substr((string) $latestDate->latest_order_date, 0, 10) : null;
                $revRows = $lifetimeRevenueBatch->get($customer->id) ?? collect();
                $data['lifetime_revenue'] = $revRows->map(fn (object $r): array => [
                    'currency' => $r->currency,
                    'revenue' => (float) $r->revenue,
                ])->sortBy('currency')->values()->all();
                return $data;
            }),
            'filters' => $filters,
            'sources' => self::SOURCES,
            'stats' => [
                'total_customers' => $totalCustomers,
                'new_this_month' => $newThisMonth,
                'orders_this_month' => $ordersThisMonth,
                'revenue_this_month' => $revenueThisMonth,
                'rmb_estimate' => $this->fx->convertToCny($revenueThisMonth),
                'subscriber_count_available' => false,
                'open_support_tickets_available' => false,
            ],
            'canOperate' => (bool) $request->user()?->hasPermission('customers.operate'),
            'canImport' => (bool) ($request->user()?->hasPermission('customers.import') && $request->user()?->hasPermission('system.permissions.manage')),
            'canExport' => (bool) ($request->user()?->hasPermission('customers.export') && $request->user()?->hasPermission('system.permissions.manage')),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Customers/Library/Create', [
            'owners' => $this->owners(),
            'sources' => self::SOURCES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->normalizeEmailInput($request);
        $validated = $request->validate(array_merge($this->rules(), $this->addressRules('billing'), $this->addressRules('shipping')));
        $customer = DB::transaction(function () use ($validated, $request): Customer {
            $c = $this->customers->create(array_diff_key($validated, array_flip(['billing_address', 'shipping_address'])), $request->user());
            $this->syncAddresses($c, $validated);
            return $c;
        });
        return redirect()->route('customers.library.show', $customer)->with('success', '客户已创建');
    }

    public function show(Request $request, Customer $customer): Response
    {
        $customer->load(['owner', 'billingAddress', 'shippingAddress']);
        $summary = DB::table('orders')
            ->where('orders.customer_id', $customer->id)
            ->where('orders.order_kind', 'customer')
            ->selectRaw('COUNT(DISTINCT orders.id) as order_count')
            ->selectRaw('MIN(orders.order_date) as first_order_date')
            ->selectRaw('MAX(orders.order_date) as last_order_date')
            ->first();

        $revenueByCurrency = DB::table('orders')
            ->leftJoin('order_items', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.customer_id', $customer->id)
            ->where('orders.order_kind', 'customer')
            ->selectRaw('COALESCE(orders.currency, \'USD\') as currency')
            ->selectRaw('COUNT(DISTINCT orders.id) as order_count')
            ->selectRaw('COALESCE(SUM(order_items.line_revenue), 0) as revenue')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(fn (object $row): array => [
                'currency' => $row->currency,
                'revenue' => (float) $row->revenue,
                'average_order_value' => (int) $row->order_count > 0
                    ? round((float) $row->revenue / (int) $row->order_count, 2)
                    : 0,
                'order_count' => (int) $row->order_count,
            ])->values()->all();

        $recentOrders = Order::query()
            ->where('customer_id', $customer->id)
            ->where('order_kind', 'customer')
            ->withSum('items as revenue', 'line_revenue')
            ->latest('order_date')->latest('id')->limit(20)->get()
            ->map(fn (Order $order): array => [
                'id' => $order->id,
                'number' => $order->external_order_number ?: $order->internal_order_number,
                'order_date' => $order->order_date?->toDateString(),
                'status' => $order->order_status?->value ?? (string) $order->order_status,
                'revenue' => (float) ($order->revenue ?? 0),
                'currency' => $order->currency ?? 'USD',
            ])->all();

        $orderIds = Order::query()
            ->where('customer_id', $customer->id)
            ->where('order_kind', 'customer')
            ->pluck('id');

        $shipmentCount = $orderIds->isNotEmpty()
            ? Shipment::query()->whereIn('order_id', $orderIds)->count()
            : 0;

        $shipments = $orderIds->isNotEmpty()
            ? Shipment::query()
                ->whereIn('order_id', $orderIds)
                ->with('order')
                ->latest('shipped_at')->limit(20)->get()
                ->map(fn (Shipment $s): array => [
                    'id' => $s->id,
                    'order_number' => $s->order?->external_order_number ?: $s->order?->internal_order_number,
                    'order_id' => $s->order_id,
                    'carrier' => $s->carrier,
                    'tracking_number' => $s->tracking_number,
                    'shipped_at' => $s->shipped_at?->toDateString(),
                    'status' => $s->shipment_status,
                ])->all()
            : [];

        $recentOrderIds = collect($recentOrders)->pluck('id');
        $orderShipmentStatuses = $recentOrderIds->isNotEmpty()
            ? DB::table('shipments')
                ->whereIn('order_id', $recentOrderIds)
                ->orderBy('shipped_at', 'desc')
                ->get(['order_id', 'shipment_status'])
                ->unique('order_id')
                ->pluck('shipment_status', 'order_id')
            : collect();
        $recentOrders = collect($recentOrders)->map(function (array $order) use ($orderShipmentStatuses): array {
            $order['shipment_status'] = $orderShipmentStatuses->get($order['id']);
            return $order;
        })->all();

        $orderCount = (int) ($summary->order_count ?? 0);
        $customer->orders_count = $orderCount;

        $toDate = static fn (?string $v): ?string => $v !== null ? substr($v, 0, 10) : null;

        return Inertia::render('Customers/Library/Show', [
            'customer' => $this->customerData($customer),
            'billingAddress' => $this->addressData($customer->billingAddress),
            'shippingAddress' => $this->addressData($customer->shippingAddress),
            'metrics' => [
                'order_count' => $orderCount,
                'first_order_date' => $toDate($summary->first_order_date ?? null),
                'last_order_date' => $toDate($summary->last_order_date ?? null),
                'revenue_by_currency' => $revenueByCurrency,
                'shipment_count' => $shipmentCount,
            ],
            'recentOrders' => $recentOrders,
            'shipments' => $shipments,
            'audit' => AuditRecord::query()
                ->where('entity_type', Customer::class)->where('entity_id', (string) $customer->id)
                ->latest('created_at')->limit(30)->get()
                ->map(fn (AuditRecord $record): array => [
                    'action' => $record->action,
                    'actor_id' => $record->actor_id,
                    'created_at' => $record->created_at?->toIso8601String(),
                ])->all(),
            'canOperate' => (bool) $request->user()?->hasPermission('customers.operate'),
        ]);
    }

    public function edit(Customer $customer): Response
    {
        $customer->load(['billingAddress', 'shippingAddress']);
        return Inertia::render('Customers/Library/Edit', [
            'customer' => $this->customerData($customer),
            'billingAddress' => $this->addressData($customer->billingAddress),
            'shippingAddress' => $this->addressData($customer->shippingAddress),
            'owners' => $this->owners(),
            'sources' => self::SOURCES,
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->normalizeEmailInput($request);
        $validated = $request->validate(array_merge($this->rules($customer), $this->addressRules('billing'), $this->addressRules('shipping')));
        DB::transaction(function () use ($customer, $validated, $request): void {
            $this->customers->update($customer, array_diff_key($validated, array_flip(['billing_address', 'shipping_address'])), $request->user());
            $this->syncAddresses($customer, $validated);
        });
        return redirect()->route('customers.library.show', $customer)->with('success', '客户已更新');
    }

    public function archive(Request $request, Customer $customer): RedirectResponse
    {
        $this->customers->archive($customer, $request->user());
        return redirect()->route('customers.library')->with('success', '客户已归档');
    }

    public function restore(Request $request, Customer $customer): RedirectResponse
    {
        $this->customers->restore($customer, $request->user());
        return redirect()->route('customers.library.show', $customer)->with('success', '客户已恢复');
    }

    public function reservedImport(Request $request): never
    {
        abort(501, '客户导入功能（M1.8）尚未实现。');
    }

    public function reservedExport(Request $request): never
    {
        abort(501, '客户导出功能（M1.8）尚未实现。');
    }

    private function syncAddresses(Customer $customer, array $validated): void
    {
        foreach (['billing', 'shipping'] as $type) {
            $key = $type.'_address';
            if (! isset($validated[$key]) || ! is_array($validated[$key])) {
                continue;
            }
            $data = $validated[$key];
            $hasContent = collect($data)->filter(fn ($v): bool => $v !== null && $v !== '')->isNotEmpty();
            if ($hasContent) {
                CustomerAddress::query()->updateOrCreate(
                    ['customer_id' => $customer->id, 'address_type' => $type],
                    $data,
                );
            } else {
                CustomerAddress::query()
                    ->where('customer_id', $customer->id)
                    ->where('address_type', $type)
                    ->delete();
            }
        }
    }

    private function customerData(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'customer_number' => $customer->customer_number,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'country_code' => $customer->country_code,
            'language_code' => $customer->language_code,
            'source_type' => $customer->source_type,
            'source_label' => self::SOURCES[$customer->source_type] ?? $customer->source_type,
            'owner' => $customer->owner ? ['id' => $customer->owner->id, 'name' => $customer->owner->name] : null,
            'notes' => $customer->notes,
            'orders_count' => (int) ($customer->orders_count ?? 0),
            'archived_at' => $customer->archived_at?->toIso8601String(),
            'created_at' => $customer->created_at?->toIso8601String(),
            'updated_at' => $customer->updated_at?->toIso8601String(),
        ];
    }

    private function addressData(?CustomerAddress $address): ?array
    {
        if ($address === null) {
            return null;
        }
        return [
            'name' => $address->name,
            'company' => $address->company,
            'phone' => $address->phone,
            'country_code' => $address->country_code,
            'province_state' => $address->province_state,
            'city' => $address->city,
            'address_line_1' => $address->address_line_1,
            'address_line_2' => $address->address_line_2,
            'postal_code' => $address->postal_code,
        ];
    }

    private function rules(?Customer $customer = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('customers', 'email')->ignore($customer?->id)],
            'phone' => ['nullable', 'string', 'max:64'],
            'country_code' => ['nullable', 'string', 'max:8', $this->countryRule()],
            'language_code' => ['nullable', 'string', 'max:16'],
            'source_type' => ['required', Rule::in(array_keys(self::SOURCES))],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    private function addressRules(string $type): array
    {
        $prefix = $type.'_address';
        return [
            "{$prefix}" => ['nullable', 'array'],
            "{$prefix}.name" => ['nullable', 'string', 'max:255'],
            "{$prefix}.company" => ['nullable', 'string', 'max:255'],
            "{$prefix}.phone" => ['nullable', 'string', 'max:64'],
            "{$prefix}.country_code" => ['nullable', 'string', 'max:2'],
            "{$prefix}.province_state" => ['nullable', 'string', 'max:128'],
            "{$prefix}.city" => ['nullable', 'string', 'max:128'],
            "{$prefix}.address_line_1" => ['nullable', 'string', 'max:255'],
            "{$prefix}.address_line_2" => ['nullable', 'string', 'max:255'],
            "{$prefix}.postal_code" => ['nullable', 'string', 'max:32'],
        ];
    }

    private function countryRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') return;
            try { CountryCodeNormalizer::normalize($value); }
            catch (InvalidCompanyAttributeException) { $fail('国家代码需为两位字母。'); }
        };
    }

    private function normalizeEmailInput(Request $request): void
    {
        if (! $request->exists('email')) {
            return;
        }
        $email = trim((string) $request->input('email'));
        $request->merge(['email' => $email === '' ? null : strtolower($email)]);
    }

    private function owners(): array
    {
        return User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->toArray();
    }
}
