# سجل تغييرات النظام المالي

كل مرحلة من خطة إعادة البناء (`FINANCE_SYSTEM_SPEC` القسم 13) تضيف قسماً هنا: ما تغيّر، والـmigrations، والصلاحيات، والتغييرات التي تكسر الواجهة.

## المرحلة 1–2: النواة والخزينة

### ما تغيّر
- **دفتر متعدد العملات:** العملة صارت على سطر القيد لا على القيد. كل قيد متوازن لكل عملة على حدة، ولا موازنة تلقائية (أُلغي حساب "فروقات القيود"). كل سطر يحمل سعر النشرة ومعادله بالدولار.
- **السندات بدل العمليات:** جدول واحد `fin_vouchers` لكل الأنواع، بدورة مسودة ← اعتماد (ترحيل) ← عكس. الاعتماد للمالية دون اشتراط شخص ثانٍ (D25، OP-14). أُلغي سقف الاعتماد والإقفال على خطوتين.
- **الصناديق متعددة العملات** مع إعداد السالب لكل عملة (D5، D6)، وفحص أدنى رصيد جارٍ من تاريخ السند فما بعد (OP-13).
- **التصريف** قيد واحد بأربعة سطور عبر `fx_position`، مع تحذير عند انحراف السعر عن النشرة (R10).
- **الجرد** بلقطة رصيد عند الإرسال واعتماد من شخص آخر يملك `finance.counts.approve` (R22، R23).
- **الحسابات الإلزامية** يختارها المحاسب من دليله (`fin_system_accounts`)، ودليل بداية `FinanceChartSeeder` يربط كل الأدوار ويزرع شجرة تصنيفات المصاريف وتصنيفات تكلفة المشاريع وتصنيفات الأصول.
- **الفترات** برقم سنة وشهر، مع فحوصات الإقفال (القسم 9.8) وإعادة الفتح بسبب.
- **الترقيم** بلا فجوات لكل نوع وسنة، ويُمنح عند الاعتماد.
- **الفروع** (`sys_branches`) وإسناد المستخدم لفروع (`fin_user_branches`): نطاق "الذات" في صلاحيات المالية = فروعه.
- **وحدة المشاريع العامة** (`prj_projects`، `prj_project_members`) و`/api/v1/projects`.
- **النشرة اليومية** مقابل الدولار بدل أزواج العملات.
- **الرواتب:** الاعتماد يرحّل الاستحقاق (`payroll_accrual`)، والدفع يرحّل `payroll_payment` بفرق التقريب وبالتصريف عند اختلاف العملة. عملات الدفع وتقريبها من إعدادات العملات (`is_payroll_currency`، `rounding_step`).
- **حُذف:** المخزون والمواد، فواتير البيع والشراء ومرفقاتها، سقوف الاعتماد، العمليات القديمة (`fin_transactions`)، الصناديق أحادية العملة، تقارير المالية القديمة (تعود في المرحلة 10).
- **أُنشئت جداول كل المراحل** مع نماذجها (الجهات، المصاريف وطلبات الموقع، مالية المشاريع، السلف والعُهد، الأصول، التوزيع)؛ خدماتها ومساراتها في مراحلها.

### Migrations
| الملف | الوحدة | المحتوى |
|---|---|---|
| `2026_11_21_100001_add_finance_columns_to_sys_currencies` | Settings | `decimals, rounding_step, is_base, is_payroll_currency, is_active`؛ USD أساسية، SYP بلا كسور وتقريب 1000 |
| `2026_11_21_100002_rebuild_sys_exchange_rates_as_daily_bulletin` | Settings | `currency_id, rate_date, rate_to_base, source, created_by`؛ تحويل الأزواج القديمة التي أحد طرفيها USD وحذف الباقي |
| `2026_11_21_100003_create_sys_branches_table` | Settings | الفروع |
| `2026_11_21_200001_create_projects_tables` | Projects | المشاريع وأعضاؤها |
| `2026_11_22_000001_rebuild_finance_schema` | Finance | حذف جداول `fin_*` القديمة وروابط الرواتب بها، وإنشاء المخطط الجديد كاملاً (35 جدولاً). الـrollback يعيد المخطط القديم فارغاً |
| `2026_11_22_000100_link_payroll_to_finance_vouchers` | Payroll | `pay_payments.finance_voucher_id`، `pay_runs.accrual_voucher_id`، و`pay_exports.box_id` يشير إلى `fin_cash_boxes` |

