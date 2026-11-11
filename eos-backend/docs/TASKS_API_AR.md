# وحدة المهام (Tasks) — واجهة البرمجة

لوحات بأسلوب Trello: لوحة ← قوائم مرتّبة ← بطاقات مرتّبة. كل المسارات تحت `/api/v1/tasks` وتتطلب `auth:sanctum` وصلاحية `tasks.view`.

## الصلاحيات والأدوار

| الصلاحية | لمن (الـseeder) | ماذا تتيح |
|---|---|---|
| `tasks.view` | كل الأدوار (ومنها `employee`) | الدخول للوحدة، واللوحة الشخصية |
| `tasks.manage` | مدير الشركة، مدير الموارد البشرية، مدير المالية، مدير المشاريع، `department_manager` | إنشاء لوحات الفرق |

**دور العضو على اللوحة** (`role` في الاستجابات): `owner` > `member` > `viewer`.

| نوع اللوحة | من ينشئها | المالك | الأعضاء |
|---|---|---|---|
| `personal` | أي مستخدم | منشئها وحده | لا أحد غيره — ولا حتى مدير الشركة |
| `team` | من يملك `tasks.manage` | المنشئ (ومن يُضاف مالكاً) | بالدعوة، بأي دور |
| `department` | مدير القسم لقسمه (أو مدير الشركة) | مدير القسم الحالي تلقائياً + المنشئ | موظفو القسم النشطون تلقائياً (`member`) + المدعوون |
| `project` | لاحقاً مع قسم المشاريع | — | — |

**القراءة فقط:** مدير الشركة ومدير النظام يرون كل اللوحات غير الشخصية كـ`viewer`، ومدير القسم يرى كـ`viewer` اللوحات غير الشخصية التي فيها موظف نشط من قسمه. من لا يرى اللوحة يأخذ **404** (لا تلميح بوجودها)، ومن دوره أقل من المطلوب يأخذ **403**.

| الإجراء | الحد الأدنى |
|---|---|
| قراءة اللوحة والبطاقات، التعليق، المتابعة، تنزيل المرفقات | `viewer` |
| إنشاء البطاقات وتعديلها وتحريكها بين القوائم العادية، التكليف، التسميات، قوائم التحقق، المرفقات، البطاقات المتكررة | `member` |
| إعدادات اللوحة، الأعضاء، القوائم، اعتماد الإنجاز أو رفضه، إعادة فتح المنتهية، أرشفة اللوحة | `owner` |

اللوحة المؤرشفة للقراءة فقط (409) حتى يستعيدها المالك.

## دورة الإنجاز

كل لوحة تبدأ بأربع قوائم: "للقيام"، "قيد التنفيذ" (`kind: normal`)، "منجز" (`done`)، "منتهية" (`finished`). القائمتان الأخيرتان ثابتتان لا تُؤرشفان، والقوائم الجديدة تُضاف قبل "منجز".

1. أي عضو يحرّك البطاقة بين القوائم العادية.
2. النقل إلى **"منجز"** للمكلّفين بالبطاقة أو المالك فقط → إشعار `tasks.card_review` للمالكين. تُحفظ القائمة السابقة.
3. المالك: `POST /cards/{id}/approve` → "منتهية" (`completed_at`, `completed_by`)، أو `POST /cards/{id}/reject {reason}` → تعود لقائمتها السابقة، والسبب تعليق، وإشعار `tasks.card_rejected` للمكلّفين.
4. البطاقة المنتهية للقراءة فقط (422) حتى `POST /cards/{id}/reopen` من المالك.
5. **اللوحة الشخصية:** النقل إلى "منجز" يُنهي البطاقة مباشرة.

## المسارات

