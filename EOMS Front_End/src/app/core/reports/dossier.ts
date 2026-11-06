import { escape, framed, frameCss, isolate, printDocument } from './pdf';

export interface DossierField {
  label: string;
  value: string;
}

export interface DossierSection {
  title: string;
  fields?: DossierField[];
  table?: { columns: string[]; rows: string[][] };
  empty?: string;
  note?: string;
}

/** A document appended after the record: its pages as images, or a note when it cannot be shown. */
export interface DossierAttachment {
  label: string;
  title: string;
  meta: string;
  pages: string[];
  note: string;
}

export interface DossierSpec {
  rtl: boolean;
  documentTitle: string;
  brand: string;
  name: string;
  line: string;
  status: string;
  statusTone: 'ok' | 'wait' | 'quiet';
  meta: [string, string][];
  sections: DossierSection[];
  attachmentsTitle: string;
  attachments: DossierAttachment[];
  footer: string;
}

const sheet = `
@page { size: A4 portrait; margin: 0; }
html, body { background: #fff !important; }
body { margin: 0; color: #111827; font: 11.5px/1.55 'Readex Pro Variable', sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.d-top { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding-bottom: 12px; border-bottom: 3px solid #2563eb; }
.d-brand { display: flex; align-items: center; gap: 12px; }
.d-brand img { height: 46px; width: auto; }
.d-brand p { margin: 0; color: #4b5563; font-size: 11px; max-width: 34ch; }
.d-doc { margin: 0; font-size: 18px; font-weight: 600; color: #1d4ed8; }
.d-id { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin: 18px 0 12px; }
.d-id h1 { margin: 0; font-size: 26px; font-weight: 600; line-height: 1.25; }
.d-id .d-line { margin: 4px 0 0; color: #4b5563; font-size: 12.5px; font-variant-numeric: tabular-nums; }
.d-status { flex: none; padding: 4px 12px; border-radius: 999px; background: #ecfdf5; color: #047857; font-weight: 600; font-size: 12px; }
.d-status.wait { background: #fffbeb; color: #b45309; }
.d-status.quiet { background: #f3f4f6; color: #4b5563; }
.d-meta { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px 16px; margin: 0 0 6px; padding: 10px 12px; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; }
.d-meta div { display: grid; }
.d-meta dt { color: #6b7280; font-size: 10px; }
.d-meta dd { margin: 0; font-weight: 500; }
.d-section { margin-top: 18px; }
.d-section h2 { margin: 0 0 8px; padding-bottom: 5px; font-size: 14px; font-weight: 600; border-bottom: 1px solid #d1d5db; break-after: avoid; }
.d-fields { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px 18px; margin: 0; }
.d-fields div { display: grid; gap: 1px; break-inside: avoid; }
.d-fields dt { color: #6b7280; font-size: 10px; }
.d-fields dd { margin: 0; font-weight: 500; overflow-wrap: anywhere; }
.d-empty, .d-note { margin: 4px 0 0; color: #6b7280; }
table.d-table { width: 100%; border-collapse: collapse; font-size: 10.5px; font-variant-numeric: tabular-nums; }
.d-table thead { display: table-header-group; }
.d-table th { background: #2563eb; color: #fff; font-weight: 600; padding: 6px 8px; text-align: start; white-space: nowrap; }
.d-table td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; text-align: start; vertical-align: top; }
.d-table tr { break-inside: avoid; }
.d-table tbody tr:nth-child(even) td { background: #f8fafc; }
.d-sheet { box-sizing: border-box; height: 296mm; padding: 12mm 12mm 14mm; break-before: page; display: flex; flex-direction: column; overflow: hidden; }
.d-sheet header { display: flex; align-items: baseline; gap: 10px; padding-bottom: 6px; border-bottom: 2px solid #2563eb; }
.d-sheet .d-tag { color: #1d4ed8; font-weight: 600; }
.d-sheet h3 { margin: 0; font-size: 14px; }
.d-sheet .d-count { margin-inline-start: auto; color: #6b7280; font-size: 10.5px; }
.d-sheet .d-sub { margin: 6px 0 10px; color: #6b7280; font-size: 10.5px; }
.d-sheet figure { flex: 1; min-height: 0; margin: 0; display: flex; align-items: center; justify-content: center; border: 1px solid #e5e7eb; }
.d-sheet img { display: block; max-width: 100%; max-height: 100%; object-fit: contain; }
.d-foot { margin-top: 18px; padding-top: 8px; border-top: 1px solid #e5e7eb; color: #6b7280; font-size: 9.5px; }
`;

