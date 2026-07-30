<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class ErpShellController extends Controller
{
    public function show(?string $module = null, ?string $path = null): View
    {
        $navigation = config('erp_navigation');
        $module ??= 'logistics';
        abort_unless(isset($navigation[$module]), 404);
        $currentModule = $navigation[$module];
        $currentPath = request()->path();
        $page = $this->findPage($currentModule['children'], $currentPath) ?? reset($currentModule['children']);

        return view('erp.shell', compact('navigation', 'module', 'currentModule', 'page', 'currentPath'));
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
