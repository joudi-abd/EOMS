# Graph Report - EOMS  (2026-11-10)

## Corpus Check
- Large corpus: 524 files · ~190,487 words. Semantic extraction will be expensive (many Claude tokens). Consider running on a subfolder.

## Summary
- 3900 nodes · 10227 edges · 185 communities (95 shown, 90 thin omitted)
- Extraction: 96% EXTRACTED · 4% INFERRED · 0% AMBIGUOUS · INFERRED: 398 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- UI Office
- UI Pages Dashboard
- API Http Controllers
- UI App Data
- UI App Data 2
- UI App Data 3
- UI App Core
- API Adms Gateway Test
- UI App Data 4
- API Hr Models
- API Pay Run Service
- API Hr Services
- API Services Attendance
- UI Pages Leaves
- API Attendance Test
- UI App Shell
- UI Pages Employees
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
- API Hr Services 2
- API Finance Services
- API Http Controllers 3
- UI App Data 5
- UI Settings Leaves
- API Hr Services 3
- API Hr Services 4
- API Feature Hr
- UI Payroll Runs
- UI App Data 6
- API Services Attendance 2
- API Pay Run Controller
- API Leave Workflow Test
- API Employee Rehire Test
- UI Pages Requests
- API Cash Service
- UI App Data 7
- API Database Migrations
- API Services Attendance 3
- API Http Controllers 4
- API Finance Controller
- API Hr Services 5
- UI Finance Ledger
- API Attendance Controller
- API Database Factories
- API Database Seeders
- API Hr Services 6
- API Hr Services 7
- API Hr Concerns
- UI Finance Cash
- API Package
- API Adms Gateway
- API Finance Services 2
- API Finance Services 3
- API Http Middleware
- UI Package
- UI Records
- API Payroll Calculator
- API User Service
- API Settings Services
- API Finance Test
- API Payroll Test
- UI Package 2
- API Unit Hr
- API Http Controllers 5
- API Http Controllers 6
- API Database Migrations 2
- API Http Resources
- API Employee Documents Test
- API Database Migrations 3
- API Leave Attendance Integration Test
- UI Rates
- API Http Resources 2
- API Contract Job History Test
- UI Finance Items
- UI Finance Reports
- UI Payroll Run
- UI Payroll
- API App Providers
- API Employee Job History Test
- API Employment Contract Test
- UI Attendance Report
- API Report Service
- API Console Commands
- API App
- API Deptrac.yaml
- API Pending Document Cleanup Test
- API Exchange Rate Test
- UI Dashboard Money
- API Attendance Integration Test
- API Leave Review Gaps Test
- API Identity Test
- UI Employee Contracts
- UI Leave File
- UI Employees
- API Expired Contract Test
- API Identity Contracts
- API Scheduled Contract Termination Test
- UI Core Reports
- UI Access
- API Report Service 2
- API Leave Attendance Overlap Test
- UI Leave Queue
- UI Finger Dialog
- UI Settings List
- UI Leave
- API Hr Providers
- API Create Hr Attendance Tables
- API Add Employee Service Periods
- API Create Hr Leave Workflows
- API Composer
- API Composer 2
- API Composer 3
- API Settings Test
- UI Finance Finance Ledger
- UI Shell
- API Backfill Leave Debit Entries
- API Employee Directory
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
- API Attendance Policy Migration Test
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
- API Create Hr Pending Document
- API Create Hr Adms Quarantined
- API Create Notifications Table
- API Audit Log
- API Create Sys Currencies Table
- API Create Sys Exchange Rates
- API Create Sys Api Clients
- API Create Personal Access Tokens
- API Create Sessions Table
- API Composer 7
- API Composer 8

## God Nodes (most connected - your core abstractions)
1. `User` - 155 edges
2. `Office` - 154 edges
3. `Employee` - 151 edges
4. `Ui` - 119 edges
5. `@angular/core` - 114 edges
6. `AdmsGatewayTest` - 70 edges
7. `AuditLogger` - 67 edges
8. `Session` - 63 edges
9. `CopyKey` - 63 edges
10. `TestCase` - 62 edges

## Surprising Connections (you probably didn't know these)
- `{closure#3}()` --calls--> `AuditLog`  [INFERRED]
  eos-backend/tests/Feature/HR/EmployeeDocumentsTest.php → eos-backend/app/Modules/Identity/Models/AuditLog.php
- `Metric` --references--> `CopyKey`  [EXTRACTED]
  EOMS Front_End/src/app/pages/dashboard/metrics/metrics.ts → EOMS Front_End/src/app/core/copy.ts
