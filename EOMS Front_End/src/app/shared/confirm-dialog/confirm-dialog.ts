import { afterRenderEffect, Component, ElementRef, inject, viewChild } from '@angular/core';
import { Confirm } from '../../core/confirm';
import { Ui } from '../../core/ui';

@Component({
  selector: 'app-confirm-dialog',
  templateUrl: './confirm-dialog.html',
  styleUrl: './confirm-dialog.scss',
})
export class ConfirmDialog extends Ui {
  protected readonly gate = inject(Confirm);
  private readonly box = viewChild<ElementRef<HTMLDialogElement>>('box');

  constructor() {
    super();
    afterRenderEffect(() => {
      const ask = this.gate.request();
      const dialog = this.box()?.nativeElement;
      if (!dialog) return;
      if (ask && !dialog.open) dialog.showModal();
      if (!ask && dialog.open) dialog.close();
    });
  }

  protected accept(): void {
    this.gate.settle(true);
  }

  protected refuse(): void {
    this.gate.settle(false);
  }

  protected onCancel(): void {
    if (this.gate.request()) this.gate.settle(false);
  }
}
