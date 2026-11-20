import { CopyKey } from '../../../core/copy';
import {
  AdvancesReport,
  AgingReport,
  AssetsReport,
  AuditRow,
  BalanceSheetReport,
  BoxBalancesReport,
  BoxStatementReport,
  BudgetReport,
  CategoryNode,
  ContractorsReport,
  CustodiesReport,
  ExpensesBlock,
  ExpensesReport,
  IncomeBlock,
  IncomeReport,
  JournalReport,
  LedgerReport,
  PartnersReport,
  PartyStatementReport,
  PayrollReport,
  PayrollRow,
  ProfitabilityReport,
  ProjectsSummaryReport,
  ProjectStatementReport,
  RateUsed,
  ReportHead,
  SheetBlock,
  StatementLine,
  TrialBlock,
  TrialBalanceReport,
} from '../../../data/finance-reports';
import { Money } from '../../../data/finance';

/**
 * Turns each report's response into the same simple shape: a strip of headline figures and a list of titled tables,
 * one per currency (amounts of different currencies never share a summed column). The page renders this shape on
 * screen and hands the very same tables to the PDF document, so both always agree.
 */

export interface Cell {
  text: string;
  /** A figure: tabular, never wraps. */
  num?: boolean;
  /** A negative figure, shown red. */
  neg?: boolean;
  /** Status colour (word + dot). */
  tone?: 'ok' | 'wait' | 'late' | 'mute';
  /** Indent level in a tree (accounts, categories). */
  depth?: number;
  /** Opens the voucher. */
  voucher?: number | null;
  /** Columns this cell spans. */
  span?: number;
  soft?: boolean;
}

export interface Row {
  cells: Cell[];
  /** A section line inside the table (a group heading). */
  sub?: boolean;
  /** A subtotal line. */
  strong?: boolean;
  /** Needs attention (over budget, overdue). */
  attention?: boolean;
}

export interface Table {
  title: string;
  note?: string;
  columns: string[];
  rows: Row[];
  /** Totals, shown bold under the rows. */
  foot?: Row[];
}

export interface Figure {
  label: string;
  value: string;
  note?: string;
  late?: boolean;
}

export interface ReportView {
  subtitle?: string;
  figures: Figure[];
  tables: Table[];
  /** Warnings worth a line of their own (an unbalanced statement). */
  notes: string[];
  /** Rates behind the consolidated figures. */
  rates?: RateUsed[];
}

/** What the builders need from the page: wording and formatting in the reader's language. */
export interface Fmt {
  t(key: CopyKey): string;
  /** The figure alone in the currency's decimals. */
  figure(value: Money | null | undefined, currency: string): string;
  /** "1,250.00 USD". */
  money(value: Money | null | undefined, currency: string): string;
  date(iso: string | null | undefined): string;
  stamp(iso: string | null | undefined): string;
  qty(value: Money | null | undefined): string;
  side(value: Money | null | undefined): string;
  kind(type: string | null | undefined, purpose?: string | null): string;
  status(status: string): string;
  projectStatus(status: string): string;
  boxType(type: string): string;
  assetStatus(status: string): string;
  depth(accountId: number): number;
  base(): string;
}

// ---------- Cells ----------

const text = (value: string | number | null | undefined, extra: Partial<Cell> = {}): Cell => ({ text: value === null || value === undefined ? '' : String(value), ...extra });
const blank = (): Cell => ({ text: '' });
const neg = (value: Money | null | undefined) => Number(value ?? 0) < 0;
const zero = (value: Money | null | undefined) => !Number(value ?? 0);

/** A figure in a column whose header names the currency. */
const amt = (f: Fmt, value: Money | null | undefined, currency: string, extra: Partial<Cell> = {}): Cell =>
  value === null || value === undefined ? text('—', extra) : { text: f.figure(value, currency), num: true, neg: neg(value), ...extra };

/** A movement: empty when zero, so debit and credit columns read at a glance. */
const move = (f: Fmt, value: Money | null | undefined, currency: string): Cell => (zero(value) ? blank() : amt(f, value, currency));

/** A figure with its currency code, for lists that mix currencies row by row and never sum them. */
const cash = (f: Fmt, value: Money | null | undefined, currency: string | null): Cell =>
  value === null || value === undefined ? text('—') : { text: f.money(value, currency ?? ''), num: true, neg: neg(value) };

const row = (cells: Cell[], extra: Partial<Row> = {}): Row => ({ cells, ...extra });
const sub = (label: string, span: number): Row => ({ cells: [text(label, { span })], sub: true });
const voucherNo = (line: { voucher_id: number | null; voucher_number: string | null; entry_number: string | null }): Cell =>
  text(line.voucher_number ?? line.entry_number ?? '', { voucher: line.voucher_id, num: true });
const percent = (f: Fmt, value: Money | null | undefined): Cell => (value === null || value === undefined ? text('—') : text(`${f.qty(value)}%`, { num: true, neg: neg(value) }));

function consolidatedTitle(f: Fmt, head: ReportHead): string {
  return `${f.t('frpConsolidated')} · ${head.consolidation?.base_currency ?? f.base()}`;
}

function baseOf(f: Fmt, head: ReportHead): string {
  return head.consolidation?.base_currency ?? f.base();
}

/** Distinct values in first-seen order. */
function distinct<T>(items: T[]): T[] {
  return [...new Set(items)];
}

function view(head: ReportHead, part: Partial<ReportView>): ReportView {
  return { figures: [], tables: [], notes: [], rates: head.consolidation?.rates_used, ...part };
}

// ---------- Cash ----------

