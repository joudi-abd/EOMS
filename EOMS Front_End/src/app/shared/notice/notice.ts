import { Component, inject } from '@angular/core';
import { Ui } from '../../core/ui';
import { Office } from '../../data/office';

@Component({
  selector: 'app-notice',
  template: `
    @if (office.notice(); as notice) {
      <div class="notice" [class.is-error]="notice.error" [attr.role]="notice.error ? 'alert' : null" aria-live="polite">
        <span>{{ notice.detail || (notice.message ? t(notice.message) : '') }}</span>
        @if (notice.undo) {
          <button class="text" type="button" (click)="notice.undo()">{{ t('undo') }}</button>
        } @else {
          <button class="text" type="button" (click)="office.notice.set(null)">{{ t('dismiss') }}</button>
        }
      </div>
    }
  `,
  styles: `
    .notice.is-error {
      background: var(--late-tint);
      border-color: #fecaca;
    }
    .notice.is-error::before {
      content: '\\f06a';
      color: var(--late);
    }
  `,
})
export class Notice extends Ui {
  protected readonly office = inject(Office);
}
