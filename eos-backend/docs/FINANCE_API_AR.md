# وحدة المالية — مرجع الـAPI والقواعد

> الحالة: **كل مراحل الخلفية (1–11) منفّذة.** هذا الملف يغطي النواة والخزينة. مرجع كل مجال في ملفه:
>
> | المجال | الملف |
> |---|---|
> | جهات التعامل، الشركاء، تصنيفات المصاريف، سند المصروف، طلبات المشرفين | `docs/finance/phases/parties-expenses.md` |
> | المشاريع، العقود، الموازنة، المقاولون، المطالبات، الربحية، كشف المشروع | `docs/finance/phases/projects.md` |
> | سلف الموظفين وأقساطها، العُهد | `docs/finance/phases/advances-custody.md` |
> | بنود الراتب، تكامل الرواتب، نسب الوقت، التوزيع على المشاريع | `docs/finance/phases/payroll-allocation.md` و`docs/PAYROLL_API_AR.md` |
> | الأصول | `docs/finance/phases/assets.md` |
> | التقارير T1–T23، لوحة المدير، معالج الإعداد، شاشة الإقفال | `docs/finance/phases/reports-setup.md` |
>
> سجل التغييرات: `docs/finance/CHANGELOG.md`.

كل المسارات تحت `/api/v1/finance` وتتطلب `auth:sanctum`. الاستجابة `{data}`، والقوائم المرقّمة `{data, links, meta}` (`per_page` حتى 100). الأخطاء `422` للتحقق، `403` للصلاحية أو لفرع خارج النطاق، `409` لحالة لا تسمح.

- **المبالغ نصوص عشرية** بأربع منازل في التخزين (`"1250.5000"`)، وتُقرَّب عند الترحيل لخانات العملة (الدولار 2، الليرة السورية 0). لا أرقام عائمة.
- **العملة** تُرسل بالمعرّف (`currency_id`) أو بالرمز (`currency: "USD"`)، ومثلها `counter_currency` و`fee_currency`.
- **التواريخ** `YYYY-MM-DD`.

## الصلاحيات والنطاق

| الصلاحية | النطاق | ماذا تتيح |
|---|---|---|
| `finance.view` | الكل / الذات | القراءة: الصناديق، السندات، القيود، الدليل، الجرد |
| `finance.settings` | الكل | العملات، الفروع، الدليل، الحسابات الإلزامية، الصناديق، إسناد الفروع للمستخدمين |
| `finance.rates` | الكل / الذات | النشرة اليومية |
| `finance.vouchers` | الكل / الذات | إنشاء السندات وتعديل مسوداتها واعتمادها ورفضها |
| `finance.reverse` | الكل / الذات | عكس السندات المعتمدة |
| `finance.counts` | الكل / الذات | إدخال الجرد وإرساله |
| `finance.counts.approve` | الكل | اعتماد الجرد ورفضه (ليس لمن أجراه) |
| `finance.periods` | الكل | فحوصات الإقفال، الإقفال، إعادة الفتح |
| `finance.reports` | الكل / الذات | التقارير (حالياً: حركة الصندوق) |
| `finance.audit` | الكل | سجل التدقيق |
| `finance.projects` / `finance.projects.view` | الكل / الذات | المشاريع (`/api/v1/projects`) |
| `finance.site_requests`، `finance.allocations`، `finance.assets` | — | مسارات قراءة أولية؛ تكتمل في مراحلها |

**النطاق "الذات" في المالية = الفروع المسندة للمستخدم** (`PUT /users/{id}/branches`). القوائم تعرض فروعه فقط، وأي إنشاء أو اعتماد على صندوق أو سند في فرع آخر يرجع 403. استثناء: `finance.projects.view` بالذات = المشاريع التي يديرها، و`finance.site_requests` بالذات = المشاريع التي يشرف عليها.

