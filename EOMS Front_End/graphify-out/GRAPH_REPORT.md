# Graph Report - EOMS Front_End  (2026-11-03)

## Corpus Check
- 170 files · ~63,753 words
- Verdict: corpus is large enough that graph structure adds value.
- Unclassified: 58 file(s) not represented in the graph (top: .scss 53, (none) 3, .ico 1)

## Summary
- 950 nodes · 2671 edges · 43 communities (27 shown, 16 thin omitted)
- Extraction: 100% EXTRACTED · 0% INFERRED · 0% AMBIGUOUS · INFERRED: 11 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- Community 0
- Community 1
- Community 2
- Community 3
- Community 4
- Community 5
- Community 6
- Community 7
- Community 8
- Community 9
- Community 10
- Community 11
- Community 12
- Community 13
- Community 14
- Community 15
- Community 16
- Community 17
- Community 18
- Community 19
- Community 20
- Community 21
- Community 22
- Community 23
- Community 24
- Community 25
- Community 26
- Community 27
- Community 28
- Community 29
- Community 30
- Community 31
- Community 32
- Community 33
- Community 34
- Community 35
- Community 36
- Community 37
- Community 38
- Community 39
- Community 40
- Community 41
- Community 42

## God Nodes (most connected - your core abstractions)
1. `Office` - 129 edges
2. `Ui` - 88 edges
3. `@angular/core` - 73 edges
4. `Session` - 50 edges
5. `CopyKey` - 49 edges
6. `@angular/router` - 42 edges
7. `Employee` - 34 edges
8. `Language` - 32 edges
9. `Text` - 28 edges
10. `Records` - 24 edges

## Surprising Connections (you probably didn't know these)
- `Line` --references--> `CopyKey`  [EXTRACTED]
  src/app/pages/employees/employee-history/employee-history.ts → src/app/core/copy.ts
- `AccountEntry` --references--> `Role`  [EXTRACTED]
  src/app/data/office.ts → src/app/core/access/accounts.ts
- `Account` --references--> `Text`  [EXTRACTED]
  src/app/core/access/accounts.ts → src/app/data/text.ts
- `accountFrom()` --indirect_call--> `roleFrom()`  [INFERRED]
  src/app/data/office.ts → src/app/core/access/accounts.ts
- `PermissionGroup` --references--> `Text`  [EXTRACTED]
  src/app/core/access/permission-copy.ts → src/app/data/text.ts

## Import Cycles
- None detected.

## Communities (43 total, 16 thin omitted)

### Community 0 - "Community 0"
Cohesion: 0.07
Nodes (13): Action, isTop(), dayIn(), departmentFrom(), employeeFrom(), plain(), ref(), FlowStatus (+5 more)

### Community 1 - "Community 1"
Cohesion: 0.06
Nodes (35): pdfjs-dist@^5.7.284, pdfjs-dist, attachment(), DossierAttachment, DossierField, DossierSection, DossierSpec, printDossier() (+27 more)

### Community 2 - "Community 2"
Cohesion: 0.05
Nodes (12): App, appConfig, routes, AuditKind, signedIn(), signedOut(), apiInterceptor(), Access (+4 more)

### Community 3 - "Community 3"
Cohesion: 0.05
Nodes (44): build, serve, test, builder, configurations, defaultConfiguration, options, cli (+36 more)

### Community 4 - "Community 4"
Cohesion: 0.07
Nodes (10): Language, AttendanceBlock, Bar, BarChart, ChartDatum, ChartSeries, hostWidth(), niceMax() (+2 more)

### Community 5 - "Community 5"
Cohesion: 0.06
Nodes (43): dependencies, @angular/common@^22.2.0, @angular/compiler@^22.2.0, @angular/core@^22.2.0, @angular/forms@^22.2.0, @angular/platform-browser@^22.2.0, @angular/router@^22.2.0, exceljs@^4.4.0 (+35 more)

### Community 6 - "Community 6"
Cohesion: 0.13
Nodes (19): @angular/core, roleLabel, nobody, base, copy, CopyKey, Lang, ModuleId (+11 more)

### Community 7 - "Community 7"
Cohesion: 0.07
Nodes (22): Account, actionNeeds, areaNeeds, grants(), holds(), Permission, permissionIds, permissionsOf() (+14 more)

### Community 8 - "Community 8"
Cohesion: 0.09
Nodes (33): AuditEntry, roleFrom(), ApiJobTitle, ApiPolicy, Adjustment, DayInput, PinLink, Punch (+25 more)

### Community 9 - "Community 9"
Cohesion: 0.11
Nodes (13): ReportFigure, ReportTable, ReportDraft, AccountsMix, Insights, People, statusFill, ChartRow (+5 more)

### Community 10 - "Community 10"
Cohesion: 0.09
Nodes (5): ApiAttendanceCorrection, ApiAttendanceDay, AttendanceBook, reportKey(), AttendanceDay

