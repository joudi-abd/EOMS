# Graph Report - eos-backend  (2026-11-17)

## Corpus Check
- 391 files · ~147,875 words
- Verdict: corpus is large enough that graph structure adds value.
- Unclassified: 22 file(s) not represented in the graph (top: (none) 17, .cache 1, .example 1)

## Summary
- 3506 nodes · 9000 edges · 204 communities (103 shown, 101 thin omitted)
- Extraction: 92% EXTRACTED · 8% INFERRED · 0% AMBIGUOUS · INFERRED: 701 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- Attendance Days & Corrections
- Employee Management
- Controller Endpoints (mixed)
- ADMS Gateway Tests
- Leave Policies & Workflow Tests
- Service Providers & HR Commands
- Permissions Seeding & Test Base
- App Bootstrap & Support
- Finance & HR Model Casts
- Finance & Tasks Migrations
- Attendance Recalculation
- Identity & HR Controllers
- Users & Auth
- Task Card Activity & Attachments
- Attendance Bindings & Punches
- Card Assignees & Labels
- Employee Documents
- Finance Ledger Core
- Contract Expiry & Termination
- Recurring Cards & Reminders
- Task Boards & Members
- Payroll Tests
- Finance Controller
- Board Access
- Attendance Hardening Tests
- Employee Rehire Tests
- Module Routes
- Cash Service & Transactions
- Payroll Calculation
- Invoices & Inventory
- Document Cleanup
- Termination & Rehire Requests
- Sham Cash Export
- Employment Contracts
- Access Resolution
- Finance Tests
- HR & Payroll Migrations
- Attendance Access & Export
- Attendance Leave Sync
- Frontend Integration Guide
- Laravel Cloud Checklists
- Finance Controller Closures
- Notifications
- Leave Balance Ledger
- HR & Settings Migrations
- Pay Run Controller
- Card Controller
- ADMS Gateway
- Departments
- Convention Detection Checklist
- Leave Backfill Migrations
- Exchange Rates
- Board Change Broadcasting
- JS Build Tooling
- Tailwind & Project Guidelines
- Ledger & Device Timezone
- Employee Directory Contract
- Role Service
- User Service
- Pay Run Endpoints
- Stock Items
- ADMS Device Config
- Service Periods & Leave Balance
- Permission Catalog
- Pay Run Service
- Caching & Locks
- Attendance Controller
- Form Requests
- Leave Request Controller
- Employee Documents Tests
- Leave-Attendance Integration Tests
- Parties & Finance Reports
- Device Auth Middleware
- Access Tests
- Pay Run Items
- Routing Best Practices
- Job Titles
- Release Tasks On Termination
- Finance API Docs
- Contract Job History Tests
- Advanced Queries
- HTTP Client Practices
- Config Practices
- Eloquent Practices
- Events & Notifications Practices
- Attendance Settings
- Access Sync
- Deptrac Boundaries
- Leave & Tasks Docs
- EmployeeJobHistoryTest
- EmploymentContractTest
- isolation
- SystemAdminSeeder
- UserController
- AccountHardeningTest
- ExchangeRateTest
- SKILL
- architecture
- endpoint-tests
- test-data
- create payroll tables
- CONVENTIONS
- AttendanceIntegrationTest
- PendingDocumentCleanupTest
- IdentityTest
- SettingController
- PERMISSIONS AR
- ScheduledContractTerminationTest
- TasksFixture
- assertions
- finance requested changes
- ReportService
- ATTENDANCE API AR
- ExpiredContractTest
- create hr attendance tables
- add employee service periods and binding
- create hr leave workflows
- UpdateEmployeeRequest
- composer
- composer
- composer
- composer
- ADMS SETUP AR
- ATTENDANCE API AR
- SettingsTest
- error-handling
- blade-views
- CsvResponse
- EmployeeDocumentResource
- PayrollCalculator
- composer
- create permission tables
- PAYROLL API AR
- EmployeeHardeningTest
- RoleEscalationTest
- TaskHardeningTest
- collections
- create hr leave foundation
- leave approval levels
- UserFactory
- scoped role permissions
- allow voiding pay payments
- PayrollCalculator
- composer
- TASKS API AR
- EmployeeOnboardingTest
- EmployeePrivacyTest
- ExpiringContractsTest
- LeaveSettingsTest
- TaskAutomationTest
- PayPayment
- EmployeeTerminationTest
- AGENTS
- add submitted by to fin invoices
- add reason to hr employee job histories 
- add scheduled termination to hr employme
- add father name and termination reason t
- add contract context to employee job his
- add leave context to hr attendance days
- retire employee on leave status
- add manager to hr departments
- backfill leave debit entries
- add entered by to hr leave requests
- add pay type to hr employment contracts
- leave request day inactive reason
- ScheduleEmploymentContractTerminationReq
- StoreEmployeeDocumentRequest
- StoreLeavePolicyRequest
- link pay payments to finance
- one active payment per line
- ReminderService
- create hr departments table
- create hr pending document cleanups tabl
- create usr users table
- create usr audit logs table
- create notifications table
- create sys exchange rates table
- create personal access tokens table
- create sessions table
- composer
- composer
- .mcp
- robots