- `{closure#1}()` --references--> `User`  [EXTRACTED]
  eos-backend/app/Modules/Finance/Services/FinanceNotifier.php → eos-backend/app/Modules/Identity/Models/User.php
- `{closure#5}()` --references--> `EmploymentContract`  [EXTRACTED]
  eos-backend/app/Modules/Payroll/Services/PayrollCalculator.php → eos-backend/app/Modules/HR/Models/EmploymentContract.php
- `{closure#3}()` --references--> `User`  [EXTRACTED]
  eos-backend/app/Modules/HR/Services/HrNotifier.php → eos-backend/app/Modules/Identity/Models/User.php

## Import Cycles
- None detected.

## Communities (185 total, 90 thin omitted)

### Community 0 - "UI Office"
Cohesion: 0.06
Nodes (13): Action, isTop(), departmentFrom(), employeeFrom(), plain(), ref(), PayrollRun, stamp() (+5 more)

### Community 1 - "UI Pages Dashboard"
Cohesion: 0.05
Nodes (30): Ui, AccountsMix, AttendanceBlock, Dashboard, HrFlow, Insights, Metric, Metrics (+22 more)

### Community 2 - "API Http Controllers"
Cohesion: 0.08
Nodes (4): FinanceController, AdmsController, AttendanceSettingsController, LeaveSettingsController

### Community 3 - "UI App Data"
Cohesion: 0.05
Nodes (39): ReportColumn, ReportFigure, ReportSpec, ReportTable, stampNow(), ReportDraft, Reporter, HalfDays (+31 more)

### Community 4 - "UI App Data 2"
Cohesion: 0.06
Nodes (60): AuditEntry, roleFrom(), Adjustment, arrival, buildDay(), clock(), dayIn(), DayInput (+52 more)

### Community 5 - "UI App Data 3"
Cohesion: 0.05
Nodes (34): Api, Query, revealed(), saveFile(), apiBase, input, quote, ItemCategory (+26 more)

### Community 6 - "UI App Core"
Cohesion: 0.04
Nodes (29): base, copy, CopyKey, ar, designCopy, en, ar, en (+21 more)

### Community 8 - "UI App Data 4"
Cohesion: 0.05
Nodes (27): Session, ApiEmployee, loginMail(), loginUserRejected(), Employee, FingerprintState, base, statusFrom (+19 more)