### اللوحات
| الطريقة والمسار | الوصف |
|---|---|
| GET `/boards?archived=0\|1` | لوحاتي المرئية مع `role` و`open_cards` و`overdue_cards` |
| GET `/boards/options` | ما يحق لي إنشاؤه: `types` و`departments` (`[{id,name}]`: كل الأقسام لمدير الشركة، وإلا الأقسام التي أديرها) |
| GET `/people?q=` | زملاء نشطون لإضافتهم إلى لوحة: `user_id, name, department_id, department_name` — لحاملي `tasks.manage` ولمديري الأقسام فقط |
| POST `/boards` | `{type, name, description?, color?, department_id?, members?: [{user_id, role}]}` → لقطة اللوحة |
| GET `/boards/{id}` | اللقطة الكاملة: `board, role, members, labels, lists, cards, cursor, today` |
| GET `/boards/{id}/changes?since={cursor}` | التغييرات بعد المؤشر: `{cursor, changes: [{id, card_id, action, user_id, created_at}], complete}` |
| PATCH `/boards/{id}` | `{name?, description?, color?}` (مالك) |
| POST `/boards/{id}/archive` | `{archived: bool}` (مالك) |
| GET `/boards/{id}/archived` | القوائم والبطاقات المؤرشفة للاستعادة |
| POST `/boards/{id}/members` · PATCH/DELETE `/boards/{id}/members/{userId}` | إدارة الأعضاء `{user_id, role}` / `{role}` (مالك). لا يُزال آخر مالك. الإزالة تزيل العضو من البطاقات المفتوحة |
| POST `/boards/{id}/lists` · PATCH `/lists/{id}` · POST `/lists/{id}/archive` | القوائم `{name}` / `{name?, position?}` / `{archived}` (مالك). لا تؤرشف قائمة فيها بطاقات |
| POST `/boards/{id}/labels` · PATCH/DELETE `/labels/{id}` | التسميات `{name, color: "#rrggbb"}` (عضو) |

### البطاقات
| الطريقة والمسار | الوصف |
|---|---|
| POST `/boards/{id}/cards` | `{list_id, title, description?, priority?: low\|normal\|high\|urgent, start_date?, due_date?, assignee_ids?, label_ids?}` |
| GET `/cards/{id}` | التفاصيل: الحقول، `assignee_ids`, `label_ids`, `watcher_ids`, `watching`, `checklists`, `comments`, `attachments`, `activity`, `warnings` |
| PATCH `/cards/{id}` | `{version, title?, description?, priority?, start_date?, due_date?}` — 409 إن تغيّرت النسخة |
| PUT `/cards/{id}/assignees` | `{user_ids}` — أعضاء اللوحة فقط (ليس المشاهدين) |
| PUT `/cards/{id}/labels` | `{label_ids}` |
| POST `/cards/{id}/move` | `{list_id, after_card_id?}` (null = أعلى القائمة) |
| POST `/cards/{id}/approve` · `/reject {reason}` · `/reopen` | دورة الإنجاز (مالك) |
| POST `/cards/{id}/archive` | `{archived}` |
| POST `/cards/{id}/watch` | `{watching}` |
| POST `/cards/{id}/transfer` | `{board_id, list_id}` نقل إلى لوحة أخرى أنت عضو فيها: التسميات بالاسم، ومن لا يعمل على اللوحة الجديدة يُزال |
| POST `/cards/{id}/copy` | `{board_id, list_id, title?}` نسخ (الحقول، التسميات بالاسم، قوائم التحقق بلا علامات، المكلّفون المسموحون) |
| POST `/cards/{id}/checklists` · PATCH/DELETE `/checklists/{id}` | `{title}` |
| POST `/checklists/{id}/items` · PATCH/DELETE `/checklist-items/{id}` | `{text}` / `{text?, done?}` |
| POST `/cards/{id}/comments` · PATCH/DELETE `/comments/{id}` | `{body, mentions?: [userId]}` — الإشارة لمن يرى اللوحة فقط؛ يعدّل الكاتب تعليقه، والمالك يحذف أي تعليق |
| POST `/cards/{id}/attachments` (multipart `file`) · GET/DELETE `/attachments/{id}` | jpg/png/webp/gif، pdf، Office، csv/txt، dwg/dxf، zip/rar/7z حتى 25MB، وحتى 30 مرفقاً. يحذفه من رفعه أو المالك |

**`warnings`**: المكلّفون الذين لديهم إجازة **معتمدة** في يوم الاستحقاق — تنبيه للعرض فقط، لا يمنع التكليف.

### عبر اللوحات
| GET `/my-cards?state=open\|finished\|all&due=overdue\|soon\|none&board_id=&priority=` | بطاقاتي من كل اللوحات المرئية، مع `board` و`list` |
|---|---|
| GET `/reports?from=&to=&board_id=&department_id=` | لكل مكلّف: `open`, `overdue`, `awaiting_review`, `finished` (في الفترة)، `on_time`, `on_time_rate`, `avg_days` — على اللوحات غير الشخصية التي يراها الطالب |

