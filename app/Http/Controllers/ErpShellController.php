<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class ErpShellController extends Controller
{
    public function showOrderList(): View
    {
        return $this->show('kol', 'kol/orders');
    }

    public function showOrderDetail(string $orderNumber): View
    {
        abort_unless($orderNumber === config('erp_orders.detail.order_number'), 404);

        return $this->show('kol', 'kol/orders/'.$orderNumber);
    }

    public function show(?string $module = null, ?string $path = null): View
    {
        $navigation = config('erp_navigation');
        $module ??= 'logistics';
        abort_unless(isset($navigation[$module]), 404);
        $currentModule = $navigation[$module];
        $currentPath = request()->path();
        $page = $this->findPage($currentModule['children'], $currentPath) ?? reset($currentModule['children']);

        $orders = config('erp_orders.orders');
        $order = config('erp_orders.detail');
        return view('erp.shell', compact('navigation', 'module', 'currentModule', 'page', 'currentPath', 'orders', 'order'));
    }

    private function findPage(array $items, string $currentPath): ?array
    {
        foreach ($items as $item) {
            if (!empty($item['path']) && trim($item['path'], '/') === $currentPath) return $item;
            if (!empty($item['children']) && ($page = $this->findPage($item['children'], $currentPath))) return $page;
        }
        return null;
    }
}
