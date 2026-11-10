# Graph Report - EOMS  (2026-11-10)

## Corpus Check
- 441 files · ~193,514 words
- Verdict: corpus is large enough that graph structure adds value.
- Unclassified: 110 file(s) not represented in the graph (top: .scss 83, (none) 20, .ico 2)

## Summary
- 4059 nodes · 10375 edges · 203 communities (110 shown, 93 thin omitted)
- Extraction: 96% EXTRACTED · 4% INFERRED · 0% AMBIGUOUS · INFERRED: 402 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `ee42a8d7`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- UI Office
- UI Pages Dashboard
- API Http Controllers
- leaves/leaves.ts
- UI App Data 2
- payroll-runs.ts
- UI App Core
- API Adms Gateway Test
- AccessFile
- API Hr Models
- PayRunService
- LeaveType
- API Services Attendance
- UI Pages Leaves
- API Attendance Test
- Components
- EmployeeDossier
- API Finance Models
- UI Core Api
- UI Hr Settings Settings Devices
- API Http Requests
- UI Core Access
- UI Finance
- UI Pages Finance
- UI Angular
- UI Finance Invoices
- API Http Controllers 2
- Illuminate\Contracts\Pagination\LengthAwarePaginator
- .log
- API Http Controllers 3
- UI App Data 5
- UI Settings Leaves
- API Hr Services 3
- API Hr Services 4
- User
- UI Payroll Runs
- UI App Data 6
- .now
- PayRun
- API Leave Workflow Test
- API Employee Rehire Test
- UI Pages Requests
- API Cash Service
- @angular/core
- Illuminate\Database\Schema\Blueprint
- JobTitle
- API Http Controllers 4
- API Finance Controller
- API Hr Services 5
- UI Finance Ledger
- API Attendance Controller
- UserFactory
- API Database Seeders
- API Hr Services 6
- API Hr Services 7
- data/finance.ts
- UI Finance Cash
- API Package
- PayRunController.php
- LedgerService
- API Finance Services 3
- API Http Middleware
- EOMS Front_End/package.json
- UI Records
- PayrollCalculator
- AuditLogger
- API Settings Services
- API Finance Test
- API Payroll Test
- PayRunService.php
- API Unit Hr
- API Http Controllers 5
- API Http Controllers 6
- Illuminate\Database\Migrations\Migration
- API Http Resources
- API Employee Documents Test
- Illuminate\Support\Facades\Schema
- API Leave Attendance Integration Test
- LeaveRequestController.php
- API Http Resources 2
- API Contract Job History Test
- UI Finance Items
- UI Finance Reports
- UI Payroll Run
- EmployeeDocuments
- Illuminate\Support\ServiceProvider
- API Employee Job History Test
- API Employment Contract Test
- UI Attendance Report
- API Report Service
- Illuminate\Console\Command
- API App
- API Deptrac.yaml
- Rates
- API Exchange Rate Test
- UI Dashboard Money
- API Attendance Integration Test
- API Leave Review Gaps Test
- API Identity Test
- UI Employee Contracts
- UI Leave File
- UI Employees
- API Expired Contract Test
- Invoice
- نظام الحضور - تحديث السماح وشرائح التأخير (V2)
- API Scheduled Contract Termination Test
- UI Core Reports
- UI Access
- API Report Service 2
- AttendanceDay
- UI Leave Queue
- UI Finger Dialog
- UI Settings List
- UI Leave
- dossier.ts
- AttendanceDeviceService
- API Add Employee Service Periods
- Product
- API Composer
- API Composer 2
- API Composer 3
- API Settings Test
- نظام الإجازات — مرجع الـAPI للفرونت
- UI Shell
- ربط EOMS بجهاز iFace 880 Plus عبر ADMS/PUSH
- وحدة المالية — مرجع الـAPI والقواعد
- API Composer 4
- API Composer 5
- API Create Permission Tables
- API Leave Settings Test
- API Role Escalation Test
- UI Employee History
- UI Line Chart
- API Add Attendance Lateness Blocks
- API Create Payroll Tables
- API Allow Voiding Pay Payments
- API Composer 6
- نظام الرواتب — مرجع الـAPI والقواعد
- API Employee Onboarding Test
- API Employee Privacy Test
- API Expiring Contracts Test
- UI Package 3
- UI Settings Office
- UI Account Switch
- API Create Jobs Table
- API Employee Termination Test
- UI Package 4
- API Add Reason To Hr
- API Add Scheduled Termination To
- API Add Father Name And
- API Add Contract Context To
- API Add Leave Context To
- API Retire Employee On Leave
- API Add Entered By To
- API Add Pay Type To
- API Link Pay Payments To
- API Create Cache Table
- API Create Hr Employees Table
- API Create Hr Employee Job
- ExchangeRateController
- API Create Hr Adms Quarantined
- PayrollFacts
- اتفاقيات مشروع EOMS
- API Create Sys Currencies Table
- Eoms
- Payroll
- 2026_11_05_191346_add_adms_gateway_tables.php
- API Create Sessions Table
- API Composer 7
- API Composer 8
- Symfony\Component\HttpFoundation\StreamedResponse
- eos-backend/README.md
- 2026_11_08_000001_create_hr_leave_foundation.php
- Dashboard
- Dashboard and every API-connected screen
- Later
- PayrollFile
- التحقق من إصلاح ربط الإجازات والحضور
- copy-hr.ts
- 2026_11_09_000002_add_manager_to_hr_departments.php
- PurchaseTable
- Laravel Application
- 2026_10_23_223447_create_hr_job_titles_table.php
- 2026_10_22_203202_create_usr_users_table.php
- 2026_10_22_174919_create_sys_settings_table.php
- Laravel Application

