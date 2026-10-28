---
name: UFUQ
description: Arabic-first working shell for an engineering office. White panels on a cool ground, one blue action, status color only for state, charts drawn from a fixed data palette.
colors:
  ground: "#f5f6f8"
  surface: "#ffffff"
  raised: "#fbfcfd"
  ink: "#111827"
  muted: "#4b5563"
  soft: "#6b7280"
  line: "#e5e7eb"
  line-strong: "#d1d5db"
  hover: "#f3f4f6"
  row-hover: "#f8fafc"
  action: "#2563eb"
  action-ink: "#1d4ed8"
  action-tint: "#eff6ff"
  late: "#b91c1c"
  on-time: "#047857"
  waiting: "#b45309"
  late-tint: "#fef2f2"
  on-time-tint: "#ecfdf5"
  waiting-tint: "#fffbeb"
  notice: "#dbeafe"
  notice-line: "#bfdbfe"
  series-1: "#2563eb"
  series-2: "#93c5fd"
  series-3: "#0f766e"
  series-4: "#94a3b8"
  series-5: "#7c3aed"
  fill-ok: "#10b981"
  fill-wait: "#f59e0b"
  fill-late: "#ef4444"
  fill-info: "#60a5fa"
  fill-quiet: "#cbd5e1"
typography:
  headline:
    fontFamily: "Readex Pro Variable, sans-serif"
    fontSize: "24px"
    fontWeight: 600
    letterSpacing: "-0.02em"
  title:
    fontFamily: "Readex Pro Variable, sans-serif"
    fontSize: "18px"
    fontWeight: 600
    letterSpacing: "-0.02em"
  panel-title:
    fontFamily: "Readex Pro Variable, sans-serif"
    fontSize: "16px"
    fontWeight: 600
  figure:
    fontFamily: "Readex Pro Variable, sans-serif"
    fontSize: "26px"
    fontWeight: 600
  body:
    fontFamily: "Readex Pro Variable, sans-serif"
    fontSize: "16px"
    fontWeight: 400
    lineHeight: 1.5
  label:
    fontFamily: "Readex Pro Variable, sans-serif"
    fontSize: "14px"
    fontWeight: 400
  caption:
    fontFamily: "Readex Pro Variable, sans-serif"
    fontSize: "13px"
    fontWeight: 400
rounded:
  surface: "12px"
  control: "8px"
  track: "999px"
spacing:
  rail: "248px"
  page-block: "28px"
  page-inline: "32px"
  page-end: "64px"
  grid-gap: "16px"
  panel: "18px 20px 20px"
  filters: "14px"
---

# Design System: UFUQ

## Overview

**Creative North Star: "The Straight Shell"**

The shell is the administrative desk of UFUQ Imperium Group for Urbanism (إدارة شركة أفق إمبريوم غروب للعمران), built straight. Arabic is the default, right to left; English uses the same structure in the other direction. The density is a working desk: a fixed rail, a heading row, headline figures, charts, then records.

The page ground is cool gray. Work sits on white panels with a 1px hairline and a 12px radius. Action blue is the only interactive color. Late red, on-time green, and waiting amber mark state in text. Charts use a separate data palette so a chart never borrows a button color for meaning.

The rail mark is the supplied UFUQ logo (the brown house mark over the black UFUQ word). Under it sits the company administration line. There is no currency symbol. Every screen with synthetic figures carries the sample-data line.

**Key characteristics:**

- One self-hosted family, Readex Pro Variable, for Arabic and Latin
- Cool ground, white panels, 1px lines, no drop shadows
- A 248px rail grouped by area, with counts of waiting decisions
- Charts drawn in SVG, mirrored for right to left, with an accessible table behind each one
- Reports export as a styled Excel workbook and as a printable A4 document with the same charts

## Colors

### Interface

