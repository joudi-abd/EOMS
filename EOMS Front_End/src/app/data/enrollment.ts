import { inject, Injectable, signal } from '@angular/core';
import { Api, serverMessage, statusOf } from '../core/api/api';
import { ApiBinding, ApiDevice, ApiEnrollmentView, ApiItem } from '../core/api/types';
import { dayIn } from './attendance';
import { Office } from './office';

/** What the enrollment dialog draws. The server status is mapped onto these stages. */
export type EnrollState =
  'none' | 'queued' | 'sent' | 'scanning' | 'verify' | 'enrolled' | 'failed';

export interface OpenBinding {
  id: number;
  deviceId: number;
  deviceName: string;
  pin: string;
  mode: string;
}

export interface EnrollStatus {
  state: EnrollState;
  presses: number;
  pin: string;
  message: string;
}

const idle: EnrollStatus = { state: 'none', presses: 0, pin: '', message: '' };
const live: readonly EnrollState[] = ['queued', 'sent', 'scanning'];
const pollEvery = 2000;

function stageOf(status: string): EnrollState {
  if (status === 'queued_user') return 'queued';
  if (status === 'await_user_ack' || status === 'queued_enroll') return 'sent';
  if (status === 'await_enroll_ack') return 'scanning';
  if (status === 'awaiting_physical_verification') return 'verify';
  if (status === 'timeout' || status === 'cancelled') return 'failed';
  if (status === 'manually_confirmed') return 'enrolled';
  if (status === 'failed') return 'failed';
  return 'sent';
}

@Injectable({ providedIn: 'root' })
export class Enrollment {
  private readonly api = inject(Api);
  private readonly office = inject(Office);
  private timer: ReturnType<typeof setTimeout> | null = null;
  private watching = 0;
  private generation = 0;
  private employeeId = '';
  readonly canCancel = signal(false);
  readonly saving = signal(false);

  /** Null while the device list is loading; false when this account cannot manage devices. */
  readonly available = signal<boolean | null>(null);
  readonly bindings = signal<OpenBinding[]>([]);
  readonly deviceName = signal('');
  readonly status = signal<EnrollStatus>(idle);
  readonly starting = signal(false);
  readonly problem = signal('');

  async open(employeeId: string): Promise<void> {
    this.stop();
    const generation = this.generation;
    this.employeeId = employeeId;
    this.canCancel.set(false);
    this.status.set(idle);
    this.problem.set('');
    this.bindings.set([]);
    this.deviceName.set('');
    this.available.set(null);
    try {
      const result = await this.api.get<ApiItem<ApiDevice[]>>('/attendance/devices');
      if (generation !== this.generation) return;
      const open: OpenBinding[] = [];
      for (const device of result.data.filter(
        (item) => item.is_active && item.connection_mode === 'adms',
      )) {
        const rows = await this.api.all<ApiBinding>(`/attendance/devices/${device.id}/bindings`);
        if (generation !== this.generation) return;
        const today = dayIn(device.timezone);
        for (const row of rows) {
          if (String(row.employee_id) !== employeeId) continue;
          if (row.valid_from > today) continue;
          if (row.valid_to && row.valid_to < today) continue;
          if (row.revoked_from && row.revoked_from <= today) continue;
          open.push({
            id: row.id,
            deviceId: device.id,
            deviceName: device.name,
            pin: row.device_user_id,
            mode: device.connection_mode,
          });
        }
      }
      this.bindings.set(open);
      this.deviceName.set(open[0]?.deviceName ?? '');
      if (open[0]) this.status.set({ ...idle, pin: open[0].pin });
      const views = await Promise.all(
        open.map(async (binding) => ({
          binding,
          view: (
            await this.api.get<ApiItem<ApiEnrollmentView>>(
              `/attendance/bindings/${binding.id}/fingerprint-enrollment`,
            )
          ).data,
        })),
      );
      if (generation !== this.generation) return;
      const pending = views.find(
        ({ view }) =>
          view.enrollment &&
          !['manually_confirmed', 'failed', 'timeout', 'cancelled'].includes(
            view.enrollment.status,
          ),
      );
      const latest =
        pending ??
        views
          .filter(({ view }) => view.enrollment)
          .sort((a, b) => b.view.enrollment!.id - a.view.enrollment!.id)[0];
      this.available.set(true);
      if (latest) {
        this.deviceName.set(latest.binding.deviceName);
        this.apply(latest.view);
      }
    } catch (error) {
      if (generation !== this.generation) return;
      const status = statusOf(error);
      this.available.set(false);
      this.bindings.set([]);
      if (status !== 403 && status !== 404 && status !== 405) {
        this.problem.set(serverMessage(error) || this.office.fail(error).error || 'Enrollment request failed.');
      }
    }
  }

