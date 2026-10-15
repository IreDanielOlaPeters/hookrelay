<?php

namespace App\Console\Commands;

use App\Support\ProductionGuard;
use Illuminate\Console\Command;

class RelayPreflight extends Command
{
    protected $signature = 'relay:preflight';

    protected $description = 'Refuse to start in production while secrets still use development defaults';

    public function handle(): int
    {
        $violations = ProductionGuard::current();
        if ($violations === []) {
            return self::SUCCESS;
        }

        $this->error('Refusing to start in production: these settings still use development defaults or are empty: '.implode(', ', $violations));
        $this->line('Set them in .env (see .env.example). APP_KEY: php artisan key:generate --show');

        return self::FAILURE;
    }
}