## God Nodes (most connected - your core abstractions)
1. `User` - 262 edges
2. `Employee` - 167 edges
3. `AuditLogger` - 88 edges
4. `TestCase` - 76 edges
5. `Board` - 72 edges
6. `Card` - 72 edges
7. `AdmsGatewayTest` - 71 edges
8. `AttendanceTest` - 60 edges
9. `LeaveWorkflowTest` - 55 edges
10. `CardService` - 52 edges

## Surprising Connections (you probably didn't know these)
- `PermissionCatalog` --implements--> `Permission Naming module.action`  [INFERRED]
  docs/PERMISSIONS_AR.md → CONVENTIONS.md
- `Consistency first (project test conventions take precedence)` --semantically_similar_to--> `Follow existing code conventions`  [INFERRED] [semantically similar]
  .claude/skills/testing-best-practices/SKILL.md → CLAUDE.md
- `Write a feature test first` --semantically_similar_to--> `Test enforcement rules`  [INFERRED] [semantically similar]
  .claude/skills/testing-best-practices/SKILL.md → CLAUDE.md
- `Tasks boards module (viewer < member < owner)` --semantically_similar_to--> `Tenant isolation: 404 rather than 403`  [INFERRED] [semantically similar]
  docs/FRONTEND_INTEGRATION_AR.md → .claude/skills/testing-best-practices/rules/endpoint-tests.md
- `{closure#3}()` --calls--> `AuditLog`  [INFERRED]
  tests/Feature/HR/EmployeeDocumentsTest.php → app/Modules/Identity/Models/AuditLog.php

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Device Punch Ingestion and Sync Completeness** — docs_adms_setup_ar_iclock_endpoints, docs_attendance_api_ar_device_ingest, docs_adms_setup_ar_confirm_sync, docs_attendance_api_ar_coverage_complete [EXTRACTED 1.00]
- **Attendance Approval to Payroll to Finance Payment Pipeline** — docs_attendance_api_ar_day_approval, docs_attendance_api_ar_payroll_inputs, docs_payroll_api_ar_payroll_run_lifecycle, docs_payroll_api_ar_salary_payment, docs_finance_api_ar_boxes_transactions [INFERRED 0.85]
- **Department Manager Scoped Access Across Attendance, Leaves and Permissions** — docs_attendance_api_ar_department_manager_scope, docs_leaves_api_ar_department_manager, docs_permissions_ar_inferred_department_manager, docs_permissions_ar_grant_scopes [INFERRED 0.85]
- **After-commit dispatch pattern for events, notifications and mail** — _claude_skills_laravel_best_practices_rules_events_notifications_should_dispatch_after_commit, _claude_skills_laravel_best_practices_rules_events_notifications_notification_after_commit, _claude_skills_laravel_best_practices_rules_mail_mail_after_commit [INFERRED 0.85]
- **Cache-lock-based coordination (locks, unique jobs, onOneServer)** — _claude_skills_laravel_best_practices_rules_architecture_atomic_locks, _claude_skills_laravel_best_practices_rules_queue_jobs_unique_jobs, _claude_skills_laravel_best_practices_rules_scheduling_on_one_server, _claude_skills_laravel_best_practices_rules_caching_cache_add [INFERRED 0.85]
- **Large dataset iteration strategies** — _claude_skills_laravel_best_practices_rules_db_performance_chunking_large_datasets, _claude_skills_laravel_best_practices_rules_collections_cursor_vs_lazy, _claude_skills_laravel_best_practices_rules_collections_lazy_by_id [INFERRED 0.85]
- **Authorization and tenant boundary testing** — _claude_skills_testing_best_practices_rules_endpoint_tests_tenant_isolation_404, _claude_skills_testing_best_practices_rules_endpoint_tests_policy_level_authorization, _claude_skills_testing_best_practices_rules_security_cross_tenant_access, _claude_skills_testing_best_practices_rules_review_coverage_checklist [INFERRED 0.85]
- **Test determinism controls (time, sleep, HTTP, data)** — _claude_skills_testing_best_practices_rules_isolation_time_and_randomness, _claude_skills_testing_best_practices_rules_isolation_prevent_stray_requests, _claude_skills_testing_best_practices_rules_performance_global_fakes, _claude_skills_testing_best_practices_rules_review_data_determinism_checklist [INFERRED 0.85]
- **Four-eyes (no self-approval) rule across EOMS modules** — docs_frontend_integration_ar_four_eyes_rule, docs_frontend_integration_ar_finance_module, docs_frontend_integration_ar_payroll_module, docs_frontend_integration_ar_attendance_module [EXTRACTED 1.00]

