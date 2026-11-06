import { inject, Injectable, signal } from '@angular/core';
import { Api, serverMessage, statusOf } from '../core/api/api';
import { ApiItem } from '../core/api/types';
import { Office } from './office';

/**
 * Fingerprint enrollment on the UA300 Pro, as the backend is expected to expose it:
 *
 *   GET  /employees/{employee}/fingerprint   → { data: ApiEnrollment }
 *   POST /employees/{employee}/fingerprint   → { data: ApiEnrollment }  starts a new enrollment
 *
 * The server queues the ADMS commands, the device picks them up, the employee places the finger
 * three times, and the template is stored against `biometric_code`. Until the route exists the
 * server answers 404 and enrollment stays unavailable.
 */
export type EnrollState = 'none' | 'queued' | 'sent' | 'scanning' | 'enrolled' | 'failed';

interface ApiEnrollment {
  state: EnrollState;
  presses?: number | null;
  pin?: number | string | null;
  message?: string | null;
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

function statusFrom(item: ApiEnrollment): EnrollStatus {
  return {
    state: item.state,
    presses: Math.max(0, Math.min(3, item.presses ?? 0)),
    pin: item.pin === null || item.pin === undefined ? '' : String(item.pin),
    message: item.message ?? '',
  };
}

@Injectable({ providedIn: 'root' })
export class Enrollment {
  private readonly api = inject(Api);
  private readonly office = inject(Office);
  private timer: ReturnType<typeof setTimeout> | null = null;
  private watching = '';

  /** Null while checking; false when the server has no enrollment route yet. */
  readonly available = signal<boolean | null>(null);
  readonly status = signal<EnrollStatus>(idle);
  readonly starting = signal(false);
  readonly problem = signal('');

  async open(employeeId: string): Promise<void> {
    this.stop();
    this.watching = employeeId;
    this.status.set(idle);
    this.problem.set('');
    this.available.set(null);
    try {
      const result = await this.api.get<ApiItem<ApiEnrollment>>(this.path(employeeId));
      this.available.set(true);
      this.apply(employeeId, statusFrom(result.data));
    } catch (error) {
      const status = statusOf(error);
      this.available.set(!(status === 404 || status === 405));
      if (this.available()) this.problem.set(serverMessage(error));
    }
  }

  async start(employeeId: string): Promise<void> {
    if (this.starting() || !this.available()) return;
    this.starting.set(true);
    this.problem.set('');
    try {
      const result = await this.api.post<ApiItem<ApiEnrollment>>(this.path(employeeId));
      this.apply(employeeId, statusFrom(result.data));
    } catch (error) {
      this.problem.set(serverMessage(error) || this.office.fail(error).error || '');
    } finally {
      this.starting.set(false);
    }
  }

  stop(): void {
    if (this.timer) clearTimeout(this.timer);
    this.timer = null;
    this.watching = '';
  }

  private apply(employeeId: string, status: EnrollStatus): void {
    this.status.set(status);
    if (live.includes(status.state)) this.poll(employeeId);
    else if (status.state === 'enrolled') void this.office.load();
  }

  private poll(employeeId: string): void {
    if (this.timer) clearTimeout(this.timer);
    this.timer = setTimeout(async () => {
      if (this.watching !== employeeId) return;
      try {
        const result = await this.api.get<ApiItem<ApiEnrollment>>(this.path(employeeId));
        if (this.watching === employeeId) this.apply(employeeId, statusFrom(result.data));
      } catch (error) {
        this.problem.set(serverMessage(error));
        this.poll(employeeId);
      }
    }, pollEvery);
  }

  private path(employeeId: string): string {
    return `/employees/${employeeId}/fingerprint`;
  }
}
