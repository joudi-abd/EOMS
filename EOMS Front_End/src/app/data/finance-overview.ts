import { inject, Injectable } from '@angular/core';
import { Api } from '../core/api/api';
import { ApiItem } from '../core/api/types';
import { Money } from './finance';

/** The general manager's dashboard (`GET /finance/dashboard`, spec §10.1) and the setup wizard's progress. */

export interface CurrencyAmount {
  currency: string;
  amount: Money;
  amount_base: Money;
}

export interface FlowFigures {
  in: Money;
  out: Money;
  net: Money;
}

export interface RateUsed {
  currency: string;
  /** Units of this currency per one base unit; null in the historical mode (each line at its own day's rate). */
  rate: Money | null;
  date: string | null;
}

export interface Dashboard {
  date: string;
  liquidity: { total_base: Money; currencies: { currency: string; balance: Money; balance_base: Money }[] };
  box_alerts: {
    cash_box_id: number;
    box: string;
    branch_id: number;
    currency: string;
    balance: Money;
    negative_limit: Money | null;
    negative: boolean;
    near_limit: boolean;
  }[];
  projects: {
    top_profit: { project_id: number; code: string | null; name: string; status: string; currency: string; net_profit: Money; net_profit_base: Money }[];
    over_budget: { project_id: number; code: string | null; name: string | null; exceeded_lines: number }[];
  };
  cash_flow: {
    this_month: string;
    last_month: string;
    currencies: { currency: string; this_month: FlowFigures; last_month: FlowFigures }[];
    consolidated: { this_month: FlowFigures; last_month: FlowFigures };
  };
  contractors: {
    payables: { currencies: CurrencyAmount[]; total_base: Money };
    agreements_remaining: CurrencyAmount[];
    agreements_remaining_base: Money;
  };
  clients: { currencies: CurrencyAmount[]; total_base: Money };
  custodies: { overdue_days: number; count: number; currencies: { currency: string; count: number; balance: Money; balance_base: Money }[] };
  cash_counts_pending: { count: number; items: { id: number; cash_box_id: number; box: string | null; count_date: string; counted_by: number }[] };
  partner_drawings: {
    month: string;
    currencies: { currency: string; personal: Money; company_expense: Money; vouchers: number; personal_base: Money }[];
    personal_total_base: Money;
  };
  consolidation: { base_currency: string; rate_mode: 'report_date' | 'historical'; rate_date: string; rates_used: RateUsed[] };
}

export interface SetupStatus {
  steps: { step: number; key: string; title: string; done: boolean; missing: string[] }[];
  done: number;
  total: number;
  completed: boolean;
}

@Injectable({ providedIn: 'root' })
export class FinOverviewApi {
  private readonly api = inject(Api);

  async dashboard(): Promise<Dashboard> {
    return (await this.api.get<ApiItem<Dashboard>>('/finance/dashboard')).data;
  }

  async setupStatus(): Promise<SetupStatus> {
    return (await this.api.get<ApiItem<SetupStatus>>('/finance/setup/status')).data;
  }
}
