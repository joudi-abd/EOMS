# المرحلة 5: مالية المشاريع والمقاولون

البادئة `/api/v1/finance`، والمصادقة `auth:sanctum`. الاستجابة `{data}` والقوائم `{data, links, meta}`. المبالغ نصوص (bcmath)، والنسب نسب مئوية (`8` = 8%).

**المرجع:** القسم 5.6 والقيود #1–#7 والقسمان 6.1 و6.2 والقواعد R11–R14 والقرارات D9–D11 وD13–D14 في `FINANCE_SYSTEM_SPEC`.

## الصلاحيات والنطاق

| الإجراء | الصلاحية | النطاق "الذاتي" (own) |
|---|---|---|
| القراءة (الملف، الملخص، الموازنة، الاتفاقيات، الاقتراح، الربحية، الكشف) | `finance.projects.view` | المشاريع التي يديرها المستخدم (`manager_user_id`)، ومعها مشاريع فروعه المسندة إن كان يملك `finance.view` أو `finance.projects` |
| الكتابة (المشروع والعقد، الحالة، الموازنة، الاتفاقيات، المطالبات) | `finance.projects` | مشاريع فروعه المسندة (`fin_user_branches`) |
| تصنيفات تكلفة المشاريع (إنشاء وتعديل) | `finance.settings` | — |
| سندات القبض والصرف والمطالبة عبر `/vouchers` | `finance.vouchers` | فروعه |

من يملك `finance.projects.view` أو `finance.view` أو `finance.projects` بنطاق الكل يرى كل المشاريع. المشروع خارج النطاق يعيد **404** في القراءة و**403** في الكتابة.

## 1. الملف المالي للمشروع (القسم 5.6، D9)

| المسار | الصلاحية | الوصف |
|---|---|---|
| `GET /projects?status=&branch_id=&client_party_id=&contract_type=&search=&per_page=` | `finance.projects.view` | المشاريع ذات الملف المالي ضمن النطاق. `search` في الاسم والكود |
| `GET /projects/summary?status=&branch_id=&client_party_id=&contract_type=&date=` | `finance.projects.view` | ملخص المشاريع (T8، بديل ورقة الملخص) |
| `GET /projects/{project_id}` | `finance.projects.view` | الملف + المشروع + ملخص التبويبات |
| `POST /projects` | `finance.projects` | ينشئ المشروع العام (وحدة المشاريع) وملفه المالي |
| `PATCH /projects/{project_id}` | `finance.projects` | تعديل حقول المشروع والعقد |
| `POST /projects/{project_id}/status` | `finance.projects` | `{status}`: `planned|active|on_hold|completed|closed` (عبر وحدة المشاريع) |

`{project_id}` في كل المسارات هو رقم المشروع العام (`prj_projects.id`)، لا رقم الملف.

**الإنشاء:**
```json
{
  "name": "عماد القاسم – شقق", "status": "active", "branch_id": 1, "manager_user_id": 7,
  "start_date": "2026-01-01", "end_date": null, "notes": null,
  "client_party_id": 12, "contract_type": "percentage_of_receipts", "contract_currency_id": 1,
  "contract_value": null, "fee_pct": 8, "fee_base_category_ids": null
}
```
- أو `project_id` بدل حقول المشروع لربط ملف مالي بمشروع عام موجود (409 إن كان له ملف).
- `code` يُمنح تلقائياً من التسلسل `project` (يبدأ من الإعداد `finance.project_code_start`، افتراضياً 100100).
- **قواعد العقد:** `client_party_id` إلزامي (جهة فعّالة بدور `client`) إلا في `internal`. `lump_sum` يحتاج `contract_value > 0`. `cost_plus` و`percentage_of_receipts` يحتاجان `fee_pct` (أكبر من صفر وحتى 100)، وتُهمل النسبة في النوعين الآخرين. `fee_base_category_ids` لـ `cost_plus` فقط: تصنيفات قابلة للفوترة (null = كلها).
- **R14:** بعد أول مطالبة معتمدة لا يُعدَّل `contract_type` ولا `fee_pct` (ولا عملة العقد ولا العميل — قرار تحفّظي) إلا بعد عكس كل المطالبات (422). الحقل `billing_locked` يبيّن ذلك.

