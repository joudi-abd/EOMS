# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Stack

Angular 22, SCSS, client-side routing, no SSR. Confirmed by the existing app and the study's frontend recommendation. This phase is frontend only; Laravel, MySQL, and external integrations named in the study are out of scope until a later phase.

## Users

Primary user of the first surface: the company director (مدير الشركة), at a desk during the working day. That account sees every module and every figure.

Other roles on the same shell: system manager, human resources officer, finance officer, project manager, engineer, procurement officer, and employee. An account can hold two roles at once, and then sees the union of those permissions. There is no inventory module. A user receives the least access their job needs. Shared records (leave decisions and purchase requests) live in one client store and update every open view immediately, including another tab, without reloading the page. There is still no server, so this sync is local to the browser.

## Product Purpose

The application runs the administrative and operational work of UFUQ Imperium Group for Urbanism (إدارة شركة أفق إمبريوم غروب للعمران) from one place: people, attendance, projects, tasks, clients, contracts, money, purchasing, documents, assets, meetings, and approvals.

Success for the director is a single home that shows the office's condition and the decisions waiting, without paper chase or a second system.

## Positioning

The firm is operated as linked records, not separate department tools. A project carries its client, contract, cost, documents, team, and approvals, and the director's home shows only what that role may see. Modules ship in phases; later modules attach without rebuilding the platform.

## Operating Context

An engineering office: staff files, site attendance, project stages (client requirements, design, design approval, execution planning, site and materials, construction, quality, handover), client contracts, operating revenue and expense, purchase requests, stored materials, drawings and documents, equipment, and internal meetings.

The working language is Arabic, right to left. English is available. There is no backend in this phase, so the home runs on labeled synthetic office data.

## Capabilities and Constraints

Confirmed modules, in study order:

- Users and permissions
- Employees
- Attendance and departure
- Human resources: leave, permission, approvals
- Payroll
- Projects and tasks
- Clients and contracts
- Operating finance
- Procurement and suppliers
- Inventory
- Documents
- Assets and equipment
- Meetings and internal communication
- Notifications and approvals
- Dashboard and reporting

The director's dashboard, the application shell, users and permissions, and the human resources section are built: employees, attendance, leave, requests, payroll, and HR settings. The other module screens are not built yet.

The human resources section follows the company's HR requirements document (وثيقة المتطلبات الوظيفية). Decisions taken there:

- Every employee has a login account, created with the employee role when the file is saved. The national ID is required and unique. The staff number is generated on save as `U-` plus eight digits.
- Saving a new employee can send them to the UA300 Pro over ADMS and start fingerprint enrollment. The file shows the stages (queued on the server, fetched by the device, finger placed, template received) and the ADMS commands. Without a backend, a labeled control stands in for the device replies.
- Departments, job titles, leave types, contract types, the shift, the grace period, workdays, and public holidays are managed in HR settings, not in code.
- Contracts keep their history; a renewal adds a contract and keeps the previous one. Job changes are recorded in the job history.
- Leaving keeps the file. Offboarding records the reason and last day, requires every custody item to be returned, then disables the login and closes the contract.
- Leave types are annual, sick, unpaid, occasion, and hourly. Requests (leave, attendance correction, equipment, resignation) go to the direct manager, then human resources; with no direct manager they go to human resources. Nobody decides their own request. Used leave is counted after approval; the accrual formula is not adopted, so no balance is shown.
- A missing punch on a workday stays in review until human resources confirms an absence, a leave covers it, or a site assignment linked to a project explains it. Overtime is recorded after the shift end with no pay value.
- Attendance reports (day, month, lateness, absence and missing punches, overtime) filter by period, department, manager, project, and employee, and export to Excel (CSV) and PDF (print).
- Payroll runs go through preparation, review, and approval by different people, then payments recorded as cash or e-wallet with a reference. Default owners until the company names them: human resources prepares, finance reviews and pays, the company director approves. No amounts are shown because salary components and currency are not adopted.
- Any account with an open employee file reaches its own attendance, leave, and requests. A manager also sees their team.

Attendance check-in and check-out are not typed on this screen. A ZKTeco UA300 Pro fingerprint terminal pushes its attendance log over ADMS; the screen shows the records, links each device PIN to an employee, and classifies the day. The adopted shift is 09:00 to 16:00, Saturday to Thursday, with a 45-minute grace. Friday is outside the working week. A missing punch is a record that needs review, not a confirmed absence, and an approved leave covers the day. A correction sits on top of the device record and does not erase it. Lateness and absence are not deducted from pay.

Dashboard facts the director's home may show, each scoped to what the role may see:

- Headcount and distribution by department
- Attendance, absence, and lateness
- Leave requests waiting for approval
- Projects by execution status
- Overdue tasks and tasks near their due date
- Completion and delay across projects
- Operating revenue and expenses
- Estimated budgets against actual spend
- Purchase requests and their approval state
- Contract and document expiry alerts

Non-functional requirements from the study: security, performance, ease of use, scalability and maintainability, backup and recovery, privacy. Payroll, finance, and permission changes are sensitive and must read as restricted.

Undecided: the real company name, logo, salary components, leave accrual amounts, and which later-phase modules ship first. The attendance device is a UA300 Pro using ADMS push.

## Brand Commitments

Product name in the rail: the supplied UFUQ logo. Official line: إدارة شركة أفق إمبريوم غروب للعمران / UFUQ Imperium Group for Urbanism.

The user made two constraints binding: the interface is modern and luxurious (حديث وفخم), and it is bilingual with Arabic as the default.

Standing preference for the main interface: the category-standard administrative shell (side navigation, summary figures, a chart, and a table), chosen explicitly. Execute that convention straight. The craft bar named with it is Height.

Do not invent a customer list, salary amounts, a currency, or performance claims.

## Evidence on Hand

Source study: the uploaded proposal "دراسة مشروع مقترح — نظام الإدارة المتكامل للشركة الهندسية". The company line and UFUQ logo were supplied later and are the visible brand. Demonstration figures on the dashboard are synthetic and must stay labeled as such.

## Product Principles

- Show the director the office, then the decision waiting on it.
- One record links the person, the project, the contract, and the cost.
- Least privilege is visible: restricted figures are absent, not merely locked behind a click.
- Arabic is the language of work; English follows the same structure.
- The shell is the permanent frame; modules arrive in phases without a new product.

## Accessibility & Inclusion

Daily office use in Arabic and English, including right-to-left layout. The study requires ease of use. No stricter standard was named.