function boxBalances(d: BoxBalancesReport, f: Fmt): ReportView {
  const base = baseOf(f, d);
  const consolidated = !!d.consolidation;
  const tables = d.totals.map<Table>((total) => ({
    title: total.code,
    columns: [f.t('finBox'), f.t('finType'), f.t('finBalance'), ...(consolidated ? [`${f.t('frpInBase')} ${base}`] : [])],
    rows: d.boxes.flatMap((box) =>
      box.balances
        .filter((item) => item.code === total.code)
        .map((item) => row([text(box.name), text(f.boxType(box.type), { soft: true }), amt(f, item.balance, item.code), ...(consolidated ? [amt(f, item.balance_base, base)] : [])])),
    ),
    foot: [row([text(f.t('finTotal')), blank(), amt(f, total.balance, total.code), ...(consolidated ? [amt(f, total.balance_base, base)] : [])])],
  }));
  const figures: Figure[] = d.totals.map((total) => ({ label: total.code, value: f.money(total.balance, total.code), late: neg(total.balance) }));
  if (d.consolidated) figures.push({ label: consolidatedTitle(f, d), value: f.money(d.consolidated.total, base) });
  return view(d, { figures, tables });
}

function statementTable(f: Fmt, title: string, code: string, opening: Money, lines: StatementLine[], totalIn: Money, totalOut: Money, closing: Money, columns: [string, string]): Table {
  return {
    title,
    columns: [f.t('finDate'), f.t('finNumber'), f.t('finDescription'), columns[0], columns[1], f.t('finBalance')],
    rows: [
      row([blank(), blank(), text(f.t('finOpening')), blank(), blank(), amt(f, opening, code)], { sub: true }),
      ...lines.map((line) =>
        row([text(f.date(line.date), { num: true }), voucherNo(line), text(line.description || line.memo || f.kind(line.voucher_type)), move(f, line.debit, code), move(f, line.credit, code), amt(f, line.balance, code)]),
      ),
    ],
    foot: [row([text(f.t('finTotal')), blank(), text(f.t('finClosing')), amt(f, totalIn, code), amt(f, totalOut, code), amt(f, closing, code)])],
  };
}

function boxStatement(d: BoxStatementReport, f: Fmt): ReportView {
  const base = baseOf(f, d);
  const tables = d.currencies.map((c) => statementTable(f, c.code, c.code, c.opening, c.lines, c.total_in, c.total_out, c.closing, [f.t('finIn'), f.t('finOut')]));
  if (d.consolidated) {
    tables.push({
      title: consolidatedTitle(f, d),
      columns: [f.t('finCurrency'), f.t('finOpening'), f.t('finIn'), f.t('finOut'), f.t('finClosing')],
      rows: d.currencies.map((c) => row([text(c.code), amt(f, c.base?.opening, base), amt(f, c.base?.total_in, base), amt(f, c.base?.total_out, base), amt(f, c.base?.closing, base)])),
      foot: [row([text(f.t('finTotal')), amt(f, d.consolidated.opening, base), amt(f, d.consolidated.total_in, base), amt(f, d.consolidated.total_out, base), amt(f, d.consolidated.closing, base)])],
    });
  }
  const figures: Figure[] = d.currencies.map((c) => ({ label: `${f.t('finClosing')} · ${c.code}`, value: f.money(c.closing, c.code), late: neg(c.closing) }));
  return view(d, { subtitle: d.box.name, figures, tables });
}

interface ExchangesReport extends ReportHead {
  exchanges: { voucher_id: number; number: string | null; date: string; status: string; from_box: string | null; from_currency: string | null; amount: Money; to_box: string | null; to_currency: string | null; counter_amount: Money; deal_rate: Money; bulletin_rate: Money | null; difference_pct: Money | null; result_base: Money }[];
  period_result_base: Money;
  fx_position: { as_of: string; currencies: { code: string | null; balance: Money; balance_base: Money }[]; balance_base: Money; result_base: Money };
}

function exchanges(d: ExchangesReport, f: Fmt): ReportView {
  const base = f.base();
  const result = d.fx_position.result_base;
  const tables: Table[] = [
    {
      title: f.t('frpExchangeList'),
      columns: [f.t('finDate'), f.t('finNumber'), f.t('frpExFrom'), f.t('frpExTo'), f.t('frpDealRate'), f.t('frpBulletinRate'), f.t('frpDiffPct'), `${f.t('frpResult')} ${base}`],
      rows: d.exchanges.map((x) =>
        row([
          text(f.date(x.date), { num: true }),
          text(x.status === 'reversed' ? `${x.number ?? ''} · ${f.status('reversed')}` : (x.number ?? ''), { voucher: x.voucher_id, num: true }),
          text(`${x.from_box ?? ''} · ${f.money(x.amount, x.from_currency ?? '')}`),
          text(`${x.to_box ?? ''} · ${f.money(x.counter_amount, x.to_currency ?? '')}`),
          text(f.qty(x.deal_rate), { num: true }),
          x.bulletin_rate === null ? text('—') : text(f.qty(x.bulletin_rate), { num: true }),
          percent(f, x.difference_pct),
          amt(f, x.result_base, base),
        ]),
      ),
      foot: [row([text(f.t('frpPeriodResult'), { span: 7 }), amt(f, d.period_result_base, base)])],
    },
    {
      title: f.t('frpFxPosition'),
      columns: [f.t('finCurrency'), f.t('finBalance'), `${f.t('frpInBase')} ${base}`],
      rows: d.fx_position.currencies.map((c) => row([text(c.code), amt(f, c.balance, c.code ?? ''), amt(f, c.balance_base, base)])),
      foot: [row([text(f.t('finTotal')), blank(), amt(f, d.fx_position.balance_base, base)])],
    },
  ];
  const figures: Figure[] = [
    { label: f.t('frpPeriodResult'), value: f.money(d.period_result_base, base), note: gainWord(f, d.period_result_base), late: neg(d.period_result_base) },
    { label: f.t('frpFxResult'), value: f.money(result, base), note: `${gainWord(f, result)} · ${f.date(d.fx_position.as_of)}`, late: neg(result) },
  ];
  return view(d, { figures, tables });
}

function gainWord(f: Fmt, value: Money): string {
  return neg(value) ? f.t('frpLoss') : zero(value) ? f.t('finEven') : f.t('frpGain');
}

interface CashCountsReport extends ReportHead {
  counts: { id: number; box: string | null; count_date: string; status: string; lines: { currency: string | null; system_balance: Money | null; counted_amount: Money; difference: Money | null; explanation: string | null }[] }[];
  per_box: { box: string | null; currency: string | null; counts: number; shortage: Money; overage: Money; net_difference: Money }[];
}

