<?php

namespace App\Modules\Backup\Application\Contracts;

interface RestoreMigrationRunner
{
    public function run(): void;
}
