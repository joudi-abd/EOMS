---
name: UFUQ
description: Arabic-first working shell for an engineering office. A slate ground, white rail and sheets, one indigo that acts and marks where you are, status colour only for state, charts drawn from a fixed data palette.
colors:
  ground: "#f2f3f8"
  surface: "#ffffff"
  raised: "#f8f8fc"
  hover: "#ecedf5"
  row: "#f7f7fc"
  line: "#e1e3ee"
  line-strong: "#c7cadb"
  ink: "#14162b"
  muted: "#454a63"
  soft: "#666b85"
  action: "#4f46c8"
  action-ink: "#3f37a8"
  action-tint: "#ecebfb"
  mark-soft: "#b9b5f0"
  notice-line: "#cfcbf3"
  scene: "#1e1b52"
  scene-ink: "#f1f0ff"
  late: "#b42318"
  late-tint: "#fdecea"
  late-line: "#f3c2bc"
  ontime: "#0f7b5c"
  ontime-tint: "#e1f4ec"
  wait: "#96590a"
  wait-tint: "#fcf1d6"
  wait-line: "#edd79b"
  series-1: "#4f46c8"
  series-2: "#b9b5f0"
  series-3: "#0e8fa3"
  series-4: "#9aa0b8"
  series-5: "#b4488a"
  fill-ok: "#2ba37a"
  fill-wait: "#e3a11b"
  fill-late: "#d9503f"
  fill-info: "#5b9bd5"
  fill-quiet: "#cdd1e0"
typography:
  headline:
    fontFamily: "Readex Pro Variable, sans-serif"
    fontSize: "26px"
    fontWeight: 600
    lineHeight: 1.35
  title:
    fontFamily: "Readex Pro Variable, sans-serif"
    fontSize: "18px"
    fontWeight: 600
    lineHeight: 1.45
  panel-title:
    fontFamily: "Readex Pro Variable, sans-serif"
    fontSize: "16px"
    fontWeight: 600
  metric:
    fontFamily: "Readex Pro Variable, sans-serif"
    fontSize: "38px"
    fontWeight: 600
    lineHeight: 1.1
    letterSpacing: "-0.03em"
  figure:
    fontFamily: "Readex Pro Variable, sans-serif"
    fontSize: "28px"
    fontWeight: 600
    lineHeight: 1.2
    letterSpacing: "-0.02em"
  body:
    fontFamily: "Readex Pro Variable, sans-serif"
    fontSize: "16px"
    fontWeight: 400
    lineHeight: 1.5
  table:
    fontFamily: "Readex Pro Variable, sans-serif"
    fontSize: "15px"
    fontWeight: 400
  label:
    fontFamily: "Readex Pro Variable, sans-serif"
    fontSize: "14px"
    fontWeight: 400
  caption:
    fontFamily: "Readex Pro Variable, sans-serif"
    fontSize: "13px"
    fontWeight: 400
  axis:
    fontFamily: "Readex Pro Variable, sans-serif"
    fontSize: "12px"
    fontWeight: 400
  sign-in:
    fontFamily: "Readex Pro Variable, sans-serif"
    fontSize: "34px"
    fontWeight: 600
    lineHeight: 1.4
rounded:
  surface: "12px"
  control: "8px"
  chip: "6px"
  dialog: "16px"
  track: "999px"
spacing:
  rail: "256px"
  top-bar: "64px"
  page-block: "28px"
  page-inline: "36px"
  page-end: "64px"
  grid-gap: "16px"
  panel: "20px 22px 22px"
  sheet: "22px 24px"
  filters: "16px 18px"
  strip-cell: "16px 18px"