## God Nodes (most connected - your core abstractions)
1. `User` - 155 edges
2. `Office` - 154 edges
3. `Employee` - 151 edges
4. `Ui` - 119 edges
5. `@angular/core` - 114 edges
6. `AdmsGatewayTest` - 70 edges
7. `AuditLogger` - 68 edges
8. `Session` - 63 edges
9. `CopyKey` - 63 edges
10. `TestCase` - 62 edges

## Surprising Connections (you probably didn't know these)
- `التدقيق` --references--> `AuditLogger`  [INFERRED]
  eos-backend/CONVENTIONS.md → eos-backend/app/Modules/Identity/Services/AuditLogger.php
- `Line` --references--> `CopyKey`  [EXTRACTED]
  EOMS Front_End/src/app/pages/employees/employee-history/employee-history.ts → EOMS Front_End/src/app/core/copy.ts
- `PayPayment` --references--> `Money`  [EXTRACTED]
  EOMS Front_End/src/app/data/payroll.ts → EOMS Front_End/src/app/data/finance.ts
- `{closure#1}()` --references--> `User`  [EXTRACTED]
  eos-backend/app/Modules/Finance/Services/FinanceNotifier.php → eos-backend/app/Modules/Identity/Models/User.php
- `{closure#1}()` --calls--> `EmployeeJobHistoryResource`  [EXTRACTED]
  eos-backend/app/Modules/HR/Http/Controllers/EmployeeJobHistoryController.php → eos-backend/app/Modules/HR/Http/Resources/EmployeeJobHistoryResource.php

## Import Cycles
- None detected.

## Communities (203 total, 93 thin omitted)

### Community 0 - "UI Office"
Cohesion: 0.07
Nodes (8): ApiPolicy, dayIn(), officeToday(), stamp(), validClock(), validIsoDate(), idOrNull(), Office

### Community 1 - "UI Pages Dashboard"
Cohesion: 0.05
Nodes (34): Reporter, Ui, FingerprintState, AccountsMix, AttendanceBlock, Dashboard, HrFlow, Insights (+26 more)

### Community 2 - "API Http Controllers"
Cohesion: 0.08
Nodes (4): FinanceController, AdmsController, AttendanceController, AdmsQuarantineService

### Community 3 - "leaves/leaves.ts"
Cohesion: 0.11
Nodes (19): HalfDays, LeaveBalance, LeaveCalendar, LeaveCalendarDay, LeaveDay, LeaveListQuery, LeaveOptions, LeaveQuote (+11 more)

### Community 4 - "UI App Data 2"
Cohesion: 0.05
Nodes (76): AuditEntry, roleFrom(), ApiDepartment, ApiEmployee, ApiEmployeeStatus, Adjustment, arrival, buildDay() (+68 more)