### البطاقات المتكررة
| GET/POST `/boards/{id}/recurrences` · PUT `/recurrences/{id}` · POST `/recurrences/{id}/stop` |
|---|
| `{list_id, frequency: daily\|weekly\|monthly, weekdays?: [0..6], month_day?: 1..31, due_in_days?: أيام عمل, starts_on?, template: {title, description?, priority?, assignee_ids?, label_ids?, checklist?: [{title, items: []}]}}` |

تُنشأ بطاقة جديدة في يومها قبل بداية الدوام بساعة (توقيت المكتب)، ولا تُنشأ في العطل الرسمية وأيام العطلة الأسبوعية (تُتخطى ولا تُرحَّل)، ولا تتكرر في اليوم نفسه. الأمر `tasks:recurring` مجدول كل ساعة.

## الإشعارات (`SystemNotification`، `data.kind`)

`tasks.board_member_added`، `tasks.card_assigned`، `tasks.card_unassigned`، `tasks.card_review` (للمالكين)، `tasks.card_rejected`، `tasks.card_finished`، `tasks.card_reopened`، `tasks.card_moved` و`tasks.card_due_changed` و`tasks.card_comment` (للمتابعين)، `tasks.mentioned`، `tasks.card_due_soon` (يوم العمل السابق للاستحقاق) و`tasks.card_overdue` (مرة لكل تاريخ استحقاق، الأمر `tasks:reminders` كل ساعة)، `tasks.cards_unassigned` و`tasks.board_handed_over` (عند انتهاء الخدمة). لا يصل إشعار لصاحب الإجراء نفسه. المكلّفون ومنشئ البطاقة يتابعونها تلقائياً.

كل إشعار يحمل `board_id` و`card_id` عند وجودها للانتقال إليها.

## انتهاء الخدمة

داخل معاملة الإنهاء نفسها: يُزال الموظف من كل اللوحات والبطاقات المفتوحة، ويُبلَّغ مالكو كل لوحة بعدد البطاقات التي أصبحت بلا مكلّف، واللوحة التي كان مالكها الوحيد تنتقل ملكيتها لمدير قسمه، ولوحته الشخصية تُؤرشف.

## التحديث اللحظي

- كل تغيير يُسجَّل في `tsk_activities` (سجل البطاقة وتغذية اللوحة معاً)، و`id` آخر نشاط هو `cursor`.
- بعد تثبيت المعاملة يُبث الحدث `board.changed` على القناة الخاصة `private-tasks.board.{id}` بالبيانات `{board_id, cursor, action, card_id}`. الواجهة تعيد جلب اللوحة أو البطاقة، ولا تُرسل بيانات البطاقة عبر القناة.
- التصريح: `POST /api/broadcasting/auth` بتوكن Sanctum، ويُقبل من يرى اللوحة.
- **التفعيل** (على السيرفر):
  ```bash
  composer require laravel/reverb
  php artisan reverb:install      # يكتب REVERB_* في .env
  # BROADCAST_CONNECTION=reverb
  php artisan reverb:start        # كخدمة دائمة (supervisor/systemd)
  ```
  وفي الواجهة: `npm i laravel-echo pusher-js`. قبل التفعيل تبقى `BROADCAST_CONNECTION=log` أو `null`، والواجهة تسحب `GET /boards/{id}/changes?since=` كل بضع ثوانٍ.

## الترتيب والتزامن

- المواضع أرقام عشرية بفاصل 1024؛ السحب يضع البطاقة في منتصف جارَيها، وعندما يضيق الفاصل تُعاد ترقيم القائمة تلقائياً.
- `version` على البطاقة يمنع الكتابة فوق تعديل شخص آخر (409).
- كل المعاملات تقفل الصفوف المعنية وتُعاد عند تعارض قاعدة البيانات (حتى 3 مرات).

## التثبيت

```bash
php artisan migrate
php artisan db:seed --class="App\Modules\Identity\Database\Seeders\RolePermissionSeeder"   # tasks.view / tasks.manage
```