components:
  button-primary:
    backgroundColor: "{colors.action}"
    textColor: "{colors.surface}"
    rounded: "{rounded.control}"
    padding: "8px 16px"
    height: "40px"
  button-primary-hover:
    backgroundColor: "{colors.action-ink}"
  button-primary-active:
    backgroundColor: "{colors.scene}"
  button-primary-disabled:
    backgroundColor: "{colors.line}"
    textColor: "{colors.soft}"
  button-bordered:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.control}"
    padding: "8px 16px"
    height: "40px"
  button-bordered-hover:
    backgroundColor: "{colors.hover}"
  button-quiet:
    textColor: "{colors.late}"
    rounded: "{rounded.control}"
    padding: "8px 16px"
    height: "40px"
  button-quiet-hover:
    backgroundColor: "{colors.late-tint}"
  field:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.control}"
    padding: "0 12px"
    height: "40px"
  panel:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.surface}"
    padding: "20px 22px 22px"
  sheet:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.surface}"
    padding: "22px 24px"
  figure-strip:
    backgroundColor: "{colors.surface}"
    typography: "{typography.figure}"
    rounded: "{rounded.surface}"
    padding: "16px 18px"
  filter-bar:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.surface}"
    padding: "16px 18px"
  rail-link:
    textColor: "{colors.muted}"
    rounded: "{rounded.control}"
    padding: "8px 12px"
    height: "40px"
  rail-link-active:
    backgroundColor: "{colors.action-tint}"
    textColor: "{colors.action-ink}"
  tab:
    textColor: "{colors.muted}"
    typography: "{typography.table}"
    padding: "8px 14px"
    height: "44px"
  tab-active:
    textColor: "{colors.ink}"
  segment-chosen:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.action-ink}"
    rounded: "{rounded.chip}"
  notice:
    backgroundColor: "{colors.action-tint}"
    textColor: "{colors.ink}"
    rounded: "{rounded.control}"
  status-chip-ok:
    backgroundColor: "{colors.ontime-tint}"
    textColor: "{colors.ontime}"
    rounded: "{rounded.track}"
  status-chip-wait:
    backgroundColor: "{colors.wait-tint}"
    textColor: "{colors.wait}"
    rounded: "{rounded.track}"
  status-chip-late:
    backgroundColor: "{colors.late-tint}"
    textColor: "{colors.late}"
    rounded: "{rounded.track}"
---

# Design System: UFUQ

## Overview

**Creative North Star: "Slate and Indigo"**

The shell is the administrative desk of UFUQ Imperium Group for Urbanism (إدارة شركة أفق إمبريوم غروب للعمران). Arabic is the default, right to left; English uses the same structure in the other direction. It is a quiet slate office: cool slate neutrals carry every surface, one indigo acts and shows where you are, and red, amber and green state a condition and nothing else.

The same four pieces open every screen, so nothing has to be relearned: a page title standing on its datum, a joined strip of figures, a filter bar, and a record sheet. Work sits on white surfaces with a 1px hairline and a 12px corner. The signature is the datum: one hairline carrying an indigo segment, shared by every page title, every tab row, and the sign-in title. The sign-in scene is the one place the indigo goes deep.

The rail mark is the supplied UFUQ logo. Under it sits the company administration line. There is no currency symbol.

**Key Characteristics:**

- One self-hosted family, Readex Pro Variable, for Arabic and Latin
- Slate ground, white rail and surfaces, 1px hairlines, no shadow at rest
- Indigo fills what acts; a tint and an underline mark where you are
- A 256px white rail grouped by area, and a 64px white top bar
- Figures read as one joined strip, never as separate cards
- Charts drawn in SVG from a fixed data palette, mirrored for right to left, with a table behind each one
- Reports export as a styled Excel workbook and a printable A4 document in the same colours

## Colors

Cool slate neutrals with a single indigo; every colour is a custom property in `src/styles/_tokens.scss`, and no component stylesheet carries a hex of its own.

### Primary

- **Acting Indigo** (`action`, also named `mark`): Primary button fill, the count badge in the rail, the bell's unread dot, the focus outline, checkbox and radio accent, the datum segment, the active tab underline, the current pay-run stage border.
- **Indigo Ink** (`action-ink`, also `mark-ink`): Primary hover, name links in tables, link buttons, and the text of anything marked on a tint (the active rail link, avatars, the chosen segment).
- **Indigo Tint** (`action-tint`, also `mark-tint` and `notice`): The ground under a marked position (active rail link, avatars, current pay-run stage, a picked table row), the 3px focus ring around a field, the pressed bordered button, and the notice banner, edged by **Notice Line** (`notice-line`).
- **Lavender** (`mark-soft`, the same value as `series-2`): The zero line a chart's bars stand on, the rule above a grand total, text selection, and the line work of the sign-in scene.
- **Deep Indigo** (`scene`) with **Scene Ink** (`scene-ink`): The sign-in scene only, plus the pressed state of a primary button.