**صف القائمة:** حقول `fin_project_profiles` + `client {id,name}` + `contract_currency` (الرمز) + `name, status, branch_id, manager_user_id, start_date, end_date` + `billing_locked`.

**العرض (`GET /projects/{id}`)** = صف القائمة + `project` (مع `members`) + `summary`:
```json
{
  "profitability": { "...": "كما في §5" },
  "budget": {"lines": 3, "warning": 1, "exceeded": 0},
  "agreements": {"count": 2, "open": 1},
  "custodies": {"open": 1, "balances": [{"currency": "USD", "balance": "250"}]},
  "billings": {"approved": 2}
}
```

**صف الملخص (T8):** `project_id, code, name, status, branch_id, client, contract_type, currency, receipts, costs, remaining (= receipts − costs), fee_pct, fee, revenue, profit (= net_margin), client_balance` — بعملة عقد كل مشروع.

## 2. تصنيفات تكلفة المشاريع

| المسار | الصلاحية | الوصف |
|---|---|---|
| `GET /project-cost-categories?active=` | `finance.projects.view` | مرتبة بـ `sort` |
| `POST /project-cost-categories` | `finance.settings` | `{name, is_billable?, is_active?, sort?}` |
| `PATCH /project-cost-categories/{id}` | `finance.settings` | `{name?, is_billable?, is_active?, sort?}`. لا حذف؛ التعطيل بـ `is_active=false` |

الزرع (في `FinanceChartSeeder`): فواتير ومواد، أجور اليد العاملة، تصميم ودراسات، متفرقات، مصاريف عامة للمشروع، تشغيل أصول (قابلة للفوترة)؛ رواتب محمّلة ومصاريف عامة موزّعة (غير قابلة، OP-2). `system_key` ثابت.

## 3. الموازنة (D10، R13، OP-6)

| المسار | الصلاحية | الوصف |
|---|---|---|
| `GET /projects/{id}/budget?date=` | `finance.projects.view` | البنود مع الفعلي والنسبة واللون |
| `POST /projects/{id}/budget-lines` | `finance.projects` | `{project_cost_category_id, currency_id, budget_amount, notes?}` — بند واحد لكل (تصنيف، عملة)؛ التكرار 422 |
| `PATCH /budget-lines/{id}` | `finance.projects` | `{budget_amount?, notes?}` |
| `DELETE /budget-lines/{id}` | `finance.projects` | |

**صف الموازنة:** حقول البند + `category` + `currency` + `actual` + `remaining` + `pct` + `level` (`none|warning|exceeded`) + `colour` (`green|amber|red`). `actual = null` إذا لم توجد نشرة لعملة البند.

- **الفعلي** = Σ(مدين − دائن) على `project_costs` للمشروع والتصنيف، بعملة البند: السطور بعملة البند بقيمتها، والباقي بمعادلها بالدولار (`base_debit − base_credit`) × سعر عملة البند في النشرة في تاريخ الترحيل أو قبله (OP-6). البند بالدولار يستخدم المعادل مباشرة.
- **المستويات:** `warning` عند بلوغ `finance.budget_overrun_threshold_pct` (افتراضياً 90%)، و`exceeded` عند تجاوز 100%.
- **R13:** بعد كل ترحيل أو عكس فيه سطر `project_costs` بمشروع وتصنيف يُعاد قياس بنود الزوج. ارتفاع المستوى يرسل إشعاراً داخلياً **مرة واحدة لكل مستوى** (`kind`: `finance.budget_warning` أو `finance.budget_exceeded`، مع `level, project_id, budget_line_id, project_cost_category_id, actual, budget, pct`) لحاملي `finance.vouchers` في فرع المشروع ولمدير المشروع، بعد الـcommit. السند يحمل تحذيراً في `warnings[]` ما دام البند في منطقة التحذير أو التجاوز. **التجاوز لا يمنع الترحيل.** انخفاض المستوى (عكس، زيادة الموازنة) يُحفظ بصمت.

## 4. اتفاقيات المقاولين (القسم 5.6، D11، #4)

