<?php

namespace App\Modules\Payroll\Services;

use App\Modules\HR\Models\Employee;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AuditLogger;
use App\Modules\Payroll\Models\PayExport;
use App\Modules\Payroll\Models\PayRun;
use App\Modules\Payroll\Models\PayRunLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bulk salary transfer through Sham Cash: finance produces the transfer file for an approved run (random DocId,
 * one row per unpaid employee), uploads it to Sham Cash, then confirms it here, which records every payment
 * from the chosen box. A line sits in at most one open file, so the same salary is never sent twice.
 */
class ShamCashService
{
    /** Whole-transaction retries on deadlocks and serialization conflicts (MariaDB 1020, PostgreSQL 40001/40P01). */
    private const TX_ATTEMPTS = 3;

    public function __construct(private PayRunService $runs, private ShamCashFile $file, private AuditLogger $audit) {}

    /**
     * @param  list<int>|null  $lineIds  explicit lines (any contract currency, converted); default: unpaid lines whose contract currency is the file currency
     */
    public function export(PayRun $run, string $currency, string $date, ?array $lineIds, bool $skipMissing, User $actor): PayExport
    {
        return DB::transaction(function () use ($run, $currency, $date, $lineIds, $skipMissing, $actor) {
            // Lock order shared with individual pay and confirm: run → open exports → lines.
            $run = PayRun::query()->lockForUpdate()->findOrFail($run->id);
            abort_unless($run->status === 'approved', 409, 'يُصدَّر ملف الشام كاش بعد اعتماد الكشف.');
            PayExport::query()->where('pay_run_id', $run->id)->whereNull('cancelled_at')->whereNull('confirmed_at')
                ->orderBy('id')->lockForUpdate()->get();
            $lines = $this->payable($run, $currency, $lineIds);
            abort_if($lines->isEmpty(), 422, 'لا يوجد موظفون مستحقون غير مدفوعين لهذا الملف.');
            $lines = PayRunLine::query()->whereIn('id', $lines->pluck('id'))->orderBy('id')->lockForUpdate()->get();
            // Under the locks, drop anyone paid or reserved since the first read.
            $lines = $this->payable($run, $currency, $lines->pluck('id')->all());
            abort_if($lines->isEmpty(), 422, 'لا يوجد موظفون مستحقون غير مدفوعين لهذا الملف.');
            $employees = Employee::query()->whereIn('id', $lines->pluck('employee_id'))->get()->keyBy('id');
            $rows = [];
            $missing = [];
            foreach ($lines as $line) {
                $employee = $employees->get($line->employee_id);
                $wallet = preg_replace('/\s+/', '', (string) $employee?->sham_cash_wallet);
                if (! preg_match('/^\d{16}$/', $wallet)) {
                    $missing[] = $employee?->full_name ?? '#'.$line->employee_id;

                    continue;
                }
                $quote = $this->runs->quote($line, $currency, $date);
                if ($quote['paid'] <= 0) {
                    continue;
                }
                $rows[] = ['line_id' => $line->id, 'employee_id' => $line->employee_id, 'name' => $employee->full_name,
                    'wallet' => $wallet, 'amount' => $quote['paid']];
            }
            if ($missing !== [] && ! $skipMissing) {
                throw ValidationException::withMessages(['wallets' => array_map(fn ($n) => $n.': محفظة شام كاش غير مسجّلة أو ليست 16 رقماً.', $missing)]);
            }
            abort_if($rows === [], 422, 'لا يوجد موظف بمحفظة شام كاش صالحة في هذا الملف.');
            abort_if(count($rows) > ShamCashFile::MAX_ROWS, 422, 'الملف يتسع لـ '.ShamCashFile::MAX_ROWS.' موظف؛ حدّد الموظفين على دفعات.');

            $export = PayExport::create([
                'pay_run_id' => $run->id, 'currency_code' => $currency, 'doc_id' => $this->docId(), 'pay_date' => $date,
                'line_ids' => array_column($rows, 'line_id'), 'rows' => $rows, 'count' => count($rows),
                'total' => round(array_sum(array_column($rows, 'amount')), 2), 'note' => 'رواتب '.$run->month, 'created_by' => $actor->id,
            ]);
            $this->audit->log('payroll.shamcash_exported', $export, null, ['doc_id' => $export->doc_id, 'count' => $export->count,
                'total' => $export->total, 'skipped' => $missing], $actor->id);

            return $export;
        }, self::TX_ATTEMPTS);
    }

