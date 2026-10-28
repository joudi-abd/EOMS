import { Component, inject, input } from '@angular/core';
import { CopyKey } from '../../../core/copy';
import { Language } from '../../../core/language';

export interface PunchLine {
  id: string;
  time: string;
  pin: string;
  person: string;
  direction: string;
  duplicate: boolean;
  unmatched: boolean;
}

@Component({
  selector: 'app-punch-log',
  templateUrl: './punch-log.html',
  styleUrl: './punch-log.scss',
})
export class PunchLog {
  readonly rows = input.required<PunchLine[]>();
  private readonly language = inject(Language);

  protected t(key: CopyKey): string {
    return this.language.t(key);
  }
}
