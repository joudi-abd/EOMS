# المرحلة 6: سلف الموظفين والعُهد

سلف الموظفين وأقساطها (D17، القيد #14، R18)، والعُهد: صرف وتسوية ورد وتنبيه التأخر (§5.7، القيود #19–#21، R15–R17).
سلف المقاولين ليست هنا (مرحلة المشاريع).

كل الحركات تُسجَّل عبر `POST /api/v1/finance/vouchers` (مع `approve: true` للترحيل مباشرة)، والمسارات أدناه للقراءة والتأجيل.
البادئة `/api/v1/finance`، والمبالغ نصوص.

## 1. سلفة موظف

### السند
النوع `advance_issue` (رقمه `AI-YYYY-000000`) أو `payment` بغرض `employee_advance` (رقمه `PV-…`). الحقلان يعملان بالطريقة نفسها.

```json
{
  "type": "advance_issue",
  "voucher_date": "2026-11-10",
  "employee_id": 12,
  "cash_box_id": 3,
  "currency": "SYP",
  "amount": 1000000,
  "meta": { "installments_count": 3, "start_month": "2026-12" },
  "description": "سلفة على الراتب",
  "approve": true
}
```

القواعد (422 مع الحقل):
- `employee_id` موظف موجود في الموارد البشرية وغير منتهي الخدمة.
- الموظف **يجب** أن يكون مرتبطاً بجهة تعامل (`fin_parties.employee_id`)، وإلا: `الموظف غير مرتبط بجهة تعامل؛ اربطه بجهة من شاشة جهات التعامل أولاً.` (الحقل `party_id`). `party_id` اختياري؛ إن أُرسل يجب أن يكون جهة الموظف نفسها، وإن لم يُرسل يُملأ تلقائياً.
- العملة من عملات الرواتب (`is_payroll_currency`، اليوم USD وSYP)، ومفعّلة في الصندوق.
- `meta.installments_count` عدد صحيح من 1 إلى 120.
- `meta.start_month` بصيغة `YYYY-MM` ولا يسبق شهر السند.

**القيد #14:** م `employee_advances` (employee_id، party) / د الصندوق.

**عند الاعتماد** تُنشأ السلفة (`fin_employee_advances`، الحالة `active`) وأقساط شهرية متساوية مقرّبة نزولاً لخانات العملة، والفرق على القسط الأخير: 1,000,000 SYP على 3 → 333,333 / 333,333 / 333,334؛ 100 USD على 3 → 33.33 / 33.33 / 33.34.

**العكس** (`POST /vouchers/{id}/reverse`): يلغي السلفة (`cancelled`). ويُرفض إذا خُصم أي قسط من راتب: `لا تُعكس السلفة بعد خصم أحد أقساطها من الراتب؛ أعد كشف الرواتب أولاً.` (422، الحقل `voucher`)، ولا يتغيّر شيء.

حالات السلفة: `active` → `settled` (حين لا يبقى قسط معلّق) ← تعود `active` إذا أُعيد كشف الرواتب. `cancelled` بعد العكس.
حالات القسط: `pending` (معلّق)، `deducted` (مخصوم في كشف رواتب، مع `pay_run_id`)، `postponed` (أُجّل).

### المسارات
| المسار | الصلاحية | الوصف |
|---|---|---|
| `GET /employee-advances?employee_id=&status=&currency=&currency_id=&per_page=` | `finance.view` | قائمة مرقّمة؛ كل عنصر فيه `remaining` و`currency` و`party` و`voucher`. نطاق الفرع عبر فرع السند |
| `GET /employee-advances/{id}` | `finance.view` | السلفة مع `installments` (مرتبة بالشهر)، و`remaining`، و`deducted_total`، و`next_due_month` |
| `GET /employee-advances/report?months=3` | `finance.view` | بيانات T15 (أدناه) |
| `POST /employee-advance-installments/{id}/postpone` `{reason}` | `finance.vouchers` | تأجيل قسط (R18) |

**التأجيل (R18):** القسط الأصلي يصبح `postponed` مع `postponed_reason`، ويُضاف قسط معلّق بالمبلغ نفسه في الشهر التالي لآخر قسط في السلفة. يُسجَّل في التدقيق (`finance.advance_installment_postponed`). يُؤجَّل القسط المعلّق من سلفة قائمة فقط (وإلا 422). الرد: السلفة بعد التأجيل (شكل `GET /employee-advances/{id}`).

**T15** (`/employee-advances/report`):
```json
{
  "balances": [{ "employee_id": 12, "employee_name": "أحمد", "party_id": 5, "currency_id": 2, "currency": "SYP", "advances": 1,
                 "outstanding": "1000000", "next_due_month": "2026-12", "next_amount": "333333" }],
  "upcoming": [{ "due_month": "2026-12", "currency_id": 2, "currency": "SYP", "installments": 2, "amount": "433333" }]
}
```
`upcoming` يغطي `months` شهراً بدءاً من الشهر الحالي، والأقساط المتأخرة المعلّقة تظهر في الشهر الحالي.

### للرواتب (المرحلة 7)
`App\Modules\Finance\Services\Advances\EmployeeAdvanceService`:
- `installmentsDue(int $employeeId, string $month): Collection` — الأقساط المعلّقة من السلف القائمة المستحقة حتى الشهر، الأقدم أولاً. كل عنصر مصفوفة `{id, advance_id, due_month, amount, currency_id, currency}`.
- `markDeducted(array $installmentIds, int $payRunId): void` — كل قسط يجب أن يكون معلّقاً (وإلا 422).
- `releaseRun(int $payRunId): void` — أقساط الكشف تعود معلّقة.
- `postpone(int $installmentId, string $reason, User $actor): void`.
- `outstanding(int $employeeId): array` — المتبقي لكل رمز عملة، مثل `{"SYP": "666667"}`.

## 2. العُهد

### صرف عهدة (#19)
النوع `custody_issue` (رقمه `CU-…`) أو `payment` بغرض `custody`.
```json
{ "type": "custody_issue", "voucher_date": "2026-11-10", "party_id": 9, "project_id": 4, "cash_box_id": 3, "currency": "USD", "amount": 1000,
  "meta": { "confirm_existing": true }, "approve": true }
```
- `party_id` صاحب العهدة: أي جهة تعامل فعّالة (عادةً بدور موظف أو آخر). `project_id` اختياري (بدونه عهدة عامة).
- **R15:** إذا كان لصاحب العهدة عهدة مفتوحة على المشروع نفسه (أو عامة والسند عام) وبالعملة نفسها، يُرفض السند (422، الحقل `meta.confirm_existing`) برسالة تذكر الرصيد الحالي، إلا مع `meta.confirm_existing: true`؛ عندها يُضاف المبلغ إلى **العهدة نفسها**. وإلا تُفتح عهدة جديدة عند الاعتماد. `custody_id` اختياري؛ إن أُرسل يجب أن يكون تلك العهدة المفتوحة.
- السند المعتمد يحمل `custody_id`.
- **القيد:** م `custody` (party = صاحب العهدة، project، custody_id) / د الصندوق.

### تسوية عهدة بفواتير (#20)
النوع `custody_settlement` (رقمه `CS-…`)، متعدد السطور، بعملة العهدة.
```json
{ "type": "custody_settlement", "voucher_date": "2026-11-12", "custody_id": 7, "currency": "USD", "approve": true,
  "lines": [
    { "project_id": 4, "project_cost_category_id": 1, "party_id": 15, "debit": 500, "memo": "إسمنت" },
    { "expense_category_id": 6, "debit": 250, "memo": "مازوت" }
  ] }
```
- كل سطر مبلغ مدين موجب (`debit`) بعملة العهدة. سطر بمشروع: م `project_costs` (المشروع، تصنيف التكلفة — `asset_operation` إذا حمل السطر `asset_id` — و party = المورد). سطر بلا مشروع: `expense_category_id` إلزامي، والتصنيف فعّال ومربوط بحساب، والمدين على حساب التصنيف. لا يُقبل `account_id` مباشر ولا صندوق على السطر.
- **القيد:** د `custody` بالمجموع (party = صاحب العهدة، custody_id، مشروع العهدة).
- **R16:** المجموع لا يتجاوز رصيد العهدة (يُقفل صف العهدة أولاً)، وإلا 422 (الحقل `lines`): `مجموع التسوية … يتجاوز رصيد العهدة … سجّل صرف عهدة إضافياً أولاً.`
- `party_id` على السند إن أُرسل يجب أن يكون صاحب العهدة. السند المعتمد يحمل `amount` (المجموع) و`currency_id` و`party_id` (صاحب العهدة).
- طلبات الموقع المدفوعة من العهدة تصل إلى هذا النوع نفسه عبر `VoucherService::createInternal`.

### رد باقي العهدة (#21)
النوع `custody_return` (رقمه `CR-…`)، `{custody_id, cash_box_id, currency, amount}`. المبلغ بعملة العهدة ولا يتجاوز رصيدها.
**القيد:** م الصندوق / د `custody` (party = صاحب العهدة، custody_id).

### الأرصدة والإغلاق
بعد كل اعتماد أو عكس يُعاد حساب `issued_total` و`settled_total` و`returned_total` و`balance` من سطور القيود المرحّلة غير المعكوسة على حساب `custody` التي تحمل رقم العهدة: الصرف = المدين، والرد = دائن سندات الرد، والتسوية = بقية الدائن. **الرصيد = المصروف − المسوّى − المردود.**
- الرصيد صفر ← `closed` و`closed_on` = تاريخ السند.
- عكس يعيد رصيداً موجباً ← `open` و`closed_on = null`.
- عكس صرف عهدة صُرف منها (الرصيد سيصبح سالباً) يُرفض (422): اعكس التسويات أو الرد أولاً.

### المسارات
| المسار | الصلاحية | الوصف |
|---|---|---|
| `GET /custodies?holder_party_id=&project_id=&currency_id=&status=open|closed&overdue=1|0&per_page=` | `finance.view` | قائمة مرقّمة (المفتوحة أولاً ثم الأقدم)، بنطاق فرع العهدة |
| `GET /custodies/{id}` | `finance.view` | العهدة مع `vouchers`: كل سند صرف أو تسوية أو رد (`kind`: `issue`/`settlement`/`return`، `status`، `amount`، وسطور التسوية في `lines`) |
| `GET /custodies/report` | `finance.view` | بيانات T16: `{custodies: [...مفتوحة مع age_days وoverdue], totals: [{currency, balance, count}], overdue_days}` |
| `GET /my-custody` | `finance.site_requests` | "عهدتي" للمشرف (§9.6) |

شكل عنصر العهدة: `id, holder_party_id, holder_name, project_id, project_name, currency_id, currency, branch_id, status, opened_on, closed_on, issued_total, settled_total, returned_total, balance, age_days, overdue`.

**عهدتي:** الحساب ← الموظف المرتبط به ← جهة التعامل المرتبطة بالموظف. الرد: `{party: {id, name}, custodies: [...], balances: [{currency, balance}] (المفتوحة), movements: [{voucher_id, number, kind, custody_id, voucher_date, description, currency, amount}] (آخر 20)}`. حساب بلا موظف أو جهة يأخذ `party: null` وقوائم فارغة. لا يرى المشرف إلا عهده.

### R17: تنبيه تأخر العهدة
الأمر `php artisan finance:custody-overdue` مجدول يومياً 08:00. كل عهدة مفتوحة عمرها (من `opened_on`) أكبر من `finance.custody_overdue_days` (افتراضياً 30) يصل عنها إشعار (`finance.custody_overdue`، مع `custody_id` و`age_days`) لأصحاب `finance.vouchers` الذين يصلون فرعها، **مرة واحدة لكل عهدة في اليوم** (عمود `overdue_notified_on`).

## Migrations
| الملف | المحتوى |
|---|---|
| `2026_11_23_advances_add_overdue_notified_on_to_fin_custodies` | `fin_custodies.overdue_notified_on date nullable` |

## الصلاحيات
لا صلاحيات جديدة: القراءة `finance.view`، والسندات والتأجيل `finance.vouchers`، والعكس `finance.reverse`، وعهدتي `finance.site_requests`.

## للواجهة
- أنواع السندات `advance_issue` و`custody_issue` و`custody_settlement` و`custody_return` والغرضان `employee_advance` و`custody` صارت متاحة.
- R15: عند 422 على `meta.confirm_existing` اعرض الرسالة واطلب التأكيد، ثم أعد الإرسال مع `meta.confirm_existing: true`.
