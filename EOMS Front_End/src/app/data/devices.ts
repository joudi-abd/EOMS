import { Injectable, inject, signal } from '@angular/core';
import { Api, serverMessage } from '../core/api/api';
import { ApiBinding, ApiDevice, ApiDeviceToken, ApiItem } from '../core/api/types';
import { CopyKey } from '../core/copy';
import { Office } from './office';

export interface DeviceOutcome {
  ok: boolean;
  error?: string;
  token?: string;
}

/** Punch-clocks, their connection mode, and the device user numbers bound to employees. Needs `attendance.manage`. */
@Injectable({ providedIn: 'root' })
export class Devices {
  private readonly api = inject(Api);
  private readonly office = inject(Office);

  readonly devices = signal<ApiDevice[]>([]);
  readonly bindings = signal<Record<number, ApiBinding[]>>({});
  readonly loading = signal(false);
  readonly loaded = signal(false);

  async load(): Promise<void> {
    this.loading.set(true);
    try {
      const result = await this.api.get<ApiItem<ApiDevice[]>>('/attendance/devices');
      this.devices.set(result.data);
      this.loaded.set(true);
    } catch (error) {
      this.office.fail(error);
    } finally {
      this.loading.set(false);
    }
  }

  async loadBindings(deviceId: number): Promise<ApiBinding[]> {
    try {
      const rows = await this.api.all<ApiBinding>(`/attendance/devices/${deviceId}/bindings`);
      this.bindings.update((all) => ({ ...all, [deviceId]: rows }));
      return rows;
    } catch (error) {
      this.office.fail(error);
      return this.bindings()[deviceId] ?? [];
    }
  }

  async create(entry: { name: string; model: string; serial: string }): Promise<DeviceOutcome> {
    const body: Record<string, string> = { name: entry.name.trim(), serial_number: entry.serial.trim() };
    if (entry.model.trim()) body['model'] = entry.model.trim();
    try {
      const result = await this.api.post<ApiItem<ApiDeviceToken>>('/attendance/devices', body);
      await this.load();
      this.office.done('deviceAdded');
      return { ok: true, token: result.data.token };
    } catch (error) {
      return this.failed(error);
    }
  }

  async setActive(device: ApiDevice, active: boolean): Promise<DeviceOutcome> {
    return this.change('deviceUpdated', () => this.api.patch(`/attendance/devices/${device.id}`, { is_active: active }));
  }

  async rotate(device: ApiDevice): Promise<DeviceOutcome> {
    try {
      const result = await this.api.post<ApiItem<ApiDeviceToken>>(`/attendance/devices/${device.id}/rotate-token`);
      await this.load();
      this.office.done('tokenRotated');
      return { ok: true, token: result.data.token };
    } catch (error) {
      return this.failed(error);
    }
  }

  async configure(device: ApiDevice, mode: 'bridge' | 'adms', sourceIp: string): Promise<DeviceOutcome> {
    const body: Record<string, unknown> = { connection_mode: mode, adms_source_ip: mode === 'adms' ? sourceIp.trim() : null };
    return this.change('connectionSaved', () => this.api.patch(`/attendance/devices/${device.id}/connection`, body));
  }

  async bind(deviceId: number, entry: { employeeId: string; pin: string; from: string; to: string }): Promise<DeviceOutcome> {
    const body: Record<string, unknown> = { employee_id: Number(entry.employeeId), device_user_id: entry.pin.trim(), valid_from: entry.from };
    if (entry.to) body['valid_to'] = entry.to;
    try {
      await this.api.post(`/attendance/devices/${deviceId}/bindings`, body);
      await this.loadBindings(deviceId);
      this.office.done('bindingAdded');
      return { ok: true };
    } catch (error) {
      return this.failed(error);
    }
  }

  async closeBinding(binding: ApiBinding, validTo: string): Promise<DeviceOutcome> {
    try {
      await this.api.post(`/attendance/bindings/${binding.id}/close`, { valid_to: validTo });
      await this.loadBindings(binding.device_id);
      this.office.done('bindingClosed');
      return { ok: true };
    } catch (error) {
      return this.failed(error);
    }
  }

  private async change(message: CopyKey, run: () => Promise<unknown>): Promise<DeviceOutcome> {
    try {
      await run();
      await this.load();
      this.office.done(message);
      return { ok: true };
    } catch (error) {
      return this.failed(error);
    }
  }

  private failed(error: unknown): DeviceOutcome {
    const outcome = this.office.fail(error);
    return { ok: false, error: serverMessage(error) || outcome.error };
  }
}
