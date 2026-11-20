<?php

namespace App\Modules\Finance\Console;

use App\Modules\Finance\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes fin_account_balances from the journal lines (the source of truth), reports every difference with the
 * live table, and rewrites the table unless --dry-run. Exit code 1 when differences were found.
 */
class RebuildBalances extends Command
{
    protected $signature = 'finance:rebuild-balances {--dry-run : Report differences without rewriting the table}';

    protected $description = 'Recompute account balances from journal lines and report differences';

    public function handle(): int
    {
        $computed = [];
        DB::table('fin_journal_lines')->selectRaw('account_id, currency_id, COALESCE(party_id, 0) as party_key, SUM(debit) as d, SUM(credit) as c')
            ->groupBy('account_id', 'currency_id', DB::raw('COALESCE(party_id, 0)'))->orderBy('account_id')->get()
            ->each(function ($row) use (&$computed) {
                $computed[$row->account_id.'|'.$row->currency_id.'|'.$row->party_key] = Money::round(Money::sub(Money::of($row->d), Money::of($row->c)), Money::SCALE);
            });
        $live = [];
        foreach (DB::table('fin_account_balances')->get() as $row) {
            $live[$row->account_id.'|'.$row->currency_id.'|'.$row->party_key] = Money::round(Money::of($row->balance), Money::SCALE);
        }

        $differences = [];
        foreach (array_unique([...array_keys($computed), ...array_keys($live)]) as $key) {
            $expected = $computed[$key] ?? '0';
            $actual = $live[$key] ?? '0';
            if (Money::cmp($expected, $actual) !== 0) {
                [$account, $currency, $party] = explode('|', $key);
                $differences[] = [$account, $currency, $party, $actual, $expected];
            }
        }

        if ($differences === []) {
            $this->info('Balances match the journal ('.count($computed).' rows).');

            return self::SUCCESS;
        }
        $this->table(['account_id', 'currency_id', 'party_key', 'live', 'from lines'], $differences);
        if (! $this->option('dry-run')) {
            DB::transaction(function () use ($computed) {
                DB::table('fin_account_balances')->delete();
                foreach (array_chunk(array_keys($computed), 500) as $chunk) {
                    DB::table('fin_account_balances')->insert(array_map(function (string $key) use ($computed) {
                        [$account, $currency, $party] = explode('|', $key);

                        return ['account_id' => (int) $account, 'currency_id' => (int) $currency, 'party_key' => (int) $party,
                            'balance' => $computed[$key], 'updated_at' => now()];
                    }, $chunk));
                }
            });
            $this->warn(count($differences).' difference(s) found; balances rebuilt from the journal.');
        } else {
            $this->warn(count($differences).' difference(s) found (dry run, nothing written).');
        }

        return self::FAILURE;
    }
}
