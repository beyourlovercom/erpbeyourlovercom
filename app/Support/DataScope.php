<?php

namespace App\Support;

enum DataScope: string
{
    case Own = 'own';
    case Assigned = 'assigned';
    case Team = 'team';
    case All = 'all';
}