function cashCounts(d: CashCountsReport, f: Fmt): ReportView {
  const tables: Table[] = [
    {
      title: f.t('frpCountList'),
      columns: [f.t('finDate'), f.t('finBox'), f.t('finStatus'), f.t('frpSystemBalance'), f.t('frpCounted'), f.t('frpDifference'), f.t('frpExplanation')],
      rows: d.counts.flatMap((count) => {
        const head = [text(f.date(count.count_date), { num: true }), text(count.box), text(f.status(count.status), { tone: toneOf(count.status) })];
        if (!count.lines.length) return [row([...head, blank(), blank(), blank(), blank()])];
        return count.lines.map((line, index) =>
          row([...(index ? [blank(), blank(), blank()] : head), cash(f, line.system_balance, line.currency), cash(f, line.counted_amount, line.currency), cash(f, line.difference, line.currency), text(line.explanation)], {
            attention: neg(line.difference),
          }),
        );
      }),
    },
  ];
  if (d.per_box.length) {
    tables.push({
      title: f.t('frpPerBox'),
      columns: [f.t('finBox'), f.t('frpCountsNo'), f.t('frpShortage'), f.t('frpOverage'), f.t('finNet')],
      rows: d.per_box.map((item) => row([text(item.box), text(item.counts, { num: true }), cash(f, item.shortage, item.currency), cash(f, item.overage, item.currency), cash(f, item.net_difference, item.currency)])),
    });
  }
  return view(d, { tables: d.counts.length ? tables : [] });
}

function toneOf(status: string): Cell['tone'] {
  switch (status) {
    case 'approved':
    case 'in_service':
    case 'active':
      return 'ok';
    case 'draft':
    case 'submitted':
    case 'planned':
    case 'on_hold':
    case 'under_maintenance':
    case 'warning':
      return 'wait';
    case 'rejected':
    case 'lost':
    case 'exceeded':
      return 'late';
    default:
      return 'mute';
  }
}

// ---------- Accounts ----------

function journal(d: JournalReport, f: Fmt): ReportView {
  const tables: Table[] = [];
  if (d.entries.length) {
    tables.push({
      title: f.t('frpEntries'),
      columns: [f.t('finAccount'), f.t('finParty'), f.t('finNotes'), f.t('finDebit'), f.t('finCredit')],
      rows: d.entries.flatMap((entry) => [
        row(
          [
            text(
              [f.date(entry.date), entry.voucher_number ?? entry.number ?? '', entry.voucher_type ? f.kind(entry.voucher_type, entry.voucher_purpose) : '', entry.description ?? '']
                .filter(Boolean)
                .join(' · '),
              { span: 5, voucher: entry.source_type === 'voucher' ? entry.source_id : null },
            ),
          ],
          { sub: true },
        ),
        ...entry.lines.map((line) =>
          row([
            text([line.account_code, line.account_name].filter(Boolean).join(' · ')),
            text(line.party),
            text(line.memo, { soft: true }),
            zero(line.debit) ? blank() : cash(f, line.debit, line.currency),
            zero(line.credit) ? blank() : cash(f, line.credit, line.currency),
          ]),
        ),
      ]),
    });
  }
  if (d.totals.length) {
    tables.push({
      title: f.t('frpTotalsByCurrency'),
      columns: [f.t('finCurrency'), f.t('finDebit'), f.t('finCredit')],
      rows: d.totals.map((total) => row([text(total.code), amt(f, total.debit, total.code), amt(f, total.credit, total.code)])),
    });
  }
  return view(d, { tables });
}

function ledger(d: LedgerReport, f: Fmt): ReportView {
  const tables: Table[] = [];
  const base = baseOf(f, d);
  const top = Math.min(...d.accounts.map((a) => f.depth(a.account_id)));
  const many = d.accounts.length > 1;
  if (many) {
    for (const code of distinct(d.accounts.flatMap((a) => a.currencies.map((c) => c.code)))) {
      tables.push({
        title: `${f.t('frpAccountsSummary')} · ${code}`,
        columns: [f.t('finAccount'), f.t('finOpening'), f.t('finDebit'), f.t('finCredit'), f.t('finClosing')],
        rows: d.accounts.flatMap((a) =>
          a.currencies
            .filter((c) => c.code === code)
            .map((c) => row([text(`${a.code} · ${a.name}`, { depth: f.depth(a.account_id) - top }), amt(f, c.opening, code), amt(f, c.total_debit, code), amt(f, c.total_credit, code), amt(f, c.closing, code)])),
        ),
      });
    }
  }
  for (const a of d.accounts) {
    for (const c of a.currencies) {
      if (many && !c.lines.length) continue;
      tables.push(statementTable(f, `${a.code} · ${a.name} · ${c.code}`, c.code, c.opening, c.lines, c.total_debit, c.total_credit, c.closing, [f.t('finDebit'), f.t('finCredit')]));
    }
  }
  if (d.consolidation) {
    tables.push({
      title: consolidatedTitle(f, d),
      columns: [f.t('finAccount'), f.t('finOpening'), f.t('finDebit'), f.t('finCredit'), f.t('finClosing')],
      rows: d.accounts
        .filter((a) => a.consolidated)
        .map((a) => row([text(`${a.code} · ${a.name}`, { depth: f.depth(a.account_id) - top }), amt(f, a.consolidated?.opening, base), amt(f, a.consolidated?.debit, base), amt(f, a.consolidated?.credit, base), amt(f, a.consolidated?.closing, base)])),
    });
  }
  return view(d, { tables });
}

/**
 * Balance is defined per currency. The consolidated table is a translation whose four-place rounding can leave a
 * gap of 0.0001, so it only warns when the gap reaches a cent.
 */