الأدوار: `finance_manager` (المحاسب العام) كل المالية بنطاق الكل عدا اعتماد الجرد؛ `company_manager` (المدير العام) عرض وتقارير ومشاريع للعرض واعتماد الجرد والتدقيق، ولا يعتمد السندات اليومية (D25)؛ `branch_accountant` (محاسب الفرع) بنطاق فروعه؛ `project_manager` مشاريعه للعرض؛ `site_supervisor` (المشرف) طلبات الموقع.

## الإعدادات (`/api/v1/settings`)

| الطلب | الصلاحية | ملاحظات |
|---|---|---|
| GET `/currencies?active=1` | مسجّل | الحقول: `code, name, symbol, decimals, rounding_step, is_base, is_payroll_currency, is_active`. الأساسية أولاً |
| POST `/currencies`، PATCH `/currencies/{id}` | `finance.settings` | عملة أساسية واحدة (USD) لا تُعطَّل ولا تتغير. الرمز لا يُعدَّل |
| GET `/exchange-rates?date=` | مسجّل | نشرة اليوم: `{date, base, rates:[{code, rate_to_base, ...}], missing:[codes]}` |
| GET `/exchange-rates/history?currency=SYP&limit=30` | مسجّل | الأحدث أولاً |
| POST `/exchange-rates` `{date, rates:{SYP: 13000, TRY: 43.4}}` | `finance.rates` | `rate_to_base` = كم وحدة من العملة تساوي 1 دولار. يستبدل أسعار اليوم نفسه، ويُسجَّل في التدقيق |
| POST `/exchange-rates/copy-previous` `{date}` | `finance.rates` | ينسخ آخر سعر سابق لكل عملة ناقصة في ذلك اليوم |
| GET `/branches`، POST، PATCH `/branches/{id}` | مسجّل / `finance.settings` | مكتب رئيسي واحد: تعيين آخر ينقل العلامة. لا يُعطَّل المكتب الرئيسي |

## النواة

| الطلب | الصلاحية | ملاحظات |
|---|---|---|
| GET `/accounts?active=1` | `finance.view` | الدليل شجرة (`children`)، ولكل حساب `roles` (أدوار النظام التي يلعبها) و`cash_box_id` |
| POST `/accounts` | `finance.settings` | `{code, name, type, parent_id?, is_postable?, requires_party?, requires_project?}` |
| PATCH `/accounts/{id}`، POST `/accounts/{id}/deactivate` | `finance.settings` | النوع لا يتغير |
| GET `/system-accounts` | `finance.view` | كل الأدوار الإلزامية: `{roles:[{role, label, account_id, account, unmapped}], unmapped_count}` |
| PUT `/system-accounts` `{mappings:{role: account_id}}` | `finance.settings` | |
| GET `/periods` | `finance.view` | |
| GET `/periods/{YYYY-MM}/checks` | `finance.periods` | `{blockers:[], warnings:[]}` |
| POST `/periods/{YYYY-MM}/close` | `finance.periods` | يرجع الفترة مع `warnings` |
| POST `/periods/{YYYY-MM}/reopen` `{reason}` | `finance.periods` | |
| GET `/entries?from&to&account_id&source_type&source_id&branch_id`، GET `/entries/{id}` | `finance.view` | القيود بسطورها |
| GET/PUT `/users/{id}/branches` `{branch_ids:[]}` | `finance.settings` | فروع محاسب الفرع |
| GET `/my-branches` | `finance.view` | `branch_ids: null` = كل الفروع |
| GET `/audit-log?action&user_id&from&to&auditable_type&auditable_id` | `finance.audit` | عمليات `finance.*` و`fx.*` و`settings.*` |

**قواعد الدليل:** الفرعي من نوع أبيه. الأب الذي له حركات لا يأخذ فروعاً. الحساب يصبح تجميعياً (`is_postable=false`) تلقائياً عند أول فرع. يُرحَّل على الحسابات الفعّالة القابلة للترحيل فقط. حسابات الصناديق (`is_system`) والحسابات المربوطة بدور لا تُعطَّل.

