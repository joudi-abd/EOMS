import { Injectable, signal } from '@angular/core';

@Injectable({ providedIn: 'root' })
export class Chrome {
  readonly menuOpen = signal(false);

  toggle(): void {
    this.menuOpen.update((open) => !open);
  }

  close(): void {
    this.menuOpen.set(false);
  }
}