### Community 9 - "API Hr Models"
Cohesion: 0.06
Nodes (12): {closure#1}(), ProcessExpiredContracts, {closure#1}(), {closure#3}(), EmploymentContractController, ScheduleEmploymentContractTerminationRequest, StoreEmploymentContractRequest, TerminateEmploymentContractRequest (+4 more)

### Community 10 - "API Pay Run Service"
Cohesion: 0.06
Nodes (20): {closure#13}(), {closure#5}(), PayPayment, PayRunItem, PayRunLine, {closure#1}(), {closure#10}(), {closure#11}() (+12 more)

### Community 11 - "API Hr Services"
Cohesion: 0.06
Nodes (26): {closure#1}(), {closure#1}(), LeaveBalanceEntry, LeavePolicy, OfficeHoliday, {closure#1}(), {closure#10}(), {closure#2}() (+18 more)

### Community 12 - "API Services Attendance"
Cohesion: 0.05
Nodes (17): {closure#1}(), {closure#1}(), RecalculateAttendance, {closure#12}(), {closure#4}(), AttendancePolicy, LeaveRequestDay, AttendanceCalculator (+9 more)

### Community 13 - "UI Pages Leaves"
Cohesion: 0.08
Nodes (3): LeaveRecord, LeaveRegister, LeavesPage

### Community 15 - "UI App Shell"
Cohesion: 0.08
Nodes (20): App, appConfig, routes, signedIn(), signedOut(), apiInterceptor(), auditKey, FinancePage (+12 more)

### Community 16 - "UI Pages Employees"
Cohesion: 0.10
Nodes (17): attachment(), DossierAttachment, DossierField, DossierSection, DossierSpec, printDossier(), section(), escape() (+9 more)

### Community 17 - "API Finance Models"
Cohesion: 0.06
Nodes (12): {closure#17}(), ApprovalLimit, InvoiceLine, Item, Party, StockMove, AttendanceUtcDateTime, AttendanceEnrollment (+4 more)

### Community 18 - "UI Core Api"
Cohesion: 0.07
Nodes (29): statusOf(), Auth, ApiAttendanceEmployee, ApiAttendancePolicySnap, ApiContract, ApiContractStatus, ApiContractType, ApiCurrency (+21 more)

### Community 19 - "UI Hr Settings Settings Devices"
Cohesion: 0.07
Nodes (8): ApiBinding, ApiDevice, clockOf(), dateOf(), Devices, Attendance, ReportView, SettingsDevices

### Community 20 - "API Http Requests"
Cohesion: 0.05
Nodes (10): RehireEmployeeRequest, StoreEmployeeDocumentRequest, StoreLeaveBalanceEntryRequest, StoreLeavePolicyRequest, TerminateEmployeeRequest, {closure#1}(), UpdateEmployeeRequest, LoginRequest (+2 more)

### Community 21 - "UI Core Access"
Cohesion: 0.08
Nodes (26): Account, actionNeeds, areaNeeds, AuditKind, grants(), holds(), Permission, permissionIds (+18 more)

### Community 22 - "UI Finance"
Cohesion: 0.07
Nodes (3): ApiItem, ApiPage, Finance

### Community 23 - "UI Pages Finance"
Cohesion: 0.06
Nodes (25): BoxKind, EntryInput, FinBox, FinInvoiceLine, FinJournalLine, FinLimit, FinStockMove, InvoiceStatus (+17 more)

### Community 24 - "UI Angular"
Cohesion: 0.05
Nodes (44): build, serve, test, builder, configurations, defaultConfiguration, options, cli (+36 more)

### Community 25 - "UI Finance Invoices"
Cohesion: 0.07
Nodes (12): FinInvoice, FinParty, InvoiceInput, InvoiceType, PartyKind, today(), blankLine(), DraftLine (+4 more)

### Community 26 - "API Http Controllers 2"
Cohesion: 0.06
Nodes (6): Controller, {closure#18}(), LeaveRequestController, SubmitLeaveRequest, AttendanceAccess, HrScope

### Community 27 - "API Hr Services 2"
Cohesion: 0.06
Nodes (8): EmployeeJobHistoryController, EmployeeJobHistory, EmployeeJobHistoryService, {closure#1}(), {closure#1}(), {closure#8}(), EmployeeService, EmployeeTerminationService

### Community 28 - "API Finance Services"
Cohesion: 0.06
Nodes (16): {closure#1}(), Account, JournalLine, Period, {closure#8}(), {closure#2}(), {closure#3}(), {closure#5}() (+8 more)

### Community 29 - "API Http Controllers 3"
Cohesion: 0.09
Nodes (8): AttendanceIngestController, {closure#1}(), EmployeeController, EmployeeRehireController, EmployeeTerminationController, StoreEmployeeRequest, EmployeeResource, ApiResponse

### Community 30 - "UI App Data 5"
Cohesion: 0.12
Nodes (25): ApiAttendanceBundle, ApiAttendancePunch, ApiAttendanceReport, ApiQuarantine, BulkApproval, lineOf(), markOf(), needsAttendanceReview() (+17 more)

### Community 32 - "API Hr Services 3"
Cohesion: 0.09
Nodes (13): {closure#1}(), {closure#2}(), ProcessPendingDocumentCleanups, EmployeeDocumentController, EmployeeDocument, PendingDocumentCleanup, {closure#1}(), {closure#2}() (+5 more)

### Community 33 - "API Hr Services 4"
Cohesion: 0.14
Nodes (7): {closure#1}(), {closure#13}(), LeaveRequest, {closure#4}(), {closure#7}(), {closure#8}(), LeaveWorkflowService

### Community 34 - "API Feature Hr"
Cohesion: 0.12
Nodes (5): RolePermissionSeeder, Currency, ExampleTest, EmployeeAccountSyncTest, TestCase

### Community 35 - "UI Payroll Runs"
Cohesion: 0.09
Nodes (3): PayRunItem, PayRunLine, PayrollRuns

### Community 36 - "UI App Data 6"
Cohesion: 0.09
Nodes (5): ApiAttendanceCorrection, ApiAttendanceDay, AttendanceBook, reportKey(), AttendanceDay

### Community 37 - "API Services Attendance 2"
Cohesion: 0.11
Nodes (6): {closure#1}(), ConfigureAdmsDevice, AttendanceBinding, AttendanceDevice, AdmsGateway, AttendanceDeviceService

### Community 38 - "API Pay Run Controller"
Cohesion: 0.10
Nodes (4): {closure#2}(), PayRunController, PayRun, CsvResponse

### Community 41 - "UI Pages Requests"
Cohesion: 0.10
Nodes (10): RequestKind, flowTone(), LeaveTable, RequestFile, kindEffect, kindIcon, kindKey, requestKinds (+2 more)

### Community 42 - "API Cash Service"
Cohesion: 0.14
Nodes (13): {closure#6}(), Box, Transaction, CashService, {closure#10}(), {closure#2}(), {closure#3}(), {closure#4}() (+5 more)

### Community 43 - "UI App Data 7"
Cohesion: 0.10
Nodes (16): serverMessage(), ApiEnrollmentView, Enrollment, EnrollState, EnrollStatus, idle, live, OpenBinding (+8 more)

### Community 44 - "API Database Migrations"
Cohesion: 0.11
Nodes (23): {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#2}(), {closure#3}(), {closure#4}() (+15 more)

### Community 45 - "API Services Attendance 3"
Cohesion: 0.10
Nodes (7): JobTitleController, JobTitle, AdmsQuarantineService, {closure#1}(), {closure#2}(), AttendanceImportService, JobTitleService

### Community 46 - "API Http Controllers 4"
Cohesion: 0.07
Nodes (4): ExchangeRateController, SettingController, SettingService, GET /user()

### Community 47 - "API Finance Controller"
Cohesion: 0.08
Nodes (4): {closure#21}(), {closure#22}(), Invoice, InvoiceService

### Community 48 - "API Hr Services 5"
Cohesion: 0.14
Nodes (5): AttendanceCorrection, AttendanceDay, {closure#1}(), {closure#3}(), HrNotifier

### Community 49 - "UI Finance Ledger"
Cohesion: 0.11
Nodes (6): AccountType, FinAccount, FinEntry, FinPeriod, blank(), FinanceLedger

### Community 50 - "API Attendance Controller"
Cohesion: 0.10
Nodes (5): AttendanceController, {closure#14}(), {closure#8}(), AttendanceDayResource, AttendanceReviewService

### Community 51 - "API Database Factories"
Cohesion: 0.10
Nodes (6): {closure#1}(), {closure#2}(), UserFactory, UserProfileUpdating, User, UserPolicy

### Community 52 - "API Database Seeders"
Cohesion: 0.11
Nodes (5): AttendancePermissionSeeder, SystemAdminSeeder, CurrencySeeder, SettingsSeeder, DatabaseSeeder

### Community 53 - "API Hr Services 6"
Cohesion: 0.14
Nodes (4): EmployeeServicePeriod, LeaveBalanceService, {closure#11}(), {closure#2}()

### Community 54 - "API Hr Services 7"
Cohesion: 0.15
Nodes (4): DepartmentController, Department, {closure#1}(), DepartmentService

### Community 56 - "UI Finance Cash"
Cohesion: 0.16
Nodes (3): cleanQuery(), FinTransaction, FinanceCash

### Community 57 - "API Package"
Cohesion: 0.13
Nodes (20): devDependencies, concurrently@^10.0.3, laravel-vite-plugin@^3.1, tailwindcss@^4.0.0, @tailwindcss/vite@^4.0.0, vite@^8.0.0, optionalDependencies, @laravel/multiplex@^0.4.1 (+12 more)

### Community 58 - "API Adms Gateway"
Cohesion: 0.23
Nodes (11): up(), {closure#10}(), {closure#11}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}() (+3 more)

### Community 59 - "API Finance Services 2"
Cohesion: 0.15
Nodes (8): JournalEntry, {closure#1}(), {closure#2}(), {closure#4}(), {closure#5}(), {closure#6}(), {closure#7}(), {closure#1}()

### Community 60 - "API Finance Services 3"
Cohesion: 0.12
Nodes (7): {closure#1}(), {closure#2}(), FinanceNotifier, HrNotification, {closure#4}(), SystemNotification, {closure#26}()

### Community 61 - "API Http Middleware"
Cohesion: 0.13
Nodes (4): AuthenticateAdmsDevice, AuthenticateAttendanceDevice, AdmsDiagnostics, RequirePermission

### Community 62 - "UI Package"
Cohesion: 0.14
Nodes (19): devDependencies, @angular/build@^22.2.4, @angular/cli@^22.2.4, @angular/compiler-cli@^22.2.0, jsdom@^30.0.0, prettier@^3.8.1, typescript@~6.0.2, vitest@^5.0.0 (+11 more)

### Community 63 - "UI Records"
Cohesion: 0.21
Nodes (3): Outcome, documentFrom(), Records

### Community 64 - "API Payroll Calculator"
Cohesion: 0.15
Nodes (3): PayrollInputService, {closure#5}(), PayrollCalculator

### Community 65 - "API User Service"
Cohesion: 0.15
Nodes (5): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), UserService

### Community 66 - "API Settings Services"
Cohesion: 0.13
Nodes (3): ExchangeRate, {closure#2}(), ExchangeRateService

### Community 69 - "UI Package 2"
Cohesion: 0.11
Nodes (19): dependencies, @angular/common@^22.2.0, @angular/compiler@^22.2.0, @angular/core@^22.2.0, @angular/forms@^22.2.0, @angular/platform-browser@^22.2.0, @angular/router@^22.2.0, @fontsource-variable/readex-pro@^5.3.0 (+11 more)

### Community 70 - "API Unit Hr"
Cohesion: 0.17
Nodes (4): LatenessCharge, ExampleTest, LatenessChargeTest, ModuleBoundariesTest

### Community 71 - "API Http Controllers 5"
Cohesion: 0.15
Nodes (3): AuthController, NotificationController, AuthService

### Community 72 - "API Http Controllers 6"
Cohesion: 0.18
Nodes (4): {closure#1}(), UserController, UpdateUserRequest, UserResource

### Community 73 - "API Database Migrations 2"
Cohesion: 0.11
Nodes (5): {closure#1}(), {closure#1}(), {closure#1}(), {closure#2}(), {closure#1}()

### Community 76 - "API Database Migrations 3"
Cohesion: 0.12
Nodes (4): {closure#1}(), {closure#1}(), {closure#1}(), {closure#1}()

### Community 79 - "API Http Resources 2"
Cohesion: 0.14
Nodes (4): {closure#1}(), {closure#1}(), EmployeeDocumentResource, EmployeeJobHistoryResource

### Community 81 - "UI Finance Items"
Cohesion: 0.19
Nodes (3): FinItem, FinanceItems, Panel

### Community 82 - "UI Finance Reports"
Cohesion: 0.17
Nodes (4): FinReport, ReportAccountRow, ReportKind, FinanceReports

### Community 85 - "API App Providers"
Cohesion: 0.20
Nodes (4): FinanceServiceProvider, PayrollServiceProvider, SettingsServiceProvider, AppServiceProvider

### Community 89 - "API Report Service"
Cohesion: 0.18
Nodes (4): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}()

### Community 90 - "API Console Commands"
Cohesion: 0.18
Nodes (5): {closure#1}(), ProcessLeaveBalances, {closure#1}(), ProcessScheduledContractTerminations, CreateApiClientCommand

### Community 91 - "API App"
Cohesion: 0.20
Nodes (6): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}()

### Community 92 - "API Deptrac.yaml"
Cohesion: 0.14
Nodes (13): type, value, deptrac, layers, paths, ruleset, collectors, name (+5 more)

### Community 106 - "UI Core Reports"
Cohesion: 0.27
Nodes (7): exceljs@^4.4.0, Cell, columnLetter(), exportExcel(), widthOf(), download(), exceljs

### Community 114 - "API Hr Providers"
Cohesion: 0.22
Nodes (3): ConfigureAttendanceDefaults, ProtectEmployeeAccountProfile, HrServiceProvider

### Community 115 - "API Create Hr Attendance Tables"
Cohesion: 0.22
Nodes (7): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}(), up()

### Community 116 - "API Add Employee Service Periods"
Cohesion: 0.25
Nodes (6): backfillKnownServiceDates(), {closure#1}(), {closure#2}(), {closure#3}(), {closure#5}(), up()

### Community 117 - "API Create Hr Leave Workflows"
Cohesion: 0.22
Nodes (6): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}()

### Community 118 - "API Composer"
Cohesion: 0.22
Nodes (8): description, keywords, license, minimum-stability, name, prefer-stable, $schema, type

### Community 119 - "API Composer 2"
Cohesion: 0.22
Nodes (9): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, platform, preferred-install, sort-packages (+1 more)

### Community 120 - "API Composer 3"
Cohesion: 0.22
Nodes (9): scripts, dev, post-autoload-dump, post-create-project-cmd, post-root-package-install, post-update-cmd, pre-package-uninstall, setup (+1 more)

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

### Community 141 - "UI Package 3"
Cohesion: 0.33
Nodes (6): scripts, build, ng, start, test, watch

### Community 144 - "API Create Jobs Table"
Cohesion: 0.33
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

### Community 147 - "UI Package 4"
Cohesion: 0.40
Nodes (5): vite, uuid, overrides, @angular/build, exceljs

### Community 174 - "API Composer 7"
Cohesion: 0.67
Nodes (3): autoload-dev, psr-4, Tests\\

### Community 175 - "API Composer 8"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

## Knowledge Gaps
- **90 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Employee` connect `API Hr Models` to `API Http Controllers`, `API Attendance Policy Migration Test`, `API Pay Run Service`, `API Hr Services`, `API Services Attendance`, `API Employee Onboarding Test`, `API Attendance Test`, `API Employee Privacy Test`, `API Finance Models`, `API Employee Termination Test`, `API Http Requests`, `API Http Controllers 2`, `API Hr Services 2`, `API Finance Services`, `API Http Controllers 3`, `API Hr Services 3`, `API Hr Services 4`, `API Feature Hr`, `API Services Attendance 2`, `API Pay Run Controller`, `API Leave Workflow Test`, `API Employee Rehire Test`, `API Hr Services 5`, `API Attendance Controller`, `API Hr Services 6`, `API Hr Services 7`, `API Hr Concerns`, `API Adms Gateway`, `API Payroll Calculator`, `API Http Resources`, `API Employee Documents Test`, `API Http Resources 2`, `API Contract Job History Test`, `API Employee Job History Test`, `API Employment Contract Test`, `API Console Commands`, `API Attendance Integration Test`, `API Leave Review Gaps Test`, `API Adms Gateway Test 2`, `API Backfill Leave Debit Entries`?**
  _High betweenness centrality (0.059) - this node is a cross-community bridge._
- **Should `UI Office` be split into smaller, more focused modules?**
  _Cohesion score 0.05970924195223261 - nodes in this community are weakly interconnected._
- **Why does `Ui` connect `UI Pages Dashboard` to `UI Office`, `UI Employee History`, `UI App Data 2`, `UI App Data 3`, `UI App Core`, `UI App Data`, `UI App Data 4`, `UI Pages Leaves`, `UI Settings Office`, `UI App Shell`, `UI Pages Employees`, `UI Hr Settings Settings Devices`, `UI Core Access`, `UI Pages Finance`, `UI App Data 5`, `UI Settings Leaves`, `UI App Data 6`, `UI Pages Requests`, `UI App Data 7`, `UI Rates`, `UI Payroll Run`, `UI Attendance Report`, `UI Dashboard Money`, `UI Employee Contracts`, `UI Leave File`, `UI Employees`, `UI Finger Dialog`, `UI Settings List`, `UI Leave`?**
  _High betweenness centrality (0.051) - this node is a cross-community bridge._
- **Should `UI Pages Dashboard` be split into smaller, more focused modules?**
  _Cohesion score 0.05241228070175439 - nodes in this community are weakly interconnected._
- **Why does `User` connect `API Database Factories` to `API Role Escalation Test`, `API Adms Gateway Test`, `API Hr Models`, `API Pay Run Service`, `API Services Attendance`, `API Attendance Test`, `API Finance Models`, `API Http Controllers 2`, `API Hr Services 2`, `API Finance Services`, `API Hr Services 3`, `API Hr Services 4`, `API Feature Hr`, `API Services Attendance 2`, `API Pay Run Controller`, `API Leave Workflow Test`, `API Employee Rehire Test`, `API Cash Service`, `API Finance Controller`, `API Hr Services 5`, `API Attendance Controller`, `API Database Seeders`, `API Hr Services 6`, `API Hr Services 7`, `API Hr Concerns`, `API Finance Services 2`, `API Finance Services 3`, `API User Service`, `API Settings Services`, `API Finance Test`, `API Payroll Test`, `API Http Controllers 5`, `API Http Controllers 6`, `API Contract Job History Test`, `API Employee Job History Test`, `API Attendance Integration Test`, `API Identity Test`, `API Identity Contracts`, `API Adms Gateway Test 2`, `API Backfill Leave Debit Entries`?**
  _High betweenness centrality (0.044) - this node is a cross-community bridge._
- **Should `API Http Controllers` be split into smaller, more focused modules?**
  _Cohesion score 0.07949412827461608 - nodes in this community are weakly interconnected._
- **Should `UI App Data` be split into smaller, more focused modules?**
  _Cohesion score 0.045987654320987656 - nodes in this community are weakly interconnected._