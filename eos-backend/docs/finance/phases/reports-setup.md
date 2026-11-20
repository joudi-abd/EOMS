# المرحلتان 10 و11: التقارير ولوحة المدير ومعالج الإعداد وشاشة الإقفال

البادئة `/api/v1/finance`، والمصادقة `auth:sanctum`. الاستجابة `{data}`. المبالغ نصوص عشرية (bcmath) بأربع منازل كحد أقصى، والنسب مئوية (`8` = 8%). التواريخ `YYYY-MM-DD`.

**المرجع:** `FINANCE_SYSTEM_SPEC` الأقسام 8، 9.8، 10 (T1–T23، 10.1، 10.2)، 12، 13، والقرارات D8 وD12 وD13 وD23 وD24.

**المصدر:** كل الأرقام تُقرأ من سطور القيود `fin_journal_lines` (مصدر الحقيقة). القيد المعكوس وقيده العكسي يبقيان معاً فيتعادلان في كل مجموع. لا قيود إقفال سنوية، فـ"الربح حتى التاريخ" = كل الإيرادات ناقص كل المصروفات حتى ذلك اليوم.

---

## 1. الإطار المشترك لكل تقرير

| المعامل | المعنى |
|---|---|
| `branch_id` | فرع واحد ضمن نطاق المستخدم في صلاحية التقرير (خارج النطاق ← 403). بدونه: كل فروع النطاق |
| `from`، `to` | الفترة. الافتراضي: من أول الشهر حتى اليوم |
| `as_of` | ليوم واحد (T1، T7، T8، T10، T11، T13، T14). يساوي `to` |
| `currency` (أو `currency_id`) | عملة واحدة؛ بدونها كل العملات |
| `view` | `separate` (الافتراضي): كل عملة منفصلة. `consolidated`: كل عملة منفصلة **ومعها** الموحّد بالدولار (D8) |
| `rate_mode` | يتجاوز الإعداد `finance.consolidated_report_rate_mode` لهذا الطلب: `report_date` أو `historical` |
| `format` | `csv` لتصدير Excel (UTF-8 مع BOM). الافتراضي JSON |

رأس كل تقرير:
```json
{
  "filters": {"branch_id": null, "branch_ids": [2], "from": "2026-11-01", "to": "2026-11-15", "currency": null, "view": "consolidated"},
  "consolidation": {
    "base_currency": "USD", "rate_mode": "report_date", "rate_date": "2026-11-15",
    "rates_used": [{"currency": "TRY", "rate": "40", "date": "2026-11-10"}, {"currency": "USD", "rate": "1", "date": "2026-11-15"}]
  }
}
```
- `branch_ids: null` = كل الفروع. `consolidation` يظهر مع `view=consolidated` فقط.
- **نطاق الفرع:** `finance.reports` بالذات = الفروع المسندة للمستخدم. يُطبَّق على فرع **السطر** (فرع الصندوق في T1 وT2، فرع القيد في T3، فرع السند في T21، فرع الجرد في T22).

### 1.1 التوحيد بالدولار (§10.2)
- **`report_date`** (الافتراضي): كل مبلغ بعملة X يُقسم على سعر X في النشرة يوم التقرير (`to`/`as_of`) أو آخر سعر قبله، ويُذكر يومه في `rates_used`. ينطبق على الأرصدة والحركات معاً.
- **`historical`**: مجموع `base_debit − base_credit` المحفوظة على السطور (كل سطر بسعر يومه)، و`rates_used` بـ`rate: null, date: null`. الأرقام التي ليست مجاميع سطور (أتعاب المشروع، ترتيب الربحية، كشف الجهة، العُهد، المتبقي على الاتفاقيات) تُحوَّل بسعر يوم التقرير في الوضعين.
- القيم الموحّدة بأربع منازل، والمجموع = مجموع الصفوف المحوّلة. غياب سعر لعملة لها مبلغ ← 422.

---

## 2. التقارير

