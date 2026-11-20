# دليل ربط الواجهة (Angular) بالخادم (Laravel) — EOMS

> **لمن هذا الملف:** لوكيل برمجي (agent) مهمته ربط مشروع الواجهة `EOMS Front_End/` بالخادم `eos-backend/` بعد جولة الإصلاحات الأخيرة.
> **القاعدة الذهبية:** الخادم هو مصدر الحقيقة. لا تعدّل `eos-backend/` إطلاقاً؛ عدّل الواجهة لتطابقه، وسجّل أي فجوة تجدها في تقريرك بدل "ترقيعها" في الواجهة.

---

## 0. خطوات العمل المطلوبة من الوكيل (بالترتيب)

1. **اقرأ هذا الملف كاملاً** ثم افتح مواصفة OpenAPI الحية: `GET {SERVER}/docs/api.json` (Scramble) — هي المرجع الآلي لكل حقل. عند أي تعارض بين هذا الملف والمواصفة الحية، **المواصفة الحية + الكود** هما الحكم.
2. **راجع طبقة الـAPI الموجودة** في الواجهة: `src/app/core/api/` (`config.ts`, `api.ts`, `auth.ts`, `interceptor.ts`, `guards.ts`, `types.ts`) و`src/app/core/access/` و`src/app/data/*.ts`. ابنِ عليها، لا تنشئ طبقة موازية.
3. **نفّذ القسم 2 (الأساسيات المشتركة)** أولاً: عنوان الخادم، التوكن، معالجة الأخطاء، الصلاحيات، التحميلات.
4. **اربط الوحدات واحدة واحدة** حسب الأقسام 4–10، وفي كل وحدة: الأنواع (types) ← خدمة البيانات (`data/*.ts`) ← الشاشة ← حالات الخطأ.
5. **طبّق جدول "التغييرات التي تكسر السلوك القديم"** (القسم 3) — هذه أماكن ستفشل فيها الواجهة الحالية حتماً.
6. **تحقق**: `ng build` بلا أخطاء، الاختبارات الموجودة (`*.spec.ts`) تمر، وجرّب كل شاشة يدوياً على خادم محلي (القسم 11).
7. **سلّم تقريراً** فيه: ما رُبط، ما بقي، وأي اختلاف بين الواجهة والخادم لم تستطع حله.

---

## 1. البيئة

| البند | القيمة |
| --- | --- |
| بادئة الـAPI | `{SERVER}/api/v1` |
| عنوان الواجهة محلياً | `http://localhost:4200` (استخدم `localhost` لا `127.0.0.1`، فالتوكن محفوظ لكل origin) |
| عنوان الخادم | يُضبط في `src/app/core/api/config.ts` عبر `apiBase`، ويمكن تجاوزه قبل الإقلاع بـ `window.EOMS_API`. **لا تكتب IP ثابتاً في الكود**؛ اجعل القيمة الافتراضية `http://127.0.0.1:8088/api/v1` للتطوير. |
| توثيق آلي | `{SERVER}/docs/api` (واجهة) و`{SERVER}/docs/api.json` (JSON) |
| CORS | الخادم يسمح بالأصول في `CORS_ALLOWED_ORIGINS` (افتراضياً `*`)، بلا cookies (`supports_credentials=false`)، ويكشف الترويستين `Content-Disposition` و`X-Doc-Id` للمتصفح. |
| المصادقة | Bearer token (Sanctum)، **لا** cookies ولا CSRF. |

---

## 2. الأساسيات المشتركة (نفّذها قبل أي شاشة)

### 2.1 شكل الاستجابات

```jsonc
// نجاح عنصر واحد أو قائمة غير مرقمة
{ "data": { ... } }            // أو { "data": [ ... ] }

// نجاح مرقّم (موحّد الآن في كل مكان، بما فيه GET /leaves)
{
  "data": [ ... ],
  "meta":  { "current_page": 1, "last_page": 4, "per_page": 20, "total": 73 },
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." }
}

// خطأ
{ "message": "نص عربي جاهز للعرض", "errors": { "field": ["..."] } }   // errors اختيارية (موجودة في 422)
```

- التواريخ: `YYYY-MM-DD` للأيام، وISO 8601 مع المنطقة للأوقات (`2026-11-14T09:00:00+03:00`). لا تحوّل تاريخ يوم (date) عبر `new Date()` ثم `toISOString()` — سيتراجع يوماً بسبب UTC. تعامل معه كنص.
- المبالغ المالية تأتي **نصوصاً عشرية** (`"2600000.00"`). لا تجمعها كـ float للعرض المحاسبي؛ اعرضها كما هي أو استخدم مكتبة عشرية.
- وحدات الإجازة أعداد صحيحة: `1_000_000` = يوم كامل.

### 2.2 رموز الحالة وكيف تتعامل الواجهة معها