    /** Path of the .xlsx for an export (rebuilt from the stored rows, so a re-download is identical). */
    public function file(PayExport $export): string
    {
        return $this->file->build(array_map(fn (array $r) => ['name' => $r['name'], 'wallet' => $r['wallet'],
            'amount' => (float) $r['amount'], 'note' => $export->note], $export->rows), $export->currency_code, $export->doc_id, $export->note);
    }

    /** Sham Cash executed the file: every reserved row becomes a recorded payment from the box, all or nothing. */
    public function confirm(PayExport $export, int $boxId, User $actor): PayExport
    {
        return DB::transaction(function () use ($export, $boxId, $actor) {
            // run → open export → lines (same order as export and individual pay).
            PayRun::query()->lockForUpdate()->findOrFail($export->pay_run_id);
            $export = PayExport::query()->lockForUpdate()->findOrFail($export->id);
            abort_if($export->confirmed_at !== null, 409, 'الملف مؤكَّد مسبقاً.');
            abort_if($export->cancelled_at !== null, 409, 'الملف ملغى.');
            $lineIds = collect($export->line_ids)->map(fn ($id) => (int) $id)->sort()->values()->all();
            PayRunLine::query()->whereIn('id', $lineIds)->orderBy('id')->lockForUpdate()->get();
            foreach ($export->rows as $row) {
                abort_unless(in_array((int) $row['line_id'], $lineIds, true), 409, 'تأكيد الملف يدفع فقط سطوره المحجوزة في هذا الملف.');
                $line = PayRunLine::query()->findOrFail($row['line_id']);
                $this->runs->recordPayment($line, ['currency' => $export->currency_code, 'method' => 'sham_cash', 'box_id' => $boxId,
                    'paid_at' => $export->pay_date->format('Y-m-d'), 'reference' => $export->doc_id, 'expected_amount' => $row['amount'],
                    'wallet' => $row['wallet'], 'from_export_id' => $export->id, 'note' => 'ملف شام كاش '.$export->doc_id], $actor);
            }
            $export->update(['confirmed_at' => now(), 'confirmed_by' => $actor->id, 'box_id' => $boxId]);
            $this->audit->log('payroll.shamcash_confirmed', $export, null, ['doc_id' => $export->doc_id, 'box_id' => $boxId], $actor->id);

            return $export->refresh();
        }, self::TX_ATTEMPTS);
    }

    /** A file that was never sent to Sham Cash: its employees become available for a new file or individual pay. */
    public function cancel(PayExport $export, User $actor): PayExport
    {
        return DB::transaction(function () use ($export, $actor) {
            PayRun::query()->lockForUpdate()->findOrFail($export->pay_run_id);
            $export = PayExport::query()->lockForUpdate()->findOrFail($export->id);
            abort_if($export->confirmed_at !== null, 409, 'الملف مؤكَّد ودُفعت رواتبه؛ ألغِ الدفعات بشكل فردي.');
            abort_if($export->cancelled_at !== null, 409, 'الملف ملغى مسبقاً.');
            $export->update(['cancelled_at' => now()]);
            $this->audit->log('payroll.shamcash_cancelled', $export, null, ['doc_id' => $export->doc_id], $actor->id);

            return $export->refresh();
        }, self::TX_ATTEMPTS);
    }

    /** Approved-run lines with a positive net, no active payment and not already in an open file. */
    private function payable(PayRun $run, string $currency, ?array $lineIds): Collection
    {
        $open = PayExport::query()->where('pay_run_id', $run->id)->whereNull('cancelled_at')->whereNull('confirmed_at')
            ->pluck('line_ids')->flatten()->map(fn ($id) => (int) $id)->all();
        $query = PayRunLine::query()->where('pay_run_id', $run->id)->where('net', '>', 0)->whereDoesntHave('payment')
            ->whereNotIn('id', $open)->orderBy('id');
        if ($lineIds !== null) {
            $query->whereIn('id', $lineIds);
            $found = $query->pluck('id')->all();
            $invalid = array_diff($lineIds, $found);
            if ($invalid !== []) {
                throw ValidationException::withMessages(['line_ids' => ['بنود غير قابلة للدفع (مدفوعة، أو في ملف مفتوح، أو صافيها صفر، أو من كشف آخر): '.implode(', ', $invalid)]]);
            }

            return $query->get();
        }

        return $query->where('currency_code', $currency)->get();
    }

    /** Random 8-digit DocId, unique across all files. */
    private function docId(): string
    {
        do {
            $id = (string) random_int(10000000, 99999999);
        } while (PayExport::query()->where('doc_id', $id)->exists());

        return $id;
    }
}
