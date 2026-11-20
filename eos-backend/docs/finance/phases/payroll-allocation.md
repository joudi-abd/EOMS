# المرحلتان 7 و8: تكامل الرواتب والتوزيع على المشاريع

بنود الراتب الديناميكية (D19)، وخصم أقساط السلف (R18)، والبنود المرتبطة بمشروع (R21)، وقيد الاستحقاق (#15) والدفع (#16)، ونسب وقت الموظفين، وتوزيع الرواتب (#17) والمصاريف العامة (#18) على المشاريع (D13، §6.3).
تفاصيل مسارات الرواتب في `docs/PAYROLL_API_AR.md`؛ هنا الملخص والقيود والتوزيع.

## 1. بنود الراتب (وحدة الرواتب)

- جدول `pay_salary_components` (رمز فريد = نوع بند الكشف، الاسم، استحقاق/استقطاع، ثابت/نسبة، أساس النسبة، مرتبط بمشروع، متكرر، حساب المصروف، نظامي، فعّال). يُزرع فيه 15 بند نظام (أنواع الحاسبة) وأمثلة الوثيقة: راتب نسبة، راتب دراسة، راتب إشراف، مهام، حوافز، مكافآت.
- `pay_employee_components`: بند متكرر لموظف (مبلغ أو نسبة، مشروع، من شهر إلى شهر).
- `pay_run_items.component_id` و`project_id` (البنود القديمة رُبطت ببنود النظام بالرمز).

| المسار (`/api/v1/payroll`) | الصلاحية |
|---|---|
| `GET components` | `payroll.view` |
| `POST components`، `PATCH components/{id}` | `finance.settings` (البنود من إعدادات المالية §9.1) |
| `GET employee-components?employee_id=` | `payroll.view` |
| `POST employee-components`، `PATCH/DELETE employee-components/{id}` | `payroll.manage` |
| `POST runs/{id}/items` `{employee_id, component_id?, category?, project_id?, amount?, base_amount?, percentage?, reason}` | `payroll.manage` |
| `POST items/{id}/review` `{decision: postpone, reason}` (قسط سلفة) | `payroll.approve` |

- **R21:** البند المرتبط بمشروع بلا `project_id` ← 422 على `project_id`. والبند غير المرتبط مع مشروع ← 422.
- **البنود المتكررة** تُضاف عند كل حساب (`origin: recurring`).
- **أقساط السلف:** بند `advance_deduction` (`origin: advance`) لكل قسط مستحق بعملة السطر؛ قسط بعملة أخرى يُتخطّى مع تنبيه `advance_currency`. **R18:** صافٍ سالب بسببها ← تنبيه `advance_exceeds_net`، والاعتماد مرفوض حتى تؤجّل المالية قسطاً. الاعتماد يعلّم الأقساط `deducted`.
- **OP-1:** المالية وحدها تعتمد الكشف؛ `company_manager` لا يملك `payroll.approve` (منذ المرحلة 1) ويرى الكشوف فقط.

## 2. القيود

**#15 استحقاق الرواتب** (`payroll_accrual`، `PR-…`) عند اعتماد الكشف، بتاريخ آخر يوم في الشهر، في المكتب الرئيسي، لكل موظف بعملة عقده وعلى كل سطر `employee_id`:

| السطر | الحساب | الأبعاد |
|---|---|---|
| م الاستحقاقات العامة | حساب البند أو `salaries_expense` | الموظف |
| م البنود المرتبطة بمشروع | `project_costs` | المشروع، تصنيف `loaded_payroll`، الموظف |
| د أقساط السلف | `employee_advances` | الموظف، جهة السلفة |
| د الاستقطاعات الأخرى | حساب البند أو `salaries_expense` (والمرتبطة بمشروع د `project_costs`) | الموظف |
| د الصافي | `salaries_payable` | الموظف |

البنود المتماثلة (الحساب والجهة والأبعاد) تُجمع في سطر واحد. كل سطر مقرّب لخانات العملة، والذمة = الصافي مقرّباً؛ فرق التقريب بينهما على سطر مصروف الرواتب العام، فتُسدّد الذمة بالضبط عند الدفع.

**#16 صرف الراتب** (`payroll_payment`، موجود منذ المرحلة 2، تم التحقق منه): م `salaries_payable` [عملة العقد] / د الصندوق. بعملة أخرى: م `salaries_payable` [ع] / د `fx_position` [ع]؛ م `fx_position` [د: المحوَّل] / د الصندوق [د: المدفوع]، وفرق التقريب على `rounding_differences` (OP-16). **D20/R19:** عملة الدفع من عملات الرواتب (`is_payroll_currency`)، والصندوق يجب أن تكون العملة مفعّلة فيه (422 على `box_id`).

**#17 توزيع الرواتب** (`allocation`، `AL-…`): م `project_costs` (المشروع، `loaded_payroll`، الموظف) / د `payroll_allocation_contra` (الموظف)، لكل عملة.

**#18 توزيع المصاريف العامة** (`allocation`): م `project_costs` (المشروع، `loaded_overhead`) / د `overhead_allocation_contra`، لكل عملة.

## 3. نسب الوقت

| المسار (`/api/v1/finance`) | الوصف |
|---|---|
| `GET allocations/time-shares?year=&month=` | `{year, month, locked, employees: [{employee_id, employee_name, total, shares: [{project_id, project_name, percentage}]}]}` |
| `PUT allocations/time-shares` | `{year, month, employees: [{employee_id, shares: [{project_id, percentage}]}]}` — يستبدل نسب الموظفين المرسلين فقط (`shares: []` يمسح). المجموع لكل موظف ≤ 100، والمشروع موجود وغير مغلق وغير مكرر |
| `POST allocations/time-shares/copy-previous` | `{year, month}` — ينسخ نسب الشهر السابق لكل موظف ليس له نسب في الشهر. يعيد شكل GET مع `copied` (عدد الموظفين). 422 إن لم يكن في الشهر السابق نسب |

الصلاحية `finance.allocations`. النسب **مجمّدة** (409) ما دام للشهر توزيع رواتب معتمد غير معكوس.

## 4. التوزيع

| المسار (`/api/v1/finance`) | الوصف |
|---|---|
| `GET allocations?kind=&year=&month=&branch_id=&status=&per_page=` | قائمة مرقّمة بنطاق الفرع |
| `GET allocations/{id}` | التوزيع مع `lines` و`totals` (لكل عملة) و`voucher` |
| `POST allocations/preview` | المعاينة بلا ترحيل |
| `POST allocations` | الحساب والتسجيل والترحيل في عملية واحدة (201) |

الطلب:
```json
{ "kind": "overhead", "year": 2026, "month": 11, "branch_id": 1, "method": "percentages",
  "lines": [{ "project_id": 4, "percentage": 60 }, { "project_id": 7, "percentage": 40 }], "notes": "اختياري" }
```
- `kind`: `payroll` أو `overhead`. `branch_id` إلزامي للعامة، وللرواتب افتراضياً المكتب الرئيسي (حيث يُرحَّل الاستحقاق).
- `method`: للرواتب `time_shares` (افتراضي الوثيقة) أو `percentages` أو `manual_amounts`؛ وللعامة `proportional_direct_cost` أو `percentages` أو `manual_amounts`.
- `percentages`: `lines[].{project_id, percentage}`، المجموع ≤ 100، يُطبَّق على وعاء كل عملة.
- `manual_amounts`: `lines[].{project_id, currency, amount}`، والمجموع لكل عملة ≤ وعائها.
- مشاريع التوزيع العام من فرع التوزيع نفسه، وغير مغلقة.

**الوعاء** (من القيود فقط؛ المالية لا تقرأ جداول الرواتب):
- **الرواتب:** لكل موظف وعملة، صافي سطور حسابات المصروف التي تحمل الموظف في قيود استحقاق الرواتب المعتمدة للشهر والفرع (مصروف الرواتب وحسابات البنود، بعد الاستقطاعات الدائنة عليها)، دون البنود المرتبطة بمشروع (هي على `project_costs` أصلاً).
- **العامة:** لكل عملة، صافي سطور حسابات المصروف في التصنيفات `is_overhead_allocatable` للشهر والفرع، غير المحمّلة على مشروع. `pool_base_total` يعرضه موحّداً بالدولار (بأسعار سطوره)، والترحيل يبقى لكل عملة.

**الطرق:**
- `time_shares`: تكلفة كل موظف × نسبه في الشهر؛ ما لا تغطيه النسب يبقى عاماً (`unallocated`).
- `proportional_direct_cost`: المشاريع ذات التكلفة المباشرة (تصنيفات `is_billable`) في الشهر من الفرع، بوزن تكلفتها بالدولار.
- **التقريب (§14-10):** ما يُوزَّع من وعاء يساوي المحسوب بالضبط، وفرق التقريب على آخر مشروع: 100$ على ثلاثة مشاريع متساوية ← 33.33 / 33.33 / 33.34.

الرد (المعاينة):
```json
{ "kind": "overhead", "year": 2026, "month": 11, "branch_id": 1, "method": "proportional_direct_cost",
  "pool": [{ "currency_id": 1, "currency": "USD", "amount": "100" }], "pool_base_total": "100",
  "lines": [{ "project_id": 4, "project_name": "أ", "currency_id": 1, "currency": "USD", "employee_id": null, "employee_name": null, "percentage": "33.3333", "amount": "33.33" }],
  "allocated": [{ "currency": "USD", "amount": "100" }], "unallocated": [{ "currency": "USD", "amount": "0" }],
  "warnings": [], "existing_allocation_id": null }
```

**منع التكرار:** توزيع معتمد واحد غير معكوس لكل (نوع، سنة، شهر، فرع): الثاني 409 حتى يُعكس الأول عبر `POST /vouchers/{voucher_id}/reverse` (`finance.reverse`)، فيصبح التوزيع `reversed`. الإنشاء يقفل صف الفترة أولاً، فطلبان متزامنان لا يمرّان معاً، والشهر المقفل مرفوض. لا يُنشأ سند `allocation` من `/vouchers` مباشرة (422).

## 5. الإقفال

فحوصات الإقفال (`GET /periods/{YYYY-MM}/checks`) كانت تضم تحذير التوزيع: "لم يُنفَّذ توزيع الرواتب/المصاريف العامة على المشاريع لهذا الشهر." ما دام لا يوجد توزيع معتمد من النوع للشهر. لم يتغيّر شيء فيها، وصار يعمل الآن فعلياً.

## Migrations
| الملف | الوحدة | المحتوى |
|---|---|---|
| `2026_11_24_000001_create_salary_components` | Payroll | `pay_salary_components` (مع الزرع)، `pay_employee_components`، `pay_run_items.component_id/project_id` وربط البنود القائمة |

## الصلاحيات
لا صلاحيات جديدة: بنود الراتب `finance.settings`، والمتكررة `payroll.manage`، والتأجيل `payroll.approve`، والتوزيع ونسب الوقت `finance.allocations`، وعكس التوزيع `finance.reverse`.

## افتراضات
- فرع قيد الاستحقاق والتوزيع للرواتب: المكتب الرئيسي (لا فرع على الموظف).
- سطور التوزيع كلها في فرع التوزيع (حتى لو كان مشروع الرواتب من فرع آخر).
- النسبة من الراتب الأساسي = الأجر الأساسي المكتسب في الشهر. والنسبة من أتعاب المشروع يُدخل المحاسب الأتعاب (`base_amount`) في البند اليدوي.
- البند المتكرر الثابت بعملة عقد الموظف.

## للواجهة (تغييرات تكسر)
- **تصدير CSV للكشف:** الأعمدة الثابتة (`salary_or_base`، `overtime`، `bonus`، `unpaid_leave`، `partial_pay_leave`، `late`، `early_exit`، `absence`، `manual_deduction`، `adjustments`) استُبدلت بعمود لكل بند مستخدم باسمه العربي.
- **بنود الكشف** فيها `component_id` و`project_id`، وفي تفاصيل السطر `component: {id, code, name, kind, is_project_linked}`. `kind` قد يكون رمز أي بند (لا قائمة ثابتة)، وأصول جديدة: `recurring` و`advance`.
- `POST runs/{id}/items`: `category` صار اختيارياً مع `component_id`، و`amount` اختياري لبنود النسبة.
- `POST items/{id}/review` يقبل `decision: postpone` ويعيد **السطر** لا البند.
- تنبيهات سطر جديدة غير مانعة: `advance_currency`، `advance_exceeds_net`.
- سند الاستحقاق صار بسطور مفصّلة لكل بند (لم يعد سطرين لكل موظف).
- مسارات جديدة: بنود الراتب، نسب الوقت (PUT ونسخ)، التوزيع (معاينة، إنشاء، قائمة، عرض).
