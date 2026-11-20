<?php

namespace App\Modules\Finance\Services\Assets;

use App\Modules\Finance\Models\Asset;
use App\Modules\Finance\Models\AssetCategory;
use App\Modules\Finance\Models\Voucher;
use App\Modules\Finance\Support\Money;
use App\Modules\Identity\Models\User;
use App\Modules\Settings\Models\Currency;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Existing assets from the company's asset sheet as CSV (spec §12 step 7, OP-15). Columns (English or Arabic
 * headers): name, category, quantity, unit_cost, currency, purchase_date, holder, notes (payment method / notes),
 * status, serial_no. Every row is checked first; any error refuses the whole file with per-row messages (422).
 * Otherwise every asset and one opening_balance voucher are created in one transaction (AssetService::registerOpening).
 */
class AssetImportService
{
    private const MAX_ROWS = 1000;

    /** Header aliases → field. */
    private const HEADERS = [
        'name' => 'name', 'الاسم' => 'name', 'اسم الأصل' => 'name', 'الأصل' => 'name',
        'category' => 'category', 'التصنيف' => 'category',
        'quantity' => 'quantity', 'qty' => 'quantity', 'الكمية' => 'quantity', 'العدد' => 'quantity',
        'unit_cost' => 'unit_cost', 'cost' => 'unit_cost', 'سعر الوحدة' => 'unit_cost', 'التكلفة' => 'unit_cost', 'السعر' => 'unit_cost',
        'currency' => 'currency', 'العملة' => 'currency',
        'purchase_date' => 'purchase_date', 'date' => 'purchase_date', 'تاريخ الشراء' => 'purchase_date', 'التاريخ' => 'purchase_date',
        'holder' => 'holder', 'المسؤول' => 'holder', 'الحائز' => 'holder', 'العهدة' => 'holder',
        'payment_method' => 'notes', 'notes' => 'notes', 'طريقة الدفع' => 'notes', 'ملاحظات' => 'notes',
        'status' => 'status', 'الحالة' => 'status',
        'serial_no' => 'serial_no', 'serial' => 'serial_no', 'الرقم التسلسلي' => 'serial_no',
    ];

    /** Status words accepted in the sheet. Disposed assets do not belong in opening balances. */
    private const STATUSES = [
        'in_service' => 'in_service', 'في الخدمة' => 'in_service', 'بالخدمة' => 'in_service', 'يعمل' => 'in_service', 'جيد' => 'in_service',
        'under_maintenance' => 'under_maintenance', 'قيد الصيانة' => 'under_maintenance', 'بالصيانة' => 'under_maintenance', 'صيانة' => 'under_maintenance',
    ];

    public function __construct(private AssetService $assets, private AssetHolders $holders) {}

    /**
     * @return array{assets: list<Asset>, voucher: Voucher}
     *
     * @throws ValidationException errors keyed `rows.{n}` (n = sheet line number, header = 1)
     */
    public function import(UploadedFile $file, string $date, int $branchId, User $actor): array
    {
        [$rows, $errors] = $this->parse($file);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->assets->registerOpening($rows, $date, $branchId, $actor);
    }

