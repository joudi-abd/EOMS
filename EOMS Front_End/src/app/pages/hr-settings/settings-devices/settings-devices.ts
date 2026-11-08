import { afterNextRender, Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Session } from '../../../core/access/session';
import { ApiBinding, ApiDevice } from '../../../core/api/types';
import { Ui } from '../../../core/ui';
import { dayIn } from '../../../data/attendance';
import { clockOf, dateOf } from '../../../data/attendance-book';
import { Devices } from '../../../data/devices';
import { Office } from '../../../data/office';

interface Reveal {
  device: string;
  value: string;
}

/** Punch devices: register, stop, renew the token, choose the connection mode, and bind device numbers to employees. */
@Component({
  selector: 'app-settings-devices',
  imports: [RouterLink],
  templateUrl: './settings-devices.html',
  styleUrl: './settings-devices.scss',
})
export class SettingsDevices extends Ui {
  protected readonly devices = inject(Devices);
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);

  protected readonly openId = signal<number | null>(null);
  protected readonly reveal = signal<Reveal | null>(null);
  protected readonly copied = signal(false);
  protected readonly problem = signal('');
  protected readonly working = signal(false);
  protected readonly mode = signal<'bridge' | 'adms'>('bridge');
  protected readonly closing = signal<number | null>(null);

  protected readonly opened = computed(() => this.devices.devices().find((item) => item.id === this.openId()) ?? null);
  /** Null until the server has answered, so an empty list is never shown while it loads. */
  protected readonly rows = computed<ApiBinding[] | null>(() => {
    const id = this.openId();
    return id === null ? null : (this.devices.bindings()[id] ?? null);
  });
  protected readonly people = computed(() => this.office.employees().filter((item) => item.status !== 'ended'));

  constructor() {
    super();
    afterNextRender(() => void this.devices.load());
  }

  protected today(device: ApiDevice): string {
    return dayIn(device.timezone);
  }

  protected seen(device: ApiDevice): string {
    if (!device.last_seen_at) return this.t('deviceNeverSeen');
    return `${this.short(dateOf(device.last_seen_at, device.timezone))} ${clockOf(device.last_seen_at, device.timezone)}`;
  }

  protected through(device: ApiDevice): string {
    if (!device.complete_through) return '—';
    return `${this.short(dateOf(device.complete_through, device.timezone))} ${clockOf(device.complete_through, device.timezone)}`;
  }

  protected modeName(value: string): 'modeAdms' | 'modeBridge' {
    return value === 'adms' ? 'modeAdms' : 'modeBridge';
  }

  protected personName(binding: ApiBinding): string {
    const person = this.office.person(String(binding.employee_id));
    return person ? this.text(person.name) : `#${binding.employee_id}`;
  }

  /** The device number starts as the employee's staff number and stays editable. */
  protected fillPin(event: Event): void {
    const select = event.target as HTMLSelectElement;
    const pin = select.form?.elements.namedItem('pin');
    const person = this.office.person(select.value);
    if (pin instanceof HTMLInputElement) pin.value = person?.staffNo ?? '';
  }

  protected open(device: ApiDevice): void {
    if (this.openId() === device.id) {
      this.openId.set(null);
      return;
    }
    this.openId.set(device.id);
    this.problem.set('');
    this.closing.set(null);
    this.mode.set(device.connection_mode === 'adms' ? 'adms' : 'bridge');
    void this.devices.loadBindings(device.id);
  }

  protected async add(event: Event): Promise<void> {
    event.preventDefault();
    const form = event.target as HTMLFormElement;
    const data = new FormData(form);
    this.working.set(true);
    const outcome = await this.devices.create({
      name: String(data.get('name') ?? ''),
      model: String(data.get('model') ?? ''),
      serial: String(data.get('serial') ?? ''),
    });
    this.working.set(false);
    this.problem.set(outcome.ok ? '' : (outcome.error ?? ''));
    if (outcome.ok && outcome.token) {
      this.reveal.set({ device: String(data.get('name') ?? ''), value: outcome.token });
      this.copied.set(false);
      form.reset();
    }
  }

  protected async toggle(device: ApiDevice): Promise<void> {
    const outcome = await this.devices.setActive(device, !device.is_active);
    this.problem.set(outcome.ok ? '' : (outcome.error ?? ''));
  }

  protected async rotate(device: ApiDevice): Promise<void> {
    if (!confirm(this.t('rotateWarn'))) return;
    this.working.set(true);
    const outcome = await this.devices.rotate(device);
    this.working.set(false);
    this.problem.set(outcome.ok ? '' : (outcome.error ?? ''));
    if (outcome.ok && outcome.token) {
      this.reveal.set({ device: device.name, value: outcome.token });
      this.copied.set(false);
    }
  }

  protected async copy(): Promise<void> {
    const value = this.reveal()?.value;
    if (!value) return;
    try {
      await navigator.clipboard.writeText(value);
      this.copied.set(true);
    } catch {
      this.copied.set(false);
    }
  }

  protected async connect(event: Event, device: ApiDevice): Promise<void> {
    event.preventDefault();
    const data = new FormData(event.target as HTMLFormElement);
    this.working.set(true);
    const outcome = await this.devices.configure(device, this.mode(), String(data.get('ip') ?? ''));
    this.working.set(false);
    this.problem.set(outcome.ok ? '' : (outcome.error ?? ''));
  }

  protected async bind(event: Event, device: ApiDevice): Promise<void> {
    event.preventDefault();
    const form = event.target as HTMLFormElement;
    const data = new FormData(form);
    this.working.set(true);
    const outcome = await this.devices.bind(device.id, {
      employeeId: String(data.get('employee') ?? ''),
      pin: String(data.get('pin') ?? ''),
      from: String(data.get('from') ?? ''),
      to: String(data.get('to') ?? ''),
    });
    this.working.set(false);
    this.problem.set(outcome.ok ? '' : (outcome.error ?? ''));
    if (outcome.ok) form.reset();
  }

  protected async closeBinding(event: Event, binding: ApiBinding): Promise<void> {
    event.preventDefault();
    const data = new FormData(event.target as HTMLFormElement);
    this.working.set(true);
    const outcome = await this.devices.closeBinding(binding, String(data.get('until') ?? ''));
    this.working.set(false);
    this.problem.set(outcome.ok ? '' : (outcome.error ?? ''));
    if (outcome.ok) this.closing.set(null);
  }
}