| الرمز | المعنى | سلوك الواجهة المطلوب |
| --- | --- | --- |
| 200/201 | نجاح | — |
| 401 | غير مسجل / توكن منتهٍ / **الحساب معطّل** / **تغيّرت كلمة المرور** | امسح التوكن واذهب لصفحة الدخول (الـinterceptor الحالي يفعل ذلك — أبقه). |
| 403 | لا صلاحية **أو** قاعدة "العينين الأربع" (لا يعتمد أحد ما أنشأه/أرسله) | اعرض `message` كما هو؛ لا تعيد المحاولة. |
| 404 | المورد غير موجود (أو المعرّف غير رقمي) | رسالة "غير موجود" وارجع للقائمة. |
| 409 | تعارض: نسخة قديمة (`version`)، حالة تغيّرت، عملية متزامنة، سجل مكرر، سجل مرتبط | اعرض `message` واطلب **تحديث البيانات** ثم إعادة المحاولة. لا تكرر الطلب تلقائياً. |
| 422 | خطأ تحقق | اربط `errors[field]` بحقول النموذج؛ إن لم يوجد `errors` اعرض `message`. |
| 429 | تجاوز معدل (الدخول: 10/دقيقة) | رسالة انتظار. |
| 5xx | خطأ خادم | رسالة عامة + زر إعادة المحاولة. |

> مهم: الخادم الآن **يفحص الصلاحية قبل التحقق من المدخلات** في المسارات المحمية، فمستخدم بلا صلاحية يحصل على 403 حتى لو كانت البيانات ناقصة. لا تفترض أن 422 يأتي أولاً.

### 2.3 المصادقة والجلسة

- `POST /auth/login` `{email, password}` → `{data: {token, user}}`. خزّن `token` (الحالي: `localStorage['eoms-token']`).
- `GET /auth/me` → `{data: {user}}` عند إقلاع التطبيق لاستعادة الجلسة.
- `POST /auth/logout` → يحذف التوكن الحالي فقط.
- **عمر التوكن 7 أيام** من إصداره (قابل للضبط في الخادم بـ `SANCTUM_EXPIRATION`). بعدها 401 → صفحة الدخول.
- تعطيل الحساب أو تغيير كلمة مروره يُبطل التوكنات فوراً (401 في الطلب التالي). إن غيّر المستخدم كلمة مروره بنفسه تبقى جلسته الحالية صالحة.

**كائن المستخدم** (`user`):
```jsonc
{
  "id": 7, "name": "...", "email": "...", "phone": "...", "status": "active|disabled",
  "roles": ["hr_manager"], "employee_id": 12, "last_login_at": "...", "created_at": "...",
  // في login و me فقط:
  "employee": { /* EmployeeResource أو null */ },
  "permissions": { "leaves.view": "department", "tasks.view": "own", "employees.manage": "all" }
}
```

### 2.4 الصلاحيات — **استخدم `user.permissions` مباشرة**

- الخادم يحسب الصلاحيات الفعلية ويعيدها في `permissions` = `{ "اسم.الصلاحية": "own" | "department" | "all" }` (أوسع نطاق يملكه الحساب). مدير القسم يأخذ صلاحيات دور القسم بنطاق `department` دون أن يظهر له دور في `roles`.
- **احذف أي منطق في الواجهة يعيد بناء الصلاحيات من أسماء الأدوار** (حالياً `src/app/core/access/accounts.ts`) واستبدله بقراءة `user.permissions`. الدالة المطلوبة:
  ```ts
  can(perm: string, scope: 'own'|'department'|'all' = 'own'): boolean
  // ترتيب النطاقات: own < department < all
  ```
- إخفاء الأزرار في الواجهة تحسين تجربة فقط؛ الخادم يفرض كل شيء. تعامل دائماً مع 403.
- كتالوج الصلاحيات وتسمياتها: `GET /access/catalog`. الأدوار: `GET /access/roles`.

### 2.5 التحميلات (Downloads) والرفع

- التحميلات (CSV، ملفات شام كاش xlsx، المرفقات، مستندات الموظفين) تحتاج ترويسة Authorization، لذلك نزّلها عبر `HttpClient` بـ `responseType: 'blob'` و`observe: 'response'`، واقرأ اسم الملف من `Content-Disposition` (مكشوف الآن عبر CORS). لا تستخدم `<a href>` مباشرة.
- ملف شام كاش يعيد رقم المستند في الترويسة `X-Doc-Id`.
- مرفقات المهام: الصور (jpeg/png/webp/gif) وPDF تُعرض inline؛ أي نوع آخر (بما فيه SVG) يُنزّل كملف `application/octet-stream`.
- الرفع: `multipart/form-data` بحقل `file`. لا تضع `Content-Type` يدوياً.

### 2.6 التزامن والنسخ (`version`)

كثير من الكيانات تحمل `version` (البطاقات، الأدوار، الفواتير، أيام الحضور، الإجازات). أرسل آخر `version` قرأته مع كل تعديل؛ عند 409 أعد جلب الكيان واعرض للمستخدم أنه تغيّر.