البيانات المالية القديمة تُحذف (تجريبية حسب OP-7). بعد الترحيل: `php artisan db:seed --class="App\Modules\Settings\Database\Seeders\CurrencySeeder"` و`BranchSeeder` و`SettingsSeeder`، ثم `php artisan permissions:sync`، واختيارياً `FinanceChartSeeder` لدليل البداية.

### الصلاحيات
- **حُذفت:** `finance.manage`، `finance.approve`.
- **أُضيفت:** `finance.settings`، `finance.rates`، `finance.vouchers`، `finance.reverse`، `finance.projects`، `finance.projects.view`، `finance.site_requests`، `finance.allocations`، `finance.counts`، `finance.counts.approve`، `finance.assets`، `finance.periods`، `finance.reports`، `finance.audit` (و`finance.view` صار بنطاقي الكل والذات).
- **الأدوار:** `finance_manager` صار "المحاسب العام" بكل المالية عدا اعتماد الجرد. `company_manager`: عرض وتقارير ومشاريع للعرض واعتماد الجرد والتدقيق، وفقد `payroll.approve` (OP-1) و`finance.approve`. جديد: `branch_accountant` (محاسب الفرع) و`site_supervisor` (المشرف). `project_manager` أخذ `finance.projects.view` بنطاق مشاريعه.
- إعدادات العملات والفروع صارت `finance.settings`، والنشرة `finance.rates` (كانت `finance.manage`).
- شغّل `php artisan permissions:sync` بعد التحديث.

### تغييرات تكسر الواجهة
- **أُزيلت** كل مسارات المالية القديمة: `/boxes`، `/transactions/*`، `/invoices*`، `/items*`، `/approval-limits`، `/parties` (تعود في المرحلة 3)، `/reports/*` (تعود في المرحلة 10)، `/entries` POST و`/entries/{id}/reverse`، `/periods/close-request` و`/periods/{month}/decision`.
- **بدائلها:** `/cash-boxes`، `/vouchers` (كل الأنواع، مع `approve: true`)، `/cash-counts`، `/system-accounts`، `/periods/{YYYY-MM}/checks|close|reopen`، `/entries` للقراءة، `/reports/box-statement`، `/users/{id}/branches`، `/my-branches`، `/audit-log`.
- **الدليل:** `GET /accounts` يعيد شجرة. الحقول `active`→`is_active`، وحُذف `system_key` (الأدوار في `roles`)، وأُضيف `is_postable, requires_party, requires_project, is_system`. التعطيل `POST /accounts/{id}/deactivate`.
- **المبالغ** نصوص بأربع منازل (كانت منزلتين)، والقيد بلا `currency_code` (العملة على كل سطر `currency_id`).
- **أسعار الصرف:** حُذف `GET /settings/exchange-rates/latest` وصيغة `{from, to, rate, rate_date}`. الجديد: `GET /settings/exchange-rates?date=` (النشرة)، `GET /settings/exchange-rates/history?currency=`، `POST /settings/exchange-rates` `{date, rates:{CODE: rate}}`، `POST /settings/exchange-rates/copy-previous`.
- **العملات:** حقول جديدة في `GET /settings/currencies`، وPOST/PATCH للإعداد.
- **الرواتب:** `finance_transaction_id` في الدفعة صار `finance_voucher_id`؛ `box_id` يشير إلى صندوق جديد (`/finance/cash-boxes`) يجب أن تكون عملة الدفع مفعّلة عليه؛ عملات الدفع من `GET /settings/currencies` (`is_payroll_currency`). مدير الشركة لم يعد يعتمد كشف الرواتب.
- **المشاريع:** مسارات جديدة `/api/v1/projects`.

## المراحل 3–11: الجهات، المصاريف، المشاريع، السلف والعُهد، الرواتب، التوزيع، الأصول، التقارير، الإعداد

التفاصيل الكاملة (المسارات، الحمولات، القيود، القواعد، الافتراضات، وتغييرات الواجهة) في ملف كل مجال تحت `docs/finance/phases/`.

