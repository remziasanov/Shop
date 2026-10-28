<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditLog\Pages;

use App\Filament\Resources\AuditLog\AuditLogResource;
use Filament\Resources\Pages\ListRecords;

class ListAuditLog extends ListRecords
{
    protected static string $resource = AuditLogResource::class;
}