---

## 3. التغييرات التي تكسر سلوك الواجهة القديم (طبّقها كلها)

| المنطقة | ما تغيّر في الخادم | ما يجب أن تفعله الواجهة |
| --- | --- | --- |
| عام | حُذف `GET /api/user` | استخدم `GET /api/v1/auth/me`. |
| عام | التوكن ينتهي بعد 7 أيام؛ الحساب المعطّل أو تغيير كلمة المرور يعطي 401 | الـinterceptor يوجّه للدخول (موجود). |
| عام | سباق إنشاء سجل مكرر أو حذف سجل مرتبط → **409** بدل 500؛ رقم/نص أكبر من حدود العمود → **422** | عالجهما برسالة الخادم. |
| الصلاحيات | `permissions` في `me/login` هي المصدر | احذف إعادة البناء من الأدوار. |
| المستخدمون | كل مسارات `/users*` تتطلب `users.manage` (403 قبل التحقق)؛ لا يعدّل أحد حساباً يملك صلاحيات أوسع منه (403)؛ `q` نص و`status` ∈ `active,disabled` | اعرض 403 برسالته. |
| المستخدمون | `PUT /users/{id}/roles` يقبل مصفوفة فارغة `[]` لإزالة كل الأدوار | — |
| المستخدمون | لا يُفعَّل حساب موظف انتهت خدمته (422 على `status`) | — |
| الإشعارات | `unread` يقبل `true/false/1/0` | — |
| الإعدادات | `GET /settings/config/public` **بلا مصادقة** (لعرض اسم الشركة في صفحة الدخول) | اطلبه قبل الدخول. |
| الإعدادات | المجموعة الفارغة تعود `{}` (كائن) لا `[]`؛ `PUT /settings/config/{key}` يحفظ المجموعة الحالية إن لم تُرسل؛ المفتاح بصيغة `group.name` بأحرف إنجليزية صغيرة | — |
| أسعار الصرف | `rate_date` يعود `YYYY-MM-DD` | لا تحوّله بـ Date. |
| المالية | (أُعيد بناؤها لاحقاً) عكس السندات يتطلب `finance.reverse` | انظر `docs/finance/CHANGELOG.md`. |
| المالية | القيد اليدوي **لا يمس حساب صندوق** (422) | الصناديق عبر القبض/الصرف/التحويل/الصرافة فقط؛ استبعد حسابات الصناديق من منتقي حسابات القيد اليدوي. |
| المالية | صرف العملة قد يعود `status: "pending"` (فوق حد الاعتماد، أو السعر يبعد >5% عن سعر اليوم المسجل، أو لا سعر مسجل لذلك اليوم) | اعرض "بانتظار الاعتماد" كما للمدفوعات. |
| المالية | لا يعتمد الفاتورة من أنشأها **أو أرسلها** (403)؛ لا يوافق على إقفال الشهر من طلبه (403) | — |
| المالية | الأشهر تُقفل بالترتيب (422 مع قائمة الأشهر المفتوحة قبلها)؛ إعادة فتح شهر تتطلب إعادة فتح الأشهر اللاحقة المقفلة (422) | اعرض الرسالة. |
| المالية | سطر فاتورة قيمته صفر يُرفض عند الحفظ (422 على `lines.N.unit_price`)؛ حدود عليا للكميات والأسعار | — |
| المالية | الصرف بتاريخ سابق يُرفض إن كان سيجعل كشف الصندوق سالباً في أي يوم لاحق | — |
| المالية | ميزان المراجعة: كل حساب صار فيه `opening_balance` و`closing_balance` إضافة لـ `debit/credit/balance` | أضف العمودين. |
| الرواتب | `kinds` في `review-remaining` إن أُرسلت يجب ألا تكون فارغة (422) | لا ترسل `kinds: []`؛ احذف المفتاح لتعني "الكل". |
| الرواتب | لا يراجع أحد بنداً أدخله بنفسه، ولا يعتمد الكشف من أضاف إليه بنوداً يدوية (403) | — |
| الرواتب | مبلغ البند اليدوي ≤ 999,999,999 | — |
| الرواتب | دفع يصبح صفراً بعد التقريب (SYP لأقرب 1000) → 422 على `currency` | اقترح عملة أخرى. |
| الرواتب | الدفع الفردي بشام كاش يتطلب محفظة 16 رقماً | — |
| الرواتب | إلغاء الدفعة: `date` ≤ اليوم وليس قبل تاريخ الدفع | — |
| الموظفون | `sham_cash_wallet` = 16 رقماً بالضبط؛ تعديله لموظف موجود يتطلب `employees.view_financial` (422 `prohibited` على الحقل) | اعرض الحقل للتعديل لمن يملك الصلاحية فقط. |
| الموظفون | فلاتر القائمة مُتحقق منها: `status` ∈ `active,terminated` | — |
| الأقسام | لا يعيّن أحد **نفسه** مديراً لقسم بصلاحيات لا يملكها (422 `manager_employee_id`)؛ المدير يجب أن يكون موظفاً على رأس عمله | — |
| الأقسام | إنهاء خدمة مدير القسم يفرغ `manager_employee_id` تلقائياً | أعد جلب الأقسام بعد إنهاء خدمة. |
| العقود | التداخل يُفحص مع العقود المنتهية/المنهاة أيضاً؛ تاريخ إنهاء الخدمة لا يسبق نهاية عقد سابق؛ `base_salary` ≤ 999,999,999,999 | — |
| الحضور | توقيت الجهاز الجديد يجب أن يطابق توقيت المكتب (422 `timezone`)؛ إن لم يُرسل يُؤخذ توقيت المكتب | الأفضل عدم إرسال `timezone`. |
| الحضور | لا يعتمد/يعيد فتح أحد يوم حضوره (403)؛ في الاعتماد الجماعي يظهر في `skipped` مع السبب | — |
| الحضور | تأكيد المزامنة مسموح لجهاز معطّل (ليُغلق أيامه الأخيرة)، ويُرفض 409 إن وُجدت بصمات محجوزة بانتظار المراجعة | — |
| الحضور | عنوان IP لجهاز ADMS فريد بين الأجهزة الفعالة (422) | — |
| الإجازات | `GET /leaves` صار بالشكل الموحد `{data, meta{current_page,last_page,per_page,total}, links}` | حدّث القارئ. |
| الإجازات | غير المعني بالطلب يحصل على 403 قبل أي تفاصيل | — |
| الإجازات | يمكن اعتماد إجازة معلقة لأيام عُملت قبل إنهاء الخدمة؛ أيام بعد نهاية الخدمة → 409 | — |
| المهام | اللوحة المؤرشفة **للقراءة فقط**: كل كتابة → 409 عدا `POST /boards/{id}/archive {archived:false}` | عطّل أدوات التحرير عند `archived_at != null`. |
| المهام | لا يمكن تخفيض آخر مالك عبر إضافة العضو (422) | — |
| المهام | `PATCH /lists/{id}` بـ `position`: للقوائم العادية فقط وقبل قائمة «تم» (422)، ضمن ±1e12 | — |
| المهام | اعتماد بطاقة تغيّرت حالتها للتو → 409 | أعد جلب اللوحة. |
| المهام | تعديل التكرار لا يعيد تفعيل تكرار موقوف إلا بإرسال `active: true`؛ `starts_on` في الماضي يُعامل كاليوم | — |

