<?php

namespace App\Modules\Identity\Application;

enum AuthorizationKeys: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Manager = 'manager';
    case Operator = 'operator';
    case DashboardView = 'dashboard.view';
    case PrototypeView = 'prototype.view';
    case FoundationView = 'architecture.foundation.view';
    case AuditView = 'audit.view';
    case PermissionsManage = 'system.permissions.manage';
    case LogisticsView = 'logistics.view';
    case SyncView = 'sync.view';
    case CustomersView = 'customers.view';
    case KolView = 'kol.view';
    case InventoryView = 'inventory.view';
    case ProcurementView = 'procurement.view';
    case ProductsView = 'products.view';
    case GuidesView = 'guides.view';
    case AccountingView = 'accounting.view';
}
