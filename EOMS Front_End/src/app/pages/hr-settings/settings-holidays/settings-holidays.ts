import { Component, inject } from '@angular/core';
import { Session } from '../../../core/access/session';
import { CopyKey } from '../../../core/copy';
import { Ui } from '../../../core/ui';
import { parseDay } from '../../../data/hr';
import { Office } from '../../../data/office';

@Component({
  selector: 'app-settings-holidays',
  templateUrl: './settings-holidays.html',
  styleUrl: './settings-holidays.scss',
})
export class SettingsHolidays extends Ui {
  protected readonly office = inject(Office);
  protected readonly session = inject(Session);

  protected weekday(day: string): string {
    return this.t(`weekday${parseDay(day).getDay()}` as CopyKey);
  }

  protected add(event: Event): void {
    event.preventDefault();
    const form = event.target as HTMLFormElement;
    const data = new FormData(form);
    if (this.office.addHoliday(String(data.get('day') ?? ''), String(data.get('name') ?? ''), this.session.account().id)) {
      form.reset();
    }
  }

  protected remove(id: string): void {
    this.office.removeHoliday(id, this.session.account().id);
  }
}