---

## 4. الهوية والإعدادات

| المسار | الصلاحية | المدخلات | الاستجابة |
| --- | --- | --- | --- |
| `POST /auth/login` | عام (10/دقيقة) | `email`, `password` | `{token, user}`؛ 401 بيانات خاطئة، 403 حساب معطّل |
| `GET /auth/me` | مسجّل | — | `{user}` |
| `POST /auth/logout` | مسجّل | — | `{message}` |
| `GET /users` | `users.manage` | `q?`, `status?`، 20/صفحة | مرقّم User |
| `POST /users` | `users.manage` | `employee_id?` أو `name` (≤120)، `email` (≤191، فريد)، `phone?` (فريد)، `password` (≥10)، `roles[]?` (يتطلب `roles.manage`) | 201 User. مع `employee_id` يُنسخ الاسم والهاتف من HR والدور الافتراضي `employee` |
| `GET /users/{id}` | `users.manage` | — | User |
| `PATCH /users/{id}` | `users.manage` | `name?`, `email?`, `phone?`, `password?`, `roles[]?` | User. اسم وهاتف الحساب المرتبط بموظف يُعدّلان من HR |
| `PATCH /users/{id}/status` | `users.manage` | `status: active|disabled` | User |
| `PUT /users/{id}/roles` | `users.manage` + `roles.manage` | `roles: string[]` (قد تكون فارغة) | User |
| `GET /notifications` | مسجّل | `unread?`, `per_page?` (1–100) | مرقّم `{id, kind, message, data, read_at, created_at}` + `unread_count` على المستوى الأعلى |
| `POST /notifications/read-all` | مسجّل | — | `{message}` |
| `POST /notifications/{uuid}/read` | مسجّل | — | `{message}` |
| `GET /access/catalog` | مسجّل | — | `{scopes:["own","department","all"], modules:[{key,label_ar,label_en,permissions:[{name,label_ar,label_en,hint_ar,hint_en,scopes,sensitive}]}]}` |
| `GET /access/roles` | مسجّل | — | `[{name,label_ar,label_en,hint_ar,hint_en,kind:"admin|standard|department",is_default,version,holders?,grants?}]` (`grants/holders` لمن يملك `roles.manage` أو `users.manage`) |
| `POST /access/roles` | `roles.manage` | `name` (`^[a-z][a-z0-9_]*$`)، `label_ar`، `label_en?`، `hint_ar?`، `hint_en?`، `grants: {perm: scope}` | 201 Role |
| `PATCH /access/roles/{name}` | `roles.manage` | `version` + أي من الحقول | Role؛ 409 نسخة قديمة |
| `DELETE /access/roles/{name}` | `roles.manage` | `version` | `{message}` |
| `POST /access/roles/{name}/restore` | `roles.manage` | `version` | Role |
| `GET /settings/config/public` | **بلا مصادقة** | — | `{"company.name": "..."}` |
| `GET /settings/config/{group}` | `settings.view` | — | `{key: value}` |
| `PUT /settings/config/{key}` | `settings.manage` | `value` (≤1000)، `group?` | `{key, value, group}` |
| `GET /settings/currencies` | مسجّل | `active?` | `[{id, code, name, symbol, decimals, rounding_step, is_base, is_payroll_currency, is_active}]` |
| `POST /settings/currencies`، `PATCH /settings/currencies/{id}` | `finance.settings` | الحقول أعلاه | العملة الأساسية (USD) لا تتغير ولا تُعطَّل |
| `GET /settings/exchange-rates` | مسجّل | `date?` | النشرة: `{date, base, rates:[{code, rate_to_base}], missing:[]}` |
| `GET /settings/exchange-rates/history` | مسجّل | `currency`, `limit?` (≤365) | `[{rate_to_base, rate_date, source}]` الأحدث أولاً |
| `POST /settings/exchange-rates` | `finance.rates` | `date`, `rates: {CODE: rate}` (وحدات لكل 1 دولار) | 201 النشرة (نفس اليوم يُستبدل) |
| `POST /settings/exchange-rates/copy-previous` | `finance.rates` | `date` | النشرة |
| `GET /settings/branches`، `POST`، `PATCH /settings/branches/{id}` | مسجّل / `finance.settings` | `name, code, is_head_office?, is_active?` | الفرع |

