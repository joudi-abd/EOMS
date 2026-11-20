import { Component, computed, inject, input, model } from '@angular/core';
import { CopyKey } from '../../../core/copy';
import { Language } from '../../../core/language';

/** A proposed swatch, or any colour picked from the palette. */
@Component({
  selector: 'app-color-field',
  templateUrl: './color-field.html',
  styleUrl: './color-field.scss',
})
export class ColorField {
  readonly swatches = input.required<readonly string[]>();
  readonly value = model.required<string>();
  readonly label = input.required<string>();
  private readonly language = inject(Language);

  protected readonly hex = computed(() => (/^#[0-9a-fA-F]{6}$/.test(this.value()) ? this.value().toLowerCase() : '#2f6f9f'));
  protected readonly custom = computed(() => !this.swatches().some((swatch) => swatch.toLowerCase() === this.hex()));

  protected t(key: CopyKey): string {
    return this.language.t(key);
  }

  protected choose(color: string): void {
    this.value.set(this.hexOf(color));
  }

  private hexOf(color: string): string {
    return /^#[0-9a-fA-F]{6}$/.test(color) ? color.toLowerCase() : this.hex();
  }
}
