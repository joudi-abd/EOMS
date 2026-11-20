# المرحلتان 3 و4: جهات التعامل والمصاريف

البادئة `/api/v1/finance`، والمصادقة `auth:sanctum`. الاستجابة `{data}` والقوائم `{data, links, meta}`. المبالغ نصوص (bcmath).

## 1. جهات التعامل (D18، القسم 5.4)

| المسار | الصلاحية | الوصف |
|---|---|---|
| `GET /parties?role=&search=&active=&employee_id=&per_page=` | `finance.view` | القائمة. `role` أحد: `client, supplier, contractor, employee, partner, other`. `search` في الاسم والهاتف والاختصاص |
| `GET /parties/{id}` | `finance.view` | بطاقة الجهة: الحقول، `roles`، `effective_tracking_mode`، `partner`، و`balances` لكل عملة |
| `GET /parties/{id}/statement?currency=&project_id=&from=&to=` | `finance.view` | كشف حساب الجهة (T12) |
| `POST /parties` | `finance.vouchers` | إنشاء |
| `PATCH /parties/{id}` | `finance.vouchers` | تعديل (يقبل `is_active`) |
| `POST /parties/{id}/deactivate` | `finance.vouchers` | تعطيل (R4) |
| `POST /parties/import` | `finance.vouchers` | استيراد CSV (multipart `file`) |

**الحمولة:**
```json
{
  "name": "مؤسسة النور", "roles": ["supplier", "contractor"],
  "phone": "0933000111", "address": null, "notes": null,
  "employee_id": 12,
  "contractor_tracking_mode": null,
  "trade": "كهرباء",
  "sham_cash_wallet": "1234567890123456", "sham_cash_name": "محمد"
}
```
- `roles` مطلوب عند الإنشاء (دور واحد على الأقل). عند التعديل يستبدل الأدوار كلها.
- `employee_id` اختياري: موظف موجود في الموارد البشرية، ولا يرتبط موظف بأكثر من جهة.
- `contractor_tracking_mode`: `agreement | payments_only | null`. القيمة `null` تعني الإعداد العام `finance.contractor_tracking_mode_default` (D11). الحقل `effective_tracking_mode` في الاستجابة يعطي النمط النافذ.
- لا تُحذف الجهة. المعطّلة تبقى في التقارير ولا تقبل قيوداً جديدة (R4).
- لا تُزال صفة "شريك" عن جهة لها شراكة نشطة.

**الاستيراد (المعالج، الخطوة 5):** ملف CSV بصف عناوين `name,phone,role,trade`. الدور واحد أو عدة أدوار بفاصل `|` (مثل `client|supplier`). إذا طابق الاسم والهاتف جهة موجودة تُضاف أدوارها الناقصة فقط. أي سطر خاطئ يرفض الملف كله (422 بقائمة الأسطر). الحد 2000 سطر. الرد: `{created, updated, skipped}`.

### كشف حساب الجهة (القسم 9.3)
يشمل سطور القيود التي تحمل الجهة على حسابات الذمم: ذمم العملاء، دفعات العملاء المقدمة، ذمم الموردين والمقاولين، سلف المقاولين والموظفين، العُهد، الرواتب المستحقة، الحساب الجاري للشريك ورأس المال، الذمم الأخرى المدينة والدائنة، وأي حساب يتطلب جهة. أما تكاليف المشاريع وإيراداتها فتحمل الجهة بعداً تحليلياً فقط، ولا تدخل الكشف.

```json
{ "party": {"id": 5, "name": "كيف كافيه"},
  "filters": {"currency_id": 1, "project_id": null, "from": "2026-11-05", "to": null},
  "currencies": [{
    "currency_id": 1, "code": "USD", "opening": "-1000", "opening_side": "علينا",
    "lines": [{"date": "2026-11-05", "entry_number": "JE-2026-000004", "voucher_id": 9, "voucher_number": "PV-2026-000002",
      "voucher_type": "payment", "voucher_purpose": "debt", "account_code": "2500", "account_name": "ذمم أخرى دائنة",
      "project_id": 3, "description": "...", "memo": null, "debit": "400", "credit": "0", "balance": "-600", "side": "علينا"}],
    "total_debit": "400", "total_credit": "0", "closing": "-600", "closing_side": "علينا"
  }]
}
```
- الرصيد = مدين − دائن. **موجب = "لنا"** (الجهة مدينة لنا)، **سالب = "علينا"**، وصفر = "متوازن".
- بلا `from` يبدأ الرصيد الافتتاحي من صفر وتُعرض كل الحركات حتى `to`.
- محاسب الفرع يرى سطور فروعه فقط (R6).
- بطاقة الجهة `balances[]`: لكل عملة `balance` و`side` وتفصيل `accounts[]` (رمز الحساب واسمه ونوعه وطبيعته ورصيده).

