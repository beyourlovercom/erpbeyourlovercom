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
    case CustomersOperate = 'customers.operate';
    case CustomersImport = 'customers.import';
    case CustomersExport = 'customers.export';
    case B2BView = 'b2b.view';
    case B2BOperate = 'b2b.operate';
    case B2BAssign = 'b2b.assign';
    case B2BViewAll = 'b2b.view_all';
    case B2BExport = 'b2b.export';
    case B2BQuotesManage = 'b2b.quotes_manage';
    case B2BChannelsManage = 'b2b.channels_manage';
    case B2BTradeApprove = 'b2b.trade.approve';
    case B2BReceiptsReview = 'b2b.receipts.review';
    case KolView = 'kol.view';
    case KolOperate = 'kol.operate';
    case KolViewsManage = 'kol.views.manage';
    case KolApprovalsManage = 'kol.approvals.manage';
    case KolAffiliatesManage = 'kol.affiliates.manage';
    case KolPayoutsRequest = 'kol.payouts.request';
    case KolPayoutsApprove = 'kol.payouts.approve';
    case KolPayoutsManage = 'kol.payouts.manage';
    case InventoryView = 'inventory.view';
    case InventoryOperate = 'inventory.operate';
    case InventoryWarehousesManage = 'inventory.warehouses.manage';
    case InventoryPackagingSubmit = 'inventory.packaging.submit';
    case InventoryPackagingReview = 'inventory.packaging.review';
    case ProcurementView = 'procurement.view';
    case ProcurementCreate = 'procurement.create';
    case ProcurementOperate = 'procurement.operate';
    case ProductsView = 'products.view';
    case ProductsOperate = 'products.operate';
    case GuidesView = 'guides.view';
    case GuidesManage = 'guides.manage';
    case AccountingView = 'accounting.view';
    case FinanceB2BCashConfirm = 'finance.b2b_cash_confirm';
    case OrdersView = 'orders.view';
    case OrdersCreate = 'orders.create';
    case OrdersEdit = 'orders.edit';
    case LogisticsOperate = 'logistics.operate';
    case SyncOperate = 'sync.operate';
    case AnalysisView = 'analysis.view';
    case MarketingView = 'marketing.view';
    case MarketingOperate = 'marketing.operate';
    case BackupView = 'backup.view';
    case BackupCreate = 'backup.create';
    case BackupDownload = 'backup.download';
    case BackupPublishGithub = 'backup.publish_github';
    case BackupDelete = 'backup.delete';
    case BackupRestore = 'backup.restore';
    case BackupManage = 'backup.manage';
}
