import { Component, computed, inject, signal } from '@angular/core';
import { Api, serverMessage } from '../../../core/api/api';
import { Ui } from '../../../core/ui';
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

interface LeaveSettingsData {
  types: LeaveType[];
  policies: LeavePolicy[];
  holidays: Holiday[];
  today: string;
}

@Component({
  selector: 'app-settings-leaves',
  templateUrl: './settings-leaves.html',
  styleUrl: './settings-leaves.scss',
})
export class SettingsLeaves extends Ui {
  private readonly api = inject(Api);
  protected readonly office = inject(Office);
  protected readonly settings = signal<LeaveSettingsData | null>(null);
  protected readonly busy = signal(false);
  protected readonly error = signal('');
  protected readonly selectedType = signal(0);
  protected readonly employeeId = signal('');
  protected readonly entries = signal<BalanceEntry[]>([]);
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
    const labels: Record<BalanceEntry['kind'], [string, string]> = {
      opening: ['رصيد افتتاحي', 'Opening balance'], adjustment: ['تسوية', 'Adjustment'],
      grant: ['منح تلقائي', 'Automatic grant'], reserve: ['حجز رصيد', 'Reservation'],
      release: ['إعادة رصيد', 'Balance release'], holiday_refund: ['استرجاع بسبب عطلة', 'Holiday refund'],
    };
    return labels[kind]?.[this.language.lang() === 'ar' ? 0 : 1] ?? kind;
  }

  protected submitType(event: SubmitEvent): void {
    this.submit(event, '/leave-settings/types', (form) => ({ name: form.get('name') }));
  }

  protected submitPolicy(event: SubmitEvent): void {
    this.submit(event, '/leave-settings/types/' + this.selectedType() + '/policies', (form) => ({
      effective_from: form.get('effective_from'),
      pay_percent: Number(form.get('pay_percent')),
      annual_days: this.tracked() ? form.get('annual_days') : null,
      grant_method: this.tracked() ? form.get('grant_method') : null,
      year_basis: this.tracked() ? form.get('year_basis') : null,
      year_start: this.tracked() && this.fixed() ? form.get('year_start') : null,
      first_year_method: this.tracked() && this.monthly() ? form.get('first_year_method') : null,
      retroactive_days: Number(form.get('retroactive_days')),
    }), () => this.loadSettings(), false);
  }

  protected toggle(type: LeaveType): void {
    void this.run(async () => {
      await this.api.patch('/leave-settings/types/' + type.id + '/activation', { active: !type.active });
      await this.loadSettings();
    });
  }

  protected submitHoliday(event: SubmitEvent): void {
    this.submit(event, '/leave-settings/holidays', (form) => ({ date: form.get('date'), name: form.get('name') }));
  }

  protected removeHoliday(id: number): void {
    void this.run(async () => {
      await this.api.delete('/leave-settings/holidays/' + id);
      await this.loadSettings();
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
      days: form.get('days'),
      reason: form.get('reason'),
    }), () => this.loadEntries(employee));
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
    });
  }

  private async loadSettings(): Promise<void> {
    const response = await this.api.get<{ data: LeaveSettingsData }>('/leave-settings');
    this.settings.set(response.data);
    if (!this.selectedType()) this.selectedType.set(response.data.types[0]?.id ?? 0);
  }

  private async loadEntries(id: string): Promise<void> {
    this.entries.set(await this.api.all<BalanceEntry>('/leave-settings/balance-entries', { employee_id: id }));
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
}