### Neutral

- **Slate Ground** (`ground`): Page background, the search field at rest, chart tracks.
- **White** (`surface`): Rail, top bar, panels, sheets, the figure strip, the filter bar, fields, bordered buttons.
- **Raised** (`raised`): Table header rows, disabled fields, sub-rows, the sign-in fields at rest.
- **Hover Wash** (`hover`) and **Row Wash** (`row`): Hover on links and bordered controls and the trough of a segmented choice; hover on a table row.
- **Hairline** (`line`) and **Strong Line** (`line-strong`): Surface edges and dividers; field and bordered-button borders, the rule under a sheet's header row, the ring of a chosen segment.
- **Desk Ink** (`ink`), **Quiet Ink** (`muted`), **Soft Ink** (`soft`): Reading text; labels, captions and axis labels; group labels, placeholders, resting icons and disabled text.

### Status

Status colours are for words, dots and chips that state a condition: late, rejected, overdue (**Late Red**, `late`); on time, approved, present (**On-Time Green**, `ontime`); waiting, due soon (**Waiting Amber**, `wait`). Each has a tint used behind a status chip or a warning line, and red and amber have a line colour for the edge of that tint. A status cell in a table is the word in its colour with a 7px dot before it.

### Data palette

Charts use their own fills so a slice can be bright without becoming a control. Components read them through the `tone` map in `shared/charts/chart.ts`, never as hex.

- **Series 1 to 5** (`series-1` indigo, `series-2` lavender, `series-3` teal, `series-4` slate, `series-5` plum): Neutral series such as revenue and expense, estimate and actual, or on-time and late counts.
- **State fills**: present or approved `fill-ok`, waiting or late arrival `fill-wait`, absent or needs review `fill-late`, leave or information `fill-info`, empty or off `fill-quiet`.

Attendance marks map to the same fill across the dashboard, the report, and the PDF.

### Named Rules

**The One Indigo Rule.** Indigo is the only interactive colour. There is no second accent, and a data fill never becomes a button fill.

**The Act and Mark Rule.** A solid indigo fill means "this acts" (a primary button, a count that waits for you). Position is never a solid fill: where you are is an indigo tint with indigo ink (rail link, avatar, pay-run stage), an indigo underline (tab), or a white chip in indigo ink (segmented choice).

**The Status-Only Rule.** Red, amber and green state a condition. The quiet refuse button is the only control in red. They do not decorate a chart or brand the shell.

## Typography

**Display Font:** Readex Pro Variable (with sans-serif)
**Body Font:** Readex Pro Variable (with sans-serif)

**Character:** One variable family carries every word in both scripts, so Arabic and Latin share one colour on the page. Hierarchy comes from size and three weights: 400 for reading, 500 for table headers, labels and bordered buttons, 600 for titles, figures and anything marked.

### Hierarchy

- **Sign-in title** (600, 34px, 1.4; 28px under 480px): The sign-in heading and the company line on the scene.
- **Headline** (600, 26px, 1.35): The page title, and the greeting on the dashboard.
- **Title** (600, 18px, 1.45): A section title on the ground, and the title of a working sheet.
- **Panel title** (600, 16px): The title in a panel head, with a 13px Quiet Ink caption under it.
- **Metric** (600, 38px, 1.1, -0.03em; 28px on a phone): The dashboard's headline figures.
- **Figure** (600, 28px, 1.2, -0.02em; 22px on a phone): The number in a module's figure strip.
- **Body** (400, 16px, 1.5): Reading text and buttons. Notes and ledes are 14px Quiet Ink up to 72ch.
- **Table** (400, 15px): Table cells and tabs. Table headers are 13px at 500 in Quiet Ink.
- **Label / Caption / Axis** (14px / 13px / 12px): Rail links and form labels; filter labels, strip captions and fact labels; axis labels, legends, group labels and role lines.

### Named Rules

**The One Family Rule.** Readex Pro Variable carries every role in Arabic and Latin. There is no display face and no monospace face; the earlier title face is gone.

