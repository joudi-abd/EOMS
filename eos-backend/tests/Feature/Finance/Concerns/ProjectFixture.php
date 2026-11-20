<?php

namespace Tests\Feature\Finance\Concerns;

use App\Modules\Finance\Models\CashBox;
use App\Modules\Finance\Models\Party;
use App\Modules\Finance\Models\PartyRole;
use App\Modules\Finance\Models\ProjectCostCategory;

/**
 * Project finance helpers on top of FinanceFixture (phase 5): parties with roles, projects through the API, client
 * receipts, direct and loaded costs.
 */
trait ProjectFixture
{
    protected CashBox $cash;

    protected Party $supplier;

    protected function setUpProjects(): void
    {
        $this->cash = $this->box('الخزنة', ['USD' => null, 'TRY' => null]);
        $this->fund($this->cash, 'USD', 1000000);
        $this->fund($this->cash, 'TRY', 10000000);
        $this->supplier = $this->party('مورد مواد', ['supplier']);
    }

    /** @param  list<string>  $roles */
    protected function party(string $name, array $roles, array $attributes = []): Party
    {
        $party = Party::create(['name' => $name, ...$attributes]);
        foreach ($roles as $role) {
            PartyRole::create(['party_id' => $party->id, 'role' => $role]);
        }

        return $party;
    }

    protected function categoryId(string $key): int
    {
        return ProjectCostCategory::where('system_key', $key)->value('id');
    }

    /** Creates a project with its finance profile through the API; returns the response data. */
    protected function project(array $payload = []): array
    {
        return $this->login($this->finance)->postJson('/api/v1/finance/projects', $payload + ['name' => 'مشروع', 'status' => 'active',
            'branch_id' => $this->hq->id, 'contract_type' => 'cost_plus', 'fee_pct' => 10, 'contract_currency_id' => $this->currencyId('USD')])
            ->assertCreated()->json('data');
    }

    protected function receipt(int $projectId, Party $client, string|int|float $amount, string $currency = 'USD', string $date = '2026-11-10', int $status = 201): ?array
    {
        return $this->login($this->finance)->voucher(['type' => 'receipt', 'purpose' => 'client_payment', 'party_id' => $client->id, 'project_id' => $projectId,
            'cash_box_id' => $this->cash->id, 'currency' => $currency, 'amount' => $amount, 'voucher_date' => $date], $status);
    }

    /** A direct project cost (#3): supplier payment on the project in the category. */
    protected function cost(int $projectId, string $category, string|int|float $amount, string $currency = 'USD', string $date = '2026-11-10'): array
    {
        return $this->login($this->finance)->voucher(['type' => 'payment', 'purpose' => 'supplier_payment', 'party_id' => $this->supplier->id,
            'project_id' => $projectId, 'project_cost_category_id' => $this->categoryId($category), 'cash_box_id' => $this->cash->id,
            'currency' => $currency, 'amount' => $amount, 'voucher_date' => $date, 'description' => 'فاتورة']);
    }

    /** A loaded (non-billable) cost through a manual journal: Dr project_costs (loaded category) / Cr the allocation contra. */
    protected function loadedCost(int $projectId, string|int|float $amount, string $category = 'loaded_payroll'): void
    {
        $this->login($this->finance)->voucher(['type' => 'manual_journal', 'lines' => [
            ['account_id' => $this->roleId('project_costs'), 'currency' => 'USD', 'debit' => $amount, 'project_id' => $projectId,
                'project_cost_category_id' => $this->categoryId($category)],
            ['account_id' => $this->roleId('payroll_allocation_contra'), 'currency' => 'USD', 'credit' => $amount],
        ]]);
    }

    protected function bill(int $projectId, string|int|float $amount, int $status = 201, string $date = '2026-11-12'): ?array
    {
        $response = $this->login($this->finance)->postJson("/api/v1/finance/projects/{$projectId}/billings", ['amount' => $amount, 'voucher_date' => $date])
            ->assertStatus($status);

        return $status < 300 ? $response->json('data') : null;
    }
}