**الحسابات الإلزامية:** `cash_boxes_parent, receivables_clients, client_advances, payables_suppliers, payables_contractors, contractor_advances, employee_advances, custody, salaries_payable, salaries_expense, project_costs, project_revenue, payroll_allocation_contra, overhead_allocation_contra, fx_position, transfer_fees, partners_current, partners_capital, fixed_assets, asset_disposal_gain_loss, asset_maintenance, cash_over_short, other_receivables, other_payables, opening_balance_equity, rounding_differences`. أي ترحيل يحتاج دوراً غير مربوط يُرفض: `اربط الحساب "..." من إعدادات الحسابات الإلزامية.` (الحقل `system_accounts`). دليل البداية: `FinanceChartSeeder`.

**محرك القيود:** لكل قيد ولكل عملة مجموع المدين = مجموع الدائن، وإلا يُرفض كاملاً (`القيد غير متوازن في العملة X.`). لا موازنة تلقائية أبداً. كل سطر يحمل سعر النشرة في تاريخ القيد أو قبله (`rate_to_base`) ومعادله بالدولار (`base_debit/base_credit`)؛ إن لم يوجد سعر يُرفض القيد. الحساب والجهة غير الفعّالين يُرفضان (R4)، والمشروع `closed` يُرفض و`completed` يُقبل مع تحذير (R5). الأرصدة في `fin_account_balances` لكل (حساب، عملة، جهة)، ويعيد بناءها `php artisan finance:rebuild-balances [--dry-run]`.

**الإقفال (R1):** لا ترحيل ولا عكس بتاريخ شهر مقفل. الأشهر تُقفل بالترتيب وبعد انتهائها. موانع الإقفال: أشهر سابقة مفتوحة، سندات مسودة أو مُرسلة في الشهر، طلبات موقع غير معالجة، جرد غير معتمد، كشف رواتب الشهر غير معتمد. تحذيرات: توزيع الرواتب والمصاريف العامة غير منفذ، أو لا يوجد كشف رواتب. إعادة الفتح بسبب، ومن آخر شهر مقفل نزولاً.

**الترقيم:** بلا فجوات لكل نوع وسنة، ويُمنح عند الاعتماد: `RV` قبض، `PV` صرف، `TR` تحويل، `FX` تصريف، `EX` مصروف، `PB` مطالبة، `CU` صرف عهدة، `CS` تسوية عهدة، `CR` رد عهدة، `AI` سلفة موظف، `AP` شراء أصل، `AM` صيانة أصل، `AD` استبعاد أصل، `CA` فرق جرد، `OB` رصيد افتتاحي، `AL` توزيع، `MJ` قيد يدوي، `PR` استحقاق رواتب، `SP` صرف راتب، `JE` القيد. الصيغة `RV-2026-000001`. المسودة تظهر `display_number: "مسودة #12"`.

## الخزينة

### الصناديق

| الطلب | الصلاحية |
|---|---|
| GET `/cash-boxes?branch_id&active` | `finance.view` (فروعه) |
| GET `/cash-boxes/{id}` | `finance.view` |
| POST `/cash-boxes` | `finance.settings` |
| PATCH `/cash-boxes/{id}`، POST `/cash-boxes/{id}/deactivate` | `finance.settings` |

```json
{"name": "موني آوت", "type": "exchange_office", "branch_id": 1, "custodian_user_id": 5, "contact_info": "…",
 "currencies": [{"currency": "USD", "allow_negative": true, "negative_limit": 30000}, {"currency": "TRY"}]}
```
`type`: `cash_safe | exchange_office | bank | e_wallet`. يُنشأ حساب الصندوق تلقائياً تحت `cash_boxes_parent` برمز `{رمز الأب}-{id}`. كل عملة في القائمة تعيد: `balance, allow_negative, negative_limit, available, negative, near_limit` (`near_limit` عند استهلاك 80% من حد السالب). لا تُزال عملة لها رصيد.