**The Untouched Joins Rule.** Arabic text is never letter-spaced. Latin page and section titles tighten by 0.015em; figures tighten by 0.02em to 0.03em.

**The Latin Figures Rule.** Counts, percentages, times and dates use tabular figures. Arabic shows Latin digits; English uses the US locale. Latin values inside Arabic text (staff numbers, codes, e-mail addresses, amounts) are isolated so their order never flips.

## Layout

The shell is a 256px rail at the inline start and a fluid stage. The stage opens with a sticky 64px white top bar and continues with the work column, which pads 28px on the block start, 36px inline, and 64px at the end plus the safe-area inset. Under 640px that padding is 20px, 16px and 48px.

Under 900px the rail leaves the grid and becomes a fixed drawer (up to 320px, 88% of the window at most) that opens below the top bar, so the bar and its close button stay in reach. The bar's menu button opens and closes it, a slate scrim covers the work behind it, and choosing a link closes it. The account's name and role collapse to the avatar.

The work column is a size container named `work`. Grids break on the column, not the window, so the rail never squeezes a table: the two-column grid stacks under 720px, the wide pair under 900px, and the finance pair (1.6fr beside 320px minimum) under 1240px.

A module screen reads, top to bottom: the heading (title and lede at the inline start, actions at the inline end, at most one primary), a notice when something just changed, the figure strip, tabs or a segmented choice, chart panels, the filter bar, and the record sheet. Sections on the ground are 40px apart; grid gaps are 16px. Under 640px the heading's actions take the full width and share it equally.

**The Own Folder Rule.** Every component lives in its own folder with its own `.ts`, `.html` and `.scss`. A component's stylesheet holds only what belongs to that component; anything two screens share lives in `src/styles/` (tokens, base, frame, controls, surfaces, tabs, tables, the ledger kit, the leave kit, print). A kit is opted into with a class on the component's host (`ledger`, `leave-kit`).

## Elevation & Depth

The system is flat. Regions are separated by the slate ground against white and by 1px hairlines; nothing lifts on hover, and a shadow is never a state. The 1px and 3px rings in the build (strip cells, the chosen segment, field focus) are lines drawn with `box-shadow`, not depth.

### Shadow Vocabulary

- **Pop** (`box-shadow: 0 18px 40px -12px rgb(20 22 43 / 0.22), 0 2px 6px rgb(20 22 43 / 0.06)`): Only for what floats above the page: the open mobile drawer and the chart tooltip.

### Named Rules

**The Flat At Rest Rule.** Panels, sheets, strips and buttons carry no shadow. The single shadow belongs to a layer that is physically over the page.

## Shapes

Gently rounded and consistent: surfaces (panels, sheets, the figure strip, the filter bar, pay-run stages) take 12px; controls (buttons, fields, rail links, the notice, tooltips, the top-bar buttons) take 8px; the inner chip of a segmented choice takes 6px; dialogs and the working sheet take 16px. Full rounds are reserved for avatars, dots, count badges, status chips and chart tracks.

Edges are a 1px hairline everywhere. The joined strip is the one clipped form: an outer hairline with a 12px corner clips cells that each ring themselves with a hairline, so neighbours share one line and a short last row leaves clean white.

**The datum** is the recurring geometry: a 1px hairline under a title with a 72px by 3px indigo segment at its inline start, and the same line under a tab row with a 3px indigo underline beneath the tab you are on.

## Components

### Rail

A quiet white column, sticky and full height, edged by a hairline. It opens with the UFUQ logo (108px wide) and the localized company line in 12px Quiet Ink over a hairline. Links are grouped under 12px Soft Ink labels; each is 40px tall with an 8px corner, an icon in Soft Ink and its name at 14px in Quiet Ink. Hover is Hover Wash with Desk Ink. The link you are on is Indigo Tint with Indigo Ink at 600 and an indigo icon. A solid indigo pill at the inline end counts what waits for the signed-in account. The foot holds the account card (a tinted initials avatar, the name, the role line) and a bordered sign-out button.

### Top bar

White, sticky, 64px, edged below by a hairline. From the inline start: the menu button (under 900px only), the employee search (a borderless slate field that turns white with an indigo border and a tint ring on focus), then at the inline end the language button, the bell with an indigo unread dot, and the account's tinted avatar with name and role.

