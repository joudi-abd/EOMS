import { Component, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Session } from '../../core/access/session';
import { CopyKey } from '../../core/copy';
import { Ui } from '../../core/ui';
import { Office } from '../../data/office';
import { Notice } from '../../shared/notice/notice';
import { Chrome } from '../../shell/chrome';
import { SettingsHolidays } from './settings-holidays/settings-holidays';
import { SettingsList } from './settings-list/settings-list';
import { SettingsShift } from './settings-shift/settings-shift';

type Tab = 'shift' | 'holidays' | 'structure' | 'types';

@Component({
  selector: 'app-hr-settings',
  imports: [Notice, RouterLink, SettingsShift, SettingsHolidays, SettingsList],
  templateUrl: './hr-settings.html',
  styleUrl: './hr-settings.scss',
})
export class HrSettings extends Ui {
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);
  protected readonly chrome = inject(Chrome);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap);

  protected readonly tabs: { id: Tab; label: CopyKey; icon: string }[] = [
    { id: 'shift', label: 'tabShift', icon: 'fa-clock' },
    { id: 'holidays', label: 'tabHolidays', icon: 'fa-calendar-day' },
    { id: 'structure', label: 'tabStructure', icon: 'fa-sitemap' },
    { id: 'types', label: 'tabTypes', icon: 'fa-list-check' },
  ];

  protected readonly tab = computed<Tab>(() => {
    const value = this.params()?.get('tab');
    return this.tabs.some((item) => item.id === value) ? (value as Tab) : 'shift';
  });
}
