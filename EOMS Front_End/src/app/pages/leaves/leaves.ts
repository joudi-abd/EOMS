import { Component, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Ui } from '../../core/ui';
import { serverMessage } from '../../core/api/api';
import { Reporter, ReportDraft } from '../../core/reports/reporter';
import { Chrome } from '../../shell/chrome';
import { Session } from '../../core/access/session';
import { Leaves, LeaveCalendar as LeaveBoard, LeaveInput, LeaveListQuery, LeaveOptions, LeaveQuote, LeaveRecord, HalfDays } from '../../data/leaves';
import { leaveClock, leaveLabel, leaveShift } from './leave-format';
import { LeaveCalendar } from './leave-calendar/leave-calendar';
import { LeaveForm } from './leave-form/leave-form';
import { LeavePeriods } from './leave-periods/leave-periods';
import { LeaveFilters, LeaveRegister } from './leave-register/leave-register';

@Component({
  selector: 'app-leaves',
  imports: [RouterLink, LeavePeriods, LeaveForm, LeaveCalendar, LeaveRegister],
  templateUrl: './leaves.html',
  styleUrl: './leaves.scss',
})
export class LeavesPage extends Ui {
  protected readonly chrome = inject(Chrome);
  private readonly api = inject(Leaves);
  private readonly reporter = inject(Reporter);
  protected readonly session = inject(Session);
  protected readonly options = signal<LeaveOptions | null>(null);
  /** Requests waiting in this reviewer's queue; null until counted (or for a non-reviewer). */
  protected readonly reviewPending = signal<number | null>(null);
  protected readonly rows = signal<LeaveRecord[]>([]);
  protected readonly quote = signal<LeaveQuote | null>(null);
  protected readonly busy = signal(false);
  protected readonly exporting = signal(false);
  protected readonly error = signal('');
  protected readonly success = signal('');
  protected scope: 'mine' | 'review' | 'all' = 'mine';
  protected page = 1;
  protected lastPage = 1;
  protected forEmployee = '';
  protected approveNow = false;
  protected filters: LeaveFilters = {status: '', employee: '', type: '', department: '', from: '', to: '', change: ''};
  protected editing?: LeaveRecord;
  protected consent = false;
  protected form: LeaveInput = {leave_type_id: 0, start_date: '', end_date: '', unit: 'day', reason: ''};
  protected halves: HalfDays = {first_start: '09:00', first_end: '12:30', second_start: '12:30', second_end: '16:00'};
  protected readonly calendar = signal<LeaveBoard | null>(null);
  protected calendarFrom = '';
  protected calendarTo = '';
  protected calendarDepartment = '';
  protected includePending = false;
  protected decision: {row: LeaveRecord; action: string} | null = null;
  private submissionKey = this.newKey();

  private newKey(): string {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
  }

