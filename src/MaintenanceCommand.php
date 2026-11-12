<?php

declare(strict_types=1);

namespace FormGuard\Laravel;

use FormGuard\Guard;
use Illuminate\Console\Command;

final class MaintenanceCommand extends Command
{
    protected $signature = 'form-guard:check {--prune : Remove expired limiter records and action reservations}';
    protected $description = 'Check Form Guard configuration and storage; optionally prune expired records.';

    public function handle(Guard $guard, ClaimStore $claims): int
    {
        try {
            $guard->runtimeDir();
            if ($this->option('prune')) {
                $this->info('Pruned ' . ($guard->prune() + $claims->prune()) . ' expired records.');
            } else {
                $guard->describePlugins();
                $this->info('Form Guard configuration and private storage are available.');
            }
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Form Guard is unavailable. Check private storage and configuration.');
            return self::FAILURE;
        }
    }
}
