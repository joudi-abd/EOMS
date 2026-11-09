import { Component, computed, inject, signal } from '@angular/core';
import { Api, serverMessage } from '../../../core/api/api';
import { CopyKey } from '../../../core/copy';
import { Ui } from '../../../core/ui';
import { HalfDays, Leaves } from '../../../data/leaves';
import { Office } from '../../../data/office';

interface LeaveType {
  id: number;
  name: string;
  active: boolean;
}

interface LeavePolicy {
  id: number;
  leave_type_id: number;
  effective_from: string;
  annual_days: string | null;
  pay_percent: number;
  grant_method: string | null;
  year_basis: string | null;
  year_start: string | null;
  first_year_method: string | null;
  retroactive_days: number;
}

interface Holiday {
  id: number;
  name: string;
  date: string;
}

interface BalanceEntry {
  id: number;
  leave_type_id: number;
  cycle_start: string;
  kind: 'opening' | 'adjustment' | 'grant' | 'reserve' | 'release' | 'holiday_refund';
  day_units: number;
  reason: string;
}

interface PendingType {
  leave_type_id: number;
  name: string;
  cycle_start: string;
}

interface PendingOpening {
  employee_id: number;
  employee_no: string;
  employee_name: string;
  service_period_id: number;
  service_start: string;
  leave_types: PendingType[];
}

interface LeaveSettingsData {
  types: LeaveType[];
  policies: LeavePolicy[];
  holidays: Holiday[];
  today: string;
}

type Section = 'types' | 'policies' | 'holidays' | 'halves' | 'balances';

@Component({
  selector: 'app-settings-leaves',
  templateUrl: './settings-leaves.html',
  styleUrl: './settings-leaves.scss',
})
export class SettingsLeaves extends Ui {
  private readonly api = inject(Api);
  private readonly leaves = inject(Leaves);
  protected readonly office = inject(Office);
  protected readonly sections: { id: Section; label: CopyKey; icon: string }[] = [
    { id: 'types', label: 'listLeaveTypes', icon: 'fa-list' },
    { id: 'policies', label: 'leaveSetupPolicies', icon: 'fa-file-lines' },
    { id: 'holidays', label: 'leaveSetupHolidays', icon: 'fa-calendar-day' },
    { id: 'halves', label: 'leaveSetupHalves', icon: 'fa-clock' },
    { id: 'balances', label: 'leaveSetupBalances', icon: 'fa-scale-balanced' },
  ];
  protected readonly section = signal<Section>('types');
  protected readonly settings = signal<LeaveSettingsData | null>(null);
  protected readonly busy = signal(false);
  protected readonly error = signal('');
  protected readonly selectedType = signal(0);
  protected readonly employeeId = signal('');
  protected readonly entries = signal<BalanceEntry[]>([]);
  protected readonly pending = signal<PendingOpening[]>([]);
  protected readonly halves = signal<HalfDays | null>(null);
  protected readonly kind = signal<'opening' | 'adjustment'>('opening');
  protected readonly prefillType = signal(0);
  protected readonly typeNames = computed(() => new Map(this.settings()?.types.map((type) => [type.id, type.name])));
  protected readonly tracked = signal(true);
  protected readonly monthly = signal(false);
  protected readonly fixed = signal(true);
  protected readonly policies = computed(() =>
    this.settings()?.policies.filter((policy) => policy.leave_type_id === this.selectedType()) ?? [],
  );

  constructor() {
    super();
    void this.run(() => this.loadSettings());
  }

  protected movement(kind: BalanceEntry['kind']): string {
    const labels: Record<BalanceEntry['kind'], CopyKey> = {
      opening: 'leaveSetupOpening',
      adjustment: 'leaveSetupAdjustment',
      grant: 'leaveSetupGrantAuto',
      reserve: 'leaveSetupReserve',
      release: 'leaveSetupRelease',
      holiday_refund: 'leaveSetupHolidayRefund',
    };
    return labels[kind] ? this.t(labels[kind]) : kind;
  }

  protected shown(value: string): string {
    return this.date(this.plainDay(value));
  }

  protected units(value: number): string {
    return this.num(value / 1_000_000);
  }

  protected allowance(value: string | null): string {
    if (value === null || value === '') return this.t('leaveSetupUntracked');
    const amount = Number(value);
    return Number.isFinite(amount) ? this.num(amount) : value;
  }

  protected grantLabel(value: string | null): string {
    if (value === 'monthly') return this.t('leaveSetupMonthly');
    if (value === 'upfront') return this.t('leaveSetupUpfront');
    return '—';
  }

  protected basisLabel(policy: LeavePolicy): string {
    if (policy.year_basis === 'anniversary') return this.t('leaveSetupAnniversary');
    if (policy.year_basis === 'fixed') return policy.year_start ?? '—';
    return '—';
  }