function trialTable(f: Fmt, title: string, block: TrialBlock, code: string, translated = false): Table {
  const top = Math.min(...block.accounts.map((a) => f.depth(a.account_id)));
  const gap = Math.max(
    Math.abs(Number(block.totals.debit) - Number(block.totals.credit)),
    Math.abs(Number(block.totals.closing_debit) - Number(block.totals.closing_credit)),
  );
  const balanced = block.totals.balanced || (translated && gap < 0.01);
  return {
    title,
    note: balanced ? undefined : f.t('frpUnbalanced'),
    columns: [f.t('finAccount'), f.t('frpOpenDebit'), f.t('frpOpenCredit'), f.t('frpMoveDebit'), f.t('frpMoveCredit'), f.t('frpCloseDebit'), f.t('frpCloseCredit')],
    rows: block.accounts.map((a) =>
      row([
        text(`${a.code} · ${a.name}`, { depth: f.depth(a.account_id) - top }),
        move(f, a.opening_debit, code),
        move(f, a.opening_credit, code),
        move(f, a.debit, code),
        move(f, a.credit, code),
        move(f, a.closing_debit, code),
        move(f, a.closing_credit, code),
      ]),
    ),
    foot: [
      row([
        text(f.t('finTotal')),
        amt(f, block.totals.opening_debit, code),
        amt(f, block.totals.opening_credit, code),
        amt(f, block.totals.debit, code),
        amt(f, block.totals.credit, code),
        amt(f, block.totals.closing_debit, code),
        amt(f, block.totals.closing_credit, code),
      ]),
    ],
  };
}

function trialBalance(d: TrialBalanceReport, f: Fmt): ReportView {
  const tables = d.currencies.map((c) => trialTable(f, c.code, c, c.code));
  if (d.consolidated) tables.push(trialTable(f, consolidatedTitle(f, d), d.consolidated, baseOf(f, d), true));
  const notes = d.currencies.some((c) => !c.totals.balanced) ? [f.t('frpUnbalanced')] : [];
  return view(d, { tables, notes });
}

function amountRows(f: Fmt, rows: { code: string; name: string; amount: Money }[], code: string): Row[] {
  return rows.map((item) => row([text(`${item.code} · ${item.name}`, { depth: 1 }), amt(f, item.amount, code)]));
}

function incomeTable(f: Fmt, title: string, block: IncomeBlock, code: string): Table {
  return {
    title,
    columns: [f.t('finAccount'), f.t('finAmount')],
    rows: [
      sub(f.t('frpRevenue'), 2),
      ...amountRows(f, block.revenue, code),
      row([text(f.t('frpTotalRevenue')), amt(f, block.total_revenue, code)], { strong: true }),
      sub(f.t('frpExpensesSection'), 2),
      ...amountRows(f, block.expenses, code),
      row([text(f.t('frpTotalExpenses')), amt(f, block.total_expenses, code)], { strong: true }),
    ],
    foot: [row([text(f.t('frpNetProfit')), amt(f, block.net_profit, code)])],
  };
}

function income(d: IncomeReport, f: Fmt): ReportView {
  const base = baseOf(f, d);
  const tables = d.currencies.map((c) => incomeTable(f, c.code, c, c.code));
  if (d.consolidated) tables.push(incomeTable(f, consolidatedTitle(f, d), d.consolidated, base));
  for (const branch of d.branches ?? []) {
    tables.push(...branch.currencies.map((c) => incomeTable(f, `${branch.name} · ${c.code}`, c, c.code)));
    if (branch.consolidated) tables.push(incomeTable(f, `${branch.name} · ${consolidatedTitle(f, d)}`, branch.consolidated, base));
  }
  const figures: Figure[] = d.currencies.map((c) => ({ label: `${f.t('frpNetProfit')} · ${c.code}`, value: f.money(c.net_profit, c.code), late: neg(c.net_profit) }));
  if (d.consolidated) figures.push({ label: consolidatedTitle(f, d), value: f.money(d.consolidated.net_profit, base), late: neg(d.consolidated.net_profit) });
  return view(d, { figures, tables });
}

function sheetTable(f: Fmt, title: string, block: SheetBlock, code: string): Table {
  return {
    title,
    note: block.balanced ? undefined : `${f.t('frpDifference')}: ${f.money(block.difference, code)}`,
    columns: [f.t('finAccount'), f.t('finAmount')],
    rows: [
      sub(f.t('frpAssetsSection'), 2),
      ...amountRows(f, block.assets, code),
      row([text(f.t('frpTotalAssets')), amt(f, block.total_assets, code)], { strong: true }),
      sub(f.t('frpLiabilities'), 2),
      ...amountRows(f, block.liabilities, code),
      row([text(f.t('frpTotalLiabilities')), amt(f, block.total_liabilities, code)], { strong: true }),
      sub(f.t('frpEquity'), 2),
      ...amountRows(f, block.equity, code),
      row([text(f.t('frpTotalEquity')), amt(f, block.total_equity, code)], { strong: true }),
      row([text(f.t('frpProfitToDate')), amt(f, block.current_profit, code)], { strong: true }),
    ],
    foot: [row([text(f.t('frpLiabEqProfit')), amt(f, block.total_liabilities_equity_profit, code)])],
  };
}

function balanceSheet(d: BalanceSheetReport, f: Fmt): ReportView {
  const tables = d.currencies.map((c) => sheetTable(f, c.code, c, c.code));
  if (d.consolidated) tables.push(sheetTable(f, consolidatedTitle(f, d), d.consolidated, baseOf(f, d)));
  const notes = d.currencies.some((c) => !c.balanced) ? [f.t('frpSheetUnbalanced')] : [];
  return view(d, { tables, notes });
}

// ---------- Projects ----------