| المرحلة | ما أُضيف | الملف |
|---|---|---|
| 3 | جهات التعامل بأدوار متعددة وربطها بالموظف واستيراد CSV؛ الشركاء وحصصهم؛ إيداع الشريك (#13) ومسحوباته بتصنيف يدوي (#11، #12، D15)؛ كشف حساب الجهة لكل عملة | `parties-expenses.md` |
| 4 | شجرة تصنيفات المصاريف؛ سند المصروف متعدد البنود (#8)؛ طلبات المشرفين: إرسال بصورة إلزامية، رفض بإشعار، اعتماد إلى تسوية عهدة أو دين للمشرف (R24، R25) | `parties-expenses.md` |
| 5 | الملف المالي للمشروع وأنواع العقود الأربعة؛ الموازنة وتنبيهاتها (R13، OP-6)؛ اتفاقيات المقاولين ونمطا التتبع (R11، R12)؛ دفعات العملاء والمقاولين وسلفهم (#1، #5–#7)؛ المطالبة واقتراحها (#2، §6.1، R14)؛ الربحية (§6.2)؛ كشف المشروع وملخص المشاريع | `projects.md` |
| 6 | سلف الموظفين بأقساط (#14، R18)؛ العُهد: صرف وتسوية ورد (#19–#21، R15–R16)؛ إشعار العُهد المتأخرة يومياً (R17) | `advances-custody.md` |
| 7 | بنود الراتب الديناميكية (D19) والبنود المتكررة لكل موظف؛ خصم أقساط السلف وتأجيلها؛ البند المرتبط بمشروع (R21)؛ قيد الاستحقاق الكامل (#15)؛ قيد الدفع بعملة أخرى مع فرق التقريب (#16) | `payroll-allocation.md` |
| 8 | نسب وقت الموظفين ونسخ الشهر السابق؛ توزيع الرواتب (#17) والمصاريف العامة (#18) بالطرق الثلاث مع المعاينة ومنع التكرار | `payroll-allocation.md` |
| 9 | سجل الأصول؛ الشراء من الصندوق (#22)؛ نقل العهدة؛ الصيانة (#23)؛ البيع والإتلاف والفقدان (#24، #25)؛ الأصول الافتتاحية واستيراد CSV | `assets.md` |
| 10 | التقارير T1–T23 مع فلتر الفرع والعرض المنفصل أو الموحّد بالدولار ورأس "الأسعار المستخدمة" وتصدير CSV؛ لوحة المدير العام | `reports-setup.md` |
| 11 | معالج الإعداد (الحالة، الدليل المبدئي، الأرصدة الافتتاحية الشاملة، فحص الميزان الافتتاحي)؛ شاشة الإقفال بفحوصاتها | `reports-setup.md` |

### Migrations جديدة
- `2026_11_23_advances_add_overdue_notified_on_to_fin_custodies` (عمود لإشعار العهدة المتأخرة مرة يومياً).
- `2026_11_24_000001_create_salary_components` (وحدة الرواتب: `pay_salary_components` مع البذرة، `pay_employee_components`، وعمودا `component_id` و`project_id` على `pay_run_items`).
- `2026_11_24_100001_setup_add_agreement_and_custody_to_fin_voucher_lines`.

### أوامر مجدولة
- `finance:custody-overdue` يومياً الساعة 08:00 (`routes/console.php`).

### تغييرات تكسر الواجهة (ملخص)
- `/parties` عادت بشكل جديد: `roles[]` بدل `kind` (و`customer` صار `client`)، و`is_active` بدل `active`.
- `GET /site-requests` صار صندوق وارد المالية (`finance.vouchers`)، والمشرف يستخدم `GET /site-requests/mine`.
- تصدير كشف الرواتب CSV بعمود لكل بند مستخدم بدل الأعمدة الثابتة. وفي `POST runs/{id}/items` صار `category` و`amount` اختياريين عند اختيار بند.
- قيد استحقاق الرواتب صار سطوراً مفصّلة لكل موظف.
- مسارات جديدة: المشاريع المالية، الاتفاقيات، الموازنات، المطالبات، السلف، العُهد، الأصول، بنود الراتب، نسب الوقت، التوزيعات، التقارير T1–T23، `dashboard`، `setup/*`، `periods/closing`.

### بعد التحديث
```bash
php artisan migrate
php artisan db:seed --class='App\Modules\Settings\Database\Seeders\CurrencySeeder'
php artisan db:seed --class='App\Modules\Settings\Database\Seeders\BranchSeeder'
php artisan db:seed --class='App\Modules\Settings\Database\Seeders\SettingsSeeder'
php artisan permissions:sync
```
ثم من معالج الإعداد: الدليل المبدئي (`POST /finance/setup/starter-chart`) أو بناء الدليل يدوياً وربط الحسابات الإلزامية، ثم النشرة الأولى، فالصناديق، فالأرصدة الافتتاحية.
