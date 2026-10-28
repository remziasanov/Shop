<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Demo\DemoImportBuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('shop:make-demo-import {--dir=examples : Directory for import-demo.xlsx and import-demo-photos.zip}')]
#[Description('Generate the demo import file (about 50 fictional products) and the ZIP archive with its photos')]
class MakeDemoImportCommand extends Command
{
    public function handle(DemoImportBuilder $builder): int
    {
        $dir = (string) $this->option('dir');
        // Absolute POSIX or Windows (C:\..., \\server) path, otherwise relative to the project.
        $dir = preg_match('#^([/\\\\]|[A-Za-z]:[/\\\\])#', $dir) === 1 ? $dir : base_path($dir);

        $result = $builder->build($dir);

        $this->components->info("Demo import written: {$result['xlsx']} ({$result['products']} products)");
        $this->components->info("Photos archive written: {$result['zip']} ({$result['photos']} photos)");

        return self::SUCCESS;
    }
}
