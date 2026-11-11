# نظام الإجازات — مرجع الـAPI للفرونت

كل المسارات تحت `/api/v1` وتتطلب `auth:sanctum`. الوحدة الرقمية للأيام: `1,000,000` = يوم كامل.

## الإعدادات (`leaves.manage`)

صلاحية خاصة بالإجازات، يملكها `hr_manager`. لم تعد `employees.manage` كافية لإعدادات الإجازات.

| المسار | الوصف |
|---|---|
| GET `/leave-settings` | الأنواع، السياسات، العطل |
| POST `/leave-settings/types` | نوع جديد |
| POST `/leave-settings/types/{id}/policies` | نشر نسخة سياسة بتاريخ سريان |
| PATCH `/leave-settings/types/{id}/activation` | تفعيل/إيقاف نوع |
| POST/DELETE `/leave-settings/holidays` | إضافة عطلة (تعيد رصيد الإجازات المتأثرة وتحدّث الحضور) / حذفها (ممنوع إن تقاطعت مع يوم إجازة فعّال). الحذف يعيد أيام الإجازة التي ألغتها العطلة ويعيد خصمها من الرصيد، ما لم يكن الطلب قد أُلغي أو قُصِّر قبل هذا اليوم بقرار من الموظف أو الموارد البشرية، أو كان اليوم خارج فترة الخدمة، أو لا يكفي الرصيد |
| GET/POST `/leave-settings/balance-entries` | سجل الرصيد والأرصدة الافتتاحية والتسويات |
| GET `/leave-settings/pending-openings` | الموظفون العائدون للخدمة الذين ينتظرون رصيداً افتتاحياً، مع الأنواع المتوقفة. يصل أيضاً إشعار `leave.opening_balance_needed` عند إعادة التعيين |
| PUT `/leaves/half-days` | فترتا نصف اليوم |

التقويم وتصدير الطلبات ليسا ضمن `leave-settings`؛ المساران المعتمدان هما `GET /leaves/calendar` و`GET /leaves/export` أدناه.

## الطلب ودورة حياته

| المسار | الوصف |
|---|---|
| GET `/leaves/options` | الأنواع المتاحة للموظف ورصيده. لكل نوع: `available_units`، و`balance` (`granted_units`, `used_units`, `reserved_units`, `available_units`)، و`awaiting_opening`. للـHR: `?employee_id=` لعرض رصيد موظف آخر |
| POST `/leaves/preview` | معاينة الأيام والخصم والجزء غير المدفوع وإرجاع `quote_token` |
| POST `/leaves` | تقديم الطلب (`quote_token`, `submission_key`, و`unpaid_consent` عند الحاجة) |
| PUT `/leaves/{id}` | تعديل طلب معلّق (بعد `POST /leaves/{id}/preview`) |
| POST `/leaves/{id}/{action}` | `approve`, `reject`, `withdraw`, `cancel`, `shorten` (مع `from_date`), `approve-change`, `reject-change`؛ كلها تتطلب `version` و`reason` |

**مدير الشركة:** طلبه يُسجَّل **معتمداً مباشرة** عند التقديم (`status=approved`, `reviewed_by` = نفسه)، ولا يُرسل لأحد للمراجعة. وكذلك إلغاؤه أو تقصيره ينفّذ فوراً دون طلب تغيير معلّق.

**تقديم الـHR نيابة عن موظف (`leaves.manage`):** أرسل `employee_id` في المعاينة والتقديم. الطلب يُسجَّل باسم الموظف (`submitted_by` = حسابه) ويظهر في "طلباتي" عنده ويصله إشعار `leave.entered_for_you`، ويُحفظ من أدخله في `entered_by`. يستطيع الـHR تعديله وسحبه وطلب إلغائه أو تقصيره نيابة عن الموظف (`can_act_for_owner` في الرد). أضف `approve_now: true` لاعتماده مباشرة إن كنت مخولاً بمراجعته؛ وإلا يُرفض بـ403 ويبقى الطلب معلّقاً. التوجيه لا يتغير: إجازة موظف HR تذهب لمدير الشركة، وإجازة مدير الشركة معتمدة مباشرة أياً كان من أدخلها.

**سجل الرصيد:** `grant` منح، `opening` افتتاحي، `adjustment` تسوية، `reserve` حجز عند التقديم، `release` تحرير حجز، `debit` خصم عند الاعتماد (يُسجَّل مع تحرير الحجز فلا يتغير المتاح)، `refund` استرداد عند إلغاء أو تقصير إجازة معتمدة أو إنهاء الخدمة، `holiday_refund` استرداد بسبب عطلة. انتهاء الرصيد في نهاية السنة ضمني: كل رصيد مربوط بسنته (`cycle_start`)، ولا يُستخدم رصيد سنة منتهية.