الصلاحية `finance.reports` ما لم يُذكر غيرها. كلها تقبل `?format=csv` (T3 يصدّر كل السطور المطابقة لا الصفحة، وT23 حتى 10,000 سجل).

| # | المسار | فلاتر إضافية | ملاحظات |
|---|---|---|---|
| T1 | `GET /reports/box-balances?as_of=` | — | رصيد كل صندوق بكل عملة نهاية اليوم |
| T2 | `GET /reports/box-statement?box_id=&from=&to=&currency=` | `box_id` إلزامي | 403 لصندوق خارج النطاق |
| T3 | `GET /reports/journal` | `account_id, party_id, project_id, voucher_type, search, per_page` | مرقّم (`meta`) |
| T4 | `GET /reports/general-ledger` | `account_id` أو `account_ids[]` | الحساب التجميعي يجلب فروعه |
| T5 | `GET /reports/trial-balance?from=&to=` | — | |
| T6 | `GET /reports/income-statement?from=&to=&by_branch=1` | `by_branch` | للشركة ولكل فرع (D12) |
| T7 | `GET /reports/balance-sheet?as_of=` | — | |
| T8 | `GET /reports/projects-summary?as_of=` | `status, client_party_id, contract_type` | `finance.projects.view` |
| T9 | `GET /reports/project-statement?project_id=&variant=internal\|client&hide_internal=1&date=` | | `finance.projects.view` |
| T10 | `GET /reports/budget-vs-actual?as_of=` | `project_id, status, project_cost_category_id` | `finance.projects.view` |
| T11 | `GET /reports/project-profitability?as_of=` | `status, contract_type` | `finance.projects.view` |
| T12 | `GET /reports/party-statement?party_id=&project_id=&from=&to=` | `party_id` إلزامي | |
| T13 | `GET /reports/contractor-balances?as_of=&party_id=` | | |
| T14 | `GET /reports/aging?as_of=&side=receivable\|payable&party_id=` | | |
| T15 | `GET /reports/employee-advances?months=3` | | غلاف لـ `/employee-advances/report` |
| T16 | `GET /reports/open-custodies` | | غلاف لـ `/custodies/report` |
| T17 | `GET /reports/partners?from=&to=&party_id=` | | |
| T18 | `GET /reports/expenses-by-category?from=&to=` | | |
| T19 | `GET /reports/payroll-cost?from=&to=&employee_id=&project_id=` | | |
| T20 | `GET /reports/asset-register` | | من مرحلة الأصول (`assets.md` §9) |
| T21 | `GET /reports/exchanges?from=&to=&box_id=` | | |
| T22 | `GET /reports/cash-counts?from=&to=&box_id=&status=` | | |
| T23 | `GET /reports/audit-log?action=&user_id=&from=&to=&auditable_type=&auditable_id=` | | `finance.audit`؛ مرقّم مثل `/audit-log` |

### T1 أرصدة الصناديق
```json
{"as_of": "2026-11-15",
 "boxes": [{"id": 1, "name": "الخزنة", "type": "cash_safe", "branch_id": 1, "is_active": true,
            "balances": [{"currency_id": 1, "code": "USD", "balance": "1001400", "negative": false, "balance_base": "1001400"}],
            "total_base": "1251508.5"}],
 "totals": [{"currency_id": 1, "code": "USD", "balance": "1001400", "balance_base": "1001400"}],
 "consolidated": {"total": "1251508.5"}}
```
تظهر كل عملة مفعّلة على الصندوق ولو بصفر. الصندوق المعطّل يظهر فقط إذا كان له رصيد. `*_base` و`consolidated` مع الموحّد فقط.

### T2 حركة صندوق
الشكل السابق نفسه (`box, from, to, currencies:[{currency_id, code, opening, lines:[{date, entry_number, voucher_id, voucher_number, voucher_type, description, memo, debit, credit, balance}], total_in, total_out, closing}]`) + الرأس المشترك. مع الموحّد: لكل عملة `base: {opening, total_in, total_out, closing}` و`consolidated` للمجموع.

