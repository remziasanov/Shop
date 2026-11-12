<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Export\VariantsExampleBuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('shop:make-variants-example {--path=examples/import-variants.xlsx : Where to write the example}')]
#[Description('Generate the example import file with a product with variants (a T-shirt, Цвет × Размер) and a plain product')]
class MakeVariantsExampleCommand extends Command
{
    public function handle(VariantsExampleBuilder $builder): int
    {
        $path = (string) $this->option('path');
        // Absolute POSIX or Windows (C:\..., \\server) path, otherwise relative to the project.
        $path = preg_match('#^([/\\\\]|[A-Za-z]:[/\\\\])#', $path) === 1 ? $path : base_path($path);

        $dir = dirname($path);

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->components->error("Cannot create directory {$dir}.");

            return self::FAILURE;
        }

        $builder->build($path);

        $this->components->info("Variants example written: {$path}");

        return self::SUCCESS;
    }
}