### Page heading and the datum

The title (and its lede or sub-line in 14px Quiet Ink) at the inline start, the actions at the inline end, all standing on the datum. Nothing sits above the title: no breadcrumb and no eyebrow line. The datum's indigo segment is removed in print.

### Buttons

Indigo acts, a bordered button offers, a quiet red one refuses. All are 40px tall with an 8px corner and 8px by 16px padding, the icon beside the label, and colour transitions of 140ms.

- **Primary**: Acting Indigo fill and border, white label at 600. Indigo Ink on hover, Deep Indigo when pressed. Disabled is Hairline with Soft Ink.
- **Bordered** (`.text`, `.ghost`): White with a Strong Line border, Desk Ink at 500. Hover Wash on hover, Indigo Tint when pressed. Exports, approve, undo, open.
- **Quiet** (`.quiet`): Transparent with a Late Red label at 500; Late Tint with a Late Line border on hover. Refuse and remove only.
- **Small** (inside the ledger kit): 32px tall, 14px, for row actions.

### Fields

White, a Strong Line border, 8px corner, 40px tall, 12px inline padding. The border darkens to Soft Ink on hover; focus turns the border indigo and adds a 3px Indigo Tint ring in place of the outline. Disabled is Raised with Soft Ink. Checkboxes and radios are 18px with an indigo accent and keep the 2px indigo outline. Everything else that takes focus shows a 2px indigo outline, 2px outside.

### Filter bar

A white surface (hairline, 12px corner, 16px by 18px padding) that scopes the sheet under it. Filters sit in an auto-fit grid with a 170px minimum, each label stacked above its field in 13px Quiet Ink. Its own actions take a row to themselves at the inline end.

### Figure strip

Figures are one joined strip, not a row of cards, so a row of numbers reads as one statement. A white surface with a hairline and a 12px corner holds cells of 16px by 18px, each ringed by a hairline: a 13px Quiet Ink caption, the 28px figure, and an optional 12px note. The strip never leaves one cell alone: cells auto-fit at a 136px minimum, counts of five to eight rebalance into rows of three or four with the last cell spanning, and under 560px the strip is two columns with an odd last cell taking the full row. A figure may take a status colour when the number itself is a warning. The dashboard's strip is the larger form: a 168px minimum, 20px by 22px cells, a 14px label, the 38px metric, and a 13px sub-line that takes a status colour when it reports a condition.

### Panel

White, 1px Hairline, 12px corner, 20px 22px 22px padding. The panel head places the title and its caption at the inline start and one bordered action at the inline end. A table inside a panel bleeds to the panel edges with a Raised header row. Any panel marked `data-print` is copied into the PDF report.

### Sheet

Forms and fact lists sit on a white sheet: 22px by 24px padding, a hairline, a 12px corner. Facts run two to a row, the label in 13px Soft Ink over its value. The ledger kit's working sheet is the dialog-in-place variant for a form or decision above a list: a Strong Line border, a 16px corner, a header row over a hairline with a square close button, and its actions at the inline end.

### Record sheet