### T3 دفتر اليومية
```json
{"data": {"filters": {}, "entries": [{"id": 9, "number": "JE-2026-000009", "date": "2026-11-11", "branch_id": 1, "status": "posted", "reversal_of_id": null,
   "description": "...", "source_type": "voucher", "source_id": 7, "voucher_number": "FX-2026-000001", "voucher_type": "exchange", "voucher_purpose": null,
   "lines": [{"line_no": 1, "account_id": 22, "account_code": "1700", "account_name": "...", "currency": "USD", "debit": "100", "credit": "0",
              "rate_to_base": "1", "base_debit": "100", "base_credit": "0", "deal_rate": "43.4", "branch_id": 1, "party_id": null, "party": null,
              "project_id": null, "project_cost_category_id": null, "expense_category_id": null, "asset_id": null, "employee_id": null,
              "contractor_agreement_id": null, "custody_id": null, "memo": "..."}]}],
  "totals": [{"currency_id": 1, "code": "USD", "debit": "...", "credit": "..."}]},
 "meta": {"current_page": 1, "last_page": 1, "per_page": 25, "total": 1}}
```
`totals` تغطي كل القيود المطابقة. فلتر العملة/الحساب/الجهة/المشروع يختار القيود التي فيها سطر مطابق ويعرض كل سطورها.

### T4 دفتر الأستاذ
`accounts: [{account_id, code, name, type, currencies: [{currency_id, code, opening, lines: [{date, entry_id, entry_number, voucher_id, voucher_number, voucher_type, description, memo, party_id, party, project_id, debit, credit, balance}], total_debit, total_credit, closing, base?: {opening, debit, credit, closing}}], consolidated?: {opening, debit, credit, closing}}]`. `balance` رصيد جارٍ (مدين − دائن).

### T5 ميزان المراجعة
لكل عملة: `accounts: [{account_id, code, name, type, opening, opening_debit, opening_credit, debit, credit, closing, closing_debit, closing_credit}]` و`totals: {opening_debit, opening_credit, debit, credit, closing_debit, closing_credit, balanced}`. الافتتاحي قبل `from` والحركة داخل الفترة. الموحّد: `consolidated: {accounts, totals}` لكل الحسابات معاً بالدولار. الحسابات بلا رصيد ولا حركة تُحذف.

### T6 قائمة الدخل
لكل عملة: `revenue: [{account_id, code, name, amount}], total_revenue, expenses: [...], total_expenses, net_profit`. مع `by_branch=1`: `branches: [{branch_id, name, code, currencies, consolidated?}]`. الإيراد = دائن − مدين، والمصروف = مدين − دائن (حسابات مقابل التحميل تظهر مصروفاً سالباً).

### T7 المركز المالي
لكل عملة: `assets, total_assets, liabilities, total_liabilities, equity, total_equity, current_profit, total_liabilities_equity_profit, difference, balanced`. **الأصول = الخصوم + حقوق الملكية + الربح حتى التاريخ** (متوازن لكل عملة لأن كل قيد متوازن لكل عملة). مع `branch_id` قد لا يتوازن الفرع وحده إذا حمل قيد واحد سطوراً بفرعين (يظهر `difference`).

### T8 ملخص المشاريع
صفوف `ProjectFinanceService::summary` (`projects.md` §1) بعملة عقد كل مشروع + `totals` لكل عملة عقد. الموحّد: لكل صف `base: {receipts, costs, remaining, fee, revenue, profit, client_balance}` و`consolidated`؛ في `historical` من المعادلات المحفوظة على سطور المشروع، والأتعاب بسعر يوم التقرير.

### T9 كشف حساب مشروع
شكل `GET /projects/{id}/statement` نفسه (`projects.md` §7). المشروع خارج الرؤية ← 404.

### T10 الموازنة مقابل الفعلي
`lines: [{budget_line_id, project_id, project_code, project, category_id, category, currency, budget, actual, remaining, pct, level, colour}]` — المتجاوزة أولاً، ثم التحذير، ثم بالنسبة تنازلياً؛ و`over_budget_projects: [ids]`.

