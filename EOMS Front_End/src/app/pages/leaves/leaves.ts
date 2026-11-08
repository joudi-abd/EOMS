import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Ui } from '../../core/ui';
import { serverMessage } from '../../core/api/api';
import { Chrome } from '../../shell/chrome';
import { Leaves, LeaveInput, LeaveOptions, LeaveQuote, LeaveRecord, HalfDays } from '../../data/leaves';

@Component({
  selector: 'app-leaves',
  imports: [FormsModule, RouterLink],
  templateUrl: './leaves.html',
  styleUrl: './leaves.scss',
})
export class LeavesPage extends Ui {
  protected readonly chrome = inject(Chrome);
  private readonly api = inject(Leaves);
  protected readonly options = signal<LeaveOptions | null>(null);
  protected readonly rows = signal<LeaveRecord[]>([]);
  protected readonly quote = signal<LeaveQuote | null>(null);
  protected readonly busy = signal(false);
  protected readonly error = signal('');
  protected readonly success = signal('');
  protected scope: 'mine' | 'review' = 'mine';
  protected page = 1;
  protected lastPage = 1;
  protected editing?: LeaveRecord;
  protected consent = false;
  protected form: LeaveInput = {leave_type_id: 0, start_date: '', end_date: '', unit: 'day', reason: ''};
  protected halves: HalfDays = {first_start: '09:00', first_end: '12:30', second_start: '12:30', second_end: '16:00'};
  protected decision: {row: LeaveRecord; action: string} | null = null;
  protected decisionReason = '';
  protected changeDate = '';
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
  protected days(units: number): string { return this.num(units / 1_000_000); }
  protected invalidate(): void { this.quote.set(null); this.consent = false; }
  private async load(): Promise<void> {
    const {data} = await this.api.options();
    this.options.set(data);
    this.halves = {...data.half_days};
    if (!this.form.leave_type_id) this.form.leave_type_id = data.types[0]?.id ?? 0;
    if (!this.form.start_date) this.form.start_date = this.form.end_date = data.today;
    await this.loadRows();
  }
  private async loadRows(): Promise<void> {
    const result = await this.api.list(this.scope, this.page);
    this.rows.set(result.data);
    this.lastPage = result.meta.last_page;
  }
  protected changeScope(scope: 'mine' | 'review'): void {
    this.scope = scope; this.page = 1; void this.run(() => this.loadRows());
  }
  protected turnPage(delta: number): void { this.page += delta; void this.run(() => this.loadRows()); }
  protected preview(): void {
    void this.run(async () => {
      this.invalidate();
      const input = this.input();
      const result = await this.api.preview(input, this.editing?.id);
      this.quote.set(result.data);
    });
  }
  private input(): LeaveInput {
    const {half, start_time, end_time, ...base} = this.form;
    return {...base, ...(base.unit === 'half' ? {half: half ?? 'first'} : {}),
      ...(base.unit === 'hours' ? {start_time, end_time} : {})};
  }
  protected submit(): void {
    const quote = this.quote();
    if (!quote || (quote.unpaid_units > 0 && !this.consent)) return;
    void this.run(async () => {
      await this.api.submit(this.input(), quote, this.consent, this.submissionKey, this.editing);
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
    this.editing = undefined; this.submissionKey = this.newKey(); this.invalidate();
    this.form = {leave_type_id: this.options()?.types[0]?.id ?? 0, start_date: this.options()?.today ?? '',
      end_date: this.options()?.today ?? '', unit: 'day', reason: ''};
  }
  protected choose(row: LeaveRecord, action: string): void {
    this.decision = {row, action}; this.decisionReason = ''; this.changeDate = '';
  }
  protected applyDecision(): void {
    const decision = this.decision;
    if (!decision) return;
    void this.run(async () => {
      await this.api.action(decision.row, decision.action, this.decisionReason,
        decision.action === 'shorten' ? this.changeDate : undefined);
      this.decision = null;
      this.success.set(this.l('تم حفظ الإجراء.', 'Action saved.'));
      await this.load();
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
  protected label(status: string): string {
    const labels: Record<string, [string, string]> = {
      pending: ['بانتظار الموافقة', 'Pending'], approved: ['معتمدة', 'Approved'], rejected: ['مرفوضة', 'Rejected'],
      withdrawn: ['مسحوبة', 'Withdrawn'], cancelled: ['ملغاة', 'Cancelled'], expired: ['منتهية', 'Expired'],
      approve: ['اعتماد', 'Approve'], reject: ['رفض', 'Reject'], withdraw: ['سحب الطلب', 'Withdraw'],
      cancel: ['طلب إلغاء', 'Request cancellation'], shorten: ['طلب تقصير', 'Request shortening'],
      'approve-change': ['اعتماد الإلغاء / التقصير', 'Approve change'], 'reject-change': ['رفض التغيير', 'Reject change'],
    };
    const pair = labels[status]; return pair ? this.l(...pair) : status;
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
