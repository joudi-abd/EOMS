import { inject, Injectable, signal } from '@angular/core';
import { Api, serverMessage, statusOf } from '../core/api/api';
import { ApiBinding, ApiDevice, ApiEnrollmentView, ApiItem } from '../core/api/types';
import { dayIn } from './attendance';
import { Office } from './office';

/** What the enrollment dialog draws. The server status is mapped onto these stages. */
export type EnrollState = 'none' | 'queued' | 'sent' | 'scanning' | 'verify' | 'enrolled' | 'failed';

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
  if (status === 'awaiting_physical_verification' || status === 'timeout') return 'verify';
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

  /** Null while the device list is loading; false when this account cannot manage devices. */
  readonly available = signal<boolean | null>(null);
  readonly bindings = signal<OpenBinding[]>([]);
  readonly deviceName = signal('');
  readonly status = signal<EnrollStatus>(idle);
  readonly starting = signal(false);
  readonly problem = signal('');

  async open(employeeId: string): Promise<void> {
    this.stop();
    this.status.set(idle);
    this.problem.set('');
    this.bindings.set([]);
    this.deviceName.set('');
    this.available.set(null);
    try {
      const result = await this.api.get<ApiItem<ApiDevice[]>>('/attendance/devices');
      const open: OpenBinding[] = [];
      for (const device of result.data) {
        const rows = await this.api.all<ApiBinding>(`/attendance/devices/${device.id}/bindings`);
        const today = dayIn(device.timezone);
        for (const row of rows) {
          if (String(row.employee_id) !== employeeId) continue;
          if (row.valid_from > today) continue;
          if (row.valid_to && row.valid_to < today) continue;
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
      this.available.set(true);
    } catch (error) {
      const status = statusOf(error);
      this.available.set(!(status === 403 || status === 404 || status === 405));
      if (this.available()) this.problem.set(serverMessage(error));
    }
  }

  async start(bindingId: number, fingerId: number): Promise<void> {
    if (this.starting() || !this.available()) return;
    const binding = this.bindings().find((item) => item.id === bindingId);
    this.starting.set(true);
    this.problem.set('');
    try {
      const result = await this.api.post<ApiItem<ApiEnrollmentView>>(`/attendance/bindings/${bindingId}/enroll-fingerprint`, {
        finger_id: fingerId,
        confirm_user_update: true,
      });
      if (binding) this.deviceName.set(binding.deviceName);
      this.apply(result.data);
    } catch (error) {
      this.problem.set(serverMessage(error) || this.office.fail(error).error || '');
    } finally {
      this.starting.set(false);
    }
  }

  async confirm(note: string): Promise<void> {
    const id = this.watching;
    if (!id) return;
    this.problem.set('');
    try {
      const result = await this.api.post<ApiItem<ApiEnrollmentView>>(`/attendance/fingerprint-enrollments/${id}/confirm`, {
        physical_test_passed: true,
        note,
      });
      this.apply(result.data);
      if (this.status().state === 'enrolled') this.office.done('enrolledFinger');
    } catch (error) {
      this.problem.set(serverMessage(error) || this.office.fail(error).error || '');
    }
  }

  stop(): void {
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
    const state = view.operator_confirmed || row.status === 'manually_confirmed' ? 'enrolled' : stageOf(row.status);
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
      try {
        const result = await this.api.get<ApiItem<ApiEnrollmentView>>(`/attendance/fingerprint-enrollments/${id}`);
        if (this.watching === id) this.apply(result.data);
      } catch (error) {
        this.problem.set(serverMessage(error));
        this.poll(id);
      }
    }, pollEvery);
  }
}