function projectsSummary(d: ProjectsSummaryReport, f: Fmt): ReportView {
  const base = baseOf(f, d);
  const figureColumns = [f.t('frpReceipts'), f.t('frpCosts'), f.t('frpRemaining'), f.t('frpFee'), f.t('frpProfit'), f.t('frpClientBalance')];
  const tables = d.totals.map<Table>((total) => ({
    title: total.currency,
    columns: [f.t('finProject'), f.t('frpClient'), f.t('finStatus'), ...figureColumns],
    rows: d.projects
      .filter((p) => p.currency === total.currency)
      .map((p) =>
        row([
          text(p.code ? `${p.code} · ${p.name}` : p.name),
          text(p.client),
          text(f.projectStatus(p.status), { tone: toneOf(p.status) }),
          amt(f, p.receipts, p.currency),
          amt(f, p.costs, p.currency),
          amt(f, p.remaining, p.currency),
          amt(f, p.fee, p.currency),
          amt(f, p.profit, p.currency),
          amt(f, p.client_balance, p.currency),
        ]),
      ),
    foot: [
      row([
        text(f.t('finTotal'), { span: 3 }),
        amt(f, total.receipts, total.currency),
        amt(f, total.costs, total.currency),
        amt(f, total.remaining, total.currency),
        amt(f, total.fee, total.currency),
        amt(f, total.profit, total.currency),
        amt(f, total.client_balance, total.currency),
      ]),
    ],
  }));
  if (d.consolidated) {
    const c = d.consolidated;
    tables.push({
      title: consolidatedTitle(f, d),
      columns: [f.t('finProject'), ...figureColumns],
      rows: d.projects.map((p) =>
        row([text(p.code ? `${p.code} · ${p.name}` : p.name), amt(f, p.base?.receipts, base), amt(f, p.base?.costs, base), amt(f, p.base?.remaining, base), amt(f, p.base?.fee, base), amt(f, p.base?.profit, base), amt(f, p.base?.client_balance, base)]),
      ),
      foot: [row([text(f.t('finTotal')), amt(f, c.receipts, base), amt(f, c.costs, base), amt(f, c.remaining, base), amt(f, c.fee, base), amt(f, c.profit, base), amt(f, c.client_balance, base)])],
    });
  }
  return view(d, { tables });
}

function projectStatement(d: ProjectStatementReport, f: Fmt): ReportView {
  const cur = d.header.currency;
  const amountColumn = `${f.t('finAmount')} ${cur}`;
  const tables: Table[] = [
    {
      title: f.t('frpIncoming'),
      columns: [f.t('finDate'), f.t('finNumber'), f.t('finBox'), f.t('finDescription'), f.t('frpOriginal'), amountColumn],
      rows: d.incoming.map((line) => row([text(f.date(line.date), { num: true }), text(line.number, { num: true }), text(line.cash_box), text(line.description), cash(f, line.original_amount, line.original_currency), amt(f, line.amount, cur)])),
      foot: [row([text(f.t('finTotal'), { span: 5 }), amt(f, d.totals.incoming, cur)])],
    },
    ...d.outgoing.map<Table>((group) => ({
      title: group.is_billable || d.variant === 'client' ? group.name : `${group.name} · ${f.t('frpLoaded')}`,
      columns: [f.t('finDate'), f.t('finNumber'), f.t('finParty'), f.t('finDescription'), f.t('frpOriginal'), amountColumn],
      rows: group.lines.map((line) => row([text(f.date(line.date), { num: true }), text(line.number, { num: true }), text(line.party), text(line.description), cash(f, line.original_amount, line.original_currency), amt(f, line.amount, cur)])),
      foot: [row([text(f.t('finTotal'), { span: 5 }), amt(f, group.total, cur)])],
    })),
  ];
  const figures: Figure[] = [
    { label: f.t('frpIncoming'), value: f.money(d.totals.incoming, cur) },
    { label: f.t('frpOutgoing'), value: f.money(d.totals.outgoing, cur) },
    { label: f.t('frpRemaining'), value: f.money(d.totals.remaining, cur), late: neg(d.totals.remaining) },
  ];
  if (d.totals.fee !== undefined && d.totals.fee !== null) {
    figures.push({ label: f.t('frpFee'), value: f.money(d.totals.fee, cur), note: d.header.fee_pct ? `${f.qty(d.header.fee_pct)}%` : undefined });
    figures.push({ label: f.t('frpRemainingAfterFee'), value: f.money(d.totals.remaining_after_fee, cur), late: neg(d.totals.remaining_after_fee) });
  }
  const subtitle = [d.header.statement_code, d.header.project, d.header.client, f.date(d.header.date)].filter(Boolean).join(' · ');
  return view(d, { subtitle, figures, tables });
}

function budget(d: BudgetReport, f: Fmt): ReportView {
  const tables = distinct(d.lines.map((line) => line.currency)).map<Table>((code) => ({
    title: code,
    columns: [f.t('finProject'), f.t('finCostCategory'), f.t('frpBudgetCol'), f.t('frpActual'), f.t('frpRemaining'), f.t('frpPct'), f.t('finStatus')],
    rows: d.lines
      .filter((line) => line.currency === code)
      .map((line) =>
        row(
          [
            text(line.project_code ? `${line.project_code} · ${line.project}` : line.project),
            text(line.category),
            amt(f, line.budget, code),
            amt(f, line.actual, code),
            amt(f, line.remaining, code),
            percent(f, line.pct),
            text(f.t(line.level === 'exceeded' ? 'frpLevelExceeded' : line.level === 'warning' ? 'frpLevelWarning' : 'frpLevelOk'), { tone: line.level === 'exceeded' ? 'late' : line.level === 'warning' ? 'wait' : 'ok' }),
          ],
          { attention: line.level === 'exceeded' },
        ),
      ),
  }));
  const over = d.over_budget_projects.length;
  return view(d, { figures: [{ label: f.t('frpOverBudget'), value: String(over), late: over > 0 }], tables });
}

function profitability(d: ProfitabilityReport, f: Fmt): ReportView {
  const base = d.ranking_basis.base_currency;
  const tables: Table[] = [
    {
      title: f.t('frpRanking'),
      columns: ['#', f.t('finProject'), f.t('finStatus'), f.t('frpRevenueCol'), f.t('frpDirectCosts'), f.t('frpLoadedCosts'), f.t('frpNetMargin'), f.t('frpPct'), `${f.t('frpNetMargin')} ${base}`],
      rows: d.projects.map((p) =>
        row([
          text(p.rank, { num: true }),
          text(p.code ? `${p.code} · ${p.name}` : p.name),
          text(f.projectStatus(p.status), { soft: true }),
          cash(f, p.revenue, p.currency),
          cash(f, p.direct_costs, p.currency),
          cash(f, p.loaded_costs, p.currency),
          cash(f, p.net_margin, p.currency),
          percent(f, p.net_margin_pct),
          amt(f, p.net_margin_base, base),
        ]),
      ),
      foot: [row([text(f.t('finTotal'), { span: 8 }), amt(f, d.totals.net_margin_base, base)])],
    },
  ];
  return view(d, { tables, rates: d.ranking_basis.rates_used });
}