### Community 11 - "Community 11"
Cohesion: 0.07
Nodes (11): Ui, FingerprintState, LeavingReason, HrFlow, Metrics, MyMonth, EmployeeLeaving, reasonKey (+3 more)

### Community 12 - "Community 12"
Cohesion: 0.10
Nodes (24): ApiAttendanceBundle, ApiAttendanceEmployee, ApiAttendancePolicySnap, ApiAttendanceReport, ApiContract, ApiContractStatus, ApiContractType, ApiCurrency (+16 more)

### Community 13 - "Community 13"
Cohesion: 0.12
Nodes (6): Session, Tab, EmployeeFinger, EmployeeForm, EmployeeInfo, fingerKey

### Community 14 - "Community 14"
Cohesion: 0.16
Nodes (10): HrRequest, RequestKind, weekOrder, flowTone(), LeaveTable, kindEffect, kindIcon, kindKey (+2 more)

### Community 15 - "Community 15"
Cohesion: 0.21
Nodes (9): ApiAttendancePunch, clockOf(), dateOf(), lineOf(), markOf(), zonedIso(), zoneOffset(), Attendance (+1 more)

### Community 17 - "Community 17"
Cohesion: 0.16
Nodes (4): statusOf(), Auth, ApiUser, Login

### Community 18 - "Community 18"
Cohesion: 0.18
Nodes (13): arrival, buildDay(), clock(), DayReport, DayRow, departure, holidayOn(), LogRow (+5 more)

### Community 21 - "Community 21"
Cohesion: 0.26
Nodes (6): @angular/router, PayMethod, PayRecord, PayrollTable, RunLine, runStatusKey

### Community 22 - "Community 22"
Cohesion: 0.29
Nodes (10): isWorkday(), daysBetween(), isoDay(), monthBounds(), parseDay(), projects, directionKey, markFill (+2 more)

### Community 23 - "Community 23"
Cohesion: 0.18
Nodes (10): ApiItem, device, ApiEnrollment, EnrollState, EnrollStatus, idle, live, reached (+2 more)

### Community 24 - "Community 24"
Cohesion: 0.26
Nodes (5): RollLine, DayMark, Line, AttendanceTable, markTone()

### Community 28 - "Community 28"
Cohesion: 0.27
Nodes (3): LeaveTypeDef, NamedItem, SettingsList

### Community 29 - "Community 29"
Cohesion: 0.44
Nodes (3): serverMessage(), Enrollment, statusFrom()

### Community 31 - "Community 31"
Cohesion: 0.25
Nodes (7): ApiDepartment, ApiEmployee, ApiEmployeeStatus, Department, EmployeeStatus, base, statusFrom

### Community 35 - "Community 35"
Cohesion: 0.39
Nodes (5): rxjs@~7.8.0, @angular/common, rxjs, Query, apiBase

### Community 39 - "Community 39"
Cohesion: 0.40
Nodes (4): ar, en, hrCopy, HrKey

## Knowledge Gaps
- **16 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Ui` connect `Community 11` to `Community 1`, `Community 2`, `Community 4`, `Community 6`, `Community 7`, `Community 8`, `Community 9`, `Community 10`, `Community 12`, `Community 13`, `Community 14`, `Community 15`, `Community 17`, `Community 19`, `Community 20`, `Community 21`, `Community 22`, `Community 23`, `Community 24`, `Community 25`, `Community 26`, `Community 27`, `Community 28`, `Community 32`, `Community 34`, `Community 36`, `Community 37`, `Community 38`, `Community 40`, `Community 41`, `Community 42`?**
  _High betweenness centrality (0.174) - this node is a cross-community bridge._
- **Should `Community 0` be split into smaller, more focused modules?**
  _Cohesion score 0.06837786661056175 - nodes in this community are weakly interconnected._
- **Why does `Office` connect `Community 0` to `Community 1`, `Community 4`, `Community 6`, `Community 7`, `Community 8`, `Community 9`, `Community 11`, `Community 12`, `Community 13`, `Community 14`, `Community 15`, `Community 18`, `Community 21`, `Community 22`, `Community 23`, `Community 24`?**
  _High betweenness centrality (0.155) - this node is a cross-community bridge._
- **Should `Community 1` be split into smaller, more focused modules?**
  _Cohesion score 0.06368011847463902 - nodes in this community are weakly interconnected._
- **Why does `@angular/core` connect `Community 6` to `Community 1`, `Community 2`, `Community 35`, `Community 4`, `Community 5`, `Community 7`, `Community 8`, `Community 9`, `Community 11`, `Community 12`, `Community 13`, `Community 14`, `Community 15`, `Community 17`, `Community 21`, `Community 22`, `Community 23`, `Community 24`?**
  _High betweenness centrality (0.100) - this node is a cross-community bridge._
- **Should `Community 2` be split into smaller, more focused modules?**
  _Cohesion score 0.05410628019323672 - nodes in this community are weakly interconnected._
- **Should `Community 3` be split into smaller, more focused modules?**
  _Cohesion score 0.046464646464646465 - nodes in this community are weakly interconnected._