**الرصيد السالب (R8، OP-13):** غير مسموح = الحد 0؛ مسموح بحد = −الحد؛ مسموح بلا حد = لا فحص. الفحص على **أدنى رصيد جارٍ من تاريخ السند فما بعد**، فلا يُكسر كشف يوم لاحق بسند بتاريخ قديم. الرسالة: `رصيد الصندوق X بعملة USD لا يكفي: الرصيد الحالي …، المتاح ….` (الحقل `amount`). الترحيل يقفل صفوف الصناديق ثم الأرصدة، فسندان متزامنان لا يكسران الحد.

### السندات

| الطلب | الصلاحية |
|---|---|
| GET `/vouchers?type&purpose&status&box_id&party_id&project_id&branch_id&from&to&search` | `finance.view` |
| GET `/vouchers/{id}` | `finance.view` — مع `lines`، `attachments`، `entry.lines`، `reversal_entry`، `display_number`، `warnings` |
| POST `/vouchers` (`approve: true` يُرحّل فوراً) | `finance.vouchers` |
| PUT `/vouchers/{id}` (مسودة فقط، مع `version`) | `finance.vouchers` |
| DELETE `/vouchers/{id}` (مسودة فقط) | `finance.vouchers` |
| POST `/vouchers/{id}/submit`، `/approve`، `/reject` `{reason}` | `finance.vouchers` |
| POST `/vouchers/{id}/reverse` `{date, reason}` | `finance.reverse` |
| POST `/vouchers/{id}/attachments` (`file`)، GET/DELETE `/vouchers/{id}/attachments/{a}` | `finance.vouchers` / `finance.view` |
| GET `/vouchers/suggest-rate?from=USD&to=TRY&date=` | `finance.view` — سعر النشرة المتقاطع لتعبئة التصريف |

دورة الحالة: `draft → (submitted) → approved → reversed`، أو `rejected` من `submitted`. المسودة تُعدَّل وتُحذف؛ المعتمد لا يُعدَّل ولا يُحذف ويُصحَّح بالعكس (R3). العكس بتاريخ في شهر مفتوح، مرة واحدة. المالية تعتمد ما أدخلته بنفسها (OP-14). أي رد يحمل `warnings: []` غير مانعة. المرفقات: jpg/png/webp/pdf حتى 10MB، و10 مرفقات للسجل، وتُحذف من المسودة فقط.

| النوع / الغرض | الحقول | القيد |
|---|---|---|
| `receipt` / `payment` بغرض `other` | `cash_box_id, currency, amount, account_id` + أبعاد اختيارية | الصندوق مقابل الحساب. الحساب ليس صندوقاً ولا حساب دور (إلا `opening_balance_equity`) |
| `receipt`/`payment` بغرض `debt` | `party_id, debt_direction: we_owe\|owed_to_us` | علينا: قبض يقترض (د `other_payables`)، صرف يسدد. لنا: صرف يُقرض (م `other_receivables`)، قبض يحصّل |
| `payment` بغرض `supplier_payment` | `party_id` (دور مورد)؛ مع `project_id` يلزم `project_cost_category_id` | بلا مشروع: م `payables_suppliers` / د الصندوق. مع مشروع: م `project_costs` (المشروع، التصنيف، المورد) / د الصندوق |
| `transfer` | `cash_box_id, counter_cash_box_id, currency, amount, fee_amount?, fee_currency?` | م الصندوق ب / د الصندوق أ؛ العمولة: م `transfer_fees` / د أ. صندوقان مختلفان والعملة مفعّلة على الاثنين |
| `exchange` | `cash_box_id, currency, amount, counter_cash_box_id, counter_currency, counter_amount` أو `rate` | م `fx_position` [X] / د أ [X]؛ م ب [Y] / د `fx_position` [Y]؛ سعر الصفقة على السطور الأربعة. `rate = counter_amount ÷ amount`. عملتان مختلفتان ومبلغان موجبان (R9). انحراف السعر عن النشرة فوق `finance.exchange_rate_tolerance_pct` (5%) يعطي تحذيراً ولا يمنع (R10) |
| `manual_journal` | `lines:[{account_id, currency, debit\|credit, party_id?, project_id?, …, memo}]` | كما أُدخل، متوازن لكل عملة. حسابات الصناديق مرفوضة |
| `opening_balance` | `lines` (للصندوق: `cash_box_id` بدل `account_id`) | السطور + سطر مقابل على `opening_balance_equity` لكل عملة تلقائياً |
| `count_adjustment`، `payroll_accrual`، `payroll_payment` | — | تُنشأ تلقائياً من الجرد والرواتب فقط |