قواعد التصعيد: لا يمنح أحد صلاحية أو نطاقاً أوسع مما يملك؛ دور مدير النظام لا يُعدَّل ولا يُسند إلا من مدير نظام؛ لا يُزال آخر مدير نظام نشط.

---

## 5. الموارد البشرية (موظفون، أقسام، عقود، مستندات)

**EmployeeResource**: دائماً `id, employee_no, has_account, fingerprint{status,device_user_id,bindings}, first_name, father_name, last_name, full_name, department_id, job_title_id, manager_id, status, hire_date, current_service_start` (+ `department, job_title, manager` عند تحميلها). مع `employees.view_sensitive`: `service_periods[], birth_date, email, phone, address, termination_date, termination_reason, biometric_code, user_id`. مع `employees.view_financial`: `sham_cash_wallet`. **لا تفترض وجود الحقول الحساسة.**

**ContractResource**: `id, employee_id, contract_number, type, status, effective_status, start_date, end_date, terminated_at, scheduled_termination_at, scheduled_termination_reason, renewed_from_id` (+ `base_salary, currency_code, pay_type` مع `view_financial`).

| المسار | الصلاحية | ملاحظات |
| --- | --- | --- |
| `GET/POST /departments`، `GET/PUT/DELETE /departments/{id}` | view / manage | `name≤120, code≤20 فريد, parent_id?, manager_employee_id?`. الحذف يرفض قسماً فيه موظفون أو فروع (422). |
| `GET/POST /job-titles`، `GET/PUT/DELETE /job-titles/{id}` | view / manage | `name` فريد |
| `GET /employees` | `employees.view` | `q?, department_id?, job_title_id?, status?` |
| `GET /employees/{id}` | `employees.view` | — |
| `POST /employees` | `employees.manage` | `first_name, father_name, last_name (≤80), national_id, hire_date, birth_date?, email?, phone?, address?, department_id?, job_title_id?, manager_id?, sham_cash_wallet? (16 رقماً)` |
| `PUT /employees/{id}` | `employees.manage` | تغيير القسم/المسمى/المدير يتطلب `effective_date` (≤ اليوم) و`reason` |
| `POST /employees/{id}/terminate` | `employees.manage` | `termination_date, reason`؛ يرفض مع عقود فعالة |
| `POST /employees/{id}/rehire` | `employees.manage` | `effective_date, reason (5–500), confirm_new_service_period: true` |
| `GET /employees/{id}/job-history` | `employees.view` | مرقّم |
| `GET /employees/{id}/contracts`، `GET /employees/{id}/contracts/{c}` | `employees.view` | — |
| `GET /contracts/expiring?days=` | `employees.view` | `[{contract_id, contract_number, type, employee_id, employee_no, employee_name, ends_on, reason, days_left}]` |
| `POST /employees/{id}/contracts` | `employees.manage` | `contract_number, type: fixed_term|indefinite, start_date, end_date (إلزامي لـ fixed_term), base_salary + currency_code (SYP|USD), pay_type: monthly|daily|hourly` → مسودة |
| `POST …/contracts/{c}/renew` · `/activate` · `/terminate {terminated_at, reason}` · `/schedule-termination {terminated_at>اليوم, reason}` · `/cancel-scheduled-termination` | `employees.manage` | — |
| `GET /employees/{id}/documents` | `employees.documents.view` | مرقّم |
| `GET /employees/{id}/documents/{d}/download` | `employees.documents.view` | ملف (blob) — يُسجَّل في التدقيق |
| `POST /employees/{id}/documents` | `employees.documents.manage` | multipart: `title, document_type?, description?, file` (pdf/jpg/png/webp/docx/xlsx ≤10MB) |
| `DELETE /employees/{id}/documents/{d}` | `employees.documents.manage` | — |