**التوجيه:** طلبات الموظفين يعتمدها `hr_manager`، وطلبات موظفي الـHR وتعديلاتها يعتمدها `company_manager`. لا أحد يراجع طلبه بنفسه.

## القوائم والفلاتر

GET `/leaves?scope=...`

| scope | من يستخدمه | ماذا يعرض |
|---|---|---|
| `mine` (افتراضي) | الجميع | طلباتي |
| `review` | `hr_manager`, `company_manager` | طلبات دوري كمراجع (معلّقة وسابقة) |
| `all` | `leaves.view` (الشركة كلها) أو `leaves.view_department` (أقسامي) | كل ما يُسمح لي برؤيته |

الفلاتر لكل النطاقات: `status`, `employee_id`, `leave_type_id`, `department_id`, `from`, `to` (أي يوم من الطلب داخل الفترة)، `change_pending=1|0`. الرد يحوي `meta.total`.

GET `/leaves/{id}`: صاحب الطلب، أو مراجعه، أو من يملك نطاقاً يشمل الموظف.

**السبب:** يراه صاحب الطلب ومراجعه ومن يملك `leaves.view`. مدير القسم يرى التواريخ والنوع فقط (يُحذف `input.reason` و`review_note` وسبب طلب التغيير).

## من في إجازة (التقويم)

GET `/leaves/calendar?from=2026-11-01&to=2026-11-30` (62 يوماً كحد أقصى)، لمن يملك `leaves.view` أو `leaves.view_department`. اختياري: `department_id`، `include_pending=1`.

يعيد `days[]` (التاريخ، الموظف، القسم، رقم الطلب وحالته، النوع، `start_time`/`end_time`، `full_day`) و`holidays[]`.

## التصدير

GET `/leaves/export` بنفس `scope` والفلاتر، ملف CSV بترميز UTF-8 يفتح مباشرة في Excel. عمود السبب يظهر فارغاً لمن لا يحق له رؤيته. أي نص يبدأ بـ `= + - @` يُسبق بـ `'` حتى لا يُنفَّذ كصيغة.

## مدير القسم

1. أعطِ المستخدم الدور `department_manager` (`attendance.view_department`, `leaves.view_department`).
2. اربطه بالقسم: `PUT /departments/{id}` مع `manager_employee_id` = رقم سجل الموظف الخاص به.
3. يرى موظفي هذا القسم وكل أقسامه الفرعية: الحضور (القائمة، اليوم، التقرير، التصدير)، والإجازات (`scope=all`، التفاصيل، التقويم، التصدير). لا يعتمد شيئاً ولا يرى مدخلات الرواتب. بياناته هو تبقى عبر الخدمة الذاتية كأي موظف.

## الإشعارات (داخل التطبيق)

| المسار | الوصف |
|---|---|
| GET `/notifications?unread=1&per_page=20` | إشعاراتي مع `unread_count` |
| POST `/notifications/{id}/read` | تعليم إشعار كمقروء |
| POST `/notifications/read-all` | تعليم الكل |

كل إشعار: `kind`، `message` بالعربية، و`data` فيه المعرّفات للربط (`leave_request_id`, `attendance_day_id`, `employee_id`, `date`...).

| kind | يصل إلى |
|---|---|
| `leave.submitted`, `leave.amended`, `leave.change_requested` | مراجعي الطلب |
| `leave.approve`, `leave.reject`, `leave.approve_change`, `leave.reject_change` | صاحب الطلب |
| `leave.expired`, `leave.holiday_refunded`, `leave.entered_for_you` | صاحب الطلب |
| `leave.opening_balance_needed` | من يملك `leaves.manage` |
| `attendance.correction_requested` | من يملك `attendance.approve` (عدا مقدّم الطلب) |
| `attendance.correction_approved`, `attendance.correction_rejected` | مقدّم التصحيح |
| `attendance.punch_on_leave_day` | من يملك `attendance.approve`، مرة واحدة عند ظهور التعارض |

تُرسل الإشعارات بعد نجاح العملية فقط؛ إذا فشلت العملية لا يصل شيء.

## الربط مع الحضور والرواتب

انظر `ATTENDANCE_API_AR.md`: قسم "الربط مع الإجازات والعطل الرسمية" و"مدخلات الرواتب الشهرية".

## التثبيت بعد السحب

```bash
php artisan migrate
php artisan db:seed --class="App\Modules\Identity\Database\Seeders\RolePermissionSeeder"
```

إعادة تشغيل الـseeder تضيف الصلاحيات والدور الجديد دون حذف المستخدمين أو أدوارهم.
