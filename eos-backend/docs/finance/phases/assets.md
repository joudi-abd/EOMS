# المرحلة 9: الأصول الثابتة

البادئة `/api/v1/finance`، والمصادقة `auth:sanctum`. الاستجابة `{data}` والقوائم `{data, links, meta}`. المبالغ نصوص (bcmath).

**بلا إهلاك أبداً (D22):** القيمة الدفترية = تكلفة الشراء حتى الاستبعاد. التكلفة والعملة والحالة لا تتغير إلا بسند.

## 1. تصنيفات الأصول (القسم 5.8)

| المسار | الصلاحية | الوصف |
|---|---|---|
| `GET /asset-categories` | `finance.view` | قائمة مسطّحة (الآباء أولاً) مع `parent_id` و`children_count` و`assets_count` |
| `POST /asset-categories` | `finance.settings` | `{name, parent_id?}` |
| `PATCH /asset-categories/{id}` | `finance.settings` | `{name?, parent_id?}` |
| `DELETE /asset-categories/{id}` | `finance.settings` | يُرفض (409) إذا كان له فروع أو أصول |

- الاسم فريد داخل المستوى نفسه (422).
- لا يكون التصنيف أباً لنفسه أو لأحد فروعه (422).

## 2. سجل الأصول وبطاقة الأصل (القسم 9.7)

| المسار | الصلاحية | الوصف |
|---|---|---|
| `GET /assets?status=&holder_employee_id=&category_id=&branch_id=&search=&per_page=` | `finance.view` | السجل ضمن فروع المستخدم. `category_id` يشمل التصنيفات الفرعية. `search` في الكود والاسم والرقم التسلسلي. الأصول الملغاة (`cancelled`) لا تظهر إلا مع `status=cancelled` |
| `GET /assets/{id}` | `finance.view` (المدير العام يعرض) | بطاقة الأصل |
| `PATCH /assets/{id}` | `finance.assets` | الحقول الوصفية فقط: `name, category_id, serial_no, notes`. أي حقل آخر يُتجاهل |
| `DELETE /assets/{id}` | `finance.assets` | حذف أصل لم يُعتمد سند شرائه (مسودة)، مع مسودة سنده. غير ذلك 409 (اعكس سند الشراء) |

**صف السجل:**
```json
{
  "id": 1, "code": "AS-0001", "name": "سيارة كيا", "category_id": 1, "purchase_date": "2026-11-05",
  "quantity": "1.0000", "unit_cost": "15500.0000", "total_cost": "15500.0000", "currency_id": 1,
  "purchase_voucher_id": 12, "current_holder_employee_id": 4, "branch_id": 1, "status": "in_service",
  "serial_no": null, "notes": null,
  "category": {"id": 1, "name": "سيارات"}, "currency": "USD", "branch": {"id": 1, "name": "المكتب الرئيسي"},
  "holder": {"id": 4, "name": "علي حسن", "employee_no": "E-1"},
  "book_value": "15500",
  "acquisition": {"id": 12, "type": "asset_purchase", "number": "AP-2026-000001", "status": "approved", "date": "2026-11-05", "amount": "15500.0000", "currency": "USD"}
}
```
- `book_value` = التكلفة إذا كان الأصل في الخدمة أو قيد الصيانة وسند اقتنائه معتمد، وإلا `"0"`.
- `acquisition`: سند الشراء (`asset_purchase`) أو سند الرصيد الافتتاحي (`opening_balance`). لأصل مسودة يظهر سند الشراء المسودة.

