import { Component, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Session } from '../../core/access/session';
import { CopyKey } from '../../core/copy';
import { Ui } from '../../core/ui';
import { Office } from '../../data/office';
import { Notice } from '../../shared/notice/notice';
import { Chrome } from '../../shell/chrome';
import { SettingsDevices } from './settings-devices/settings-devices';
import { SettingsList } from './settings-list/settings-list';
import { SettingsShift } from './settings-shift/settings-shift';

import { SettingsLeaves } from './settings-leaves/settings-leaves';
import { SettingsOffice } from './settings-office/settings-office';

type Tab = 'shift' | 'structure' | 'devices' | 'leaves' | 'office';

@Component({
  selector: 'app-hr-settings',
  imports: [Notice, RouterLink, SettingsShift, SettingsList, SettingsDevices, SettingsLeaves, SettingsOffice],
  templateUrl: './hr-settings.html',
  styleUrl: './hr-settings.scss',
})
export class HrSettings extends Ui {
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);
  protected readonly chrome = inject(Chrome);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  /** The device list and bindings need `attendance.manage`; the other tabs follow the screen's own access. */
  protected readonly tabs = computed<{ id: Tab; label: CopyKey; icon: string }[]>(() => {
    const list: { id: Tab; label: CopyKey; icon: string }[] = [
      { id: 'shift', label: 'tabShift', icon: 'fa-clock' },
      { id: 'structure', label: 'tabStructure', icon: 'fa-sitemap' },
    ];
    if (this.session.has('attendance.manage')) list.push({ id: 'devices', label: 'tabDevices', icon: 'fa-fingerprint' });
    if (this.session.has('employees.manage')) list.push({ id: 'leaves', label: 'leaveSetupTab', icon: 'fa-calendar-days' });
    if (this.session.has('settings.view')) list.push({ id: 'office', label: 'companySettings', icon: 'fa-building' });
    return list;
  });

  protected readonly tab = computed<Tab>(() => {
    const value = this.params()?.get('tab');
    return this.tabs().some((item) => item.id === value) ? (value as Tab) : 'shift';
  });
}
