import { Component, inject } from '@angular/core';
import { Ui } from '../../core/ui';
import { Office } from '../../data/office';

@Component({
  selector: 'app-notice',
  templateUrl: './notice.html',
  styleUrl: './notice.scss',
})
export class Notice extends Ui {
  protected readonly office = inject(Office);
}