**البطاقة** = صف السجل ومعه:
- `custody_history`: `[{id, transferred_at, from: {id,name,employee_no}|null, to: {...}, notes, recorded_by}]` الأحدث أولاً.
- `maintenance`: `[{id, date, description, project_id, voucher_id, voucher: {...}}]`.
- `disposals`: `[{id, type, date, sale_amount, currency, notes, voucher_id, voucher: {...}}]`.
- `expenses`: المصاريف المرتبطة بالأصل عبر بُعد الأصل (D13):
  ```json
  {
    "lines": [{"id": 9, "date": "2026-11-09", "kind": "maintenance", "currency": "USD", "amount": "300",
               "account": {"code": "5200", "name": "تكاليف المشاريع"}, "project_id": 3, "memo": "...",
               "entry_number": "JE-2026-000007", "voucher_id": 15, "voucher_number": "AM-2026-000002"}],
    "totals": {"operating": {"USD": "75", "TRY": "900"}, "maintenance": {"USD": "500"}}
  }
  ```
  تشمل سطور القيود المرحّلة غير المعكوسة على حسابات المصروفات (ومنها `project_costs` و`asset_maintenance`) التي تحمل `asset_id`. السطر "صيانة" إذا جاء من سند `asset_maintenance`، وإلا "تشغيل" (محروقات ونحوها من سند المصروف #8 مع بُعد الأصل). كل عملة منفصلة.

**الحالات:** `in_service` (في الخدمة)، `under_maintenance` (قيد الصيانة)، `sold`، `scrapped`، `lost`، و`cancelled` (أُلغي بعكس سند شرائه — انظر §4).

## 3. شراء أصل من شاشة الأصول (D22، القيد #22)

`POST /assets/purchase` — `finance.assets`
```json
{
  "name": "لابتوب", "category_id": 2, "quantity": 3, "unit_cost": 750, "currency": "USD",
  "purchase_date": "2026-11-05", "holder_employee_id": 4, "branch_id": 1,
  "serial_no": null, "notes": null,
  "cash_box_id": 3, "approve": true, "description": null, "reference_no": null
}
```
- ينشئ الأصل بالحالة `in_service` وكود `AS-0001` المتسلسل، و`total_cost = quantity × unit_cost` مقرّباً لخانات العملة.
- ينشئ سند `asset_purchase` (رقمه `AP-YYYY-000001`) ويعتمده فوراً. مع `"approve": false` يبقى السند مسودة، والأصل لا يقبل عمليات حتى يُعتمد السند من شاشة السندات (`POST /vouchers/{id}/approve`).
- القيد: م `fixed_assets` (asset) / د الصندوق. فحص عملة الصندوق ورصيده (R7/R8) كما في كل السندات.
- الحائز موظف موجود غير منتهية خدمته (422).
- العملة: `currency` (رمز) أو `currency_id`.
- الصندوق وفرع الأصل ضمن فروع المستخدم في `finance.assets` (403).
- الرد 201: `{asset: <البطاقة>, voucher: <السند مع سطوره وقيده و warnings>}`.

## 4. عكس سند الشراء

عبر `POST /vouchers/{id}/reverse` المعتاد (`finance.reverse`).
- يُرفض (409) إذا كان للأصل نقل عهدة، أو صيانة، أو استبعاد، أو مصاريف مرحّلة أخرى تحمل الأصل. اعكس تلك أولاً (نقل العهدة لا يُعكس، فالأصل المنقول لا يُلغى شراؤه).
- غير ذلك: يُعكس القيد وتصبح حالة الأصل `cancelled`. لا يُحذف صف الأصل لأن سطور القيدين تشير إليه. الأصل الملغى لا يقبل أي عملية.

## 5. أصل قائم كرصيد افتتاحي (القسم 12، الخطوة 7)

`POST /assets/opening` — `finance.assets`
```json
{
  "name": "سيارة", "category_id": 1, "quantity": 1, "unit_cost": 15500, "currency": "USD",
  "purchase_date": "2024-03-01", "holder_employee_id": 4, "branch_id": 1, "status": "in_service",
  "serial_no": null, "notes": "نقدي", "date": "2026-10-01"
}
```
- `date` = تاريخ الأرصدة الافتتاحية (تاريخ السند). `purchase_date` اختياري (الافتراضي `date`). `status`: `in_service` أو `under_maintenance`.
- سند `opening_balance` معتمد: م `fixed_assets` (asset) / د `opening_balance_equity` (القيد #29). بلا صندوق.

### استيراد ملف الأصول CSV (OP-15)

`POST /assets/import` (multipart) — `finance.assets`: `file` (CSV حتى 2MB)، `date`، `branch_id`.

صف العناوين (إنكليزي أو عربي):

| الحقل | عناوين مقبولة | ملاحظات |
|---|---|---|
| الاسم | `name`، `الاسم`، `اسم الأصل` | مطلوب |
| التصنيف | `category`، `التصنيف` | اسم تصنيف موجود |
| الكمية | `quantity`، `الكمية`، `العدد` | الافتراضي 1 |
| تكلفة الوحدة | `unit_cost`، `سعر الوحدة`، `التكلفة`، `السعر` | مطلوب، موجب |
| العملة | `currency`، `العملة` | رمز عملة مفعّلة، مطلوب |
| تاريخ الشراء | `purchase_date`، `تاريخ الشراء` | `YYYY-MM-DD` |
| الحائز | `holder`، `المسؤول`، `الحائز` | رقم الموظف أو اسمه الكامل (يجب أن يطابق موظفاً واحداً) |
| طريقة الدفع / ملاحظات | `payment_method`، `notes`، `طريقة الدفع`، `ملاحظات` | تُحفظ في `notes` |
| الحالة | `status`، `الحالة` | `in_service`/`في الخدمة` أو `under_maintenance`/`قيد الصيانة`. المستبعد لا يُستورد |
| الرقم التسلسلي | `serial_no`، `الرقم التسلسلي` | |

- تُفحص كل الأسطر أولاً. أي خطأ يرفض الملف كله (422) والأخطاء بالمفتاح `rows.{رقم السطر في الملف}` (العناوين = 1).
- إذا سلمت كل الأسطر: تُنشأ كل الأصول وسند `opening_balance` واحد بسطر لكل أصل، ومقابل `opening_balance_equity` لكل عملة، في معاملة واحدة.
- الرد 201: `{count, assets: [...], voucher}`. الحد 1000 أصل.

## 6. نقل العهدة

`POST /assets/{id}/transfer` — `finance.assets`
```json
{ "to_employee_id": 7, "date": "2026-11-10", "notes": "للموقع" }
```
- يسجل سطراً في سجل العهدة (من الحائز الحالي إلى الجديد) ويحدّث الحائز. بلا قيد. مُدقَّق.
- يُرفض (422) للموظف نفسه، أو لموظف غير موجود أو منتهية خدمته.
- الرد: البطاقة.

## 7. الصيانة (القيد #23)

`POST /assets/{id}/maintenance` — `finance.assets`
```json
{ "cash_box_id": 3, "currency": "USD", "amount": 200, "date": "2026-11-08", "description": "تبديل زيت", "project_id": null, "approve": true }
```
- سند `asset_maintenance` (رقمه `AM-YYYY-000001`):
  - بلا مشروع: م `asset_maintenance` (asset) / د الصندوق.
  - مع مشروع: م `project_costs` (project، تصنيف التكلفة `asset_operation` "تشغيل أصول"، asset) / د الصندوق.
- عند الاعتماد يُسجَّل في `maintenance` على البطاقة. عكس السند يحذف السجل.
- الرد 201: `{asset, voucher}`.

**تبديل الحالة:** `POST /assets/{id}/status` `{status: "under_maintenance" | "in_service"}` — بلا قيد، مُدقَّق.

## 8. البيع والإتلاف والفقدان (القيدان #24 و#25)

`POST /assets/{id}/dispose` — `finance.assets`
```json
{ "type": "sale", "date": "2026-11-12", "sale_amount": 1200, "cash_box_id": 3, "notes": null, "approve": true }
```
- `type`: `sale | scrap | loss`. `sale_amount` و`cash_box_id` مطلوبان للبيع وممنوعان لغيره.
- البيع بعملة الأصل نفسها. إرسال `currency` مختلفة يعطي 422.
- سند `asset_disposal` (رقمه `AD-YYYY-000001`، ونوعه في `meta.disposal_type`):
  - بيع: م الصندوق (السعر) / د `fixed_assets` (التكلفة) / والفرق على `asset_disposal_gain_loss`: دائن عند الربح، مدين عند الخسارة.
  - إتلاف أو فقدان: م `asset_disposal_gain_loss` / د `fixed_assets` (التكلفة).
- الحالة تصبح `sold | scrapped | lost` ويُسجَّل الاستبعاد. الأصل المستبعد لا يقبل أي عملية بعدها (422).
- عكس سند الاستبعاد يحذف سجل الاستبعاد ويعيد الأصل إلى `in_service`.

## 9. تقرير سجل الأصول (T20)

`GET /reports/asset-register?status=&holder_employee_id=&category_id=&branch_id=&search=&group_by=status|holder|category|branch` — `finance.reports` (ضمن فروع المستخدم في هذه الصلاحية).

```json
{
  "group_by": "category",
  "groups": [{"key": 1, "label": "سيارات", "count": 1, "cost": {"USD": "1000"}, "book_value": {"USD": "1000"},
              "operating_expenses": {"TRY": "900"}, "maintenance_expenses": {"USD": "120"}}],
  "assets": [{ "...صف السجل...": "", "operating_expenses": {"TRY": "900"}, "maintenance_expenses": {"USD": "120"} }]
}
```
- غير مرقّم. كل عملة منفصلة (D8). المفتاح `0` للأصول بلا حائز أو بلا تصنيف.

## 10. السندات من شاشة السندات

أنواع `asset_purchase` و`asset_maintenance` و`asset_disposal` تُنشأ عادةً من شاشة الأصول. إن أُنشئت عبر `POST /vouchers`:
- `asset_purchase` يقبل فقط أصلاً بلا سند اقتناء معتمد، ومبلغه وعملته يطابقان تكلفة الأصل.
- `asset_maintenance` يحتاج `asset_id` و`description`.
- `asset_disposal` يحتاج `asset_id` و`meta.disposal_type`.

## 11. ملاحظات للواجهة
- المدير العام (`company_manager`) لا يملك `finance.assets` في الأدوار الافتراضية، فيرى الأصول من تقرير T20 فقط (`finance.reports`).
- عكس سند الرصيد الافتتاحي لأصل لا يغيّر حالة الأصل، لكنه يمنع عملياته ويجعل `book_value` صفراً (السند لم يعد معتمداً).
