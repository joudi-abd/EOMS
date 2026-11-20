<?php

namespace App\Modules\Finance\Services\Reports;

use App\Support\CsvResponse;
use Generator;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `?format=csv` for every list report (spec §10: Excel export; Excel opens these UTF-8 CSV files). One flat row per
 * table line of the JSON report; consolidated figures get their own columns or rows marked with the base currency.
 */
class ReportCsv
{
    public function download(string $report, array $data): StreamedResponse
    {
        [$headers, $rows] = $this->table($report, $data);

        return CsvResponse::download($report.'-'.now()->format('Ymd-His').'.csv', $headers, $rows);
    }

    /** @return array{0: list<string>, 1: iterable<array>} */
    public function table(string $report, array $d): array
    {
        return match ($report) {
            'box-balances' => [['الصندوق', 'الفرع', 'العملة', 'الرصيد', 'المعادل بالدولار'], $this->each($d['boxes'], fn ($b) => array_map(
                fn ($r) => [$b['name'], $b['branch_id'], $r['code'], $r['balance'], $r['balance_base'] ?? null], $b['balances']))],
            'box-statement' => [['العملة', 'التاريخ', 'رقم القيد', 'رقم السند', 'نوع السند', 'البيان', 'وارد', 'صادر', 'الرصيد'], $this->each($d['currencies'], fn ($c) => [
                [$c['code'], null, null, null, null, 'رصيد افتتاحي', null, null, $c['opening']],
                ...array_map(fn ($l) => [$c['code'], $l['date'], $l['entry_number'], $l['voucher_number'], $l['voucher_type'], $l['description'], $l['debit'], $l['credit'], $l['balance']], $c['lines']),
                [$c['code'], null, null, null, null, 'المجموع والرصيد الختامي', $c['total_in'], $c['total_out'], $c['closing']]])],
            'journal' => [['التاريخ', 'رقم القيد', 'رقم السند', 'نوع السند', 'البيان', 'رمز الحساب', 'الحساب', 'العملة', 'مدين', 'دائن', 'الجهة', 'المشروع', 'ملاحظة'], $d['rows']],
            'general-ledger' => [['رمز الحساب', 'الحساب', 'العملة', 'التاريخ', 'رقم القيد', 'رقم السند', 'البيان', 'الجهة', 'مدين', 'دائن', 'الرصيد'], $this->each($d['accounts'],
                fn ($a) => $this->each($a['currencies'], fn ($c) => [
                    [$a['code'], $a['name'], $c['code'], null, null, null, 'رصيد افتتاحي', null, null, null, $c['opening']],
                    ...array_map(fn ($l) => [$a['code'], $a['name'], $c['code'], $l['date'], $l['entry_number'], $l['voucher_number'], $l['description'], $l['party'],
                        $l['debit'], $l['credit'], $l['balance']], $c['lines'])]))],
            'trial-balance' => [['العملة', 'رمز الحساب', 'الحساب', 'النوع', 'افتتاحي مدين', 'افتتاحي دائن', 'حركة مدين', 'حركة دائن', 'ختامي مدين', 'ختامي دائن'],
                [...$this->each($d['currencies'], fn ($c) => array_map(fn ($a) => [$c['code'], ...$this->tbRow($a)], $c['accounts'])),
                    ...array_map(fn ($a) => [($d['consolidation']['base_currency'] ?? 'USD').' (موحّد)', ...$this->tbRow($a)], $d['consolidated']['accounts'] ?? [])]],
            'income-statement' => [['العملة', 'القسم', 'رمز الحساب', 'الحساب', 'المبلغ'], $this->statementRows($d, ['revenue' => 'الإيرادات', 'expenses' => 'المصروفات'],
                ['net_profit' => 'صافي الربح'])],
            'balance-sheet' => [['العملة', 'القسم', 'رمز الحساب', 'الحساب', 'المبلغ'], $this->statementRows($d,
                ['assets' => 'الأصول', 'liabilities' => 'الخصوم', 'equity' => 'حقوق الملكية'], ['current_profit' => 'الربح حتى التاريخ', 'difference' => 'الفرق'])],
            'projects-summary' => [['الكود', 'المشروع', 'الحالة', 'العميل', 'نوع العقد', 'العملة', 'الدفعات', 'المصاريف', 'الباقي', 'النسبة', 'الأتعاب', 'الإيراد', 'الربح', 'رصيد العميل'],
                array_map(fn ($p) => [$p['code'], $p['name'], $p['status'], $p['client'], $p['contract_type'], $p['currency'], $p['receipts'], $p['costs'], $p['remaining'],
                    $p['fee_pct'], $p['fee'], $p['revenue'], $p['profit'], $p['client_balance']], $d['projects'])],
            'project-statement' => [['القسم', 'التصنيف', 'التاريخ', 'الرقم', 'الجهة أو الصندوق', 'البيان', 'المبلغ الأصلي', 'العملة الأصلية', 'المبلغ'], [
                ...array_map(fn ($l) => ['الوارد', null, $l['date'], $l['number'], $l['cash_box'], $l['description'], $l['original_amount'], $l['original_currency'], $l['amount']], $d['incoming']),
                ...$this->each($d['outgoing'], fn ($g) => array_map(fn ($l) => ['الصادر', $g['name'], $l['date'], $l['number'], $l['party'], $l['description'],
                    $l['original_amount'], $l['original_currency'], $l['amount']], $g['lines']))]],
            'budget-vs-actual' => [['كود المشروع', 'المشروع', 'التصنيف', 'العملة', 'الموازنة', 'الفعلي', 'المتبقي', 'النسبة', 'المستوى'],
                array_map(fn ($l) => [$l['project_code'], $l['project'], $l['category'], $l['currency'], $l['budget'], $l['actual'], $l['remaining'], $l['pct'], $l['level']], $d['lines'])],
            'project-profitability' => [['الترتيب', 'الكود', 'المشروع', 'نوع العقد', 'العملة', 'الإيراد', 'التكاليف المباشرة', 'التكاليف المحمّلة', 'الهامش الإجمالي', 'نسبة الهامش الإجمالي',
                'الهامش الصافي', 'نسبة الهامش الصافي', 'الهامش الصافي بالدولار'], array_map(fn ($p) => [$p['rank'], $p['code'], $p['name'], $p['contract_type'], $p['currency'],
                    $p['revenue'], $p['direct_costs'], $p['loaded_costs'], $p['gross_margin'], $p['gross_margin_pct'], $p['net_margin'], $p['net_margin_pct'], $p['net_margin_base']], $d['projects'])],
            'party-statement' => [['العملة', 'التاريخ', 'رقم القيد', 'رقم السند', 'الحساب', 'البيان', 'مدين', 'دائن', 'الرصيد', 'الجهة'], $this->each($d['currencies'], fn ($c) => [
                [$c['code'], null, null, null, null, 'رصيد افتتاحي', null, null, $c['opening'], $c['opening_side']],
                ...array_map(fn ($l) => [$c['code'], $l['date'], $l['entry_number'], $l['voucher_number'], $l['account_name'], $l['description'], $l['debit'], $l['credit'],
                    $l['balance'], $l['side']], $c['lines'])])],
            'contractor-balances' => [['المقاول', 'العملة', 'الاتفاقيات', 'صافي قيمة الاتفاقيات', 'المدفوع على الاتفاقيات', 'المدفوع بلا اتفاقية', 'المتبقي', 'السلف المفتوحة', 'ذمم المقاول'],
                array_map(fn ($r) => [$r['contractor'], $r['currency'], $r['agreements'], $r['net_value'], $r['paid_on_agreements'], $r['paid_without_agreement'], $r['remaining'],
                    $r['advances_outstanding'], $r['payables']], $d['contractors'])],
            'aging' => [['الجانب', 'الجهة', 'العملة', 'المستحق', '0–30', '31–60', '61–90', '+90', 'غير مخصص', 'أقدم تاريخ مفتوح'],
                array_map(fn ($r) => [$r['side'] === 'receivable' ? 'لنا' : 'علينا', $r['party'], $r['currency'], $r['outstanding'], $r['0_30'], $r['31_60'], $r['61_90'],
                    $r['90_plus'], $r['unapplied'], $r['oldest_open_date']], $d['rows'])],
            'employee-advances' => [['الموظف', 'العملة', 'عدد السلف', 'المتبقي', 'القسط القادم', 'شهره'],
                array_map(fn ($r) => [$r['employee_name'], $r['currency'], $r['advances'], $r['outstanding'], $r['next_amount'], $r['next_due_month']], $d['balances'])],
            'open-custodies' => [['صاحب العهدة', 'المشروع', 'العملة', 'تاريخ الفتح', 'المصروف', 'المسوّى', 'المردود', 'الرصيد', 'العمر بالأيام', 'متأخرة'],
                array_map(fn ($r) => [$r['holder_name'], $r['project_name'], $r['currency'], $r['opened_on'], $r['issued_total'], $r['settled_total'], $r['returned_total'],
                    $r['balance'], $r['age_days'], $r['overdue']], $d['custodies'])],
            'partners' => [['الشريك', 'العملة', 'الشهر', 'المسحوبات', 'الإيداعات', 'الرصيد'], $this->each($d['partners'], fn ($p) => [
                [$p['partner'], $p['currency'], 'افتتاحي', null, null, $p['opening']],
                ...array_map(fn ($m) => [$p['partner'], $p['currency'], $m['month'], $m['drawings'], $m['deposits'], $m['balance']], $p['months'])])],
            'expenses-by-category' => [['العملة', 'التصنيف', 'المستوى', 'مجموع فرعي', ...$d['months'], 'المجموع'], [
                ...$this->each($d['currencies'], fn ($c) => $this->expenseRows($c['code'], $c)),
                ...(isset($d['consolidated']) ? $this->expenseRows(($d['consolidation']['base_currency'] ?? 'USD').' (موحّد)', $d['consolidated']) : [])]],
            'payroll-cost' => [['الموظف', 'المشروع', 'البند', 'العملة', 'المبلغ'],
                array_map(fn ($l) => [$l['employee'], $l['project'], $l['component'], $l['currency'], $l['amount']], $d['lines'])],
            'exchanges' => [['التاريخ', 'الرقم', 'الحالة', 'من صندوق', 'من عملة', 'المبلغ', 'إلى صندوق', 'إلى عملة', 'المقابل', 'سعر الصفقة', 'سعر النشرة', 'الفرق', 'الفرق %', 'الأثر بالدولار'],
                array_map(fn ($e) => [$e['date'], $e['number'], $e['status'], $e['from_box'], $e['from_currency'], $e['amount'], $e['to_box'], $e['to_currency'], $e['counter_amount'],
                    $e['deal_rate'], $e['bulletin_rate'], $e['difference'], $e['difference_pct'], $e['result_base']], $d['exchanges'])],
            'cash-counts' => [['التاريخ', 'الصندوق', 'الحالة', 'العملة', 'رصيد النظام', 'المعدود', 'الفرق', 'التوضيح'], $this->each($d['counts'], fn ($c) => array_map(
                fn ($l) => [$c['count_date'], $c['box'], $c['status'], $l['currency'], $l['system_balance'], $l['counted_amount'], $l['difference'], $l['explanation']], $c['lines']))],
            'audit-log' => [['#', 'الوقت', 'المستخدم', 'العملية', 'نوع السجل', 'رقم السجل', 'IP'], array_map(fn ($a) => [$a['id'], $a['created_at'], $a['user_id'], $a['action'],
                $a['auditable_type'], $a['auditable_id'], $a['ip_address'] ?? null], $d['items'])],
            default => throw new InvalidArgumentException("Unknown report $report"),
        };
    }