// ---------- Parties ----------

function partyStatement(d: PartyStatementReport, f: Fmt): ReportView {
  const base = baseOf(f, d);
  const tables = d.currencies.map<Table>((c) => ({
    title: c.code,
    columns: [f.t('finDate'), f.t('finNumber'), f.t('finType'), f.t('finDescription'), f.t('finDebit'), f.t('finCredit'), f.t('finBalance'), f.t('frpSide')],
    rows: [
      row([blank(), blank(), blank(), text(f.t('finOpening')), blank(), blank(), amt(f, c.opening, c.code), text(f.side(c.opening))], { sub: true }),
      ...c.lines.map((line) =>
        row([
          text(f.date(line.date), { num: true }),
          voucherNo(line),
          text(line.voucher_type ? f.kind(line.voucher_type, line.voucher_purpose) : '', { soft: true }),
          text(line.description || line.memo || line.account_name),
          move(f, line.debit, c.code),
          move(f, line.credit, c.code),
          amt(f, line.balance, c.code),
          text(f.side(line.balance)),
        ]),
      ),
    ],
    foot: [row([text(f.t('finTotal'), { span: 4 }), amt(f, c.total_debit, c.code), amt(f, c.total_credit, c.code), amt(f, c.closing, c.code), text(f.side(c.closing))])],
  }));
  if (d.consolidated) {
    tables.push({
      title: consolidatedTitle(f, d),
      columns: [f.t('finCurrency'), f.t('finOpening'), f.t('finClosing'), f.t('frpSide')],
      rows: d.currencies.map((c) => row([text(c.code), amt(f, c.base?.opening, base), amt(f, c.base?.closing, base), text(f.side(c.base?.closing))])),
      foot: [row([text(f.t('finTotal')), amt(f, d.consolidated.opening, base), amt(f, d.consolidated.closing, base), text(f.side(d.consolidated.closing))])],
    });
  }
  const figures: Figure[] = d.currencies.map((c) => ({ label: `${f.t('finClosing')} · ${c.code}`, value: f.money(Math.abs(Number(c.closing)), c.code), note: f.side(c.closing) }));
  return view(d, { subtitle: d.party.name, figures, tables });
}

function contractors(d: ContractorsReport, f: Fmt): ReportView {
  const tables = d.totals.map<Table>((total) => {
    const code = total.currency;
    return {
      title: code,
      columns: [f.t('frpContractor'), f.t('frpAgreements'), f.t('frpNetValue'), f.t('frpPaidOn'), f.t('frpRemaining'), f.t('frpPaidWithout'), f.t('frpAdvancesOut'), f.t('frpPayables')],
      rows: d.contractors
        .filter((c) => c.currency === code)
        .map((c) =>
          row([
            text(c.contractor),
            text(`${c.open_agreements} / ${c.agreements}`, { num: true }),
            amt(f, c.net_value, code),
            amt(f, c.paid_on_agreements, code),
            amt(f, c.remaining, code),
            amt(f, c.paid_without_agreement, code),
            amt(f, c.advances_outstanding, code),
            amt(f, c.payables, code),
          ]),
        ),
      foot: [
        row([
          text(f.t('finTotal'), { span: 2 }),
          amt(f, total.net_value, code),
          amt(f, total.paid_on_agreements, code),
          amt(f, total.remaining, code),
          amt(f, total.paid_without_agreement, code),
          amt(f, total.advances_outstanding, code),
          amt(f, total.payables, code),
        ]),
      ],
    };
  });
  return view(d, { tables });
}

function aging(d: AgingReport, f: Fmt): ReportView {
  const tables = d.totals.map<Table>((total) => {
    const code = total.currency;
    const net = String(Number(total.outstanding) - Number(total.unapplied));
    return {
      title: `${f.t(total.side === 'receivable' ? 'frpReceivable' : 'frpPayable')} · ${code}`,
      columns: [f.t('finParty'), f.t('frpOutstanding'), f.t('frpB0'), f.t('frpB31'), f.t('frpB61'), f.t('frpB90'), f.t('frpUnapplied'), f.t('finNet'), f.t('frpOldest')],
      rows: d.rows
        .filter((r) => r.side === total.side && r.currency === code)
        .map((r) =>
          row(
            [text(r.party), amt(f, r.outstanding, code), move(f, r['0_30'], code), move(f, r['31_60'], code), move(f, r['61_90'], code), move(f, r['90_plus'], code), move(f, r.unapplied, code), amt(f, r.net, code), text(f.date(r.oldest_open_date), { num: true })],
            { attention: !zero(r['90_plus']) },
          ),
        ),
      foot: [row([text(f.t('finTotal')), amt(f, total.outstanding, code), amt(f, total['0_30'], code), amt(f, total['31_60'], code), amt(f, total['61_90'], code), amt(f, total['90_plus'], code), amt(f, total.unapplied, code), amt(f, net, code), blank()])],
    };
  });
  return view(d, { tables, notes: [] });
}

// ---------- Employees and partners ----------

