import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, inject, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { CopyKey } from '../../../../core/copy';
import { today } from '../../../../data/finance';
import { AssetImportResult, FinAssets, importColumns } from '../../../../data/finance-assets';
import { FinanceBase } from '../../finance-base';

type Column = (typeof importColumns)[number]['field'];

const columnLabel: Record<Column, CopyKey> = {
  name: 'fasAssetName',
  category: 'finCategory',
  quantity: 'fasQuantity',
  unit_cost: 'fasUnitCost',
  currency: 'finCurrency',
  purchase_date: 'fasPurchaseDate',
  holder: 'fasHolder',
  notes: 'finNotes',
  status: 'finStatus',
  serial_no: 'fasSerial',
};

const columnNote: Partial<Record<Column, CopyKey>> = {
  category: 'fasColCategory',
  quantity: 'fasColQuantity',
  currency: 'fasColCurrency',
  purchase_date: 'fasColDate',
  holder: 'fasColHolder',
  status: 'fasColStatus',
};

/**
 * Existing assets from a CSV file, all as one approved opening-balance voucher. The server checks every row first;
 * one bad row rejects the whole file (422, errors keyed `rows.{line}`), and those row errors are listed here.
 */
@Component({
  selector: 'app-fin-asset-import',
  imports: [FormsModule, RouterLink],
  templateUrl: './asset-import.html',
  styleUrl: './asset-import.scss',
  host: { class: 'ledger' },
})
export class AssetImport extends FinanceBase {
  private readonly assets = inject(FinAssets);

  readonly saved = output<void>();
  readonly closed = output<void>();

  protected readonly columns = importColumns;
  protected readonly file = signal<File | null>(null);
  protected readonly rowErrors = signal<{ line: string; messages: string[] }[]>([]);
  protected readonly result = signal<AssetImportResult | null>(null);
  protected openingDate = today();
  protected readonly branchId = signal(0);

  protected readonly branchList = computed(() => {
    const mine = this.finance.myBranches();
    return this.finance
      .branches()
      .filter((item) => item.is_active && (mine === null || mine.includes(item.id)))
      .sort((a, b) => Number(b.is_head_office) - Number(a.is_head_office));
  });

  constructor() {
    super();
    void this.quiet(async () => {
      await Promise.all([this.finance.loadBranches(), this.finance.loadMyBranches()]);
      this.branchId.set(this.branchList()[0]?.id ?? 0);
    });
  }

  protected label(field: Column): string {
    return this.t(columnLabel[field]);
  }

  protected note(field: Column): string {
    const key = columnNote[field];
    return key ? this.t(key) : '';
  }

  protected picked(event: Event): void {
    const input = event.target as HTMLInputElement;
    this.file.set(input.files?.[0] ?? null);
    this.rowErrors.set([]);
    this.result.set(null);
  }

  protected async submit(): Promise<void> {
    const file = this.file();
    if (!file || !this.branchId() || !this.openingDate) return;
    this.rowErrors.set([]);
    this.busy.set(true);
    try {
      const result = await this.assets.import(file, this.openingDate, this.branchId());
      this.result.set(result);
      this.office.done('fasImported');
      this.saved.emit();
    } catch (error) {
      this.rowErrors.set(rowsOf(error));
      this.office.fail(error);
    } finally {
      this.busy.set(false);
    }
  }
}

/** The 422 errors keyed `rows.{line}` (line 1 = headers), in line order; other keys come first as they are. */
function rowsOf(error: unknown): { line: string; messages: string[] }[] {
  if (!(error instanceof HttpErrorResponse) || error.status !== 422) return [];
  const errors = (error.error as { errors?: Record<string, unknown> } | null)?.errors ?? {};
  return Object.entries(errors)
    .map(([key, value]) => ({
      line: key.startsWith('rows.') ? key.slice(5) : '',
      messages: (Array.isArray(value) ? value : [value]).filter((item): item is string => typeof item === 'string'),
    }))
    .filter((item) => item.messages.length)
    .sort((a, b) => Number(a.line || 0) - Number(b.line || 0));
}
