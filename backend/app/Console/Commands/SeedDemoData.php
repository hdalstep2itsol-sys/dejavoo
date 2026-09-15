<?php

namespace App\Console\Commands;

use App\Services\DemoDataService;
use Illuminate\Console\Command;
use Throwable;

class SeedDemoData extends Command
{
    protected $signature = 'dejavoo:seed-demo
        {--reset : Remove and rebuild only the known deterministic demo dataset}';

    protected $description = 'Prepare deterministic users and operational demo data';

    public function handle(DemoDataService $demoData): int
    {
        $password = (string) config('demo.user_password');
        if (mb_strlen($password) < 12) {
            $this->error('DEV_TEST_USER_PASSWORD is required and must contain at least 12 characters.');

            return self::FAILURE;
        }

        try {
            $counts = $demoData->seed($password, (bool) $this->option('reset'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($this->option('reset')
            ? 'The demo dataset was reset and rebuilt.'
            : 'The demo dataset is ready. Existing demo records were reused.');

        foreach ($counts as $label => $count) {
            $this->line(sprintf('  %-25s %d', $label, $count));
        }

        $this->newLine();
        $this->comment('All demo users use DEV_TEST_USER_PASSWORD. No provider credentials were created.');

        return self::SUCCESS;
    }
}