  protected firstLabel(value: string | null): string {
    if (value === 'remaining') return this.t('leaveSetupRemaining');
    if (value === 'upfront') return this.t('leaveSetupUpfront');
    return '—';
  }

  protected canActivate(type: LeaveType): boolean {
    const today = this.settings()?.today ?? '';
    return this.settings()?.policies.some((policy) => policy.leave_type_id === type.id && this.plainDay(policy.effective_from) <= today) ?? false;
  }

  /** The policy in force today for a type: the latest one already effective. */
  protected currentPolicyOf(type: LeaveType): LeavePolicy | null {
    const today = this.settings()?.today ?? '';
    return (
      (this.settings()?.policies ?? [])
        .filter((policy) => policy.leave_type_id === type.id && this.plainDay(policy.effective_from) <= today)
        .sort((a, b) => this.plainDay(b.effective_from).localeCompare(this.plainDay(a.effective_from)))[0] ?? null
    );
  }

  /** A published policy that starts after today, if any. */
  protected futurePolicyOf(type: LeaveType): LeavePolicy | null {
    const today = this.settings()?.today ?? '';
    return (
      (this.settings()?.policies ?? [])
        .filter((policy) => policy.leave_type_id === type.id && this.plainDay(policy.effective_from) > today)
        .sort((a, b) => this.plainDay(a.effective_from).localeCompare(this.plainDay(b.effective_from)))[0] ?? null
    );
  }

  /** Opens the policies section on this type, ready to publish a new version. */
  protected openPolicies(type: LeaveType): void {
    this.pickType(type.id);
    this.section.set('policies');
  }

  protected isPast(value: string): boolean {
    return this.plainDay(value) < (this.settings()?.today ?? '');
  }

  protected dayOf(value: string): string {
    return this.plainDay(value).slice(8, 10);
  }

  protected monthOf(value: string): string {
    const day = this.plainDay(value);
    const date = new Date(`${day}T00:00:00`);
    return Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat(this.language.lang() === 'ar' ? 'ar-SY' : 'en-GB', { month: 'short' }).format(date);
  }

  protected currentPolicyId(): number {
    const today = this.settings()?.today ?? '';
    return this.policies().find((policy) => this.plainDay(policy.effective_from) <= today)?.id ?? 0;
  }

  protected calendar(): LeavePolicy | null {
    const id = this.selectedType();
    const rows = (this.settings()?.policies ?? [])
      .filter((policy) => policy.leave_type_id === id && policy.annual_days !== null)
      .sort((a, b) => this.plainDay(a.effective_from).localeCompare(this.plainDay(b.effective_from)));
    return rows[0] ?? null;
  }

  protected earliest(): string {
    const today = this.settings()?.today ?? '';
    const latest = this.policies().map((policy) => this.plainDay(policy.effective_from)).sort().at(-1) ?? '';
    const after = latest ? this.dayAfter(latest) : today;
    return after > today ? after : today;
  }

  protected roster(): { id: string; staffNo: string; name: string }[] {
    const rows = this.office.employees()
      .filter((person) => person.status !== 'ended')
      .map((person) => ({ id: person.id, staffNo: person.staffNo, name: this.text(person.name) }));
    for (const row of this.pending()) {
      const id = String(row.employee_id);
      if (!rows.some((person) => person.id === id)) rows.push({ id, staffNo: row.employee_no, name: row.employee_name });
    }
    return rows;
  }

  protected pickType(id: number): void {
    this.selectedType.set(id);
    const latest = this.policies()[0];
    if (!latest) return;
    this.tracked.set(latest.annual_days !== null);
    this.monthly.set(latest.grant_method === 'monthly');
    this.fixed.set(latest.year_basis !== 'anniversary');
  }

  protected recordOpening(row: PendingOpening, typeId: number): void {
    this.kind.set('opening');
    this.prefillType.set(typeId);
    this.section.set('balances');
    this.selectEmployee(String(row.employee_id));
  }

  protected submitType(event: SubmitEvent): void {
    this.submit(event, '/leave-settings/types', (form) => ({ name: String(form.get('name') ?? '').trim() }));
  }

  protected submitPolicy(event: SubmitEvent): void {
    this.submit(event, '/leave-settings/types/' + this.selectedType() + '/policies', (form) => {
      const tracked = this.tracked();
      const calendar = this.calendar();
      const basis = !tracked ? null : (calendar?.year_basis ?? String(form.get('year_basis') ?? ''));
      const monthly = tracked && String(form.get('grant_method') ?? '') === 'monthly';
      return {
        effective_from: form.get('effective_from'),
        pay_percent: Number(form.get('pay_percent')),
        retroactive_days: Number(form.get('retroactive_days')),
        annual_days: tracked ? this.scaled(form.get('annual_days'), 6) : null,
        grant_method: tracked ? form.get('grant_method') : null,
        year_basis: basis,
        year_start: basis === 'fixed' ? (calendar?.year_start ?? form.get('year_start')) : null,
        first_year_method: monthly ? form.get('first_year_method') : null,
      };
    });
  }

