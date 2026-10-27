<?php

namespace App\Modules\Settings\Console;

use App\Modules\Settings\Models\ApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateApiClientCommand extends Command
{
    protected $signature = 'api-client:create {name} {--scope=* : الصلاحيات الممنوحة لهذا العميل}';

    protected $description = 'إنشاء عميل API وعرض توكنه مرة واحدة';

    public function handle(): int
    {
        $plain = Str::random(64);

        ApiClient::create([
            'name' => $this->argument('name'),
            'token_hash' => hash('sha256', $plain),
            'scopes' => $this->option('scope'),
            'is_active' => true,
        ]);

        $this->info('التوكن (يظهر مرة واحدة فقط، احفظه بأمان):');
        $this->line($plain);

        return self::SUCCESS;
    }
}