function advances(d: AdvancesReport, f: Fmt): ReportView {
  const tables: Table[] = [];
  for (const code of distinct(d.balances.map((b) => b.currency))) {
    const rows = d.balances.filter((b) => b.currency === code);
    tables.push({
      title: `${f.t('frpOutstandingAdvances')} · ${code}`,
      columns: [f.t('finEmployee'), f.t('frpAdvancesCount'), f.t('frpOutstanding'), f.t('frpNextDue')],
      rows: rows.map((b) => row([text(b.employee_name), text(b.advances, { num: true }), amt(f, b.outstanding, code), text(b.next_due_month ? `${b.next_due_month} · ${f.money(b.next_amount, code)}` : '—', { num: true })])),
      foot: [row([text(f.t('finTotal'), { span: 2 }), amt(f, String(rows.reduce((sum, b) => sum + Number(b.outstanding), 0)), code), blank()])],
    });
  }
  for (const code of distinct(d.upcoming.map((u) => u.currency))) {
    const rows = d.upcoming.filter((u) => u.currency === code);
    tables.push({
      title: `${f.t('frpUpcoming')} · ${code}`,
      columns: [f.t('finMonth'), f.t('frpInstallments'), f.t('finAmount')],
      rows: rows.map((u) => row([text(u.due_month, { num: true }), text(u.installments, { num: true }), amt(f, u.amount, code)])),
      foot: [row([text(f.t('finTotal'), { span: 2 }), amt(f, String(rows.reduce((sum, u) => sum + Number(u.amount), 0)), code)])],
    });
  }
  return { figures: [], tables, notes: [] };
}

function custodies(d: CustodiesReport, f: Fmt): ReportView {
  const tables = d.totals.map<Table>((total) => {
    const code = total.currency;
    return {
      title: code,
      note: `${f.t('frpOverdueHint')} ${d.overdue_days} ${f.t('finDays')}.`,
      columns: [f.t('frpHolder'), f.t('finProject'), f.t('frpOpenedOn'), f.t('frpAge'), f.t('frpIssued'), f.t('frpSettled'), f.t('frpReturned'), f.t('finBalance')],
      rows: d.custodies
        .filter((c) => c.currency === code)
        .map((c) =>
          row(
            [
              text(c.holder_name),
              text(c.project_name),
              text(f.date(c.opened_on), { num: true }),
              text(`${c.age_days} ${f.t('finDays')}`, { num: true, neg: c.overdue }),
              amt(f, c.issued_total, code),
              amt(f, c.settled_total, code),
              amt(f, c.returned_total, code),
              amt(f, c.balance, code),
            ],
            { attention: c.overdue },
          ),
        ),
      foot: [row([text(`${f.t('finTotal')} (${total.count})`, { span: 7 }), amt(f, total.balance, code)])],
    };
  });
  return { figures: [], tables, notes: [] };
}

function partners(d: PartnersReport, f: Fmt): ReportView {
  const tables: Table[] = [];
  for (const code of distinct(d.partners.map((p) => p.currency))) {
    const rows = d.partners.filter((p) => p.currency === code);
    tables.push({
      title: code,
      columns: [f.t('frpPartner'), f.t('finOpening'), f.t('frpDrawings'), f.t('frpDeposits'), f.t('finClosing'), f.t('frpSide')],
      rows: rows.map((p) => row([text(p.partner), amt(f, p.opening, code), amt(f, p.total_drawings, code), amt(f, p.total_deposits, code), amt(f, p.closing, code), text(f.side(p.closing))])),
    });
    if (d.months.length > 1) {
      tables.push({
        title: `${f.t('frpByMonth')} · ${code}`,
        columns: [f.t('finMonth'), f.t('frpDrawings'), f.t('frpDeposits'), f.t('finBalance')],
        rows: rows.flatMap((p) => [sub(p.partner ?? '', 4), ...p.months.map((m) => row([text(m.month, { num: true }), move(f, m.drawings, code), move(f, m.deposits, code), amt(f, m.balance, code)]))]),
      });
    }
  }
  return view(d, { tables });
}

function payroll(d: PayrollReport, f: Fmt): ReportView {
  const base = baseOf(f, d);
  const tables: Table[] = [];
  const group = (title: CopyKey, column: CopyKey, items: PayrollRow[], label: (item: PayrollRow) => string) => {
    for (const code of distinct(items.map((i) => i.currency))) {
      const rows = items.filter((i) => i.currency === code);
      if (!rows.length) continue;
      tables.push({ title: `${f.t(title)} · ${code}`, columns: [f.t(column), f.t('finAmount')], rows: rows.map((i) => row([text(label(i)), amt(f, i.amount, code)])) });
    }
  };
  group('frpByEmployee', 'finEmployee', d.by_employee, (i) => i.employee ?? f.t('frpNoEmployee'));
  group('frpByProject', 'finProject', d.by_project, (i) => i.project ?? f.t('frpNoProject'));
  group('frpByComponent', 'frpComponent', d.by_component, (i) => i.component ?? '');
  group('frpAllocated', 'finProject', d.allocated_to_projects, (i) => i.project ?? '');
  const figures: Figure[] = d.totals.map((total) => ({ label: total.currency, value: f.money(total.amount, total.currency) }));
  if (d.consolidated) figures.push({ label: consolidatedTitle(f, d), value: f.money(d.consolidated.total, base) });
  return view(d, { figures, tables });
}

// ---------- Expenses and assets ----------

function expenseTable(f: Fmt, title: string, months: string[], block: ExpensesBlock, code: string): Table {
  const monthly = months.length > 1;
  const cells = (values: Record<string, Money>, total: Money, extra: Partial<Cell> = {}): Cell[] => [...(monthly ? months.map((m) => move(f, values[m], code)) : []), amt(f, total, code, extra)];
  const walk = (nodes: CategoryNode[]): Row[] =>
    nodes.flatMap((node) => [row([text(node.name, { depth: node.depth }), ...cells(node.months, node.total)], { strong: node.is_subtotal }), ...walk(node.children)]);
  const span = 2 + (monthly ? months.length : 0);
  return {
    title,
    columns: [f.t('finCategory'), ...(monthly ? months : []), f.t('finTotal')],
    rows: [
      ...walk(block.categories),
      ...(block.uncategorized.length ? [sub(f.t('frpUncategorized'), span), ...block.uncategorized.map((u) => row([text(`${u.code} · ${u.name}`, { depth: 1 }), ...cells(u.months, u.total)]))] : []),
    ],
    foot: [row([text(f.t('finTotal')), ...cells(block.month_totals, block.total)])],
  };
}