  constructor() { super(); void this.run(() => this.load()); }
  protected l(ar: string, en: string): string { return this.language.lang() === 'ar' ? ar : en; }
  private arabic(): boolean { return this.language.lang() === 'ar'; }
  private days(units: number): string { return this.num(units / 1_000_000); }
  protected invalidate(): void { this.quote.set(null); this.consent = false; }
  private async load(): Promise<void> {
    await this.loadOptions(this.subjectId());
    const data = this.options();
    if (data && !this.form.start_date) this.form.start_date = this.form.end_date = data.today;
    if (data && !this.calendarFrom) {
      this.calendarFrom = data.today;
      this.calendarTo = leaveShift(data.today, 13);
    }
    await this.loadRows();
    if (data?.can_review) void this.loadReviewCount();
  }
  /** One page-1 request on the review queue, read for its total. */
  private async loadReviewCount(): Promise<void> {
    try {
      const result = await this.api.list({scope: 'review', page: 1, status: 'pending'});
      const meta = result.meta as {total?: number};
      this.reviewPending.set(meta.total ?? result.data.length);
    } catch {
      this.reviewPending.set(null);
    }
  }
  protected openReview(): void {
    this.changeScope('review');
  }
  protected balanceDays(units: number | null): string { return units === null ? '—' : this.days(units); }
  private async loadOptions(employeeId?: number): Promise<void> {
    const {data} = await this.api.options(employeeId);
    this.options.set(data);
    if (data.half_days?.first_start) {
      this.halves = {
        first_start: leaveClock(data.half_days.first_start), first_end: leaveClock(data.half_days.first_end),
        second_start: leaveClock(data.half_days.second_start), second_end: leaveClock(data.half_days.second_end),
      };
    }
    if (!data.types.some((type) => type.id === this.form.leave_type_id)) this.form.leave_type_id = data.types[0]?.id ?? 0;
  }
  private subjectId(): number | undefined {
    const id = Number(this.forEmployee);
    return !this.editing && this.options()?.can_configure && id ? id : undefined;
  }
  private query(): LeaveListQuery {
    const employee = Number(this.filters.employee);
    const type = Number(this.filters.type);
    const department = Number(this.filters.department);
    return {
      scope: this.scope, page: this.page,
      ...(this.filters.status ? {status: this.filters.status} : {}),
      ...(employee ? {employee_id: employee} : {}),
      ...(type ? {leave_type_id: type} : {}),
      ...(department ? {department_id: department} : {}),
      ...(this.filters.from ? {from: this.filters.from} : {}),
      ...(this.filters.to ? {to: this.filters.to} : {}),
      ...(this.filters.change === '' ? {} : {change_pending: this.filters.change === '1'}),
    };
  }
  private async loadRows(): Promise<void> {
    const result = await this.api.list(this.query());
    this.rows.set(result.data);
    this.lastPage = result.meta.last_page;
  }
  protected changeScope(scope: 'mine' | 'review' | 'all'): void {
    this.scope = scope; this.page = 1; void this.run(() => this.loadRows());
  }
  protected applyFilters(): void {
    this.page = 1; void this.run(() => this.loadRows());
  }
  protected pickEmployee(id: string): void {
    this.forEmployee = id; this.approveNow = false; this.invalidate();
    void this.run(() => this.loadOptions(id ? Number(id) : undefined));
  }
  protected turnPage(delta: number): void { this.page += delta; void this.run(() => this.loadRows()); }
  protected preview(): void {
    void this.run(async () => {
      this.invalidate();
      const input = this.input();
      const result = await this.api.preview(input, this.editing?.id, this.subjectId());
      this.quote.set(result.data);
    });
  }
  private input(): LeaveInput {
    const {half, start_time, end_time, ...base} = this.form;
    return {...base, ...(base.unit === 'half' ? {half: half ?? 'first'} : {}),
      ...(base.unit === 'hours' ? {start_time: leaveClock(start_time), end_time: leaveClock(end_time)} : {})};
  }
  protected submit(): void {
    const quote = this.quote();
    if (!quote || (quote.unpaid_units > 0 && !this.consent)) return;
    void this.run(async () => {
      await this.api.submit(this.input(), quote, this.consent, this.submissionKey, this.editing, this.subjectId(), this.approveNow);
      this.reset();
      this.success.set(this.l('تم حفظ الطلب وحجز الرصيد.', 'Request saved and balance reserved.'));
      await this.load();
    });
  }
  protected edit(row: LeaveRecord): void {
    this.editing = row; this.form = {...row.input, leave_type_id: row.leave_type_id};
    this.invalidate(); this.success.set(''); this.decision = null;
    window.scrollTo({top: 0, behavior: 'smooth'});
  }
  protected reset(): void {
    this.editing = undefined; this.forEmployee = ''; this.approveNow = false; this.submissionKey = this.newKey(); this.invalidate();
    this.form = {leave_type_id: this.options()?.types[0]?.id ?? 0, start_date: this.options()?.today ?? '',
      end_date: this.options()?.today ?? '', unit: 'day', reason: ''};
  }
  protected choose(row: LeaveRecord, action: string): void {
    this.decision = {row, action};
  }
  protected applyDecision(draft: {reason: string; from: string}): void {
    const decision = this.decision;
    if (!decision) return;
    void this.run(async () => {
      await this.api.action(decision.row, decision.action, draft.reason,
        decision.action === 'shorten' ? draft.from : undefined);
      this.decision = null;
      this.success.set(this.l('تم حفظ الإجراء.', 'Action saved.'));
      await this.load();
    });
  }
  protected async exportExcel(): Promise<void> {
    await this.exportReport(draft => this.reporter.excel(draft));
  }
  protected exportPdf(): void {
    void this.exportReport(draft => { this.reporter.pdf(draft); return Promise.resolve(); });
  }
  protected loadCalendar(): void {
    const from = new Date(`${this.calendarFrom}T00:00:00`);
    const to = new Date(`${this.calendarTo}T00:00:00`);
    if ((to.getTime() - from.getTime()) / 86_400_000 > 61) {
      this.error.set(this.l('الحد الأقصى 62 يوماً.', 'The maximum span is 62 days.'));
      return;
    }
    void this.run(async () => {
      const department = Number(this.calendarDepartment);
      const result = await this.api.calendar(this.calendarFrom, this.calendarTo, department || undefined, this.includePending);
      this.calendar.set(result.data);
    });
  }
  protected saveHalves(): void {
    void this.run(async () => {
      await this.api.saveHalves(this.halves);
      this.invalidate();
      this.options.update(value => value ? {...value, half_days: {...this.halves}} : value);
      this.success.set(this.l('تم حفظ فترتي نصف اليوم.', 'Half-day periods saved.'));
    });
  }
  private async matching(): Promise<LeaveRecord[]> {
    const query = this.query();
    const first = await this.api.list({...query, page: 1});
    const rows = [...first.data];
    for (let page = 2; page <= first.meta.last_page; page++) {
      rows.push(...(await this.api.list({...query, page})).data);
    }
    return rows;
  }
  private draft(rows: LeaveRecord[]): ReportDraft {
    const count = (status: string) => rows.filter(row => row.status === status).length;
    const active = (row: LeaveRecord) => (row.days ?? []).filter(day => day.active !== false);
    const sum = (row: LeaveRecord, key: 'covered_units' | 'unpaid_units') => active(row).reduce((total, day) => total + day[key], 0);
    const typeName = (id: number) => this.options()?.types.find(type => type.id === id)?.name ?? String(id);
    const unit: Record<LeaveInput['unit'], [string, string]> = {day: ['يوم', 'Day'], half: ['نصف يوم', 'Half day'], hours: ['ساعات', 'Hours']};
    const scope: Record<typeof this.scope, [string, string]> = {mine: ['طلباتي', 'My requests'], review: ['مراجعة الطلبات', 'Review'], all: ['كل الطلبات', 'All requests']};
    const calendar = this.calendar();
    const label = (status: string) => leaveLabel(this.arabic(), status);
    return {
      file: 'leaves',
      title: this.l('تقرير الإجازات', 'Leave report'),
      subtitle: this.l(...scope[this.scope]),
      landscape: true,
      meta: [
        [this.l('النطاق', 'Scope'), this.l(...scope[this.scope])],
        ...(this.filters.status ? [[this.l('الحالة', 'Status'), label(this.filters.status)] as [string, string]] : []),
        ...(this.filters.from || this.filters.to ? [[this.l('الفترة', 'Period'), `${this.filters.from || '…'} – ${this.filters.to || '…'}`] as [string, string]] : []),
      ],
      figures: [
        {label: this.l('الطلبات', 'Requests'), value: this.num(rows.length)},
        {label: label('pending'), value: this.num(count('pending'))},
        {label: label('approved'), value: this.num(count('approved'))},
        {label: label('rejected'), value: this.num(count('rejected'))},
      ],
      tables: [
        {
          name: this.l('الطلبات', 'Requests'),
          columns: [
            {header: this.l('الطلب', 'Request'), numeric: true},
            {header: this.l('الموظف', 'Employee')},
            {header: this.l('النوع', 'Type')},
            {header: this.l('الحالة', 'Status')},
            {header: this.l('من', 'From'), numeric: true},
            {header: this.l('إلى', 'Until'), numeric: true},
            {header: this.l('الوحدة', 'Unit')},
            {header: this.l('السبب', 'Reason')},
            {header: this.l('أيام مغطاة', 'Covered days'), numeric: true},
            {header: this.l('أيام غير مدفوعة', 'Unpaid days'), numeric: true},
          ],
          rows: rows.map(row => [
            row.id, row.employee_name, typeName(row.leave_type_id), label(row.status),
            row.input.start_date, row.input.end_date, this.l(...unit[row.input.unit]), row.input.reason ?? '',
            this.days(sum(row, 'covered_units')), this.days(sum(row, 'unpaid_units')),
          ]),
        },
        ...(calendar ? [
          {
            name: this.l('من في إجازة', 'Who is away'),
            columns: [
              {header: this.l('التاريخ', 'Date'), numeric: true},
              {header: this.l('الموظف', 'Employee')},
              {header: this.l('النوع', 'Type')},
              {header: this.l('الحالة', 'Status')},
              {header: this.l('من', 'From'), numeric: true},
              {header: this.l('إلى', 'Until'), numeric: true},
            ],
            rows: calendar.days.map(day => [day.date, day.employee_name ?? '', day.leave_type?.name ?? '', label(day.status), leaveClock(day.start_time), leaveClock(day.end_time)]),
          },
          {
            name: this.l('العطل الرسمية', 'Office holidays'),
            columns: [{header: this.l('التاريخ', 'Date'), numeric: true}, {header: this.l('الاسم', 'Name')}],
            rows: (calendar.holidays ?? []).map(holiday => [holiday.date.slice(0, 10), holiday.name]),
          },
        ] : []),
      ],
    };
  }
  private async exportReport(write: (draft: ReportDraft) => Promise<void>): Promise<void> {
    if (this.exporting()) return;
    this.exporting.set(true);
    this.error.set('');
    try { await write(this.draft(await this.matching())); }
    catch (error) { this.error.set(serverMessage(error) || this.l('تعذر الاتصال بالخادم.', 'Unable to reach server.')); }
    finally { this.exporting.set(false); }
  }
  private async run(work: () => Promise<void>): Promise<void> {
    if (this.busy()) return;
    this.busy.set(true); this.error.set(''); this.success.set('');
    try { await work(); }
    catch (error) {
      this.error.set(serverMessage(error) || this.l('تعذر الاتصال بالخادم.', 'Unable to reach server.'));
      this.quote.set(null);
    } finally { this.busy.set(false); }
  }
}