### T11 ربحية المشاريع
`projects: [{rank, project_id, code, name, status, branch_id, contract_type, currency, revenue, direct_costs, loaded_costs, gross_margin, gross_margin_pct, net_margin, net_margin_pct, fee, net_margin_base, gross_margin_base}]` مرتبة بـ`net_margin_base` (الهامش الصافي بالدولار بسعر يوم التقرير، لأن عملات العقود تختلف)، و`ranking_basis` يذكر الأسعار. النسب من الإيراد (null بلا إيراد).

### T12 كشف حساب جهة
شكل `/parties/{id}/statement` (`parties-expenses.md`) مقيّداً بنطاق الفروع. بدون `from` يبدأ من أول حركة. الموحّد: لكل عملة `base: {opening, closing}` و`consolidated: {opening, closing, closing_side}`.

### T13 أرصدة المقاولين
`contractors: [{party_id, contractor, currency_id, currency, agreements, open_agreements, agreed_value, discount, net_value, paid_on_agreements, paid_without_agreement, remaining, advances_outstanding, payables}]` و`totals` لكل عملة.
- `net_value` = صافي الاتفاقيات غير الملغاة. `paid_on_agreements` = `project_costs` عليها (يشمل السلف المخصومة). `remaining = net_value − paid_on_agreements`.
- `paid_without_agreement` = تكاليف مشاريع بجهة المقاول بلا اتفاقية (نمط الدفعات فقط) أو على اتفاقية ملغاة.
- `advances_outstanding` = رصيد `contractor_advances`. `payables` = رصيد `payables_contractors` (موجب = علينا).

### T14 أعمار الذمم
`method: "fifo"`, `buckets: ["0_30","31_60","61_90","90_plus"]`, `rows: [{side, party_id, party, currency_id, currency, outstanding, 0_30, 31_60, 61_90, 90_plus, unapplied, net, oldest_open_date}]`, `totals`.
- **لنا (`receivable`):** `receivables_clients` و`other_receivables`. **علينا (`payable`):** `payables_suppliers` و`payables_contractors` و`other_payables`.
- **الطريقة (FIFO):** لكل جهة وعملة وجانب تُقرأ الحركات يوماً بيوم. مدين اليوم (جانب لنا) يفتح بنداً بتاريخه، والدائن يسدد أقدم البنود المفتوحة أولاً. ما يبقى مفتوحاً يُعمَّر بالأيام من تاريخه حتى `as_of`. الدائن الزائد عن كل البنود (دفعة مقدمة أو زيادة) يظهر في `unapplied` بلا عمر ويسدد أول بند لاحق. جانب علينا معكوس.

### T15 / T16
بيانات `/employee-advances/report` و`/custodies/report` كما هي (`advances-custody.md`)، ونطاقها نطاق `finance.view` كمصدرها.

### T17 الحساب الجاري للشركاء
`months: ["2026-10","2026-11"]`, `partners: [{party_id, partner, currency_id, currency, opening, months: [{month, drawings, deposits, balance}], total_drawings, total_deposits, closing, closing_side}]`. المسحوبات = مدين `partners_current`، والإيداعات = دائنه؛ الرصيد مدين − دائن (موجب = لنا على الشريك).

### T18 المصاريف حسب التصنيف (بديل ملف المصاريف الداخلية)
لكل عملة (والموحّد): `categories` شجرة `[{category_id, name, depth, is_subtotal, months: {"2026-10": "50"}, total, children}]` (الأب = مجموعه ومجموع أبنائه)، و`uncategorized: [{account_id, code, name, months, total}]`، و`month_totals`، و`total`.
- يشمل حسابات المصروفات **عدا** `project_costs` وحسابي مقابل التحميل (تكاليف مشاريع لا مصاريف شركة).
- تصنيف السطر = `expense_category_id` على السطر، وإلا التصنيف الوحيد المربوط بحساب السطر (مثل "صيانة أصول"). ما لا تصنيف له في `uncategorized` بحسابه.