### Community 5 - "payroll-runs.ts"
Cohesion: 0.06
Nodes (20): revealed(), saveFile(), ItemCategory, ItemOrigin, ItemStatus, LineIssue, PaymentInput, PayMethod (+12 more)

### Community 6 - "UI App Core"
Cohesion: 0.05
Nodes (27): base, copy, CopyKey, ar, designCopy, en, ar, en (+19 more)

### Community 8 - "AccessFile"
Cohesion: 0.13
Nodes (5): loginMail(), loginUserRejected(), AccessFile, EmployeeForm, EmployeeInfo

### Community 9 - "API Hr Models"
Cohesion: 0.06
Nodes (13): {closure#1}(), ProcessExpiredContracts, {closure#1}(), ProcessScheduledContractTerminations, {closure#1}(), {closure#3}(), EmploymentContractController, EmploymentContractResource (+5 more)

### Community 10 - "PayRunService"
Cohesion: 0.20
Nodes (9): {closure#10}(), {closure#11}(), {closure#2}(), {closure#5}(), {closure#6}(), {closure#7}(), {closure#8}(), {closure#9}() (+1 more)

### Community 11 - "LeaveType"
Cohesion: 0.07
Nodes (11): LeaveBalanceEntry, LeavePolicy, LeaveType, EmploymentPeriodService, {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}() (+3 more)

### Community 12 - "API Services Attendance"
Cohesion: 0.05
Nodes (16): {closure#1}(), AttendanceDay, AttendancePolicy, LeaveRequestDay, AttendanceCalculator, {closure#1}(), AttendanceClock, {closure#1}() (+8 more)

### Community 13 - "UI Pages Leaves"
Cohesion: 0.07
Nodes (6): LeaveInput, LeaveRecord, LeaveForm, leaveClock(), LeaveRegister, LeavesPage

### Community 15 - "Components"
Cohesion: 0.07
Nodes (28): Buttons, Charts, Colors, Components, Data palette, Design System: UFUQ, Do, Do's and Don'ts (+20 more)

### Community 16 - "EmployeeDossier"
Cohesion: 0.33
Nodes (3): DossierSection, EmployeeDossier, readAsDataUrl()

### Community 17 - "API Finance Models"
Cohesion: 0.06
Nodes (12): ApprovalLimit, InvoiceLine, JournalLine, {closure#3}(), AttendanceUtcDateTime, AttendanceEnrollment, LeaveCycle, AuditLog (+4 more)

### Community 18 - "UI Core Api"
Cohesion: 0.06
Nodes (40): ApiAttendanceEmployee, ApiAttendancePolicySnap, ApiAttendanceReport, ApiBinding, ApiContract, ApiContractStatus, ApiContractType, ApiCurrency (+32 more)

### Community 19 - "UI Hr Settings Settings Devices"
Cohesion: 0.07
Nodes (7): ApiDevice, ApiQuarantine, clockOf(), dateOf(), Devices, Attendance, SettingsDevices

### Community 20 - "API Http Requests"
Cohesion: 0.06
Nodes (9): LeaveSettingsController, RehireEmployeeRequest, ScheduleEmploymentContractTerminationRequest, StoreEmploymentContractRequest, StoreLeaveBalanceEntryRequest, StoreLeavePolicyRequest, TerminateEmploymentContractRequest, {closure#1}() (+1 more)

### Community 21 - "UI Core Access"
Cohesion: 0.06
Nodes (32): Account, Action, actionNeeds, areaNeeds, AuditKind, grants(), holds(), isTop() (+24 more)

### Community 22 - "UI Finance"
Cohesion: 0.05
Nodes (4): ApiItem, ApiPage, Finance, Server

### Community 23 - "UI Pages Finance"
Cohesion: 0.11
Nodes (11): amount(), Money, PeriodStatus, TransactionStatus, FinanceTab, invoiceStatusKey, money2, periodStatusKey (+3 more)

### Community 24 - "UI Angular"
Cohesion: 0.05
Nodes (45): build, serve, test, builder, configurations, defaultConfiguration, options, cli (+37 more)

### Community 25 - "UI Finance Invoices"
Cohesion: 0.10
Nodes (4): FinInvoice, FinParty, blankLine(), FinanceInvoices

### Community 26 - "API Http Controllers 2"
Cohesion: 0.12
Nodes (5): {closure#1}(), LeaveRequestController, SubmitLeaveRequest, AttendanceAccess, HrScope

### Community 27 - "Illuminate\Contracts\Pagination\LengthAwarePaginator"
Cohesion: 0.10
Nodes (4): EmployeeJobHistoryService, {closure#1}(), {closure#8}(), EmployeeService

### Community 28 - ".log"
Cohesion: 0.08
Nodes (23): {closure#1}(), {closure#5}(), {closure#6}(), {closure#7}(), {closure#8}(), {closure#9}(), {closure#1}(), {closure#1}() (+15 more)

### Community 29 - "API Http Controllers 3"
Cohesion: 0.06
Nodes (12): AttendanceIngestController, DepartmentController, {closure#1}(), EmployeeController, {closure#1}(), EmployeeJobHistoryController, EmployeeRehireController, EmployeeTerminationController (+4 more)

### Community 30 - "UI App Data 5"
Cohesion: 0.13
Nodes (21): ApiAttendanceBundle, ApiAttendancePunch, BulkApproval, lineOf(), markOf(), needsAttendanceReview(), requiredMinutesOf(), RollLine (+13 more)

### Community 32 - "API Hr Services 3"
Cohesion: 0.06
Nodes (14): {closure#1}(), {closure#2}(), ProcessPendingDocumentCleanups, EmployeeDocumentController, EmployeeDocument, PendingDocumentCleanup, {closure#1}(), {closure#2}() (+6 more)

### Community 33 - "API Hr Services 4"
Cohesion: 0.15
Nodes (4): AttendanceLeaveSync, {closure#7}(), {closure#8}(), LeaveWorkflowService

### Community 34 - "User"
Cohesion: 0.04
Nodes (10): AttendancePunch, EmployeeTerminationService, RolePermissionSeeder, User, CurrencySeeder, ExampleTest, LeaveAttendanceFixture, EmployeeAccountSyncTest (+2 more)

### Community 35 - "UI Payroll Runs"
Cohesion: 0.09
Nodes (3): PayLineDetail, PayRunItem, PayrollRuns

### Community 36 - "UI App Data 6"
Cohesion: 0.19
Nodes (3): ApiAttendanceDay, AttendanceBook, reportKey()

### Community 37 - ".now"
Cohesion: 0.08
Nodes (18): up(), {closure#1}(), ConfigureAdmsDevice, AttendanceBinding, AttendanceDevice, AdmsDiagnostics, AdmsGateway, {closure#10}() (+10 more)

### Community 38 - "PayRun"
Cohesion: 0.23
Nodes (3): {closure#2}(), PayRunController, PayRun

### Community 41 - "UI Pages Requests"
Cohesion: 0.10
Nodes (11): RequestKind, flowTone(), LeaveTable, RequestFile, kindEffect, kindIcon, kindKey, requestKinds (+3 more)

### Community 42 - "API Cash Service"
Cohesion: 0.16
Nodes (11): {closure#6}(), Box, Transaction, CashService, {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}() (+3 more)

### Community 43 - "@angular/core"
Cohesion: 0.04
Nodes (53): App, appConfig, routes, Api, Query, serverMessage(), statusOf(), Auth (+45 more)

### Community 44 - "Illuminate\Database\Schema\Blueprint"
Cohesion: 0.10
Nodes (26): {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#2}(), {closure#3}(), {closure#4}() (+18 more)

### Community 45 - "JobTitle"
Cohesion: 0.21
Nodes (3): JobTitleController, JobTitle, JobTitleService

### Community 46 - "API Http Controllers 4"
Cohesion: 0.09
Nodes (3): SettingController, SettingService, GET /user()

### Community 47 - "API Finance Controller"
Cohesion: 0.08
Nodes (4): {closure#17}(), {closure#21}(), Item, StockMove

### Community 48 - "API Hr Services 5"
Cohesion: 0.21
Nodes (6): AttendanceCorrection, LeaveRequest, {closure#1}(), {closure#2}(), {closure#3}(), HrNotifier

### Community 49 - "UI Finance Ledger"
Cohesion: 0.09
Nodes (7): AccountType, cleanQuery(), FinAccount, FinEntry, FinPeriod, blank(), FinanceLedger

### Community 50 - "API Attendance Controller"
Cohesion: 0.10
Nodes (5): {closure#12}(), {closure#14}(), {closure#4}(), {closure#8}(), AttendanceDayResource

### Community 52 - "API Database Seeders"
Cohesion: 0.13
Nodes (4): AttendancePermissionSeeder, SystemAdminSeeder, SettingsSeeder, DatabaseSeeder

### Community 53 - "API Hr Services 6"
Cohesion: 0.18
Nodes (5): EmployeeServicePeriod, LeaveBalanceService, {closure#11}(), {closure#2}(), {closure#4}()

### Community 54 - "API Hr Services 7"
Cohesion: 0.12
Nodes (3): Department, {closure#1}(), DepartmentService

### Community 55 - "data/finance.ts"
Cohesion: 0.11
Nodes (18): EntryInput, FinBox, FinInvoiceLine, FinJournalLine, FinLimit, FinStockMove, InvoiceInput, InvoiceStatus (+10 more)

### Community 56 - "UI Finance Cash"
Cohesion: 0.10
Nodes (7): BoxKind, FinTransaction, TransactionInput, TransactionKind, FinanceCash, NewKind, Panel

### Community 57 - "API Package"
Cohesion: 0.13
Nodes (20): devDependencies, concurrently@^10.0.3, laravel-vite-plugin@^3.1, tailwindcss@^4.0.0, @tailwindcss/vite@^4.0.0, vite@^8.0.0, optionalDependencies, @laravel/multiplex@^0.4.1 (+12 more)

### Community 58 - "PayRunController.php"
Cohesion: 0.10
Nodes (4): {closure#13}(), {closure#5}(), PayPayment, PayRunLine

### Community 59 - "LedgerService"
Cohesion: 0.07
Nodes (16): {closure#1}(), Account, JournalEntry, Period, {closure#10}(), {closure#8}(), {closure#9}(), {closure#1}() (+8 more)

### Community 60 - "API Finance Services 3"
Cohesion: 0.12
Nodes (7): {closure#1}(), {closure#2}(), FinanceNotifier, HrNotification, {closure#4}(), SystemNotification, {closure#26}()

### Community 61 - "API Http Middleware"
Cohesion: 0.15
Nodes (3): AuthenticateAdmsDevice, AuthenticateAttendanceDevice, RequirePermission

### Community 62 - "EOMS Front_End/package.json"
Cohesion: 0.07
Nodes (40): dependencies, @angular/common@^22.2.0, @angular/compiler@^22.2.0, @angular/core@^22.2.0, @angular/forms@^22.2.0, @angular/platform-browser@^22.2.0, @angular/router@^22.2.0, @fontsource-variable/noto-kufi-arabic@^5.3.0 (+32 more)

### Community 63 - "UI Records"
Cohesion: 0.19
Nodes (3): Outcome, documentFrom(), Records

### Community 65 - "AuditLogger"
Cohesion: 0.07
Nodes (11): {closure#1}(), EmployeeRehireService, {closure#1}(), IdentityEmployeeDirectory, EmployeeDirectory, AuditLogger, {closure#1}(), {closure#2}() (+3 more)

### Community 66 - "API Settings Services"
Cohesion: 0.13
Nodes (4): {closure#13}(), ExchangeRate, {closure#2}(), ExchangeRateService

### Community 69 - "PayRunService.php"
Cohesion: 0.10
Nodes (6): PayRunItem, {closure#1}(), {closure#12}(), {closure#18}(), {closure#19}(), {closure#4}()

### Community 70 - "API Unit Hr"
Cohesion: 0.17
Nodes (4): LatenessCharge, ExampleTest, LatenessChargeTest, ModuleBoundariesTest

### Community 71 - "API Http Controllers 5"
Cohesion: 0.11
Nodes (4): AuthController, NotificationController, LoginRequest, AuthService

### Community 72 - "API Http Controllers 6"
Cohesion: 0.17
Nodes (5): {closure#1}(), UserController, StoreUserRequest, UpdateUserRequest, UserResource

### Community 73 - "Illuminate\Database\Migrations\Migration"
Cohesion: 0.09
Nodes (5): {closure#1}(), {closure#1}(), up(), {closure#1}(), {closure#1}()

### Community 76 - "Illuminate\Support\Facades\Schema"
Cohesion: 0.10
Nodes (5): {closure#1}(), {closure#1}(), {closure#1}(), {closure#1}(), {closure#1}()

### Community 78 - "LeaveRequestController.php"
Cohesion: 0.09
Nodes (3): Controller, {closure#13}(), {closure#18}()

### Community 79 - "API Http Resources 2"
Cohesion: 0.08
Nodes (5): {closure#1}(), StoreEmployeeDocumentRequest, EmployeeDocumentResource, EmployeeJobHistoryResource, OfficeHoliday

### Community 82 - "UI Finance Reports"
Cohesion: 0.17
Nodes (4): FinReport, ReportAccountRow, ReportKind, FinanceReports

### Community 85 - "Illuminate\Support\ServiceProvider"
Cohesion: 0.08
Nodes (8): FinanceServiceProvider, HrServiceProvider, NullEmployeeDirectory, UserPolicy, IdentityServiceProvider, PayrollServiceProvider, SettingsServiceProvider, AppServiceProvider

### Community 89 - "API Report Service"
Cohesion: 0.14
Nodes (5): Party, {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}()

### Community 90 - "Illuminate\Console\Command"
Cohesion: 0.13
Nodes (7): ConfigureAttendanceDefaults, {closure#1}(), ProcessLeaveBalances, RecalculateAttendance, ProtectEmployeeAccountProfile, UserProfileUpdating, CreateApiClientCommand

### Community 91 - "API App"
Cohesion: 0.20
Nodes (6): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}()

### Community 92 - "API Deptrac.yaml"
Cohesion: 0.14
Nodes (13): type, value, deptrac, layers, paths, ruleset, collectors, name (+5 more)

### Community 103 - "Invoice"
Cohesion: 0.23
Nodes (3): {closure#22}(), Invoice, InvoiceService

### Community 104 - "نظام الحضور - تحديث السماح وشرائح التأخير (V2)"
Cohesion: 0.12
Nodes (16): إدارة الجهاز والربط, إرسال الحركات من الوسيط, إعدادات الرواتب المستقبلية, التثبيت, الربط مع الإجازات والعطل الرسمية, القواعد المطبقة, بيئة التحقق من الحزمة, ترقية سياسة مستخدمة دون تغيير التاريخ (+8 more)

### Community 106 - "UI Core Reports"
Cohesion: 0.19
Nodes (8): exceljs@^4.4.0, Cell, columnLetter(), exportExcel(), widthOf(), download(), ReportSpec, exceljs

### Community 114 - "dossier.ts"
Cohesion: 0.36
Nodes (12): attachment(), DossierAttachment, DossierField, DossierSpec, printDossier(), section(), escape(), exportPdf() (+4 more)

### Community 116 - "API Add Employee Service Periods"
Cohesion: 0.25
Nodes (6): backfillKnownServiceDates(), {closure#1}(), {closure#2}(), {closure#3}(), {closure#5}(), up()

### Community 117 - "Product"
Cohesion: 0.15
Nodes (12): Accessibility & Inclusion, Brand Commitments, Capabilities and Constraints, Evidence on Hand, Operating Context, Platform, Positioning, Product (+4 more)

### Community 118 - "API Composer"
Cohesion: 0.22
Nodes (8): description, keywords, license, minimum-stability, name, prefer-stable, $schema, type

### Community 119 - "API Composer 2"
Cohesion: 0.22
Nodes (9): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, platform, preferred-install, sort-packages (+1 more)

### Community 120 - "API Composer 3"
Cohesion: 0.22
Nodes (9): scripts, dev, post-autoload-dump, post-create-project-cmd, post-root-package-install, post-update-cmd, pre-package-uninstall, setup (+1 more)

### Community 122 - "نظام الإجازات — مرجع الـAPI للفرونت"
Cohesion: 0.15
Nodes (11): التصحيح والاعتماد, الإشعارات (داخل التطبيق), الإعدادات (`leaves.manage`), التثبيت بعد السحب, التصدير, الربط مع الحضور والرواتب, الطلب ودورة حياته, القوائم والفلاتر (+3 more)

### Community 124 - "ربط EOMS بجهاز iFace 880 Plus عبر ADMS/PUSH"
Cohesion: 0.17
Nodes (10): 1. كيف يعمل الاتصال وأين نضع IP؟, 2. تثبيت التعديل, 3. أول اتصال محلي: نفس شبكة الجهاز والكمبيوتر, 4. التأكد من الاتصال, 5. تعديل الاتصال من الفرونت/Postman, 6. الخادم على الإنترنت: ترتيب الربط الصحيح, 7. ما الذي يستقبله الخادم؟, 8. تسجيل البصمة عن بُعد — تجربة اختيارية (+2 more)

### Community 125 - "وحدة المالية — مرجع الـAPI والقواعد"
Cohesion: 0.17
Nodes (11): الأساس المحاسبي, الأطراف والمواد والفواتير, الإشعارات, الإقفال الشهري, البداية, التقارير (عملة واحدة لكل تقرير), الصلاحيات, الصناديق والعمليات (+3 more)

### Community 126 - "API Composer 4"
Cohesion: 0.25
Nodes (8): require, dedoc/scramble, laravel/framework, laravel/sanctum, laravel/tinker, php, predis/predis, spatie/laravel-permission

### Community 127 - "API Composer 5"
Cohesion: 0.25
Nodes (8): require-dev, deptrac/deptrac, fakerphp/faker, laravel/pail, laravel/pint, mockery/mockery, nunomaduro/collision, phpunit/phpunit

### Community 128 - "API Create Permission Tables"
Cohesion: 0.25
Nodes (5): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}()

### Community 133 - "API Add Attendance Lateness Blocks"
Cohesion: 0.29
Nodes (5): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), up()

### Community 134 - "API Create Payroll Tables"
Cohesion: 0.29
Nodes (4): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}()

### Community 135 - "API Allow Voiding Pay Payments"
Cohesion: 0.29
Nodes (4): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}()

### Community 136 - "API Composer 6"
Cohesion: 0.29
Nodes (7): autoload, exclude-from-classmap, files, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 137 - "نظام الرواتب — مرجع الـAPI والقواعد"
Cohesion: 0.18
Nodes (10): الإشعارات, التثبيت, الدفع, الصلاحيات, العرض والتصدير, العقد, تسويات الأشهر المقفلة, دورة الكشف (+2 more)

### Community 141 - "UI Package 3"
Cohesion: 0.33
Nodes (6): scripts, build, ng, start, test, watch

### Community 144 - "API Create Jobs Table"
Cohesion: 0.33
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

### Community 147 - "UI Package 4"
Cohesion: 0.40
Nodes (5): vite, uuid, overrides, @angular/build, exceljs

### Community 165 - "اتفاقيات مشروع EOMS"
Cohesion: 0.22
Nodes (8): اتفاقيات مشروع EOMS, الاختبارات, البنية, التدفق, التدقيق, تسمية الصلاحيات, شكل الاستجابة (API), قواعد عامة

### Community 167 - "Eoms"
Cohesion: 0.25
Nodes (7): Additional Resources, Building, Code scaffolding, Development server, Eoms, Running end-to-end tests, Running unit tests

### Community 170 - "2026_11_05_191346_add_adms_gateway_tables.php"
Cohesion: 0.25
Nodes (5): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}()

### Community 174 - "API Composer 7"
Cohesion: 0.67
Nodes (3): autoload-dev, psr-4, Tests\\

### Community 175 - "API Composer 8"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

### Community 186 - "eos-backend/README.md"
Cohesion: 0.25
Nodes (7): About Laravel, Agentic Development, Code of Conduct, Contributing, Learning Laravel, License, Security Vulnerabilities

### Community 187 - "2026_11_08_000001_create_hr_leave_foundation.php"
Cohesion: 0.29
Nodes (5): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), up()

### Community 188 - "Dashboard"
Cohesion: 0.33
Nodes (5): Audience, Dashboard, Direction contract, Scope, Unresolved

### Community 189 - "Dashboard and every API-connected screen"
Cohesion: 0.33
Nodes (5): Audience, Dashboard and every API-connected screen, Direction contract, Scope, Unresolved

### Community 193 - "التحقق من إصلاح ربط الإجازات والحضور"
Cohesion: 0.33
Nodes (5): اختبارات آلية, التحقق من إصلاح ربط الإجازات والحضور, تجربة يدوية, ما تغيّر, نتيجة التحقق أثناء إعداد التحديث

### Community 194 - "copy-hr.ts"
Cohesion: 0.40
Nodes (4): ar, en, hrCopy, HrKey

### Community 198 - "Laravel Application"
Cohesion: 0.50
Nodes (3): Agent Setup, Laravel Application, Prerequisites

### Community 202 - "Laravel Application"
Cohesion: 0.50
Nodes (3): Agent Setup, Laravel Application, Prerequisites

## Knowledge Gaps
- **93 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Employee` connect `API Hr Models` to `API Http Controllers`, `PayRunService`, `LeaveType`, `API Services Attendance`, `API Employee Onboarding Test`, `API Attendance Test`, `API Employee Privacy Test`, `API Finance Models`, `API Employee Termination Test`, `API Http Requests`, `API Http Controllers 2`, `Illuminate\Contracts\Pagination\LengthAwarePaginator`, `.log`, `API Http Controllers 3`, `API Hr Services 3`, `API Hr Services 4`, `User`, `.now`, `API Leave Workflow Test`, `API Employee Rehire Test`, `API Hr Services 5`, `API Attendance Controller`, `API Hr Services 6`, `API Hr Services 7`, `PayRunController.php`, `PayrollCalculator`, `AuditLogger`, `PayRunService.php`, `API Http Resources`, `API Employee Documents Test`, `LeaveRequestController.php`, `API Http Resources 2`, `API Contract Job History Test`, `API Employee Job History Test`, `API Employment Contract Test`, `Illuminate\Console\Command`, `API Attendance Integration Test`?**
  _High betweenness centrality (0.059) - this node is a cross-community bridge._
- **Should `UI Office` be split into smaller, more focused modules?**
  _Cohesion score 0.0720658888126287 - nodes in this community are weakly interconnected._
- **Why does `Ui` connect `UI Pages Dashboard` to `UI Employee History`, `UI App Data 2`, `leaves/leaves.ts`, `UI App Core`, `AccessFile`, `UI Pages Leaves`, `UI Settings Office`, `UI Core Api`, `UI Hr Settings Settings Devices`, `UI Core Access`, `UI Pages Finance`, `UI App Data 5`, `UI Settings Leaves`, `PayrollFacts`, `Payroll`, `UI Pages Requests`, `@angular/core`, `PayrollFile`, `PurchaseTable`, `UI Payroll Run`, `EmployeeDocuments`, `UI Attendance Report`, `Rates`, `UI Dashboard Money`, `UI Employee Contracts`, `UI Leave File`, `UI Employees`, `AttendanceDay`, `UI Finger Dialog`, `UI Settings List`, `UI Leave`?**
  _High betweenness centrality (0.047) - this node is a cross-community bridge._
- **Should `UI Pages Dashboard` be split into smaller, more focused modules?**
  _Cohesion score 0.04721111745669142 - nodes in this community are weakly interconnected._
- **Why does `User` connect `User` to `API Role Escalation Test`, `API Adms Gateway Test`, `API Hr Models`, `PayRunService`, `API Services Attendance`, `API Attendance Test`, `API Finance Models`, `API Http Requests`, `API Http Controllers 2`, `Illuminate\Contracts\Pagination\LengthAwarePaginator`, `.log`, `API Hr Services 3`, `API Hr Services 4`, `PayRun`, `API Leave Workflow Test`, `API Employee Rehire Test`, `API Cash Service`, `API Finance Controller`, `API Hr Services 5`, `UserFactory`, `API Database Seeders`, `API Hr Services 6`, `PayRunController.php`, `LedgerService`, `API Finance Services 3`, `AuditLogger`, `API Finance Test`, `API Payroll Test`, `PayRunService.php`, `API Http Controllers 5`, `API Http Controllers 6`, `LeaveRequestController.php`, `API Contract Job History Test`, `Illuminate\Support\ServiceProvider`, `API Employee Job History Test`, `Illuminate\Console\Command`, `API Attendance Integration Test`, `API Identity Test`, `Invoice`?**
  _High betweenness centrality (0.034) - this node is a cross-community bridge._
- **Should `API Http Controllers` be split into smaller, more focused modules?**
  _Cohesion score 0.08202323991797676 - nodes in this community are weakly interconnected._
- **Should `leaves/leaves.ts` be split into smaller, more focused modules?**
  _Cohesion score 0.10668563300142248 - nodes in this community are weakly interconnected._