## Communities (204 total, 101 thin omitted)

### Community 0 - "Attendance Days & Corrections"
Cohesion: 0.07
Nodes (5): AttendanceCorrection, AttendanceDay, {closure#1}(), HrNotifier, AttendanceTest

### Community 1 - "Employee Management"
Cohesion: 0.04
Nodes (14): {closure#1}(), EmployeeController, EmployeeJobHistoryController, EmployeeTerminationController, EmployeeResource, EmployeeJobHistory, EmployeeJobHistoryService, EmployeeRehireService (+6 more)

### Community 2 - "Controller Endpoints (mixed)"
Cohesion: 0.07
Nodes (3): AttendanceController, LeaveSettingsController, BoardController

### Community 4 - "Leave Policies & Workflow Tests"
Cohesion: 0.08
Nodes (5): LeavePolicy, LeaveType, {closure#9}(), LeaveSettingsService, LeaveWorkflowTest

### Community 5 - "Service Providers & HR Commands"
Cohesion: 0.05
Nodes (18): FinanceServiceProvider, ConfigureAttendanceDefaults, ProcessLeaveBalances, EmployeeTerminating, ProtectEmployeeAccountProfile, HrServiceProvider, {closure#1}(), SyncPermissions (+10 more)

### Community 6 - "Permissions Seeding & Test Base"
Cohesion: 0.08
Nodes (5): RolePermissionSeeder, Currency, ExampleTest, EmployeeAccountSyncTest, TestCase

### Community 7 - "App Bootstrap & Support"
Cohesion: 0.06
Nodes (14): LatenessCharge, ConcurrencyErrors, {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}() (+6 more)

### Community 8 - "Finance & HR Model Casts"
Cohesion: 0.05
Nodes (15): ApprovalLimit, InvoiceAttachment, InvoiceLine, JournalLine, {closure#3}(), AttendanceUtcDateTime, AttendanceEnrollment, LeaveCycle (+7 more)

### Community 9 - "Finance & Tasks Migrations"
Cohesion: 0.07
Nodes (38): {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#2}(), {closure#3}(), {closure#4}() (+30 more)

### Community 10 - "Attendance Recalculation"
Cohesion: 0.07
Nodes (15): {closure#1}(), {closure#1}(), RecalculateAttendance, {closure#12}(), {closure#4}(), AttendancePolicy, AttendanceCalculator, {closure#1}() (+7 more)

### Community 11 - "Identity & HR Controllers"
Cohesion: 0.07
Nodes (7): AttendanceIngestController, EmployeeRehireController, AccessController, AuthController, NotificationController, AuthService, ApiResponse

### Community 12 - "Users & Auth"
Cohesion: 0.13
Nodes (3): User, Card, CardService

### Community 13 - "Task Card Activity & Attachments"
Cohesion: 0.05
Nodes (10): Activity, Attachment, Checklist, ChecklistItem, Comment, {closure#12}(), {closure#13}(), {closure#14}() (+2 more)

### Community 14 - "Attendance Bindings & Punches"
Cohesion: 0.08
Nodes (8): AttendanceBinding, AttendancePunch, AdmsQuarantineService, {closure#1}(), {closure#2}(), AttendanceClock, AttendanceImportService, {closure#1}()

### Community 16 - "Card Assignees & Labels"
Cohesion: 0.13
Nodes (18): {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#14}(), {closure#15}(), {closure#16}() (+10 more)

### Community 17 - "Employee Documents"
Cohesion: 0.09
Nodes (9): {closure#1}(), EmployeeDocumentController, Employee, EmployeeDocument, EmployeeFingerprintStatus, {closure#1}(), {closure#2}(), EmployeeDocumentService (+1 more)

### Community 18 - "Finance Ledger Core"
Cohesion: 0.07
Nodes (12): {closure#1}(), Account, JournalEntry, Period, {closure#8}(), {closure#1}(), {closure#2}(), {closure#6}() (+4 more)

### Community 19 - "Contract Expiry & Termination"
Cohesion: 0.10
Nodes (11): {closure#1}(), ProcessExpiredContracts, {closure#1}(), ProcessScheduledContractTerminations, {closure#1}(), {closure#3}(), EmploymentContractController, StoreEmploymentContractRequest (+3 more)

### Community 20 - "Recurring Cards & Reminders"
Cohesion: 0.11
Nodes (7): Recurrence, {closure#1}(), {closure#2}(), RecurrenceService, ReminderService, TaskQueries, WorkCalendar

### Community 21 - "Task Boards & Members"
Cohesion: 0.10
Nodes (15): {closure#1}(), BoardMember, Label, TaskList, BoardService, {closure#1}(), {closure#10}(), {closure#11}() (+7 more)

### Community 24 - "Board Access"
Cohesion: 0.11
Nodes (8): Board, BoardAccess, {closure#3}(), {closure#5}(), {closure#6}(), {closure#6}(), {closure#3}(), {closure#1}()

### Community 25 - "Attendance Hardening Tests"
Cohesion: 0.08
Nodes (4): AttendanceHardeningTest, LeaveAttendanceFixture, LeaveAttendanceOverlapTest, LeaveReviewGapsTest

### Community 28 - "Cash Service & Transactions"
Cohesion: 0.15
Nodes (12): Transaction, CashService, {closure#1}(), {closure#10}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}() (+4 more)

### Community 29 - "Payroll Calculation"
Cohesion: 0.15
Nodes (11): {closure#10}(), {closure#11}(), {closure#14}(), {closure#2}(), {closure#25}(), {closure#5}(), {closure#6}(), {closure#7}() (+3 more)

### Community 30 - "Invoices & Inventory"
Cohesion: 0.13
Nodes (9): {closure#23}(), Invoice, {closure#1}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#7}(), {closure#9}() (+1 more)

### Community 31 - "Document Cleanup"
Cohesion: 0.11
Nodes (8): {closure#1}(), {closure#2}(), ProcessPendingDocumentCleanups, PendingDocumentCleanup, {closure#3}(), {closure#1}(), PendingDocumentCleanupService, AttendancePolicyMigrationTest

### Community 32 - "Termination & Rehire Requests"
Cohesion: 0.08
Nodes (7): RehireEmployeeRequest, StoreEmployeeRequest, StoreLeaveBalanceEntryRequest, TerminateEmployeeRequest, TerminateEmploymentContractRequest, LoginRequest, StoreExchangeRateRequest

### Community 33 - "Sham Cash Export"
Cohesion: 0.11
Nodes (6): PayExport, {closure#16}(), ShamCashFile, {closure#1}(), {closure#6}(), ShamCashService

### Community 34 - "Employment Contracts"
Cohesion: 0.13
Nodes (18): {closure#10}(), {closure#1}(), {closure#1}(), {closure#18}(), {closure#3}(), {closure#1}(), {closure#1}(), {closure#2}() (+10 more)

### Community 35 - "Access Resolution"
Cohesion: 0.14
Nodes (4): Access, Scope, RequirePermission, {closure#1}()

### Community 37 - "HR & Payroll Migrations"
Cohesion: 0.07
Nodes (6): {closure#1}(), {closure#1}(), {closure#1}(), {closure#1}(), {closure#1}(), {closure#2}()

### Community 38 - "Attendance Access & Export"
Cohesion: 0.11
Nodes (4): AttendanceAccess, HrScope, {closure#2}(), {closure#26}()

### Community 39 - "Attendance Leave Sync"
Cohesion: 0.17
Nodes (4): AttendanceLeaveSync, {closure#7}(), {closure#8}(), LeaveWorkflowService

### Community 40 - "Frontend Integration Guide"
Cohesion: 0.15
Nodes (21): Frontend (Angular) to Backend (Laravel) Integration Guide - EOMS, Attendance and devices module, Breaking changes table for the old frontend, can(perm, scope) frontend helper, EmployeeResource with permission-gated sensitive/financial fields, API environment (/api/v1, apiBase, window.EOMS_API, CORS, port 8088), finance.approve required for reversals and invoice cancellation, Finance module (accounts, entries, boxes, transactions, invoices, reports) (+13 more)

### Community 41 - "Laravel Cloud Checklists"
Cohesion: 0.10
Nodes (10): Laravel Cloud multi-step checklists, Deploying to Laravel Cloud skill, Laravel Cloud CLI (./vendor/bin/cloud), Laravel Cloud documentation (llms.txt), Laravel Cloud Object Storage (Cloudflare R2), cloud tinker / command:run remote access, Managed queues, Resend email integration (+2 more)

### Community 42 - "Finance Controller Closures"
Cohesion: 0.08
Nodes (4): {closure#17}(), {closure#22}(), {closure#6}(), Box

### Community 43 - "Notifications"
Cohesion: 0.10
Nodes (11): {closure#1}(), {closure#2}(), FinanceNotifier, HrNotification, {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}() (+3 more)

### Community 44 - "Leave Balance Ledger"
Cohesion: 0.09
Nodes (4): LeaveBalanceEntry, LeaveRequestDay, OfficeHoliday, {closure#11}()

### Community 45 - "HR & Settings Migrations"
Cohesion: 0.08
Nodes (6): {closure#1}(), {closure#1}(), {closure#1}(), {closure#1}(), {closure#1}(), {closure#1}()

### Community 46 - "Pay Run Controller"
Cohesion: 0.09
Nodes (5): {closure#1}(), {closure#14}(), {closure#6}(), PayRunLine, {closure#15}()

### Community 48 - "ADMS Gateway"
Cohesion: 0.18
Nodes (10): {closure#10}(), {closure#11}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}(), {closure#7}() (+2 more)

### Community 49 - "Departments"
Cohesion: 0.15
Nodes (9): Department, {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), DepartmentService, {closure#6}() (+1 more)

### Community 51 - "Leave Backfill Migrations"
Cohesion: 0.16
Nodes (5): up(), {closure#1}(), {closure#13}(), LeaveRequestController, LeaveRequest

### Community 52 - "Exchange Rates"
Cohesion: 0.13
Nodes (6): ExchangeRateController, ExchangeRate, {closure#1}(), {closure#2}(), {closure#4}(), ExchangeRateService

### Community 53 - "Board Change Broadcasting"
Cohesion: 0.10
Nodes (3): BoardChanged, {closure#2}(), TaskBoardsTest

### Community 54 - "JS Build Tooling"
Cohesion: 0.13
Nodes (20): devDependencies, concurrently@^10.0.3, laravel-vite-plugin@^3.1, tailwindcss@^4.0.0, @tailwindcss/vite@^4.0.0, vite@^8.0.0, optionalDependencies, @laravel/multiplex@^0.4.1 (+12 more)

### Community 55 - "Tailwind & Project Guidelines"
Cohesion: 0.13
Nodes (14): Tailwind CSS Development Skill, Tailwind common pitfalls, Tailwind v4 @import "tailwindcss" syntax, Tailwind v4 replaced deprecated utilities, Testing Best Practices Skill, Laravel Boost Guidelines (CLAUDE.md), .ai/rules project rules, Laravel Boost MCP server tools (+6 more)

### Community 56 - "Ledger & Device Timezone"
Cohesion: 0.10
Nodes (7): {closure#1}(), {closure#10}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#9}(), {closure#2}()

### Community 57 - "Employee Directory Contract"
Cohesion: 0.10
Nodes (3): EmployeeDirectory, NullEmployeeDirectory, {closure#2}()

### Community 58 - "Role Service"
Cohesion: 0.20
Nodes (5): {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), RoleService

### Community 59 - "User Service"
Cohesion: 0.17
Nodes (4): {closure#3}(), {closure#5}(), {closure#6}(), UserService

### Community 60 - "Pay Run Endpoints"
Cohesion: 0.19
Nodes (3): {closure#3}(), PayRunController, PayRun

### Community 61 - "Stock Items"
Cohesion: 0.11
Nodes (7): {closure#18}(), Item, StockMove, {closure#3}(), {closure#6}(), {closure#7}(), {closure#8}()

### Community 62 - "ADMS Device Config"
Cohesion: 0.21
Nodes (4): {closure#1}(), ConfigureAdmsDevice, AttendanceDevice, AdmsGateway

### Community 63 - "Service Periods & Leave Balance"
Cohesion: 0.25
Nodes (5): EmployeeServicePeriod, LeaveBalanceService, {closure#13}(), {closure#2}(), {closure#4}()

### Community 64 - "Permission Catalog"
Cohesion: 0.14
Nodes (7): {closure#5}(), {closure#1}(), PermissionCatalog, {closure#8}(), {closure#9}(), {closure#1}(), {closure#2}()

### Community 65 - "Pay Run Service"
Cohesion: 0.11
Nodes (4): {closure#1}(), {closure#20}(), {closure#21}(), {closure#4}()

### Community 67 - "Attendance Controller"
Cohesion: 0.12
Nodes (3): {closure#14}(), {closure#8}(), AttendanceDayResource

### Community 68 - "Form Requests"
Cohesion: 0.11
Nodes (3): SubmitLeaveRequest, StoreUserRequest, UpdateUserRequest

### Community 72 - "Parties & Finance Reports"
Cohesion: 0.14
Nodes (5): Party, {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}()

### Community 73 - "Device Auth Middleware"
Cohesion: 0.15
Nodes (3): AuthenticateAdmsDevice, AuthenticateAttendanceDevice, AdmsDiagnostics

### Community 75 - "Pay Run Items"
Cohesion: 0.14
Nodes (5): PayRunItem, {closure#24}(), {closure#5}(), {closure#7}(), {closure#8}()

### Community 76 - "Routing Best Practices"
Cohesion: 0.14
Nodes (3): Routing and controller best practices, Validation and forms best practices, Laravel best practices skill

### Community 77 - "Job Titles"
Cohesion: 0.22
Nodes (3): JobTitleController, JobTitle, JobTitleService

### Community 78 - "Release Tasks On Termination"
Cohesion: 0.19
Nodes (4): {closure#1}(), ReleaseTasksOnTermination, ActivityLog, TaskNotifier

### Community 79 - "Finance API Docs"
Cohesion: 0.15
Nodes (14): Approval Limits per Currency, Cash Boxes and Transactions (receipt, payment, transfer, exchange), Chart of Accounts, Finance Module API, Financial Reports (box, party, ledger, trial balance, income statement, balance sheet), Sales and Purchase Invoices, Journal Entries (auto-balanced difference, reverse-only), Monthly Period Close Workflow (+6 more)

### Community 87 - "Access Sync"
Cohesion: 0.16
Nodes (5): AccessSync, {closure#1}(), {closure#2}(), DefaultRoles, up()

### Community 88 - "Deptrac Boundaries"
Cohesion: 0.13
Nodes (14): type, value, deptrac, layers, paths, ruleset, collectors, name (+6 more)

### Community 89 - "Leave & Tasks Docs"
Cohesion: 0.18
Nodes (15): Attendance Integration with Leaves and Public Holidays, Service Termination Effects on Attendance and Leave, Leave Day Units (1,000,000 = one day), Leave Balance Ledger (grant, opening, reserve, debit, refund, holiday_refund), Who-Is-On-Leave Calendar, Leave CSV Export with Formula-Injection Guard, Leave Request Lifecycle (preview quote_token, approve, shorten, cancel), Leave Approval Routing (hr_manager / company_manager) (+7 more)

### Community 92 - "isolation"
Cohesion: 0.16
Nodes (9): Fakes, Mocks, and Determinism Rules, Mocking with Mockery ($this->mock), Naming and Structure Rules, Test file layout ({ClassName}Test.php mirroring class path), One test class per class under test; #[Group] only for selection, Test Suite Performance Rules, Global fakes in base TestCase setUp(), Find slow tests with --profile (+1 more)

### Community 93 - "SystemAdminSeeder"
Cohesion: 0.23
Nodes (4): SystemAdminSeeder, CurrencySeeder, SettingsSeeder, DatabaseSeeder

### Community 94 - "UserController"
Cohesion: 0.27
Nodes (3): {closure#1}(), UserController, UserResource

### Community 97 - "SKILL"
Cohesion: 0.18
Nodes (3): Infer conventions skill, .ai/rules directory and index.md, Boost record-rule MCP tool

### Community 99 - "endpoint-tests"
Cohesion: 0.19
Nodes (8): Endpoint Tests Rules, Endpoint coverage cases (auth, tenant, role, scope, validation, valid), Coverage checklist (auth, tenant, policy matrix, validation, security), Security Tests Rules, Cross-tenant access test, Injection into dynamic query components (sort columns/filters), Unexpected payload key (mass assignment) test, Tasks boards module (viewer < member < owner)

### Community 100 - "test-data"
Cohesion: 0.17
Nodes (9): Testing validation through behavior, PHPUnit/Laravel feature search table, Parallel testing with ParaTest (php artisan test --parallel), Reviewing Tests Checklist, Data and determinism checklist, Test value checklist, Factories and Test Data Rules, Public static data providers with descriptive keys (+1 more)

### Community 101 - "create payroll tables"
Cohesion: 0.14
Nodes (7): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#1}(), {closure#2}(), {closure#3}()

### Community 102 - "CONVENTIONS"
Cohesion: 0.17
Nodes (8): API Response Shape (/api/v1, data/links/meta, message/errors), AuditLogger, EOMS Project Conventions, Permission Naming module.action, Per-Module Table Prefixes (usr_, hr_, pay_), attendance:configure-adms Artisan Command, hr_attendance_devices Table (adms_source_ip), Access API (/auth/me, /access/catalog, /access/roles)

### Community 108 - "PERMISSIONS AR"
Cohesion: 0.20
Nodes (12): Department Manager Read-Only Attendance Scope, Department Manager Role Setup (manager_employee_id), Access Service (scope, covers, usersWith), AccessTest Coverage Test, DefaultRoles, Grant Scopes (all, department, own), HrScope::employeeIds, Department Manager Inferred from hr_departments.manager_employee_id (+4 more)

### Community 111 - "assertions"
Cohesion: 0.27
Nodes (5): Assertions Rules, Arrange, act, assert, How to Find Test Framework Features, Framework fakes (Event/Queue/Mail/Exceptions::fake), Assertions checklist

### Community 112 - "finance requested changes"
Cohesion: 0.20
Nodes (8): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}(), {closure#7}(), up()

### Community 114 - "ATTENDANCE API AR"
Cohesion: 0.22
Nodes (9): ATTLOG Tab-Separated Punch Format, /iclock/* Endpoints (cdata, getrequest, devicecmd, ping, registry), Effective-Dated Attendance Policy Versions, attendance:configure-defaults Command, first_last / directional Punch Pairing Modes, overtime_multiplier Policy Setting, Leave/Attendance Overlap Fix, LeaveAttendanceOverlapTest (+1 more)

### Community 116 - "create hr attendance tables"
Cohesion: 0.22
Nodes (7): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}(), up()

### Community 117 - "add employee service periods and binding"
Cohesion: 0.25
Nodes (6): backfillKnownServiceDates(), {closure#1}(), {closure#2}(), {closure#3}(), {closure#5}(), up()

### Community 118 - "create hr leave workflows"
Cohesion: 0.22
Nodes (6): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}()

### Community 120 - "composer"
Cohesion: 0.22
Nodes (8): description, keywords, license, minimum-stability, name, prefer-stable, $schema, type

### Community 121 - "composer"
Cohesion: 0.22
Nodes (9): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, platform, preferred-install, sort-packages (+1 more)

### Community 122 - "composer"
Cohesion: 0.22
Nodes (9): require-dev, deptrac/deptrac, fakerphp/faker, laravel/boost, laravel/pail, laravel/pint, mockery/mockery, nunomaduro/collision (+1 more)

### Community 123 - "composer"
Cohesion: 0.22
Nodes (9): scripts, dev, post-autoload-dump, post-create-project-cmd, post-root-package-install, post-update-cmd, pre-package-uninstall, setup (+1 more)

### Community 124 - "ADMS SETUP AR"
Cohesion: 0.22
Nodes (9): eoms_test Test Database, Native ADMS/PUSH Gateway for iFace 880 Plus, ATTENDANCE_ADMS_* Env Flags, ZKTeco iFace 880 Plus Device, Remote Fingerprint Enrollment Workflow (USERINFO / ENROLL_FP), Attendance System V2 (Grace and Late Blocks), AttendancePermissionSeeder, Dated Device User Bindings (device_user_id) (+1 more)

### Community 125 - "ATTENDANCE API AR"
Cohesion: 0.31
Nodes (9): Manual Device Sync Confirmation (confirm-sync / complete_through), Device connection_mode (adms | bridge), Attendance Day Projection (status, flags, fields), coverage_complete Sync Coverage Flag, Day Approval, Bulk Approval and Reopen (pending_changes), Bridge JSON device-ingest Endpoint, Device Token (SHA-256 hashed, separate from Sanctum), Optimistic version Field (409 on conflict) (+1 more)

### Community 130 - "EmployeeDocumentResource"
Cohesion: 0.32
Nodes (3): {closure#1}(), EmployeeDocumentResource, EmployeeJobHistoryResource

### Community 132 - "composer"
Cohesion: 0.25
Nodes (8): require, dedoc/scramble, laravel/framework, laravel/sanctum, laravel/tinker, php, predis/predis, spatie/laravel-permission

### Community 133 - "create permission tables"
Cohesion: 0.25
Nodes (5): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}()

### Community 134 - "PAYROLL API AR"
Cohesion: 0.29
Nodes (7): Monthly Payroll Inputs Endpoint (ready / blockers), RolePermissionSeeder, Contract pay_type (monthly, daily, hourly), Locked-Month Payroll Adjustments, Payroll Calculation Rules (unpaid leave, overtime, late, absence), Payroll Module API, Payroll Run Lifecycle (draft, submitted, approved)

### Community 139 - "create hr leave foundation"
Cohesion: 0.29
Nodes (5): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), up()

### Community 140 - "leave approval levels"
Cohesion: 0.29
Nodes (4): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}()

### Community 142 - "scoped role permissions"
Cohesion: 0.29
Nodes (4): {closure#1}(), {closure#2}(), {closure#4}(), {closure#5}()

### Community 143 - "allow voiding pay payments"
Cohesion: 0.29
Nodes (4): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}()

### Community 145 - "composer"
Cohesion: 0.29
Nodes (7): autoload, exclude-from-classmap, files, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 146 - "TASKS API AR"
Cohesion: 0.29
Nodes (7): Attendance Correction Request and Review, In-App Notifications (leave.*, attendance.*), Payroll Notifications (run_submitted, run_returned, run_approved), Board Member Roles (owner > member > viewer), Board Types (personal, team, department, project), Card Completion Cycle (done -> owner approve -> finished), Tasks Notifications (SystemNotification tasks.*)

### Community 154 - "AGENTS"
Cohesion: 0.40
Nodes (5): Laravel Boost, Laravel Boost Bootstrap Guidelines (AGENTS.md), PHP and Composer Prerequisites (php.new installer), Laravel Boost (Agentic Development), Laravel Framework

### Community 188 - "composer"
Cohesion: 0.67
Nodes (3): autoload-dev, psr-4, Tests\\

### Community 189 - "composer"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

## Ambiguous Edges - Review These
- `RolePermissionSeeder` → `permissions:sync Artisan Command`  [AMBIGUOUS]
  docs/PERMISSIONS_AR.md · relation: conceptually_related_to
- `Managed queues` → `retry_after longer than job timeout`  [AMBIGUOUS]
  .claude/skills/deploying-to-cloud/SKILL.md · relation: conceptually_related_to
- `Framework fakes (Event/Queue/Mail/Exceptions::fake)` → `Global fakes in base TestCase setUp()`  [AMBIGUOUS]
  .claude/skills/testing-best-practices/rules/performance.md · relation: conceptually_related_to

## Knowledge Gaps
- **47 isolated node(s):** `php`, `PHP and Composer Prerequisites (php.new installer)`, `API Response Shape (/api/v1, data/links/meta, message/errors)`, `Laravel Framework`, `ZKTeco iFace 880 Plus Device` (+42 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 919 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **101 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `User` connect `Users & Auth` to `Attendance Days & Corrections`, `Employee Management`, `ADMS Gateway Tests`, `Leave Policies & Workflow Tests`, `Service Providers & HR Commands`, `Permissions Seeding & Test Base`, `EmployeeHardeningTest`, `Finance & HR Model Casts`, `RoleEscalationTest`, `Attendance Recalculation`, `Identity & HR Controllers`, `UserFactory`, `Task Card Activity & Attachments`, `HR & Tasks Model Relations`, `Card Assignees & Labels`, `Attendance Bindings & Punches`, `Finance Ledger Core`, `Recurring Cards & Reminders`, `Task Boards & Members`, `Payroll Tests`, `PayPayment`, `Board Access`, `Attendance Hardening Tests`, `Employee Rehire Tests`, `Cash Service & Transactions`, `Payroll Calculation`, `Invoices & Inventory`, `Document Cleanup`, `Sham Cash Export`, `Access Resolution`, `Finance Tests`, `Attendance Access & Export`, `Attendance Leave Sync`, `Finance Controller Closures`, `Notifications`, `Leave Balance Ledger`, `Departments`, `Leave Backfill Migrations`, `Ledger & Device Timezone`, `Role Service`, `User Service`, `Pay Run Endpoints`, `Stock Items`, `Permission Catalog`, `Pay Run Service`, `Form Requests`, `Leave Request Controller`, `Access Tests`, `Pay Run Items`, `Release Tasks On Termination`, `Contract Job History Tests`, `EmployeeJobHistoryTest`, `SystemAdminSeeder`, `UserController`, `AccountHardeningTest`, `AttendanceIntegrationTest`, `IdentityTest`, `TasksFixture`?**
  _High betweenness centrality (0.156) - this node is a cross-community bridge._
- **What connects `php`, `PHP and Composer Prerequisites (php.new installer)`, `API Response Shape (/api/v1, data/links/meta, message/errors)` to the rest of the system?**
  _47 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Attendance Days & Corrections` be split into smaller, more focused modules?**
  _Cohesion score 0.06832298136645963 - nodes in this community are weakly interconnected._
- **Why does `Employee` connect `Employee Documents` to `Attendance Days & Corrections`, `Employee Management`, `Controller Endpoints (mixed)`, `PayrollCalculator`, `Leave Policies & Workflow Tests`, `Service Providers & HR Commands`, `Permissions Seeding & Test Base`, `EmployeeHardeningTest`, `Finance & HR Model Casts`, `Attendance Recalculation`, `Identity & HR Controllers`, `Task Card Activity & Attachments`, `Attendance Bindings & Punches`, `HR & Tasks Model Relations`, `PayrollCalculator`, `Contract Expiry & Termination`, `EmployeeOnboardingTest`, `EmployeePrivacyTest`, `Board Access`, `Attendance Hardening Tests`, `Employee Rehire Tests`, `EmployeeTerminationTest`, `Payroll Calculation`, `Document Cleanup`, `Termination & Rehire Requests`, `Sham Cash Export`, `Employment Contracts`, `Attendance Access & Export`, `ScheduleEmploymentContractTerminationReq`, `Attendance Leave Sync`, `Notifications`, `Leave Balance Ledger`, `Pay Run Controller`, `ADMS Gateway`, `Ledger & Device Timezone`, `Service Periods & Leave Balance`, `Pay Run Service`, `Attendance Controller`, `Leave Request Controller`, `Employee Documents Tests`, `Pay Run Items`, `Release Tasks On Termination`, `Contract Job History Tests`, `EmployeeJobHistoryTest`, `EmploymentContractTest`, `AttendanceIntegrationTest`, `UpdateEmployeeRequest`?**
  _High betweenness centrality (0.096) - this node is a cross-community bridge._
- **Should `Employee Management` be split into smaller, more focused modules?**
  _Cohesion score 0.04050632911392405 - nodes in this community are weakly interconnected._

### Low-confidence Hints
_AMBIGUOUS edges — the extractor was unsure. Verify before acting on these._

- **What is the exact relationship between `RolePermissionSeeder` and `permissions:sync Artisan Command`?**
  _Edge tagged AMBIGUOUS (relation: conceptually_related_to) - confidence is low._
- **What is the exact relationship between `Managed queues` and `retry_after longer than job timeout`?**
  _Edge tagged AMBIGUOUS (relation: conceptually_related_to) - confidence is low._