---

## 6. الحضور والأجهزة

| المسار | الصلاحية | ملاحظات |
| --- | --- | --- |
| `GET /employees/{id}/attendance?date=` | الموظف نفسه أو `attendance.view` يغطيه | `{day, punches[], corrections[]}` |
| `GET /employees/{id}/attendance-report?from&to` | نفسه | ≤31 يوماً؛ `{days[], summary{...}}` |
| `GET /employees/{id}/payroll-inputs?month=YYYY-MM` | `payroll.view` أو `attendance.view` شامل | — |
| `GET /attendance/days?date=` | `attendance.view` (شامل أو أقسامي) | فلاتر: `employee_id, department_id, status, flag, review_status, per_page`؛ يعيد مرقّماً + `summary{total, by_status}` |
| `GET /attendance/days/export?from&to` | نفسه | CSV |
| `GET /attendance/days/{day}` | نفسه أو صاحب اليوم | — |
| `POST /attendance/days/{day}/corrections` | `attendance.adjust` أو صاحب اليوم | `version, type: times|absence, first_in?, last_out?, reason (5–1000)` |
| `POST /attendance/corrections/{c}/review` | `attendance.approve` | `decision: approve|reject, reason` (لا يراجع طلبه) |
| `POST /attendance/days/{day}/approve` | `attendance.approve` | `version` (403 ليومك) |
| `POST /attendance/days/{day}/reopen` | `attendance.approve` | `version, reason` |
| `POST /attendance/days/approve-bulk` | `attendance.approve` | `from, to (≤31d), employee_ids[]?, department_id?` → `{approved, already_approved, outside_service, skipped[{day_id, employee_id, date, status, reason}]}` |
| `GET /attendance/policies` | `attendance.view` شامل | — |
| `POST /attendance/policies` | `attendance.manage` | `effective_from` (من الغد فما بعد، عدا الأولى)، `timezone` (= الأولى)، `working_days[0-6]`، `start_time/end_time (H:i)`، `grace_minutes`, `grace_mode`, `late_block_minutes`, `overtime_multiplier`, `pairing_mode`, `duplicate_seconds`, `finalize_after_minutes` |
| `GET/POST /attendance/devices`، `PATCH /attendance/devices/{d}` (`is_active`) | `attendance.manage` | الإنشاء يعيد `{device, token}` — **التوكن يظهر مرة واحدة**، اعرضه للنسخ |
| `PATCH /attendance/devices/{d}/connection` | `attendance.manage` | `connection_mode: bridge|adms, adms_source_ip` |
| `POST /attendance/devices/{d}/rotate-token` | `attendance.manage` | `{device, token}` |
| `POST /attendance/devices/{d}/confirm-sync` | `attendance.manage` | `complete_through (ISO), note (5–500)` |
| `GET/POST /attendance/devices/{d}/bindings`، `POST /attendance/bindings/{b}/close` | `attendance.manage` | ربط `employee_id` برقم المستخدم على الجهاز |
| `GET /attendance/unmatched-punches`، `GET /attendance/quarantined-punches` | `attendance.manage` | مرقّم |
| `POST /attendance/quarantined-punches/{id}/review` | manage + approve | `action: release|dismiss, corrected_occurred_at?, reason` |
| `POST /attendance/bindings/{b}/enroll-fingerprint`، `GET …/fingerprint-enrollment`، `GET/POST /attendance/fingerprint-enrollments/{id}[/confirm|/cancel]` | `attendance.manage` | تسجيل بصمة عن بعد (ميزة تجريبية) |

مسارات `/iclock/*` و`/attendance/device-ingest` للأجهزة فقط — **لا تستدعها من الواجهة**.

---

## 7. الإجازات