### T19 تكلفة الرواتب
من قيود سندات `payroll_accrual` فقط (المالية لا تقرأ وحدة الرواتب): صافي (مدين − دائن) سطورها على حسابات المصروفات (مصروف الرواتب، تكاليف المشاريع للبنود المرتبطة بمشروع، حسابات البنود).
`lines: [{employee_id, employee, project_id, project, account_id, component, currency, amount}]`، `by_employee`، `by_project`، `by_component`، `totals` لكل عملة، و`allocated_to_projects: [{project_id, project, currency, amount}]` (الرواتب الموزّعة بسندات `allocation` على تصنيف "رواتب محمّلة"). **البند = ملاحظة السطر (memo) التي يكتبها الاستحقاق، وإلا اسم الحساب.**

### T21 التصريف وفروقات العملة
`exchanges: [{voucher_id, number, date, status, branch_id, from_box, from_currency, amount, to_box, to_currency, counter_amount, deal_rate, bulletin_rate, difference, difference_pct, fee_amount, fee_currency, result_base, description}]`، `period_result_base`، و`fx_position: {as_of, currencies: [{code, balance, balance_base}], balance_base, result_base, result_side}`.
- `bulletin_rate` = السعر المتقاطع من النشرة في يوم السند أو قبله، بنفس اتجاه سعر الصفقة.
- `result_base` لكل تصريف = −(أثره على `fx_position` بالدولار)؛ موجب = ربح.
- `fx_position.balance_base` = رصيد `fx_position` بالدولار حتى `to` (يشمل فروقات عملة دفع الرواتب)؛ دائن = **ربح تصريف**، مدين = خسارة.

### T22 الجرد
`counts: [{id, cash_box_id, box, branch_id, count_date, status, counted_by, approved_by, voucher_id, lines: [{currency, system_balance, counted_amount, difference, explanation}]}]` و`per_box: [{cash_box_id, box, currency, counts, shortage, overage, net_difference}]` (للمعتمد فقط).

---

## 3. لوحة المدير العام (§10.1)

`GET /dashboard` — `finance.reports` (نطاق الفروع). استعلامات تجميعية فقط، والتوحيد بإعداد الشركة على تاريخ اليوم.
```json
{
  "date": "2026-11-15",
  "liquidity": {"total_base": "...", "currencies": [{"currency": "USD", "balance": "...", "balance_base": "..."}]},
  "box_alerts": [{"cash_box_id": 3, "box": "صرافة", "branch_id": 1, "currency": "USD", "balance": "-900", "negative_limit": "1000", "negative": true, "near_limit": true}],
  "projects": {"top_profit": [{"project_id": 5, "code": "100100", "name": "...", "status": "active", "currency": "USD", "net_profit": "2000", "net_profit_base": "2000"}],
               "over_budget": [{"project_id": 6, "code": "100101", "name": "...", "exceeded_lines": 1}]},
  "cash_flow": {"this_month": "2026-11", "last_month": "2026-10",
                "currencies": [{"currency": "USD", "this_month": {"in": "...", "out": "...", "net": "..."}, "last_month": {"in": "...", "out": "...", "net": "..."}}],
                "consolidated": {"this_month": {"in": "...", "out": "...", "net": "..."}, "last_month": {"in": "...", "out": "...", "net": "..."}}},
  "contractors": {"payables": {"currencies": [{"currency": "USD", "amount": "...", "amount_base": "..."}], "total_base": "..."},
                  "agreements_remaining": [{"currency": "USD", "amount": "5000", "amount_base": "5000"}], "agreements_remaining_base": "5000"},
  "clients": {"currencies": [{"currency": "USD", "amount": "500", "amount_base": "500"}], "total_base": "500"},
  "custodies": {"overdue_days": 30, "count": 1, "currencies": [{"currency": "USD", "count": 1, "balance": "100", "balance_base": "100"}]},
  "cash_counts_pending": {"count": 1, "items": [{"id": 4, "cash_box_id": 1, "box": "الخزنة", "count_date": "2026-11-14", "counted_by": 2}]},
  "partner_drawings": {"month": "2026-11", "currencies": [{"currency": "USD", "personal": "300", "company_expense": "0", "vouchers": 1, "personal_base": "300"}], "personal_total_base": "300"},
  "consolidation": {"base_currency": "USD", "rate_mode": "report_date", "rate_date": "2026-11-15", "rates_used": []}
}
```
- **الصناديق المنبّهة:** رصيد سالب؛ `near_limit` عند استهلاك 80% من حد السالب (كلوحة الصناديق).
- **أعلى 5 مشاريع ربحاً:** الربح الصافي (§6.2: الإيراد − كل تكاليف المشروع) بعملة العقد، مرتباً بمعادله بالدولار. **المتجاوزة للموازنة:** بنود بمستوى `exceeded` (المخزّن من مراقب الموازنة R13).
- **الوارد والصادر:** حركات صناديق النطاق هذا الشهر حتى اليوم والشهر السابق كاملاً، **بلا التحويل والتصريف** (حركة داخلية).
- **المستحق للمقاولين:** رصيد `payables_contractors` + المتبقي على الاتفاقيات المفتوحة (صافي القيمة − المدفوع، لا يقل عن صفر). **المستحق من العملاء:** رصيد `receivables_clients`.
- **العُهد المتأخرة:** مفتوحة وعمرها أكثر من `finance.custody_overdue_days`. **الجرد المنتظر:** بحالة `submitted`.
- **مسحوبات الشركاء هذا الشهر:** سندات `partner_drawing` المعتمدة؛ `personal` (حساب جارٍ) و`company_expense` (مصروف شركة، D15).