| المسار | الصلاحية | الوصف |
|---|---|---|
| `GET /contractor-agreements?project_id=&party_id=&status=&per_page=` | `finance.projects.view` | مع المجاميع لكل اتفاقية |
| `GET /contractor-agreements/{id}` | `finance.projects.view` | بطاقة الاتفاقية مع الدفعات والسلف |
| `POST /contractor-agreements` | `finance.projects` | `{project_id, contractor_party_id, scope, project_cost_category_id?, currency_id, agreed_value, discount?, status?, notes?}` |
| `PATCH /contractor-agreements/{id}` | `finance.projects` | كل الحقول عدا `project_id`. بعد ربط سندات بها لا يتغير المقاول ولا العملة ولا التصنيف. `status`: `open|completed|cancelled` |
| `DELETE /contractor-agreements/{id}` | `finance.projects` | 409 إذا ارتبطت بسندات (ألغها بدل الحذف) |

- لا قيد عند الإنشاء (#4). `net_value = agreed_value − discount` يُحسب في الخادم (0 ≤ الحسم ≤ القيمة). التصنيف الافتراضي "أجور اليد العاملة". المقاول جهة فعّالة بدور `contractor`.
- **نمط التتبع (D11):** `fin_parties.contractor_tracking_mode` (`agreement|payments_only`) وإلا الإعداد `finance.contractor_tracking_mode_default`.

**البطاقة** (بديل عمود الملاحظات `1500+2000+…`):
```json
{
  "id": 3, "project_id": 5, "contractor_party_id": 9, "scope": "تمديد صحية", "currency_id": 1, "currency": "USD",
  "agreed_value": "10000.0000", "discount": "500.0000", "net_value": "9500.0000", "status": "open",
  "contractor": {"id": 9, "name": "أحمد جمو"}, "category": {"id": 2, "name": "أجور اليد العاملة"},
  "gross_paid": "5000", "cash_paid": "3000", "advances_deducted": "2000", "advances_outstanding": "0",
  "remaining": "4500", "overrun": false,
  "payments": [{"voucher_id": 21, "number": "PV-2026-000004", "date": "2026-11-10", "cash_box_id": 1, "cash_box": "الخزنة",
                "gross": "5000", "advance_deduction": "2000", "cash": "3000", "description": "...", "override_reason": null}],
  "advances": [{"...": "نفس الشكل لسلف المقاول المرتبطة بالاتفاقية"}]
}
```
- `gross_paid` = Σ `project_costs` على الاتفاقية. `cash_paid` = المدفوع نقداً. `advances_deducted` = السلف المخصومة من دفعاتها.
- **المتبقي** = `net_value − cash_paid − advances_deducted` (= `net_value − gross_paid`). سالب = تجاوز (`overrun: true`).
- `advances_outstanding` = رصيد `contractor_advances` المرتبط بالاتفاقية.

## 5. الربحية (القسم 6.2)

`GET /projects/{id}/profitability?date=` (`finance.projects.view`) — بعملة العقد حتى التاريخ (افتراضياً اليوم):
```json
{
  "project_id": 5, "currency": "USD", "date": "2026-11-15",
  "revenue": "1650", "direct_costs": "1500", "loaded_costs": "300", "total_costs": "1800",
  "gross_margin": "150", "net_margin": "-150", "receipts": "2000", "billed": "1650", "client_balance": "350",
  "fee_pct": "10", "fee": "150", "fee_base_costs": "1500",
  "costs_by_category": [{"id": 1, "name": "فواتير ومواد", "system_key": "materials", "is_billable": true, "amount": "1000"}]
}
```
- `revenue` = Σ `project_revenue`. `direct_costs` = تكاليف التصنيفات القابلة للفوترة. `loaded_costs` = تكاليف التصنيفات غير القابلة (الرواتب المحمّلة والعامة الموزّعة).
- `gross_margin = revenue − direct_costs`، `net_margin = revenue − direct_costs − loaded_costs`، `client_balance = receipts − billed` (موجب = للعميل رصيد لدينا).
- `fee` (أتعاب الشركة): `cost_plus` = النسبة × تكاليف وعاء الأتعاب؛ `percentage_of_receipts` = النسبة × الدفعات؛ `null` للمقطوعية والداخلي.
- **التحويل للعملة:** السطور بعملة العقد بقيمتها، والباقي بمعادله بالدولار × سعر عملة العقد في النشرة في التاريخ أو قبله (422 إذا لم يوجد).
- **الدفعات (receipts)** = حركات `client_advances` للمشروع عدا قيود المطالبات (أي دفعات العملاء #1 والأرصدة الافتتاحية).

## 6. المطالبات (#2، القسم 6.1)

| المسار | الصلاحية | الوصف |
|---|---|---|
| `GET /projects/{id}/billing-suggestion?date=` | `finance.projects.view` | الاقتراح. 422 للمشروع الداخلي |
| `GET /projects/{id}/billings` | `finance.projects.view` | سندات `project_billing` للمشروع (كل الحالات) |
| `POST /projects/{id}/billings` | `finance.projects` | `{amount, voucher_date?, description?, reference_no?, approve?=true}` — ينشئ سند مطالبة لعميل المشروع بعملة العقد ويعتمده (افتراضياً) |

ويمكن أيضاً إنشاؤها عبر `POST /vouchers` بـ `type: "project_billing"` و`project_id` و`amount` و`currency` (= عملة العقد) بصلاحية `finance.vouchers`.

**الاقتراح:**
```json
{
  "project_id": 5, "contract_type": "percentage_of_receipts", "currency": "USD", "date": "2026-11-15",
  "billable_costs": "80000", "fee_base_costs": "80000", "fee_pct": "8", "fee": "11320",
  "receipts": "141500", "already_billed": "0", "suggested": "91320", "max": null, "available_advances": "141500"
}
```
| النوع | الحساب |
|---|---|
| `cost_plus` | `suggested = billable_costs + fee_pct × fee_base_costs − already_billed` (= `billable × (1 + fee) − billed` حين يكون الوعاء كل التصنيفات القابلة) |
| `percentage_of_receipts` | `suggested = billable_costs + fee_pct × receipts − already_billed` |
| `lump_sum` | `suggested = null` (يدوي، OP-3)، `max = contract_value − already_billed` |
| `internal` | غير متاح (422) |

- `billable_costs` = تكاليف التصنيفات القابلة للفوترة بتاريخ ≤ `date`، بعملة العقد. `already_billed` = Σ `project_revenue`. `suggested` لا يقل عن صفر.
- `available_advances` = دفعات العميل المقدمة غير المُطالب بها بعد.

**القيد (#2):** المبلغ B بعملة العقد، وA = دفعات العميل المقدمة المفتوحة على المشروع بتلك العملة (المستلمة حتى تاريخ المطالبة والباقية اليوم):
```
م client_advances        min(A, B)     (party = العميل، project)
م receivables_clients    B − min(A, B)
د project_revenue        B
```
**القواعد:** المشروع له ملف وليس `internal`؛ الجهة (إن أُرسلت) = عميل المشروع؛ العملة = عملة العقد؛ المقطوعية: B ≤ `contract_value − already_billed` (422 مع المتبقي). الرقم `PB-YYYY-000000`.

## 7. كشف حساب المشروع (T9، D14)

`GET /projects/{id}/statement?variant=internal|client&hide_internal=1&date=` (`finance.projects.view`). البيانات فقط؛ الـPDF يُرسم في الواجهة (OP-15).
```json
{
  "variant": "internal", "hide_internal": false,
  "header": {"company": "UFUQ", "statement_code": "100100", "project": "شقق", "project_id": 5, "client": "عماد القاسم",
             "date": "2026-11-15", "currency": "USD", "contract_type": "percentage_of_receipts", "contract_value": null, "fee_pct": "8"},
  "incoming": [{"date": "2026-11-10", "number": "RV-2026-000001", "cash_box": "الخزنة", "description": null,
                "original_amount": "2000", "original_currency": "USD", "amount": "2000"}],
  "outgoing": [{"category_id": 1, "name": "فواتير ومواد", "system_key": "materials", "is_billable": true,
                "lines": [{"date": "...", "number": "PV-2026-000002", "party": "مورد", "description": "...",
                           "original_amount": "3000", "original_currency": "USD", "amount": "3000"}], "total": "3000"}],
  "totals": {"incoming": "27000", "outgoing": "5200", "remaining": "21800", "fee": "2160", "remaining_after_fee": "19640"},
  "profitability": {"...": "كما في §5"},
  "signatures": ["إدارة المشاريع", "إدارة التنفيذ", "مهندس الموقع", "المحاسبة العامة", "الفرع", "المدير العام"]
}
```
- `outgoing` مجمّع بالتصنيف بترتيب الإكسل (القابلة للفوترة أولاً حسب `sort`، ثم المحمّلة).
- `variant=client`: يخفي التصنيفات غير القابلة للفوترة، ويحذف `fee` و`remaining_after_fee` و`fee_pct` و`profitability`.
- `hide_internal=1` مع النسخة الداخلية: يحذف الأتعاب والربحية ويُبقي التكاليف المحمّلة.
- `amount` بعملة العقد؛ `original_amount/original_currency` كما سُجّل.

## 8. السندات (القيود #1، #5، #6، #7)

كلها عبر `POST /vouchers` (`finance.vouchers`)، مع `approve: true` للترحيل.

### قبض دفعة عميل — `receipt` / `client_payment` (#1)
`{cash_box_id, currency, amount, party_id, project_id}` — الجهة بدور `client` وهي عميل المشروع في ملفه، والمشروع له ملف مالي.
```
م الصندوق [ع] / د client_advances [ع] (party, project)
```

### دفعة مقاول — `payment` / `contractor_payment` (#5، #7، R11، R12)
`{cash_box_id, currency, amount (الإجمالي G), party_id, contractor_agreement_id?, project_id?, project_cost_category_id?, advance_deduction? (D), override_reason?}`
```
م project_costs              G       (project, تصنيف الاتفاقية أو السند, party, agreement)
د contractor_advances        D       (party, agreement)        — إن وُجد خصم
د الصندوق                    G − D                             — إن كان موجباً
```
- **نمط `agreement` (R11):** الاتفاقية إلزامية ومفتوحة وللمقاول نفسه؛ المشروع والتصنيف من الاتفاقية (إرسال غيرهما 422)؛ العملة = عملة الاتفاقية. إذا تجاوز مجموع الدفعات (بعد هذه) `net_value` تُرفض (422 على `override_reason` مع المتبقي) إلا مع `override_reason`، فتُرحَّل مع تحذير في `warnings[]`.
- **نمط `payments_only` (R12):** بلا اتفاقية يكفي `project_id` و`project_cost_category_id`. وإن أُرسلت اتفاقية تُطبَّق قواعدها.
- **الخصم (#7):** 0 ≤ D ≤ G، وD ≤ رصيد سلف المقاول المفتوحة بالعملة (المرتبطة بالاتفاقية إن كانت الدفعة على اتفاقية).
- R13: تحذير الموازنة كما في §3.

### سلفة مقاول — `payment` / `contractor_advance` (#6)
`{cash_box_id, currency, amount, party_id, contractor_agreement_id?, project_id?}` — الجهة بدور `contractor`. مع اتفاقية: مفتوحة وللمقاول نفسه وبعملتها، والمشروع مشروعها.
```
م contractor_advances (party, agreement, project) / د الصندوق
```

### تكلفة مباشرة — `payment` / `supplier_payment` (#3)
بلا تغيير من المرحلة 2، وصارت تخضع لفحص الموازنة R13 تلقائياً.

## ملاحظات للواجهة (تغييرات)
- مسارات جديدة كلها؛ لا شيء محذوف.
- `POST /vouchers` يقبل الآن `receipt.client_payment` و`payment.contractor_payment` و`payment.contractor_advance` و`project_billing`.
- أي اعتماد سند فيه تكاليف مشروع قد يعيد تحذيرات موازنة في `warnings[]`، وتصل إشعارات `finance.budget_warning|finance.budget_exceeded`.
- إنشاء المشروع من شاشة المالية يتم بـ `POST /finance/projects` (يُنشئ المشروع العام أيضاً)؛ `POST /api/v1/projects` يبقى للسجل العام بلا ملف مالي، ويُربط لاحقاً بـ `project_id`.