function section(item: DossierSection): string {
  const fields = item.fields?.length
    ? `<dl class="d-fields">${item.fields.map((field) => `<div><dt>${escape(field.label)}</dt><dd>${isolate(field.value || '—')}</dd></div>`).join('')}</dl>`
    : '';
  const table = item.table?.rows.length
    ? `<table class="d-table"><thead><tr>${item.table.columns.map((column) => `<th>${escape(column)}</th>`).join('')}</tr></thead><tbody>${item.table.rows
        .map((row) => `<tr>${row.map((cell) => `<td>${isolate(cell || '—')}</td>`).join('')}</tr>`)
        .join('')}</tbody></table>`
    : '';
  const empty = !fields && !table && item.empty ? `<p class="d-empty">${escape(item.empty)}</p>` : '';
  const note = item.note ? `<p class="d-note">${escape(item.note)}</p>` : '';
  return `<section class="d-section"><h2>${escape(item.title)}</h2>${fields}${table}${empty}${note}</section>`;
}

/** Every page of an appendix is a whole A4 sheet that repeats the appendix title. */
function attachment(item: DossierAttachment): string {
  const meta = item.meta
    .split(' · ')
    .map((part) => isolate(part))
    .join(' · ');
  const head = (index: number) => `<header><span class="d-tag">${escape(item.label)}</span><h3>${escape(item.title)}</h3>${
    item.pages.length > 1 ? `<span class="d-count">${index + 1} / ${item.pages.length}</span>` : ''
  }</header><p class="d-sub">${meta}</p>`;
  if (!item.pages.length) {
    return `<section class="d-sheet">${head(0)}<p class="d-note">${escape(item.note)}</p></section>`;
  }
  return item.pages
    .map(
      (src, index) =>
        `<section class="d-sheet">${head(index)}<figure><img src="${src}" alt="" /></figure>${
          index === item.pages.length - 1 && item.note ? `<p class="d-note">${escape(item.note)}</p>` : ''
        }</section>`,
    )
    .join('\n');
}

/** The employee file as one printable A4 document: the record first, then each document as an appendix. */
export function printDossier(spec: DossierSpec): void {
  const styles = [...document.querySelectorAll('link[rel="stylesheet"], style')].map((node) => node.outerHTML).join('\n');
  const meta = spec.meta.map(([label, value]) => `<div><dt>${escape(label)}</dt><dd>${isolate(value)}</dd></div>`).join('');
  const html = `<!doctype html>
<html lang="${spec.rtl ? 'ar' : 'en'}" dir="${spec.rtl ? 'rtl' : 'ltr'}">
<head>
<meta charset="utf-8" />
<base href="${document.baseURI}" />
<title>${escape(`${spec.documentTitle} - ${spec.name}`)}</title>
${styles}
<style>${sheet}${frameCss}</style>
</head>
<body>
${framed(`<header class="d-top">
  <div class="d-brand">
    <img src="${new URL('brand/ufuq.png', document.baseURI).href}" alt="UFUQ" />
    <p>${escape(spec.brand)}</p>
  </div>
  <p class="d-doc">${escape(spec.documentTitle)}</p>
</header>
<div class="d-id">
  <div>
    <h1>${escape(spec.name)}</h1>
    <p class="d-line">${spec.line.split(' · ').map(isolate).join(' · ')}</p>
  </div>
  <span class="d-status ${spec.statusTone}">${escape(spec.status)}</span>
</div>
<dl class="d-meta">${meta}</dl>
${spec.sections.map(section).join('\n')}
<footer class="d-foot">${escape(spec.footer)}</footer>`)}
${spec.attachments.map(attachment).join('\n')}
</body>
</html>`;
  printDocument(html);
}