    /** Flattens: the rows `$fn` returns for each item. */
    private function each(array $items, callable $fn): Generator
    {
        foreach ($items as $item) {
            yield from $fn($item);
        }
    }

    private function tbRow(array $a): array
    {
        return [$a['code'], $a['name'], $a['type'], $a['opening_debit'], $a['opening_credit'], $a['debit'], $a['credit'], $a['closing_debit'], $a['closing_credit']];
    }

    /** Statement sections per currency block, then the consolidated block. */
    private function statementRows(array $d, array $sections, array $totals): array
    {
        $blocks = array_map(fn ($c) => [$c['code'], $c], $d['currencies']);
        if (isset($d['consolidated'])) {
            $blocks[] = [($d['consolidation']['base_currency'] ?? 'USD').' (موحّد)', $d['consolidated']];
        }
        $rows = [];
        foreach ($blocks as [$code, $block]) {
            foreach ($sections as $key => $label) {
                foreach ($block[$key] as $line) {
                    $rows[] = [$code, $label, $line['code'], $line['name'], $line['amount']];
                }
            }
            foreach ($totals as $key => $label) {
                $rows[] = [$code, $label, null, null, $block[$key]];
            }
        }

        return $rows;
    }

    private function expenseRows(string $code, array $block): array
    {
        $rows = [];
        $walk = function (array $nodes) use (&$walk, &$rows, $code) {
            foreach ($nodes as $n) {
                $rows[] = [$code, str_repeat('— ', $n['depth']).$n['name'], $n['depth'], $n['is_subtotal'], ...array_values($n['months']), $n['total']];
                $walk($n['children']);
            }
        };
        $walk($block['categories']);
        foreach ($block['uncategorized'] as $u) {
            $rows[] = [$code, 'بلا تصنيف: '.$u['name'], 0, false, ...array_values($u['months']), $u['total']];
        }
        $rows[] = [$code, 'المجموع', null, true, ...array_values($block['month_totals']), $block['total']];

        return $rows;
    }
}