---

## 4. معالج الإعداد (القسم 12، المرحلة 11)

| المسار | الصلاحية | الوصف |
|---|---|---|
| `GET /setup/status` | `finance.view` | الخطوات الثماني |
| `POST /setup/starter-chart` | `finance.settings` | دليل البداية على دليل فارغ فقط (409 غير ذلك) |
| `POST /setup/opening-balances` | `finance.settings` | الخطوة 7 |
| `GET /setup/opening-check?date=` | `finance.view` | الخطوة 8 |

### 4.1 الحالة
```json
{"steps": [{"step": 1, "key": "currencies", "title": "العملات والنشرة الأولى", "done": false,
            "missing": ["أدخل النشرة الأولى للعملات: TRY."], "details": {"active": ["USD","SYP","TRY"], "without_rate": ["TRY"]}}],
 "done": 5, "total": 8, "completed": false}
```
| # | `key` | تكتمل عندما |
|---|---|---|
| 1 | `currencies` | عملة أساسية فعّالة، ولكل عملة فعّالة أخرى سعر نشرة واحد على الأقل |
| 2 | `branches` | فرع فعّال ومكتب رئيسي |
| 3 | `chart` | دليل غير فارغ وكل الأدوار الإلزامية مربوطة (`details.starter_chart_available` عند الدليل الفارغ) |
| 4 | `boxes` | صندوق فعّال على الأقل، ولكل صندوق فعّال عملة |
| 5 | `parties` | جهة فعّالة على الأقل (`details.by_role`؛ الاستيراد `POST /parties/import`) |
| 6 | `projects` | لكل مشروع نشط ملف مالي (عقد). `details.without_budget` تنبيه فقط |
| 7 | `opening_balances` | سند `opening_balance` معتمد على الأقل. `details`: السندات، `start_date`، عدد الأصول الافتتاحية، ورابطا `POST /assets/opening` و`POST /assets/import` |
| 8 | `opening_check` | ميزان المراجعة الافتتاحي متوازن لكل عملة، وحساب الأرصدة الافتتاحية يطابق |

