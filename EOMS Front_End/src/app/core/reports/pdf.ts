import { ReportSpec } from './report';

const escape = (value: string | number) =>
  String(value).replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char] ?? char);

const sheet = `
@page { size: A4 var(--orientation); margin: 14mm 12mm 16mm; @bottom-center { content: counter(page) " / " counter(pages); font: 9px 'Readex Pro Variable', sans-serif; color: #6b7280; } }
html, body { background: #fff !important; }
body { margin: 0; color: #111827; font: 12px/1.5 'Readex Pro Variable', sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.r-head { display: grid; justify-items: start; gap: 6px; padding-bottom: 12px; border-bottom: 2px solid #2563eb; }
.r-brand { display: block; height: 48px; width: auto; }
.r-company { margin: 0; color: #4b5563; font-size: 12px; }
.r-meta { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px 16px; margin: 12px 0 14px; padding: 10px 12px; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; }
.r-meta div { display: grid; }
.r-meta dt { color: #6b7280; font-size: 10px; }
.r-meta dd { margin: 0; font-weight: 500; }
.r-figures { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 8px; margin-bottom: 14px; }
.r-figure { border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px 10px; break-inside: avoid; }
.r-figure span { display: block; color: #6b7280; font-size: 10px; }
.r-figure strong { display: block; font-size: 18px; font-weight: 600; font-variant-numeric: tabular-nums; }
.r-charts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-bottom: 14px; }
.r-charts > * { break-inside: avoid; border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px 12px; margin: 0 !important; }
.r-charts .panel-head a, .r-charts button { display: none !important; }
.r-charts .span-2, .r-charts .wide-chart { grid-column: 1 / -1; }
h2.r-section { font-size: 14px; margin: 16px 0 8px; }
table.r-table { width: 100%; border-collapse: collapse; font-size: 11px; font-variant-numeric: tabular-nums; }
.r-table thead { display: table-header-group; }
.r-table th { background: #2563eb; color: #fff; font-weight: 600; padding: 6px 8px; text-align: start; white-space: nowrap; }
.r-table td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; text-align: start; }
.r-table tr { break-inside: avoid; }
.r-table tbody tr:nth-child(even) td { background: #f8fafc; }
.r-table td.n { text-align: center; }
.r-foot { margin-top: 14px; padding-top: 8px; border-top: 1px solid #e5e7eb; color: #6b7280; font-size: 10px; }
`;

/** Renders the report as its own A4 document in a hidden frame and opens the print dialog, where it saves as PDF. Charts are copied from the live page. */
export function exportPdf(spec: ReportSpec, charts?: Element | null): void {
  const styles = [...document.querySelectorAll('link[rel="stylesheet"], style')].map((node) => node.outerHTML).join('\n');
  const chartHtml = charts
    ? [...charts.querySelectorAll('[data-print]')]
        .map((node) => {
          const copy = node.cloneNode(true) as HTMLElement;
          copy.querySelectorAll('svg[viewBox]').forEach((svg) => {
            if (svg.closest('.donut')) return;
            svg.removeAttribute('width');
            svg.removeAttribute('height');
            svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
            (svg as SVGElement).style.cssText = 'width:100%;height:auto;display:block;';
          });
          copy.querySelectorAll('.tip, .vh, a, button').forEach((item) => item.remove());
          return copy.outerHTML;
        })
        .join('\n')
    : '';
  const meta = spec.meta.map(([label, value]) => `<div><dt>${escape(label)}</dt><dd>${escape(value)}</dd></div>`).join('');
  const figures = spec.figures.map((figure) => `<div class="r-figure"><span>${escape(figure.label)}</span><strong>${escape(figure.value)}</strong></div>`).join('');
  const tables = spec.tables
    .map(
      (table) => `
      <h2 class="r-section">${escape(table.name)}</h2>
      <table class="r-table">
        <thead><tr>${table.columns.map((column) => `<th>${escape(column.header)}</th>`).join('')}</tr></thead>
        <tbody>${table.rows
          .map((row) => `<tr>${row.map((value, index) => `<td class="${table.columns[index]?.numeric ? 'n' : ''}">${escape(value ?? '')}</td>`).join('')}</tr>`)
          .join('')}</tbody>
      </table>`,
    )
    .join('');

  const html = `<!doctype html>
<html lang="${spec.rtl ? 'ar' : 'en'}" dir="${spec.rtl ? 'rtl' : 'ltr'}">
<head>
<meta charset="utf-8" />
<base href="${document.baseURI}" />
<title>${escape(spec.title)}</title>
${styles}
<style>:root { --orientation: ${spec.landscape ? 'landscape' : 'portrait'}; } ${sheet.replace('var(--orientation)', spec.landscape ? 'landscape' : 'portrait')}</style>
</head>
<body>
<header class="r-head">
  <img class="r-brand" src="${new URL('brand/ufuq.png', document.baseURI).href}" alt="UFUQ" />
  <p class="r-company">${escape(spec.brand)}</p>
</header>
<dl class="r-meta">${meta}</dl>
${figures ? `<section class="r-figures">${figures}</section>` : ''}
${chartHtml ? `<section class="r-charts">${chartHtml}</section>` : ''}
${tables}
<footer class="r-foot">${escape(spec.footer)}</footer>
</body>
</html>`;

  const frame = document.createElement('iframe');
  frame.setAttribute('aria-hidden', 'true');
  frame.style.cssText = 'position:fixed;inset-inline-end:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
  document.body.appendChild(frame);
  const target = frame.contentDocument;
  const view = frame.contentWindow;
  if (!target || !view) {
    frame.remove();
    return;
  }
  target.open();
  target.write(html);
  target.close();
  const finish = () => setTimeout(() => frame.remove(), 1000);
  view.addEventListener('afterprint', finish, { once: true });
  const print = () => {
    view.focus();
    view.print();
    setTimeout(finish, 60000);
  };
  const ready = target.fonts?.ready ?? Promise.resolve();
  Promise.race([ready, new Promise((resolve) => setTimeout(resolve, 1500))]).then(() => setTimeout(print, 250));
}
