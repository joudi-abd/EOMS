<?php

namespace App\Modules\Finance\Services\Core;

use App\Modules\Settings\Services\SettingService;
use Illuminate\Support\Facades\DB;

/**
 * Gapless numbers (spec §11), taken inside the caller's transaction with the sequence row locked: a rolled-back
 * posting gives its number back. Yearly documents: `{P}-{YYYY}-{000000}` per type and year; project codes are plain
 * numbers from `finance.project_code_start`; asset codes `AS-0001`.
 */
class Sequences
{
    public const PREFIXES = [
        'journal_entry' => 'JE', 'receipt' => 'RV', 'payment' => 'PV', 'transfer' => 'TR', 'exchange' => 'FX', 'expense' => 'EX',
        'project_billing' => 'PB', 'custody_issue' => 'CU', 'custody_settlement' => 'CS', 'custody_return' => 'CR',
        'advance_issue' => 'AI', 'asset_purchase' => 'AP', 'asset_maintenance' => 'AM', 'asset_disposal' => 'AD',
        'count_adjustment' => 'CA', 'opening_balance' => 'OB', 'allocation' => 'AL', 'manual_journal' => 'MJ',
        'payroll_accrual' => 'PR', 'payroll_payment' => 'SP', 'asset' => 'AS', 'project' => '',
    ];

    public function __construct(private SettingService $settings) {}

    public function next(string $docType, string $date, int $branchId = 0): string
    {
        $prefix = self::PREFIXES[$docType] ?? strtoupper(substr($docType, 0, 2));
        $yearly = ! in_array($docType, ['project', 'asset'], true);
        $year = $yearly ? (int) substr($date, 0, 4) : 0;
        $start = $docType === 'project' ? max(1, (int) $this->settings->get('finance.project_code_start', '100100')) : 1;
        $keys = ['doc_type' => $docType, 'year' => $year, 'branch_id' => $branchId];
        DB::table('fin_sequences')->insertOrIgnore($keys + ['prefix' => $prefix, 'next_number' => $start, 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('fin_sequences')->where($keys)->lockForUpdate()->first();
        $number = (int) $row->next_number;
        DB::table('fin_sequences')->where('id', $row->id)->update(['next_number' => $number + 1, 'updated_at' => now()]);

        return match (true) {
            $docType === 'project' => (string) $number,
            $docType === 'asset' => sprintf('%s-%04d', $row->prefix, $number),
            default => sprintf('%s-%04d-%06d', $row->prefix, $year, $number),
        };
    }
}