### 4.2 الأرصدة الافتتاحية (الخطوة 7)
```json
{
  "date": "2026-09-30", "branch_id": 1, "description": "اختياري",
  "boxes": [{"cash_box_id": 1, "currency": "USD", "amount": 25000}],
  "projects": [{"project_id": 5, "currency": "USD", "receipts": 141500, "billed": 100000,
                "costs": [{"project_cost_category_id": 1, "amount": 60000}]}],
  "agreements": [{"contractor_agreement_id": 3, "paid": 7810}],
  "parties": [{"party_id": 9, "currency": "USD", "direction": "owed_to_us", "amount": 400}],
  "employee_advances": [{"employee_id": 12, "currency": "SYP", "remaining": 1000000, "installments_count": 3, "start_month": "2026-10"}],
  "custodies": [{"holder_party_id": 7, "project_id": 5, "currency": "USD", "balance": 250, "opened_on": "2026-09-20"}],
  "partners": [{"party_id": 2, "currency": "USD", "direction": "owed_to_us", "amount": 3000}]
}
```
كلها في **سند `opening_balance` واحد معتمد** (`VoucherService::createInternal` ثم `PostingService::approve`)، ويضيف `OpeningBalancePoster` سطر `opening_balance_equity` لكل عملة. `branch_id` الافتراضي المكتب الرئيسي.

| القسم | السطر |
|---|---|
| `boxes` | م الصندوق (المبلغ السالب: د). الصندوق من فرع السند والعملة مفعّلة عليه |
| `projects.receipts` | د `client_advances` (عميل المشروع، المشروع) — تُحسب دفعات للمشروع |
| `projects.costs` | م `project_costs` (المشروع، التصنيف) سطر مجمّع لكل تصنيف |
| `agreements.paid` | م `project_costs` (مشروع الاتفاقية، تصنيفها، المقاول، `contractor_agreement_id`) بعملة الاتفاقية. **لا تكرّر هذه المبالغ في تكاليف المشروع** |
| `parties` | `owed_to_us`: م `other_receivables`؛ `we_owe`: د `other_payables` (الجهة) |
| `employee_advances` | م `employee_advances` (الموظف وجهته إن وُجدت) + سلفة `fin_employee_advances` بأقساط معلّقة بلا صندوق. الأقساط إما `installments: [{due_month, amount}]` (مجموعها = المتبقي) أو `installments_count` + `start_month` (أقساط متساوية والكسر على الأخير) |
| `custodies` | م `custody` (صاحب العهدة، المشروع، `custody_id`) + عهدة مفتوحة `fin_custodies` بتاريخ `opened_on` (الافتراضي تاريخ البدء). تُرفض إذا كانت له عهدة مفتوحة بنفس المشروع والعملة |
| `partners` | `owed_to_us`: م `partners_current`؛ `we_owe`: د (الجهة شريك) |