  async start(bindingId: number, fingerId: number): Promise<void> {
    if (
      this.starting() ||
      !this.available() ||
      live.includes(this.status().state) ||
      this.status().state === 'verify'
    )
      return;
    const generation = this.generation;
    const binding = this.bindings().find((item) => item.id === bindingId);
    if (!binding) return;
    this.starting.set(true);
    this.problem.set('');
    try {
      const result = await this.api.post<ApiItem<ApiEnrollmentView>>(
        `/attendance/bindings/${bindingId}/enroll-fingerprint`,
        {
          finger_id: fingerId,
          confirm_user_update: true,
        },
      );
      if (generation !== this.generation) return;
      if (binding) this.deviceName.set(binding.deviceName);
      this.apply(result.data);
    } catch (error) {
      if (generation === this.generation)
        this.problem.set(serverMessage(error) || this.office.fail(error).error || '');
    } finally {
      if (generation === this.generation) this.starting.set(false);
    }
  }

  async confirm(note: string): Promise<void> {
    const id = this.watching;
    if (!id || this.saving() || this.status().state !== 'verify') return;
    const generation = this.generation;
    this.saving.set(true);
    this.problem.set('');
    try {
      const result = await this.api.post<ApiItem<ApiEnrollmentView>>(
        `/attendance/fingerprint-enrollments/${id}/confirm`,
        {
          physical_test_passed: true,
          note,
        },
      );
      if (generation !== this.generation) return;
      this.apply(result.data);
      if (this.status().state === 'enrolled') {
        this.office.done('enrolledFinger');
        await this.office.refreshEmployee(this.employeeId);
      }
    } catch (error) {
      if (generation === this.generation)
        this.problem.set(serverMessage(error) || this.office.fail(error).error || '');
    } finally {
      if (generation === this.generation) this.saving.set(false);
    }
  }

  async cancel(note: string): Promise<void> {
    if (!this.watching || !this.canCancel() || this.saving()) return;
    const generation = this.generation;
    this.saving.set(true);
    try {
      const result = await this.api.post<ApiItem<ApiEnrollmentView>>(
        `/attendance/fingerprint-enrollments/${this.watching}/cancel`,
        { note },
      );
      if (generation !== this.generation) return;
      this.apply(result.data);
      await this.office.refreshEmployee(this.employeeId);
    } catch (error) {
      if (generation === this.generation) this.problem.set(serverMessage(error));
    } finally {
      if (generation === this.generation) this.saving.set(false);
    }
  }

  stop(): void {
    this.generation++;
    this.starting.set(false);
    this.saving.set(false);
    if (this.timer) clearTimeout(this.timer);
    this.timer = null;
    this.watching = 0;
  }

  private apply(view: ApiEnrollmentView): void {
    const row = view.enrollment;
    if (!row) {
      this.status.set(idle);
      return;
    }
    this.watching = row.id;
    const state =
      view.operator_confirmed || row.status === 'manually_confirmed'
        ? 'enrolled'
        : row.status === 'timeout' && view.can_confirm === true
          ? 'verify'
          : stageOf(row.status);
    this.canCancel.set(view.can_cancel === true);
    this.status.set({
      state,
      presses: state === 'enrolled' ? 3 : 0,
      pin: row.device_user_id,
      message: '',
    });
    if (live.includes(state)) this.poll(row.id);
  }

  private poll(id: number): void {
    if (this.timer) clearTimeout(this.timer);
    this.timer = setTimeout(async () => {
      if (this.watching !== id) return;
      const generation = this.generation;
      try {
        const result = await this.api.get<ApiItem<ApiEnrollmentView>>(
          `/attendance/fingerprint-enrollments/${id}`,
        );
        if (this.watching === id && generation === this.generation) this.apply(result.data);
      } catch (error) {
        if (generation !== this.generation) return;
        this.problem.set(serverMessage(error));
        this.poll(id);
      }
    }, pollEvery);
  }
}
