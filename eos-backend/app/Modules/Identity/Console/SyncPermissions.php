<?php

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Access\AccessSync;
use Illuminate\Console\Command;

class SyncPermissions extends Command
{
    protected $signature = 'permissions:sync {--reset-defaults : Put every shipped role back to its code definition}';

    protected $description = 'Write the permission catalogue and the shipped roles to the database (run on every deploy)';

    public function handle(AccessSync $sync): int
    {
        if ($this->option('reset-defaults') && ! $this->confirm('This replaces the grants of every shipped role. Continue?', ! $this->input->isInteractive())) {
            return self::FAILURE;
        }
        $result = $sync->run((bool) $this->option('reset-defaults'));
        $this->info('Permissions created: '.(implode(', ', $result['created']) ?: '—'));
        $this->info('Permissions removed: '.(implode(', ', $result['removed']) ?: '—'));
        $this->info('Roles written: '.(implode(', ', $result['roles']) ?: '—'));

        return self::SUCCESS;
    }
}