| المسار | الصلاحية | ملاحظات |
| --- | --- | --- |
| `GET /leave-settings` | `leaves.manage` | `{types[], policies[], holidays[], today}` |
| `POST /leave-settings/types`، `POST /leave-settings/types/{t}/policies`، `PATCH /leave-settings/types/{t}/activation` | `leaves.manage` | — |
| `POST/DELETE /leave-settings/holidays[/{h}]` | `leaves.manage` | — |
| `GET/POST /leave-settings/balance-entries`، `GET /leave-settings/pending-openings` | `leaves.manage` | — |
| `GET /leaves/options?employee_id=` | مسجّل | أنواع الإجازة والأرصدة و`can_submit/can_review/can_configure` و`half_days` |
| `PUT /leaves/half-days` | `leaves.manage` | — |
| `GET /leaves?scope=mine|review|all&…` | حسب النطاق | **مرقّم موحد الآن** |
| `GET /leaves/calendar?from&to` | `leaves.view` | ≤62 يوماً |
| `GET /leaves/export` | حسب النطاق | CSV |
| `POST /leaves/preview` | مسجّل | يعيد `quote_token` (ثابت لنفس المدخلات) |
| `POST /leaves` | مسجّل | مدخلات المعاينة + `quote_token` + `submission_key` (UUID تولّده الواجهة مرة لكل طلب وتعيد إرساله عند إعادة المحاولة) + `unpaid_consent` + `approve_now?` |
| `GET /leaves/{id}`، `POST /leaves/{id}/preview`، `PUT /leaves/{id}` | المعني | التعديل يتطلب `version` و`quote_token` |
| `POST /leaves/{id}/{approve|reject|withdraw|cancel|shorten|approve-change|reject-change}` | حسب الإجراء | `version, reason (≤500), from_date (لـ shorten)` |

تدفق الطلب في الواجهة: نموذج → `preview` → عرض الأيام والمدفوع/غير المدفوع → موافقة على غير المدفوع إن وُجد → `POST /leaves` بنفس `submission_key`.

---

## 8. المالية (`/finance`)

أُعيد بناء المالية (المرحلتان 1–2): دفتر متعدد العملات، صناديق متعددة العملات، سندات بكل الأنواع، جرد، فترات، وصلاحيات جديدة بدل `finance.manage` و`finance.approve`. المرجع الكامل في `docs/FINANCE_API_AR.md`، والتغييرات التي تكسر الواجهة في `docs/finance/CHANGELOG.md`.

---

## 9. الرواتب (`/payroll`)

| المسار | الصلاحية | ملاحظات |
| --- | --- | --- |
| `GET runs?status=` | `payroll.view` | مرقّم (24) ملخصات: `id, month, status, …, employees, not_ready, blocking_issues, undecided_items, paid, payable, totals{CUR:{earnings,deductions,net}}` |
| `GET runs/{run}` | `payroll.view` | الملخص + `lines[]` |
| `GET runs/{run}/lines/{line}` | `payroll.view` | السطر + `items[]` + `payment` |
| `GET runs/{run}/export` | `payroll.view` | CSV |
| `POST runs {month}` | `payroll.manage` | الشهر منتهٍ وغير موجود؛ الإنشاء والحساب الأول عملية واحدة |
| `POST runs/{run}/recalculate`، `POST runs/{run}/items {employee_id, category: earning|deduction, amount ≤999,999,999, reason}`، `DELETE items/{item}`، `POST runs/{run}/submit` | `payroll.manage` | الإرسال قد يعيد 422 مع `errors.run[]` (قائمة مشاكل اعرضها كلها) |
| `POST items/{item}/review {decision: approve|modify|waive, amount?, reason?}` | `payroll.approve` | لا لبندك |
| `POST runs/{run}/review-remaining {decision: approve|waive, kinds?: [late|early_exit|absence|bonus|manual_deduction], reason?}` | `payroll.approve` | `kinds` غير فارغة إن أُرسلت |
| `POST items/{item}/adjustment-decision {decision: accept|reject, reason}` | `payroll.approve` | — |
| `POST runs/{run}/return {note}`، `POST runs/{run}/approve` | `payroll.approve` | الاعتماد قد يعيد 422 `errors.run[]` أو 409 بنود بلا قرار |
| `POST lines/{line}/payment {currency, method: cash|sham_cash, box_id, paid_at ≤اليوم, reference (لشام كاش), expected_amount?, note?}` | `payroll.pay` | 201 PayPayment |
| `POST payments/{p}/void {date, reason}` | `payroll.pay` | — |
| `POST runs/{run}/shamcash {currency, date?, line_ids?, skip_missing?}` | `payroll.pay` | **ملف xlsx** + ترويسة `X-Doc-Id` |
| `GET runs/{run}/shamcash-exports`، `GET shamcash-exports/{e}/file`، `POST shamcash-exports/{e}/confirm {box_id}`، `POST shamcash-exports/{e}/cancel` | `payroll.pay` | — |

دورة الكشف: `draft` (الموارد البشرية تحسب وتضيف بنوداً) → `submitted` (المالية تقرر في كل بند مقترح) → `approved` → الدفع. لا يعتمد أحد كشفاً أعدّه أو أضاف إليه.

---

## 10. المهام (`/tasks`)

كل المسارات تتطلب `tasks.view`. ما يُسمح به على لوحة يحدده **دورك فيها** (`viewer < member < owner`) الموجود في `role` ضمن لقطة اللوحة. لوحة لا تراها → 404.

