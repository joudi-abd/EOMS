import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, output, signal } from '@angular/core';
import { FinanceParties, ImportResult } from '../../../../data/finance-parties';
import { FinanceBase } from '../../finance-base';

/**
 * CSV import (`POST /finance/parties/import`): header `name,phone,role,trade`, several roles joined by `|`.
 * Any bad row rejects the whole file; the server's row errors are listed in the sheet.
 */
@Component({
  selector: 'app-fin-party-import',
  templateUrl: './party-import.html',
  styleUrl: './party-import.scss',
  host: { class: 'ledger' },
})
export class FinPartyImport extends FinanceBase {
  private readonly parties = inject(FinanceParties);

  readonly imported = output<void>();
  readonly closed = output<void>();

  protected readonly file = signal<File | null>(null);
  protected readonly result = signal<ImportResult | null>(null);
  protected readonly errors = signal<string[]>([]);

  protected chose(event: Event): void {
    const input = event.target as HTMLInputElement;
    this.file.set(input.files?.[0] ?? null);
    this.result.set(null);
    this.errors.set([]);
  }

  protected async run(): Promise<void> {
    const file = this.file();
    if (!file) return this.warn('fpaPickFile');
    this.busy.set(true);
    this.result.set(null);
    this.errors.set([]);
    try {
      this.result.set(await this.parties.importCsv(file));
      this.office.done('fpaImportDone');
      this.imported.emit();
    } catch (error) {
      const rows = rowErrors(error);
      if (rows.length) this.errors.set(rows);
      else this.office.fail(error);
    } finally {
      this.busy.set(false);
    }
  }
}

/** The 422's per-row messages (`errors.file[]`), if any. */
function rowErrors(error: unknown): string[] {
  if (!(error instanceof HttpErrorResponse) || error.status !== 422) return [];
  const errors = (error.error as { errors?: Record<string, string[]> } | null)?.errors ?? {};
  return Object.values(errors).flat().filter((item) => typeof item === 'string');
}