الأغراض الأخرى (`client_payment, contractor_payment, contractor_advance, internal_expense, partner_drawing, partner_deposit, employee_advance, custody`) والأنواع (`expense, project_billing, custody_*, advance_issue, asset_*, allocation`) ترجع `هذا النوع من السندات غير متاح بعد.` حتى مراحلها.

### الجرد

| الطلب | الصلاحية |
|---|---|
| GET `/cash-counts?box_id&status&from&to`، GET `/cash-counts/{id}` | `finance.view` |
| POST `/cash-counts` `{cash_box_id, count_date, notes?, lines:[{currency, counted_amount, explanation?}]}` | `finance.counts` |
| PUT/DELETE `/cash-counts/{id}` (مسودة، من أجراه) | `finance.counts` |
| POST `/cash-counts/{id}/submit`، `/resnapshot` | `finance.counts` |
| POST `/cash-counts/{id}/approve`، `/reject` `{reason}` | `finance.counts.approve` |
| POST/GET `/cash-counts/{id}/attachments` | كشف الصراف |

عند الإرسال يُلتقط رصيد النظام لكل عملة في تاريخ الجرد ويُحسب `difference = counted − system`، ويصل إشعار لحاملي `finance.counts.approve`. لا يُرحَّل الفرق قبل الاعتماد (R22)، ولا يعتمد من أجرى الجرد. إذا تحرّك الصندوق بعد الإرسال يظهر `stale: true` ويُرفض الاعتماد (409) حتى `resnapshot` (R23). عند الاعتماد يُرحَّل سند `count_adjustment`: العجز م `cash_over_short` / د الصندوق، والزيادة عكسه.

## التقارير

GET `/reports/box-statement?box_id&currency_id?&from&to` (`finance.reports`): لكل عملة `opening`، والسطور (`date, entry_number, voucher_number, voucher_type, description, debit, credit, balance`)، و`total_in`، `total_out`، `closing`. الافتراضي من أول الشهر حتى اليوم.

## المشاريع (`/api/v1/projects`)

سجل مشاريع عام: GET `/` و`/{id}` (`finance.projects.view`)، POST `/`، PATCH `/{id}`، POST `/{id}/status` `{status}`، PUT `/{id}/members` `{members:[{user_id, role: supervisor|member}]}` (`finance.projects`). الحالات: `planned, active, on_hold, completed, closed`. بنطاق الذات: مدير المشاريع يرى مشاريعه، ومحاسب الفرع يرى مشاريع فروعه وينشئ فيها.

## الرواتب

اعتماد كشف الرواتب يُرحّل سند `payroll_accrual` بتاريخ آخر يوم في الشهر (م `salaries_expense` / د `salaries_payable` لكل موظف). كل دفعة راتب سند `payroll_payment` مرحّل: بنفس العملة م `salaries_payable` / د الصندوق والفرق على `rounding_differences`؛ وبعملة أخرى عبر `fx_position`. إلغاء الدفعة من الرواتب يعكس سندها؛ المالية لا تعكس سندات الرواتب من شاشة السندات (409).
