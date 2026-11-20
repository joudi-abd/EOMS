import { Component, computed, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { thisMonth } from '../../../../data/finance';
import { FinanceBase } from '../../finance-base';
import { FpjAllocationRuns } from '../allocation-runs/allocation-runs';
import { FpjTimeShares } from '../time-shares/time-shares';

/** Spreading a month's payroll and overhead onto projects: the employees' time shares, then the allocations. */
@Component({
  selector: 'app-fpj-allocations',
  imports: [FormsModule, FpjTimeShares, FpjAllocationRuns],
  templateUrl: './allocations.html',
  styleUrl: './allocations.scss',
  host: { class: 'ledger' },
})
export class FpjAllocations extends FinanceBase implements OnInit {
  protected readonly month = signal(previousMonth());
  protected readonly year = computed(() => Number(this.month().slice(0, 4)));
  protected readonly monthNo = computed(() => Number(this.month().slice(5, 7)));

  ngOnInit(): void {
    void this.quiet(async () => {
      await Promise.all([
        this.finance.loadCurrencies(),
        this.finance.loadBranches(),
        this.may('finance.projects.view') ? this.finance.loadProjects() : undefined,
      ]);
    });
  }

  protected pick(value: string): void {
    if (/^\d{4}-\d{2}$/.test(value)) this.month.set(value);
  }
}

/** Allocations are made after the month closes, so the picker opens on last month. */
function previousMonth(): string {
  const [year, month] = thisMonth().split('-').map(Number);
  return month === 1 ? `${year - 1}-12` : `${year}-${String(month - 1).padStart(2, '0')}`;
}