## 2. الشركاء (D16)

| المسار | الصلاحية |
|---|---|
| `GET /partners?active=` | `finance.view` |
| `POST /partners` | `finance.vouchers` |
| `PATCH /partners/{id}` | `finance.vouchers` |

```json
{ "party_id": 7, "share_pct": 40, "joined_at": "2026-01-01", "is_active": true, "other_shares": {"3": 60} }
```
- **القاعدة:** حصص الشركاء النشطين مجموعها 100% بالضبط، أو كلها فارغة (المدير وحده).
- `other_shares` (رقم الشريك ← حصته) يعدّل حصص الآخرين في الطلب نفسه، فيمكن إضافة شريك ثانٍ أو إخراج شريك دون كسر القاعدة.
- تسجيل الشريك يضيف للجهة دور `partner` تلقائياً.

## 3. سندات الشركاء (عبر `POST /vouchers`)

**إيداع شريك** (#13): `type: receipt`, `purpose: partner_deposit`
```json
{ "type": "receipt", "purpose": "partner_deposit", "voucher_date": "2026-11-10", "cash_box_id": 1, "currency": "USD", "amount": 2000,
  "party_id": 7, "account_id": 31, "approve": true }
```
القيد: م صندوق / د `partners_current` أو `partners_capital` (party). الحساب `account_id` إلزامي، ويجب أن يكون أحد هذين الحسابين. والجهة يجب أن تحمل دور شريك.

**مسحوبات شريك** (#11، #12): `type: payment`, `purpose: partner_drawing`. الحقل `classification` إلزامي (D15):
- `partner_personal`: م `partners_current` (party = الشريك) / د صندوق.
- `company_expense`: مثل المصروف الداخلي (#8). `expense_category_id` إلزامي، و`project_id` و`project_cost_category_id` و`asset_id` اختيارية. ملاحظة السطر تبدأ بـ"بطلب المدير"، والشريك جهة السطر.

## 4. تصنيفات المصاريف (D21، القسم 5.5)

| المسار | الصلاحية |
|---|---|
| `GET /expense-categories?active=` | `finance.view` — شجرة، لكل عقدة `children` و`is_leaf` و`account_code` و`account_name` |
| `POST /expense-categories` | `finance.settings` |
| `PATCH /expense-categories/{id}` | `finance.settings` |
| `POST /expense-categories/{id}/deactivate` | `finance.settings` |

```json
{ "name": "صيانة مكتب", "parent_id": 1, "account_id": 40, "is_overhead_allocatable": true }
```
- التصنيف الفرعي الأخير (الورقة) هو ما يُستخدم في السندات، ويحتاج حساب مصروفات فعّالاً يقبل القيود. التصنيف الأب للتجميع فقط.
- لا يُضاف فرع تحت تصنيف عليه حركات. ولا يكون التصنيف أباً لنفسه أو لأحد فروعه.
- لا يُعطَّل أب له فروع نشطة.
- البذرة في `FinanceChartSeeder`.

## 5. سندات المصروف (#8)

**سند مصروف متعدد البنود:** `type: expense`. صندوق واحد وعملة واحدة. **مبلغ كل بند في `debit`.**
```json
{ "type": "expense", "voucher_date": "2026-11-10", "cash_box_id": 1, "currency": "USD", "description": "مصاريف الأسبوع", "approve": true,
  "lines": [
    {"expense_category_id": 4, "debit": 40, "memo": "قهوة"},
    {"expense_category_id": 7, "asset_id": 2, "debit": 60},
    {"project_id": 3, "project_cost_category_id": 1, "debit": 250, "party_id": 9},
    {"expense_category_id": 7, "project_id": 3, "asset_id": 2, "debit": 50}
  ] }
```
لكل بند:
- **بلا مشروع:** م حساب التصنيف (expense_category، asset، party). التصنيف إلزامي.
- **مع مشروع:** م `project_costs` (project، تصنيف التكلفة). إذا حُدد أصل دون تصنيف تكلفة يُستخدم "تشغيل أصول" (`asset_operation`). وإلا فتصنيف التكلفة إلزامي.
- د الصندوق بالمجموع في سطر واحد. و`amount` في السند يساوي مجموع البنود تلقائياً.
- البنود بعملة السند فقط، والمبلغ موجب، ولا `account_id` على البند.

**مصروف داخلي بسند صرف:** `type: payment`, `purpose: internal_expense`. بند واحد بحقول السند نفسها: `amount`، `expense_category_id`، `project_id`، `project_cost_category_id`، `asset_id`، و`party_id` الاختياري. التوجيه كما سبق.

## 6. طلبات مصروف الموقع (R24، R25، القسمان 9.5 و9.6)

| المسار | الصلاحية | الوصف |
|---|---|---|
| `POST /site-requests` | `finance.site_requests` | تقديم طلب (multipart/form-data) |
| `GET /site-requests/mine?status=&project_id=` | `finance.site_requests` | "طلباتي" |
| `GET /site-requests/{id}` | صاحب الطلب أو `finance.vouchers` لفرعه | التفاصيل مع المرفقات |
| `GET /site-requests/{id}/attachments/{a}` | كالسابق | تنزيل صورة الفاتورة |
| `GET /site-requests?status=&project_id=&payment_source=&requested_by=&branch_id=&from=&to=` | `finance.vouchers` | صندوق وارد المالية، والمعلّق أولاً |
| `POST /site-requests/{id}/approve` | `finance.vouchers` | `{date?}` (افتراضياً اليوم) |
| `POST /site-requests/{id}/reject` | `finance.vouchers` | `{reason}` إلزامي |

**التقديم** (حقول multipart): `project_id`، `project_cost_category_id`، `amount`، `currency` أو `currency_id`، `supplier_party_id` (اختياري)، `description`، `payment_source` = `custody | own_money`، و`invoice` (صورة أو PDF، **إلزامية**).
- المشرف (النطاق "الذات") يقدّم على المشاريع التي يشرف عليها فقط، وإلا 403. أما النطاق "الكل" فيقدّم على أي مشروع.
- جهة المشرف هي الجهة المربوطة بملفه الوظيفي (`parties.employee_id`). إذا لم تُربط يُرجع 422: "حسابك غير مرتبط بجهة تعامل؛ اطلب من المالية ربط ملفك الوظيفي بجهة تعامل.".
- مع `custody` يجب أن تكون للمشرف عهدة مفتوحة بالعملة نفسها، وإلا 422.
- يصل إشعار لحاملي `finance.vouchers`.

**الاعتماد** (R25):
- `custody`: يُنشأ سند `custody_settlement` ويُعتمد على عهدة المشرف المفتوحة للمشروع والعملة، أو على عهدته العامة بالعملة إن لم توجد. له سطر واحد: المشروع وتصنيف التكلفة والمورد. ويُطبَّق R16: التسوية لا تتجاوز رصيد العهدة.
- `own_money`: يُنشأ سند `manual_journal` ويُعتمد: م `project_costs` (project، تصنيف التكلفة، party = المورد) / د `other_payables` (party = المشرف). فتصبح الشركة مدينة للمشرف، وتُسدَّد لاحقاً بسند صرف دين (`payment` / `debt` / `we_owe`).
- تُنسخ صورة الفاتورة إلى السند. والطلب يحمل `voucher_id` و`voucher_number`، و`custody_id` في مسار العهدة.
- يصل إشعار للمشرف عند الاعتماد وعند الرفض (مع السبب).
- **المشرف لا يعتمد ولا يرفض** (403)، لأن القرار يحتاج `finance.vouchers`.
- القرار يتم على طلب `submitted` فقط، وإلا 409.

**شكل الطلب في الاستجابة:** حقول الجدول + `attachments[]` + `project_name`، `cost_category_name`، `currency_code`، `holder_name`، `supplier_name`، `voucher_number`.

## 7. تغييرات تكسر الواجهة
- `GET /site-requests` صار صندوق وارد المالية بصلاحية `finance.vouchers` (كان مساراً أولياً بصلاحية `finance.site_requests`). المشرف يستخدم `GET /site-requests/mine`.
- عادت `/parties` بشكل جديد: `roles[]` (أدوار متعددة) بدل الحقل القديم `kind` (customer | supplier)، و`is_active` بدل `active`. الدور `customer` صار `client`. التعطيل صار `POST /parties/{id}/deactivate`.