- اللوحات: `GET boards?archived=`، `GET boards/options`، `GET people?q=` (`tasks.manage`)، `POST boards`، `GET boards/{id}` (اللقطة الكاملة: `board, role, members, labels, lists, cards, cursor, today`)، `PATCH boards/{id}`، `POST boards/{id}/archive {archived}`، `GET boards/{id}/archived`.
- الأعضاء: `POST boards/{id}/members {user_id, role}`، `PATCH|DELETE boards/{id}/members/{user}`.
- القوائم والتسميات: `POST boards/{id}/lists {name}`، `PATCH lists/{id} {name?, position?}`، `POST lists/{id}/archive`، `POST boards/{id}/labels`، `PATCH|DELETE labels/{id}`.
- التكرار: `GET|POST boards/{id}/recurrences`، `PUT recurrences/{id}`، `POST recurrences/{id}/stop`.
- البطاقات: `GET my-cards`، `GET reports?from&to`، `POST boards/{id}/cards`، `GET|PATCH cards/{id}` (`version` إلزامي في PATCH)، `PUT cards/{id}/assignees {user_ids}`، `PUT cards/{id}/labels {label_ids}`، `POST cards/{id}/move {list_id, after_card_id?}` (يعيد **ملخص** البطاقة)، `approve`، `reject {reason}`، `reopen`، `archive {archived}`، `watch {watching}`، `transfer {board_id, list_id}`، `copy {board_id, list_id, title?}`.
- القوائم المرجعية والتعليقات والمرفقات: `POST cards/{id}/checklists`، `PATCH|DELETE checklists/{id}`، `POST checklists/{id}/items`، `PATCH|DELETE checklist-items/{id}`، `POST cards/{id}/comments {body, mentions?}`، `PATCH|DELETE comments/{id}`، `POST cards/{id}/attachments` (≤25MB، 30 لكل بطاقة)، `GET|DELETE attachments/{id}`.

**المزامنة بين المستخدمين (مهم):**
- البث اللحظي (WebSocket) **غير مفعّل حالياً** في الخادم (لا يوجد خادم Reverb/Pusher و`BROADCAST_CONNECTION=log`). **اعتمد الاستطلاع (polling) كآلية أساسية:**
  1. خذ `cursor` من `GET boards/{id}`.
  2. كل 5–10 ثوانٍ واللوحة مفتوحة والتبويب ظاهر: `GET boards/{id}/changes?since={cursor}` → `{cursor, changes[{id, card_id, action, user_id, created_at}], complete}`.
  3. إن كانت `changes` غير فارغة: أعد جلب البطاقات المعنية (`GET cards/{id}`) أو اللوحة كاملة إن كان التغيير على مستوى اللوحة (`board.*`, `list.*`, `member.*`, `label.*`). إن `complete=false` استطلع فوراً مرة أخرى.
  4. خزّن `cursor` الجديد.
  5. عند عودة التبويب للظهور أو انقطاع الشبكة: أعد جلب اللوحة كاملة.
- إن فُعّل البث لاحقاً: القناة `private-tasks.board.{id}` عبر `POST {SERVER}/api/broadcasting/auth` بترويسة Bearer، والحدث `.board.changed` بالحمولة `{board_id, cursor, action, card_id}` — تعامل معه كإشارة لتشغيل الخطوة 2 فوراً، لا كمصدر بيانات.

---

## 11. التشغيل المحلي والتحقق

```bash
# الخادم
cd eos-backend && php artisan migrate && php artisan db:seed && php artisan serve --host=127.0.0.1 --port=8088
# الواجهة
cd "EOMS Front_End" && npm install && npx ng serve   # http://localhost:4200
```

- المدير الأول يُنشأ من `.env` (`ADMIN_EMAIL`/`ADMIN_PASSWORD`) عبر `db:seed`. لا توجد بيانات اعتماد تجريبية مكتوبة في الكود؛ اطلبها من المستخدم أو أنشئ حسابات اختبار عبر `POST /users` بعد الدخول كمدير.
- المهام المجدولة (التكرار، التذكيرات، إعادة حساب الحضور، انتهاء العقود): `php artisan schedule:work` أثناء التطوير.
- قائمة تحقق لكل شاشة:
  - [ ] حالة التحميل، والقائمة الفارغة، والترقيم.
  - [ ] 403 يعرض رسالة الخادم ولا يكسر الصفحة.
  - [ ] 409 يعرض الرسالة ويعيد جلب البيانات.
  - [ ] 422 يربط الأخطاء بالحقول.
  - [ ] الأزرار مخفية/معطلة حسب `user.permissions` ودور اللوحة.
  - [ ] النصوص العربية من `message` تُعرض كما هي (RTL).
  - [ ] التواريخ تُعرض دون إزاحة يوم.
  - [ ] التحميلات تحمل اسم الملف الصحيح.