A table inside a white bordered surface with a 12px corner that scrolls horizontally on a narrow column. The header row is Raised over a Strong Line rule, 13px at 500 in Quiet Ink. Cells are 15px with 12px by 14px padding (18px at the sheet's edges) and a Hairline rule; rows take Row Wash on hover. Names are links in Indigo Ink at 600. Numbers use tabular figures and never wrap. Status cells are the status word with its dot. In the ledger kit a row that needs attention sits on Waiting Tint, a picked row on Indigo Tint, and a sub-row on Raised.

### Tabs

First-level navigation inside a screen: a row of text links on the datum's hairline, 44px tall, 15px, Quiet Ink, with no icons. Hover is Desk Ink on Hover Wash. The tab you are on is Desk Ink at 600 over a 3px indigo underline. The row scrolls sideways on a narrow column. Used for the employee file, attendance views, finance, payroll and settings.

### Segmented choice

The second level under tabs, and any one-of-a-few choice, so two rows never read as one. A Hover Wash trough with an 8px corner and 3px padding holds 14px Quiet Ink items; the chosen item is a white chip in Indigo Ink at 600, ringed by a Strong Line. Used for whose requests to show, the sections of leave settings, and choices inside finance forms.

### Status chips and pay-run stages

A status chip is a full-round pill, 24px tall, 13px at 500: the status tint behind the status word, or Hover Wash with Quiet Ink when neutral. A pay run shows its four stages as white bordered cells; a finished stage carries a green check, and the current stage is Indigo Tint with an indigo border.

### Notice

An Indigo Tint banner with a Notice Line border and an 8px corner: a check icon in indigo, the message, and a bordered undo button at the inline end. It confirms a change the signed-in person just made. A problem uses Late Tint with a Late Line border and Late Red text; a caution inside a form uses the amber equivalents.

### Charts

All charts are SVG drawn at the rendered width, which is tracked with a ResizeObserver. In Arabic the x axis runs right to left; y-axis numbers always sit on the outside edge. Each chart has a visually hidden table with the same data, a legend for more than one series, and a tooltip on hover (Desk Ink ground, white text, 8px corner, the Pop shadow).

- **Line chart**: One or two series at 2.25px with a soft area fill under the first and white-centred dots. Used for the attendance rate over recent workdays.
- **Bar chart**: Grouped bars with a 3px corner, or square stacked bars, standing on a lavender zero line. Used for the daily attendance mix, hours worked, requests by kind and status, project stages, and estimate against actual.
- **Donut chart**: An 18px ring on a Slate Ground track with the total in the centre and a list of count and share beside it. Used for today's attendance, leave and request states, employees by status, account state, purchase state, and payment state.
- **Rank chart**: Horizontal 10px full-round bars on a Slate Ground track for named items, with the value and an optional unit at the end. Used for lateness by department, headcount by department, attendance rate by employee, leave by type, and project progress.

The y axis is divided into four steps, and the top is four times a round step (for example 8, 12, 20, or 100), so gridlines carry whole numbers. Axis labels are spaced from the newest point backwards so the latest date is always labeled.

### Reports

Every report has an Excel and a PDF action on the heading row. Both carry the token colours as fixed values, since they leave the page: Desk Ink text, Quiet and Soft Ink labels, Hairline rules, Raised bands, and Acting Indigo for the header.

- **Excel**: A real .xlsx built in the browser (exceljs, loaded only when exporting). A summary sheet carries the title, period, scope, issued by, issued at, the headline figures, and the footer. Detail sheets have a styled header row, a frozen header, filters, column widths fitted to content, and a right-to-left view in Arabic.
- **PDF**: An A4 document in a hidden frame, printed through the browser dialog. It has the UFUQ logo over a 2px indigo rule, the title, a metadata strip on Raised (period, scope, issued by, issued at), the headline figures, the page's chart panels, and tables whose indigo header with white text repeats on every page above banded rows, with a page counter. Landscape is used for wide tables.

### Tables and print

Every header and cell aligns to the reading start, in the app, the PDF, and the Excel file. Latin values inside Arabic text (staff numbers, codes, e-mail addresses, amounts) are isolated so their order never flips. Report documents use no page margin, so the browser prints no URL, title, or date; a frame whose header and footer rows repeat on every page gives the spacing instead. Printing a screen directly hides the rail, the top bar, tabs, filters, actions and buttons, and lets the sheet run to its full width.

### Employee file records

Contracts are white cards, one per contract: the number, a status chip (draft in Waiting Amber, active in On-Time Green, terminated in Late Red), the type and dates, then the actions under a hairline. Job history is a timeline on an inline-start rule, with the old value struck through in Soft Ink. Documents are a list in one white surface with a tinted file-type icon, the title, and the type, file name, size, and date in a caption, with download and delete at the inline end.

### Fingerprint enrollment dialog

A native modal dialog, 540px, 16px corner, hairline edge, over a dimmed ground. A fingerprint sits in a tinted disc inside a ring of three arcs, one per press; the tint follows the state (quiet when idle, indigo while live, On-Time Green when enrolled, Late Red when it fails), a scan line sweeps the disc while the finger is down, and a small badge marks success or failure. The four stages run as a list beneath it. When the server has no enrollment endpoint the headline turns Waiting Amber and starting stays disabled.

### Sign-in

A full-height split, and the one scene where the indigo goes deep. The sign-in column is white: the language button at the top, then the 34px title standing on the same datum as every page, a lede, 54px fields on Raised with a 12px corner and a leading icon (the password field carries a show and hide eye at the inline end), and a full-width 54px indigo button. The other column is Deep Indigo: the UFUQ logo on a white plaque with a 16px corner, the company line at 34px in Scene Ink, and the office's building elevations drawn as lavender line work over a 3px ground line. Under 960px the scene hides and the logo moves into the column's top bar.

## Permissions in the interface

The backend is the source of truth. `/auth/me` returns role names only, so the interface rebuilds each account's permissions from its roles with the same table the backend seeder uses (`core/access/accounts.ts`), and every area and action names the permissions it needs. The store refuses the same actions again, and the server refuses them a third time.

- **System manager**: every permission, including accounts and role assignment. The only role that opens the users screen.
- **Company manager**: every `.view` and `.approve` permission. Sees every module except users, decides leave, purchases, payroll and attendance, and edits nothing. Personal and salary fields stay hidden (no `view_sensitive`, no `view_financial`).
- **HR**: employees, contracts, documents, attendance, leave, reports. No payroll, finance, or meetings. Reads personal data but not salary or wallet. HR edits the shift hours, which are versioned: a save posts a new version to `/attendance/policies` that rules from a chosen date (tomorrow or later), and the screen lists every version with its state.
- **Finance**: payroll (prepare, review, pay), finance, and purchase approval. It cannot add purchases or approve payroll.
- **Project manager**: projects, meetings, documents, reports, and a read-only employee list.
- **Engineer** and **purchasing officer**: the engineer reads projects, meetings and documents; the purchasing officer adds purchases and reads assets.
- **Employee**: no permissions. Reaches its own attendance, leave and requests through its employee file.

A locked row in the users screen shows a lock icon and no toggle. Nobody below a director acts on a director's account or file, and only a system manager grants roles. The account form shows each role as a card with its permission count and a one-line summary, and a panel beneath lists the permissions the chosen roles grant, grouped by module. Edit buttons in an employee file appear only when the account may edit that file, and nobody edits their own file except a director.

Lists are requested only when the account holds the permission to read them, so a role never triggers a forbidden request.

## Do's and Don'ts

### Do:

- **Do** open every screen with the same pieces: the title on its datum, the joined figure strip, the filter bar, the record sheet.
- **Do** fill with indigo only what acts, and mark where you are with a tint, an underline, or a white chip in indigo ink.
- **Do** keep at most one primary action on the heading row; everything else is bordered, and refusal is quiet red.
- **Do** join figures into one strip and let the last cell span so no figure sits alone.
- **Do** put forms and fact lists on a white sheet (22px by 24px, hairline, 12px corner).
- **Do** take colour from the tokens: `--series-*` and `--fill-*` for data, status colours for words, and matching fixed values in the Excel and PDF builders.
- **Do** give every chart a caption that says its scope and period, and a hidden table; mark every panel that belongs in the PDF with `data-print`.
- **Do** break layouts on the `work` container, and keep logical directions so Arabic and English share one structure.
- **Do** keep a component's stylesheet to that component, and move anything shared into `src/styles/`.
- **Do** leave money as plain numbers; the currency is not set.

### Don't:

- **Don't** add a second accent, a second typeface, or a dark theme. The deep indigo belongs to the sign-in scene.
- **Don't** mark position with a solid indigo fill, or use a data fill as a button colour, or a status colour as decoration.
- **Don't** put a breadcrumb or an eyebrow line above a page title, or icons in first-level tabs.
- **Don't** add a shadow to a surface at rest. The Pop shadow is for the drawer and the tooltip.
- **Don't** split figures into separate cards.
- **Don't** write a hex in a component stylesheet, or letter-space Arabic.
- **Don't** invent a currency, salary amounts, or personal data. The UFUQ logo and the company administration line are the supplied brand; do not restyle them.
- **Don't** draw an axis on fractional steps, or leave the newest point unlabeled.
- **Don't** add motion beyond what exists: colour and border changes on controls (140ms), row hover (120ms), chart hover fades (160ms), the leave queue opening and rank fills easing to their width (240ms), and the busy spinner and fingerprint scan. All of it is removed under reduced motion.