  protected toggle(type: LeaveType): void {
    void this.run(async () => {
      await this.api.patch('/leave-settings/types/' + type.id + '/activation', { active: !type.active });
      await this.loadSettings();
      this.office.done('leaveSetupSaved');
    });
  }

  protected submitHoliday(event: SubmitEvent): void {
    this.submit(event, '/leave-settings/holidays', (form) => ({
      date: form.get('date'),
      name: String(form.get('name') ?? '').trim(),
    }));
  }

  protected removeHoliday(id: number): void {
    void this.run(async () => {
      await this.api.delete('/leave-settings/holidays/' + id);
      await this.loadSettings();
      this.office.done('leaveSetupSaved');
    });
  }

  protected selectEmployee(id: string): void {
    this.employeeId.set(id);
    this.entries.set([]);
    if (id) void this.run(() => this.loadEntries(id));
  }

  protected submitBalance(event: SubmitEvent): void {
    const employee = this.employeeId();
    this.submit(event, '/leave-settings/balance-entries', (form) => ({
      employee_id: Number(employee),
      leave_type_id: Number(form.get('leave_type_id')),
      kind: form.get('kind'),
      days: this.scaled(form.get('days'), 6),
      reason: String(form.get('reason') ?? '').trim(),
    }), () => this.afterBalance(employee));
  }

  protected submitHalves(event: SubmitEvent): void {
    event.preventDefault();
    const form = new FormData(event.currentTarget as HTMLFormElement);
    const clock = (name: string) => String(form.get(name) ?? '').slice(0, 5);
    const body: HalfDays = {
      first_start: clock('first_start'),
      first_end: clock('first_end'),
      second_start: clock('second_start'),
      second_end: clock('second_end'),
    };
    void this.run(async () => {
      await this.leaves.saveHalves(body);
      this.halves.set(body);
      this.office.done('leaveSetupSaved');
    });
  }

  private submit(
    event: SubmitEvent,
    path: string,
    body: (data: FormData) => object,
    refresh: () => Promise<void> = () => this.loadSettings(),
    reset = true,
  ): void {
    event.preventDefault();
    const form = event.currentTarget as HTMLFormElement;
    const data = body(new FormData(form));
    void this.run(async () => {
      await this.api.post(path, data);
      if (reset) form.reset();
      await refresh();
      this.office.done('leaveSetupSaved');
    });
  }

  private async loadSettings(): Promise<void> {
    const [response, pending, options] = await Promise.all([
      this.api.get<{ data: LeaveSettingsData }>('/leave-settings'),
      this.api.get<{ data: PendingOpening[] }>('/leave-settings/pending-openings'),
      this.leaves.options(),
    ]);
    this.settings.set(response.data);
    this.pending.set(pending.data);
    if (!this.selectedType()) this.selectedType.set(response.data.types[0]?.id ?? 0);
    if (options.data.half_days?.first_start) this.halves.set(this.clocks(options.data.half_days));
  }

  private async loadEntries(id: string): Promise<void> {
    this.entries.set(await this.api.all<BalanceEntry>('/leave-settings/balance-entries', { employee_id: id }));
  }

  private async afterBalance(id: string): Promise<void> {
    await Promise.all([this.loadEntries(id), this.loadSettings()]);
  }

  private async run(action: () => Promise<void>): Promise<void> {
    if (this.busy()) return;
    this.busy.set(true);
    this.error.set('');
    try {
      await action();
    } catch (error) {
      this.error.set(serverMessage(error) || this.t('leaveSetupError'));
    } finally {
      this.busy.set(false);
    }
  }

  private plainDay(value: string): string {
    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return value;
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value.slice(0, 10);
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${date.getFullYear()}-${month}-${day}`;
  }

  private dayAfter(iso: string): string {
    const [year, month, day] = iso.split('-').map(Number);
    const date = new Date(year, month - 1, day + 1);
    const nextMonth = String(date.getMonth() + 1).padStart(2, '0');
    const nextDay = String(date.getDate()).padStart(2, '0');
    return `${date.getFullYear()}-${nextMonth}-${nextDay}`;
  }

  private scaled(raw: FormDataEntryValue | null, places: number): string {
    const text = String(raw ?? '').trim();
    const match = text.match(/^(-?)(\d+)(?:\.(\d+))?$/);
    if (!match) return text;
    const whole = match[2].replace(/^0+(?=\d)/, '');
    const fraction = (match[3] ?? '').slice(0, places).replace(/0+$/, '');
    return match[1] + whole + (fraction ? `.${fraction}` : '');
  }

  private clocks(row: HalfDays): HalfDays {
    const cut = (value: string) => value.slice(0, 5);
    return {
      first_start: cut(row.first_start),
      first_end: cut(row.first_end),
      second_start: cut(row.second_start),
      second_end: cut(row.second_end),
    };
  }
}