- **Action Blue** (#2563eb): Primary buttons, the active tab underline, nav badges, focus outline.
- **Pressed Blue** (#1d4ed8): Primary hover, the active rail item text, the active tab text.
- **Selected Wash** (#eff6ff): Active rail item background, the signed-in account avatar.
- **Notice Wash** (#dbeafe) with **Notice Line** (#bfdbfe): The undo banner, which leads with a check icon in Action Blue.
- **Cool Ground** (#f5f6f8): Page background.
- **White** (#ffffff): Rail, panels, stat cards, filter bar, tables, fields, bordered buttons.
- **Raised** (#fbfcfd): Table header rows inside panels and sheets.
- **Desk Ink** (#111827), **Quiet Ink** (#4b5563), **Soft Ink** (#6b7280): Reading text, labels and captions, axis labels.
- **Hairline** (#e5e7eb) and **Strong Line** (#d1d5db): Surface edges and field borders.

### Status

Status colors are for words and dots that state a condition: late, rejected, overdue (Late Red #b91c1c); on time, approved, present (On-Time Green #047857); waiting, due soon (Waiting Amber #b45309). Each has a tint used only behind a status chip. A status cell in a table is the word in its color with a 7px dot before it.

### Data palette

Charts use their own fills so a slice can be bright without becoming a control.

- **Series 1–5** (#2563eb, #93c5fd, #0f766e, #94a3b8, #7c3aed): Neutral series such as revenue and expense, estimate and actual, or on-time and late counts.
- **State fills**: present or approved #10b981, waiting or late arrival #f59e0b, absent or needs review #ef4444, leave or information #60a5fa, empty or off #cbd5e1. Left early uses Series 5, and a field mission uses Series 3.

Attendance marks always map to the same fill across the dashboard, the report, and the PDF.

### Named rules

**The One Accent Rule.** Action blue is the only interactive color. Data fills never become button fills.

**The Status-Only Rule.** Red, green, and amber text marks state. The quiet reject button is the only control in red.

## Typography

One variable family, Readex Pro Variable, for every word. Weights are 400 for reading, 500 for table headers, and 600 for titles, figures, and the active rail item. Counts, percentages, times, and dates use tabular figures. Arabic shows Latin digits; English uses the US locale. The UFUQ mark is an image, not type.

- **Headline** (600, 24px): The page title, and the greeting on the dashboard.
- **Title** (600, 18px): Section title on the ground.
- **Panel title** (600, 16px): The title inside a panel head, with a 13px Quiet Ink caption under it.
- **Figure** (600, 26px): The number in a stat card. Dashboard metric cards use 32px.
- **Body** (400, 16px, 1.5): Reading text and buttons. Notes are 14px Quiet Ink up to 72ch.
- **Label / Caption** (14px / 13px): Field labels, chart legends, axis labels (12px Soft Ink).

## Layout

The shell is a 248px rail and a fluid work column that fills the rest of the window. Charts and tables stretch to the panel width. The work column pads 28px on the block start, 32px inline, and 64px at the end plus the safe-area inset. Below 900px the rail hides behind the menu button; below 640px the padding is 20px and 16px.

The work column is a size container named `work`. Two-column grids (`.grid-2`) stack when the column is under 720px, and the wide-and-narrow pair (`.grid-3`, 1.4fr and 1fr) stacks under 900px. Breakpoints follow the column, not the window, so the rail never squeezes a table into a narrow column.

A module screen reads, top to bottom: the heading row (title, then the actions at the inline end, at most one primary), the sample-data line, a note, stat cards, chart panels, the filter bar, and the record sheet.

## Components

### Rail

White, sticky, full height. The rail opens with the UFUQ logo, then the localized company line (إدارة شركة أفق إمبريوم غروب للعمران / UFUQ Imperium Group for Urbanism). Navigation is grouped under small Soft Ink labels: Human resources, Projects and operations, Finance, System. Each link is an icon beside its name, 40px tall. The active link fills with Selected Wash and Pressed Blue at 600. A blue pill badge at the inline end counts decisions waiting for the signed-in account. The footer holds an account card (initials avatar, name, role line), the test account switch, the sample-data line, and the language switch.

### Buttons

40px tall, 8px radius, 8px by 14px padding, icon beside the label.

- **Primary**: Action Blue fill, white label, 600. Pressed Blue on hover. Disabled is Hairline with Quiet Ink.
- **Bordered** (`.text`): White, Hairline border, Desk Ink. Exports, approve, undo, open report.
- **Quiet** (`.ghost`): Transparent, Late Red label. Reject only.

### Stat cards

A row of white cards (`.summary`, `.stats`) that fill the row (auto-fit, 136px minimum, figures never wrap, 12px gap): caption, figure, small caption. A figure may carry a status color when the number itself is a warning, for example open fingerprint enrollments. Dashboard metric cards are larger: label, 32px figure, and a sub-line in a status color when it reports a condition.

### Panel

White, 1px Hairline, 12px radius, 18px 20px 20px padding. The panel head places the title and its caption at the inline start and one bordered action at the inline end. A table inside a panel bleeds to the panel edges (`.flush`) with a Raised header row. Any panel marked `data-print` is copied into the PDF report.

### Filter bar

A white panel holding the filters in an auto-fit grid (170px minimum). Each label stacks above its field in 13px Quiet Ink.

### Record sheet

A table inside a white bordered surface (`.sheet`) that scrolls horizontally on a narrow column. The header row is Raised, 14px, 500, Quiet Ink. Rows have a Hairline rule and Row Wash on hover. Names are links in Action Blue at 600. Numbers align to the end with tabular figures. Status cells use the status word with its dot.

### Tabs

A row of links with a hairline under them. The active tab is Pressed Blue at 600 with a 2px Action Blue underline. Used for the employee file sections and the attendance views.

### Charts

All charts are SVG drawn at the rendered width, which is tracked with a ResizeObserver. In Arabic the x axis runs right to left; y-axis numbers always sit on the outside edge. Each chart has a visually hidden table with the same data, a legend for more than one series, and a tooltip on hover.

- **Line chart**: One or two series with a soft area fill under the first. Used for the attendance rate over recent workdays.
- **Bar chart**: Grouped bars with a 3px corner, or square stacked bars. Used for the daily attendance mix, hours worked, requests by kind and status, project stages, and estimate against actual.
- **Donut chart**: A ring with the total in the center and a legend that lists count and share. Used for today's attendance, leave and request states, employees by status, account state, purchase state, and payment state.
- **Rank chart**: Horizontal bars for named items with the value, and an optional unit, at the end. Used for lateness by department, headcount by department, attendance rate by employee, leave by type, and project progress.

The y axis is divided into four steps, and the top is four times a round step (for example 8, 12, 20, or 100), so gridlines carry whole numbers. Axis labels are spaced from the newest point backwards so the latest date is always labeled.

### Reports

Every report has an Excel and a PDF action on the heading row.

- **Excel**: A real .xlsx built in the browser (exceljs, loaded only when exporting). A summary sheet carries the title, period, scope, issued by, issued at, the headline figures, and the sample-data footer. Detail sheets have a styled header row, a frozen header, filters, column widths fitted to content, and a right-to-left view in Arabic.
- **PDF**: An A4 document in a hidden frame, printed through the browser dialog. It has the UFUQ logo, the title, a metadata strip (period, scope, issued by, issued at), the headline figures, the page's chart panels, and the tables with a header that repeats on every page and a page counter. Landscape is used for wide tables.

### Notice

A Notice Wash banner with a check icon, the message, and an undo button at the inline end. It confirms a change the signed-in person just made, and clears itself after five seconds. A server error uses the Late Red tint with an exclamation icon.

### Sign-in

A full-height split. The sign-in column is white: the language switch at the top, then a 36px title, a lede, 54px fields with a leading icon (the password field carries a show and hide eye at the inline end), a full-width primary button, and a hint about who resets passwords. The other column is a drafting sheet on Cool Ground: a 24px and 120px blueprint grid in faint Action Blue, the UFUQ logo, the company line at 34px, and building elevations drawn as Action Blue line work over a ground line. Below 960px the drafting sheet hides and the logo moves into the top bar.

## Permissions in the interface

The interface only shows what the signed-in account may do; the store refuses the same actions again. The company director sees everything and may act on every record. Nobody else may act on a director's account or file, and only a director grants the director role. A locked row in the users screen shows a lock icon and no toggle. Edit buttons in an employee file appear only when the account may edit that file, and nobody edits their own file except the director.

The dashboard is built from the same rules. A director sees every section. HR sees people, attendance, leave and requests, and payroll. Finance sees payroll, revenue and expense, budget, and purchases. A project manager sees projects and their team's decisions. An employee sees their own month: hours worked per day, their attendance mix, their leave, and their open requests. The system manager sees accounts by role and account state.

## Do's and Don'ts

### Do

- **Do** keep the rail, the type, and the logical directions on every module screen.
- **Do** put work on white panels and keep at most one primary action on the heading row.
- **Do** use the data palette for charts and the status colors for words.
- **Do** give every chart a caption that says its scope and period, and a hidden table.
- **Do** mark every panel that belongs in the PDF with `data-print`.
- **Do** label synthetic figures, and leave money as plain numbers next to the note that currency is not set.

### Don't

- **Don't** add drop shadows, a second typeface, or a dark theme.
- **Don't** invent a currency, salary amounts, or personal data. The UFUQ logo and the company administration line are the supplied brand; do not restyle them.
- **Don't** use a data fill as a button color, or a status color as a chart decoration.
- **Don't** draw an axis on fractional steps, or leave the newest point unlabeled.
- **Don't** add motion beyond what exists: the leave queue opening and rank fills easing to their width (240ms), chart hover fades (160ms), and the rail hover wash (120ms). All of it is removed under reduced motion.