**المطالب به (`projects.billed`):** لا يدخل سند الأرصدة الافتتاحية، لأن دفتر المشروع يعدّ كل حركة على `client_advances` خارج سندات المطالبة دفعةً، فسطر مطالبة هناك كان سيُنقص الدفعات. يُرحَّل بدلاً من ذلك **سند `project_billing` معتمد بتاريخ البدء** (القيد #2: م `client_advances` حتى الدفعات الافتتاحية والباقي م `receivables_clients` / د `project_revenue`)، فيبدأ كشف المشروع وربحيته صحيحين: الدفعات = الدفعات الافتتاحية، والمطالب به = الإيراد. يُدخل بعملة العقد، ولا يُقبل للمشروع الداخلي، ويخضع لحد المقطوعية.

**القواعد:** سند معالج واحد لكل فرع (409 إذا رُحّل؛ اعكسه لإعادة الإدخال)، وكل الفروع بتاريخ بدء واحد (422). المشاريع والاتفاقيات والصناديق من فرع السند. الطلب كله معاملة واحدة: أي خطأ لا يترك سنداً ولا عهدة ولا سلفة.

**الرد (201):** `{voucher: (شكل GET /vouchers/{id}), billings: [...], custody_ids: [...], employee_advance_ids: [...], check: (شكل opening-check)}`.

**العكس:** عكس سند الأرصدة الافتتاحية من `/vouchers/{id}/reverse` يعيد حساب العُهد التي فتحها (فتُغلق إن لم يُصرف منها؛ ويُرفض العكس إذا سُوّي منها شيء) ويلغي السلف التي أنشأها (ويُرفض إذا خُصم منها قسط). سندات المطالبة الافتتاحية تُعكس وحدها.

### 4.3 التحقق (الخطوة 8)
```json
{"date": "2026-09-30", "balanced": true, "equity_matches": true,
 "currencies": [{"currency_id": 1, "currency": "USD", "total_debit": "...", "total_credit": "...", "balanced": true,
                 "opening_balance_equity": "...", "net_assets": "...", "other_equity": "...", "profit_to_date": "...", "equity_matches": true}]}
```
ميزان كل الحركات حتى تاريخ البدء (الافتراضي: أقدم سند `opening_balance` معتمد). `opening_balance_equity` (دائن موجب) = صافي الأصول (الأصول − الخصوم) − حقوق الملكية الأخرى − الربح حتى التاريخ (تكاليف المشاريع والمطالبات الافتتاحية تقع في الربح حتى التاريخ).

---

## 5. شاشة الإقفال (§9.8)

| المسار | الصلاحية | الوصف |
|---|---|---|
| `GET /periods/closing` | `finance.periods` | كل الأشهر من أول قيد حتى الشهر الحالي، الأحدث أولاً |
| `GET /periods/{YYYY-MM}/checks` | `finance.periods` | بلا تغيير: `{month, blockers, warnings}` |

```json
[{"month": "2026-10", "year": 2026, "month_number": 10, "status": "open", "closed_at": null, "closed_by": null, "reopened_at": null, "reopen_reason": null,
  "blockers": 1, "warnings": 3, "can_close": false,
  "checklist": [{"key": "pending_vouchers", "label": "مسودات معلّقة", "level": "blocker", "passed": false, "count": 1,
                 "messages": ["توجد 1 سندات مسودة أو بانتظار الاعتماد في هذا الشهر."]}]}]
```
مفاتيح القائمة: `month_ended`، `earlier_months`، `pending_vouchers`، `site_requests`، `cash_counts` (موانع)، `allocation_payroll`، `allocation_overhead` (تحذيرات)، و`module:PayrollPeriodCheck` (كشف رواتب الشهر غير معتمد: مانع) و`module_warning:PayrollPeriodCheck` (لا يوجد كشف: تحذير). الأشهر المقفلة بلا `checklist`. الفحوصات الخمسة كانت موجودة؛ أُضيف شكلها المنظّم (`PeriodService::checklist`) والقائمة.

---

## 6. ملاحظات للواجهة
- **PDF وExcel في الواجهة** (OP-15): الخادم يعطي JSON جاهزاً للجداول، و`?format=csv` لكل تقرير قائمة.
- اعرض "الأسعار المستخدمة" من `consolidation.rates_used` في رأس كل تقرير موحّد مع تاريخ كل سعر.
- محاسب الفرع: لا ترسل `branch_id` خارج `GET /my-branches` (403). مدير المشاريع: T8–T11 فقط (والباقي 403).
- `box-statement` احتفظ بشكله وأُضيف إليه الرأس المشترك و`view=consolidated` و`currency`.
- المعالج: اعرض `steps[].missing` كقائمة مهام، وزر "دليل البداية" عندما `chart.details.starter_chart_available`. بعد الخطوة 7 اعرض `check` من الرد.
- T14: `unapplied` مبالغ بلا عمر (دفعات مقدمة)؛ اعرضها عموداً منفصلاً.

## Migrations
| الملف | المحتوى |
|---|---|
| `2026_11_24_100001_setup_add_agreement_and_custody_to_fin_voucher_lines` | `fin_voucher_lines.contractor_agreement_id` و`custody_id` (للأرصدة الافتتاحية) |

## الصلاحيات
لا صلاحيات جديدة: `finance.reports` (التقارير واللوحة)، `finance.projects.view` (T8–T11)، `finance.audit` (T23)، `finance.view` (حالة المعالج والتحقق)، `finance.settings` (دليل البداية والأرصدة الافتتاحية)، `finance.periods` (شاشة الإقفال).
