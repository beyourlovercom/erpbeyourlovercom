<?php

namespace App\Support;

enum AuthorizationKeys: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Manager = 'manager';
    case Operator = 'operator';
    case DashboardView = 'dashboard.view';
    case PrototypeView = 'prototype.view';
    case FoundationView = 'architecture.foundation.view';
    case PermissionsManage = 'system.permissions.manage';
}
