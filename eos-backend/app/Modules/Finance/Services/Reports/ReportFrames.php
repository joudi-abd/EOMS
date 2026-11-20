<?php

namespace App\Modules\Finance\Services\Reports;

use App\Modules\Finance\Services\Core\FinanceScope;
use App\Modules\Identity\Models\User;
use App\Modules\Settings\Models\Currency;
use App\Modules\Settings\Services\ExchangeRateService;
use App\Modules\Settings\Services\SettingService;
use Illuminate\Validation\ValidationException;

/**
 * Builds the ReportFrame of a request: validation rules shared by every report, the branch reach of the report's
 * permission (403 when the requested branch is outside it), the default period (this month up to today) and the
 * consolidation rate mode (`finance.consolidated_report_rate_mode`, overridable per request with `rate_mode`).
 */
class ReportFrames
{
    /** Validation rules of the common filters. */
    public const RULES = [
        'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:sys_branches,id'],
        'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        'as_of' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        'currency' => ['sometimes', 'nullable', 'string', 'max:3'],
        'currency_id' => ['sometimes', 'nullable', 'integer', 'exists:sys_currencies,id'],
        'view' => ['sometimes', 'nullable', 'in:separate,consolidated'],
        'rate_mode' => ['sometimes', 'nullable', 'in:report_date,historical'],
        'format' => ['sometimes', 'nullable', 'in:json,csv'],
    ];

    public function __construct(private FinanceScope $scope, private ExchangeRateService $rates, private SettingService $settings) {}

    /**
     * @param  array<string, mixed>  $input  validated with RULES
     * @param  string|null  $permission  whose branch reach bounds the report; null = no branch reach (project reports are
     *                                   bounded by project visibility instead, and `branch_id` is a plain filter)
     * @param  bool  $period  true: `from`–`to` (from defaults to the first of the month); false: one day (`as_of`/`to`)
     */
    public function make(array $input, User $user, ?string $permission = 'finance.reports', bool $period = true): ReportFrame
    {
        $allowed = $permission === null ? null : $this->scope->branchIds($user, $permission);
        $branchId = isset($input['branch_id']) ? (int) $input['branch_id'] : null;
        if ($branchId !== null) {
            abort_unless($allowed === null || in_array($branchId, $allowed, true), 403, 'لا تملك صلاحية على هذا الفرع.');
        }
        $branchIds = $branchId !== null ? [$branchId] : $allowed;
        $to = $input['as_of'] ?? $input['to'] ?? now()->toDateString();
        $from = $period ? ($input['from'] ?? substr($to, 0, 8).'01') : null;
        if ($from !== null && $from > $to) {
            throw ValidationException::withMessages(['from' => ['بداية الفترة بعد نهايتها.']]);
        }
        $consolidated = ($input['view'] ?? 'separate') === 'consolidated';

        return new ReportFrame($branchIds, $branchId, $from, $to, $this->currency($input), $consolidated,
            $consolidated ? $this->consolidator($to, $input['rate_mode'] ?? null) : null);
    }

    /** A consolidator for the day, in the requested mode or the company setting. */
    public function consolidator(string $date, ?string $mode = null): Consolidator
    {
        $mode ??= (string) $this->settings->get('finance.consolidated_report_rate_mode', 'report_date');

        return new Consolidator($this->rates, in_array($mode, Consolidator::MODES, true) ? $mode : 'report_date', $date);
    }

    private function currency(array $input): ?Currency
    {
        if (! empty($input['currency_id'])) {
            return Currency::query()->findOrFail((int) $input['currency_id']);
        }
        if (! empty($input['currency'])) {
            return Currency::query()->where('code', strtoupper((string) $input['currency']))->first()
                ?? throw ValidationException::withMessages(['currency' => ['العملة غير موجودة.']]);
        }

        return null;
    }
}