function expenses(d: ExpensesReport, f: Fmt): ReportView {
  const tables = d.currencies.map((c) => expenseTable(f, c.code, d.months, c, c.code));
  if (d.consolidated) tables.push(expenseTable(f, consolidatedTitle(f, d), d.months, d.consolidated, baseOf(f, d)));
  const figures: Figure[] = d.currencies.map((c) => ({ label: c.code, value: f.money(c.total, c.code) }));
  if (d.consolidated) figures.push({ label: consolidatedTitle(f, d), value: f.money(d.consolidated.total, baseOf(f, d)) });
  return view(d, { figures, tables });
}

function assets(d: AssetsReport, f: Fmt): ReportView {
  const each = (values: Record<string, Money> | null | undefined): Cell => {
    const entries = Object.entries(values ?? {}).filter(([, value]) => !zero(value));
    return entries.length ? text(entries.map(([code, value]) => f.money(value, code)).join(' · '), { num: true }) : text('—');
  };
  const groupColumn: Record<AssetsReport['group_by'], CopyKey> = { status: 'finStatus', holder: 'frpHolder', category: 'finCategory', branch: 'finBranch' };
  const tables: Table[] = [
    {
      title: f.t('frpGroups'),
      columns: [f.t(groupColumn[d.group_by]), f.t('frpCount'), f.t('frpCost'), f.t('frpBookValue'), f.t('frpOperating'), f.t('frpMaintenance')],
      rows: d.groups.map((g) =>
        row([text(d.group_by === 'status' ? f.assetStatus(String(g.key)) : g.label), text(g.count, { num: true }), each(g.cost), each(g.book_value), each(g.operating_expenses), each(g.maintenance_expenses)]),
      ),
    },
    {
      title: f.t('frpAssetList'),
      columns: [f.t('finCode'), f.t('finName'), f.t('finStatus'), f.t('frpHolder'), f.t('finBranch'), f.t('frpCost'), f.t('frpBookValue'), f.t('frpOperating'), f.t('frpMaintenance')],
      rows: d.assets.map((a) =>
        row([
          text(a.code, { num: true }),
          text(a.name),
          text(f.assetStatus(a.status), { tone: toneOf(a.status) }),
          text(a.holder?.name),
          text(a.branch?.name),
          cash(f, a.total_cost, a.currency),
          cash(f, a.book_value, a.currency),
          each(a.operating_expenses),
          each(a.maintenance_expenses),
        ]),
      ),
    },
  ];
  return { figures: [], tables: d.assets.length ? tables : [], notes: [] };
}

// ---------- Control ----------

function audit(rows: AuditRow[], f: Fmt): ReportView {
  const changed = (item: AuditRow): string => {
    const keys = Object.keys(item.new_values ?? item.old_values ?? {});
    return keys.length > 4 ? `${keys.slice(0, 4).join('، ')}…` : keys.join('، ');
  };
  return {
    figures: [],
    notes: [],
    tables: rows.length
      ? [
          {
            title: f.t('frpAuditTrail'),
            columns: [f.t('frpWhen'), f.t('frpAction'), f.t('frpRecord'), f.t('frpUser'), f.t('frpFields')],
            rows: rows.map((item) =>
              row([
                text(f.stamp(item.created_at), { num: true }),
                text(item.action, { num: true }),
                text(item.auditable_type ? `${item.auditable_type.split('\\').pop()} #${item.auditable_id ?? ''}` : '', { num: true }),
                text(item.user_id ? `#${item.user_id}` : '—', { num: true }),
                text(changed(item), { soft: true }),
              ]),
            ),
          },
        ]
      : [],
  };
}

/** The view of a report's response. */
export function buildView(key: string, data: unknown, f: Fmt): ReportView {
  switch (key) {
    case 'box-balances':
      return boxBalances(data as BoxBalancesReport, f);
    case 'box-statement':
      return boxStatement(data as BoxStatementReport, f);
    case 'exchanges':
      return exchanges(data as ExchangesReport, f);
    case 'cash-counts':
      return cashCounts(data as CashCountsReport, f);
    case 'journal':
      return journal(data as JournalReport, f);
    case 'general-ledger':
      return ledger(data as LedgerReport, f);
    case 'trial-balance':
      return trialBalance(data as TrialBalanceReport, f);
    case 'income-statement':
      return income(data as IncomeReport, f);
    case 'balance-sheet':
      return balanceSheet(data as BalanceSheetReport, f);
    case 'projects-summary':
      return projectsSummary(data as ProjectsSummaryReport, f);
    case 'project-statement':
      return projectStatement(data as ProjectStatementReport, f);
    case 'budget-vs-actual':
      return budget(data as BudgetReport, f);
    case 'project-profitability':
      return profitability(data as ProfitabilityReport, f);
    case 'party-statement':
      return partyStatement(data as PartyStatementReport, f);
    case 'contractor-balances':
      return contractors(data as ContractorsReport, f);
    case 'aging':
      return aging(data as AgingReport, f);
    case 'employee-advances':
      return advances(data as AdvancesReport, f);
    case 'open-custodies':
      return custodies(data as CustodiesReport, f);
    case 'partners':
      return partners(data as PartnersReport, f);
    case 'payroll-cost':
      return payroll(data as PayrollReport, f);
    case 'expenses-by-category':
      return expenses(data as ExpensesReport, f);
    case 'asset-register':
      return assets(data as AssetsReport, f);
    case 'audit-log':
      return audit(data as AuditRow[], f);
    default:
      return { figures: [], tables: [], notes: [] };
  }
}

/** A table's rows and totals as plain text for the PDF document (a spanning cell fills its columns with blanks). */
export function plainRows(table: Table): string[][] {
  return [...table.rows, ...(table.foot ?? [])].map((r) =>
    r.cells.flatMap((cell) => [`${'   '.repeat(cell.depth ?? 0)}${cell.text}`, ...Array.from({ length: Math.max(0, (cell.span ?? 1) - 1) }, () => '')]),
  );
}
