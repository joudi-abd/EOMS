<?php

namespace App\Modules\Finance\Posting;

use App\Modules\Finance\Models\Voucher;
use Illuminate\Validation\ValidationException;

/**
 * Voucher type (receipt and payment: "type.purpose") → Poster class. Every type is listed; the phase that owns a type
 * creates its class under App\Modules\Finance\Posting. A missing class means the type is not available yet.
 */
final class PosterRegistry
{
    public const MAP = [
        // Phase 2 — treasury
        'receipt.other' => Posters\OtherCashPoster::class,
        'payment.other' => Posters\OtherCashPoster::class,
        'receipt.debt' => Posters\DebtPoster::class,
        'payment.debt' => Posters\DebtPoster::class,
        'payment.supplier_payment' => Posters\SupplierPaymentPoster::class,
        'transfer' => Posters\TransferPoster::class,
        'exchange' => Posters\ExchangePoster::class,
        'manual_journal' => Posters\ManualJournalPoster::class,
        'opening_balance' => Posters\OpeningBalancePoster::class,
        'count_adjustment' => Posters\CountAdjustmentPoster::class,
        'payroll_payment' => Posters\PayrollPaymentPoster::class,
        'payroll_accrual' => Posters\PayrollAccrualPoster::class,
        // Phase 3 — parties
        'receipt.partner_deposit' => Posters\PartnerDepositPoster::class,
        'payment.partner_drawing' => Posters\PartnerDrawingPoster::class,
        // Phase 4 — expenses
        'expense' => Posters\ExpensePoster::class,
        'payment.internal_expense' => Posters\InternalExpensePoster::class,
        // Phase 5 — projects
        'receipt.client_payment' => Posters\ClientPaymentPoster::class,
        'payment.contractor_payment' => Posters\ContractorPaymentPoster::class,
        'project_billing' => Posters\ProjectBillingPoster::class,
        // Phase 6 — advances and custody
        'payment.contractor_advance' => Posters\ContractorAdvancePoster::class,
        'payment.employee_advance' => Posters\EmployeeAdvancePoster::class,
        'advance_issue' => Posters\EmployeeAdvancePoster::class,
        'payment.custody' => Posters\CustodyIssuePoster::class,
        'custody_issue' => Posters\CustodyIssuePoster::class,
        'custody_settlement' => Posters\CustodySettlementPoster::class,
        'custody_return' => Posters\CustodyReturnPoster::class,
        // Phase 8 — allocation
        'allocation' => Posters\AllocationPoster::class,
        // Phase 9 — assets
        'asset_purchase' => Posters\AssetPurchasePoster::class,
        'asset_maintenance' => Posters\AssetMaintenancePoster::class,
        'asset_disposal' => Posters\AssetDisposalPoster::class,
    ];

    /** Purposes a receipt or a payment may carry. */
    public const PURPOSES = [
        'receipt' => ['client_payment', 'partner_deposit', 'debt', 'other'],
        'payment' => ['supplier_payment', 'contractor_payment', 'contractor_advance', 'internal_expense', 'partner_drawing',
            'employee_advance', 'custody', 'debt', 'other'],
    ];

    public static function key(Voucher $voucher): string
    {
        return in_array($voucher->type, ['receipt', 'payment'], true) ? $voucher->type.'.'.$voucher->purpose : $voucher->type;
    }

    public function for(Voucher $voucher): Poster
    {
        if (in_array($voucher->type, ['receipt', 'payment'], true) && ! in_array($voucher->purpose, self::PURPOSES[$voucher->type], true)) {
            throw ValidationException::withMessages(['purpose' => ['غرض السند غير صالح لهذا النوع.']]);
        }
        $class = self::MAP[self::key($voucher)] ?? null;
        if ($class === null || ! class_exists($class)) {
            throw ValidationException::withMessages(['type' => ['هذا النوع من السندات غير متاح بعد.']]);
        }

        return app($class);
    }
}