    /** @return array{0: list<array<string, mixed>>, 1: array<string, list<string>>} */
    private function parse(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        $header = fgetcsv($handle, escape: '\\');
        if ($header === false || $header === [null]) {
            fclose($handle);

            return [[], ['file' => ['الملف فارغ.']]];
        }
        $columns = [];
        foreach ($header as $i => $title) {
            $key = mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $title)));
            if (isset(self::HEADERS[$key])) {
                $columns[$i] = self::HEADERS[$key];
            }
        }
        foreach (['name', 'unit_cost', 'currency'] as $required) {
            if (! in_array($required, $columns, true)) {
                fclose($handle);

                return [[], ['file' => ['العمود "'.$required.'" غير موجود في الملف.']]];
            }
        }
        $rows = [];
        $errors = [];
        $line = 1;
        $categories = AssetCategory::query()->get(['id', 'name']);
        $currencies = Currency::query()->where('is_active', true)->get(['id', 'code', 'decimals'])->keyBy(fn ($c) => strtoupper($c->code));
        while (($cells = fgetcsv($handle, escape: '\\')) !== false) {
            $line++;
            if ($cells === [null] || implode('', array_map('trim', array_map('strval', $cells))) === '') {
                continue;
            }
            if (count($rows) + count($errors) >= self::MAX_ROWS) {
                $errors['file'] = ['الحد الأقصى '.self::MAX_ROWS.' أصل في الملف.'];
                break;
            }
            $raw = [];
            foreach ($columns as $i => $field) {
                $raw[$field] = trim((string) ($cells[$i] ?? ''));
            }
            [$row, $messages] = $this->row($raw, $categories, $currencies);
            if ($messages !== []) {
                $errors["rows.$line"] = $messages;
            } else {
                $rows[] = $row;
            }
        }
        fclose($handle);
        if ($rows === [] && $errors === []) {
            $errors['file'] = ['لا توجد أصول في الملف.'];
        }

        return [$rows, $errors];
    }

    /** @return array{0: array<string, mixed>, 1: list<string>} */
    private function row(array $raw, Collection $categories, Collection $currencies): array
    {
        $messages = [];
        $row = ['name' => $raw['name'] ?? '', 'serial_no' => ($raw['serial_no'] ?? '') ?: null, 'notes' => ($raw['notes'] ?? '') ?: null];
        if ($row['name'] === '' || mb_strlen($row['name']) > 200) {
            $messages[] = 'اسم الأصل مطلوب (200 حرف كحد أقصى).';
        }
        $currency = $currencies->get(strtoupper($raw['currency'] ?? ''));
        if ($currency === null) {
            $messages[] = 'العملة "'.($raw['currency'] ?? '').'" غير موجودة أو غير مفعّلة.';
        } else {
            $row['currency_id'] = $currency->id;
        }
        foreach (['quantity' => '1', 'unit_cost' => null] as $field => $default) {
            $value = str_replace([',', ' '], '', $raw[$field] ?? '');
            $value = $value === '' ? $default : $value;
            if ($value === null || ! is_numeric($value) || ! Money::isPositive(Money::of($value))) {
                $messages[] = $field === 'quantity' ? 'الكمية يجب أن تكون رقماً موجباً.' : 'التكلفة يجب أن تكون رقماً موجباً.';
            } else {
                $row[$field] = Money::of($value);
            }
        }
        if (($raw['category'] ?? '') !== '') {
            $category = $categories->first(fn ($c) => trim($c->name) === $raw['category']);
            if ($category === null) {
                $messages[] = 'التصنيف "'.$raw['category'].'" غير موجود.';
            } else {
                $row['category_id'] = $category->id;
            }
        }
        if (($raw['purchase_date'] ?? '') !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw['purchase_date']);
            if ($date === false || $date->format('Y-m-d') !== $raw['purchase_date']) {
                $messages[] = 'تاريخ الشراء بصيغة YYYY-MM-DD.';
            } else {
                $row['purchase_date'] = $raw['purchase_date'];
            }
        }
        if (($raw['holder'] ?? '') !== '') {
            $holder = $this->holders->findByLabel($raw['holder']);
            if ($holder === null) {
                $messages[] = 'الموظف "'.$raw['holder'].'" غير موجود (رقم الموظف أو اسمه الكامل).';
            } else {
                $row['holder_employee_id'] = $holder;
            }
        }
        if (($raw['status'] ?? '') !== '') {
            $status = self::STATUSES[mb_strtolower($raw['status'])] ?? null;
            if ($status === null) {
                $messages[] = 'الحالة "'.$raw['status'].'" غير مقبولة: في الخدمة أو قيد الصيانة.';
            } else {
                $row['status'] = $status;
            }
        }

        return [$row, $messages];
    }
}
