import { Component, computed, inject, input } from '@angular/core';
import { CopyKey } from '../../../core/copy';
import { Ui } from '../../../core/ui';
import { device } from '../../../data/attendance';
import { Employee, FingerprintState } from '../../../data/employees';
import { Office } from '../../../data/office';
import { fingerKey } from '../employee-info/employee-info';

const order: FingerprintState[] = ['none', 'queued', 'waiting', 'enrolled'];

@Component({
  selector: 'app-employee-finger',
  templateUrl: './employee-finger.html',
  styleUrl: './employee-finger.scss',
})
export class EmployeeFinger extends Ui {
  readonly person = input.required<Employee>();
  protected readonly office = inject(Office);
  protected readonly device = device;
  protected readonly fingerKey = fingerKey;

  protected readonly pin = computed(() => this.office.pinOf(this.person().id));

  protected readonly steps = computed(() => {
    const state = this.person().fingerprint;
    const reached = state === 'failed' ? 2 : order.indexOf(state);
    const keys: CopyKey[] = ['fingerStepServer', 'fingerStepFetch', 'fingerStepPlace', 'fingerStepDone'];
    return keys.map((key, index) => ({ key, done: reached > index || (state === 'enrolled' && index === 3), failed: state === 'failed' && index === 2 }));
  });

  protected readonly commands = computed(() => {
    const pin = this.pin();
    if (!pin) return [];
    return [
      `C:${pin}1:DATA UPDATE USERINFO PIN=${pin}\\tName=${this.person().staffNo}\\tPri=0`,
      `C:${pin}2:ENROLL_FP PIN=${pin}\\tFID=6\\tRETRY=3\\tOVERWRITE=1`,
    ];
  });

  protected readonly busy = computed(() => this.person().fingerprint === 'queued' || this.person().fingerprint === 'waiting');
}
