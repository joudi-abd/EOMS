import { ReportSpec } from './report';

export const escape = (value: string | number) =>
  String(value).replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char] ?? char);

/**
 * With no page margin the browser has nowhere to print its own URL, title, date, and page count.
 * The frame's header and footer rows repeat on every printed page and stand in for that margin.
 */
export const frameCss = `
.p-frame { width: 100%; border-collapse: collapse; }
.p-frame > thead > tr > td, .p-frame > tfoot > tr > td { padding: 0; border: 0; background: none; }
.p-frame > tbody > tr > td { padding: 0 12mm; border: 0; background: none; vertical-align: top; }
.p-top { height: 12mm; }
.p-bottom { height: 14mm; }
`;

export function framed(content: string): string {
  return `<table class="p-frame" role="presentation"><thead><tr><td><div class="p-top"></div></td></tr></thead><tfoot><tr><td><div class="p-bottom"></div></td></tr></tfoot><tbody><tr><td>${content}</td></tr></tbody></table>`;
}

/** Latin codes, numbers, and e-mail addresses keep their own order inside right-to-left text. */
export const isolate = (value: string | number) => `<bdi>${escape(value)}</bdi>`;

const sheet = `
@page { size: A4 var(--orientation); margin: 0; }
html, body { background: #fff !important; }
body { margin: 0; color: #14162b; font: 12px/1.5 'Readex Pro Variable', sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.r-head { display: grid; justify-items: start; gap: 6px; padding-bottom: 12px; border-bottom: 2px solid #4f46c8; }
.r-brand { display: block; height: 48px; width: auto; }
.r-company { margin: 0; color: #454a63; font-size: 12px; }
.r-meta { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px 16px; margin: 12px 0 14px; padding: 10px 12px; background: #f8f8fc; border: 1px solid #e1e3ee; border-radius: 8px; }
.r-meta div { display: grid; }
.r-meta dt { color: #666b85; font-size: 10px; }
.r-meta dd { margin: 0; font-weight: 500; }
.r-figures { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 8px; margin-bottom: 14px; }
.r-figure { border: 1px solid #e1e3ee; border-radius: 8px; padding: 8px 10px; break-inside: avoid; }
.r-figure span { display: block; color: #666b85; font-size: 10px; }
.r-figure strong { display: block; font-size: 18px; font-weight: 600; font-variant-numeric: tabular-nums; }
.r-charts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-bottom: 14px; }
.r-charts > * { break-inside: avoid; border: 1px solid #e1e3ee; border-radius: 8px; padding: 10px 12px; margin: 0 !important; }
.r-charts .panel-head a, .r-charts button { display: none !important; }
.r-charts .span-2, .r-charts .wide-chart { grid-column: 1 / -1; }
h2.r-section { font-size: 14px; margin: 16px 0 8px; }
table.r-table { width: 100%; border-collapse: collapse; font-size: 11px; font-variant-numeric: tabular-nums; }
.r-table thead { display: table-header-group; }
.r-table th { background: #4f46c8; color: #fff; font-weight: 600; padding: 6px 8px; text-align: start; white-space: nowrap; }
.r-table td { padding: 5px 8px; border-bottom: 1px solid #e1e3ee; text-align: start; }
.r-table tr { break-inside: avoid; }
.r-table tbody tr:nth-child(even) td { background: #f8f8fc; }
.r-foot { margin-top: 14px; padding-top: 8px; border-top: 1px solid #e1e3ee; color: #666b85; font-size: 10px; }
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
  const meta = spec.meta.map(([label, value]) => `<div><dt>${escape(label)}</dt><dd>${isolate(value)}</dd></div>`).join('');
  const figures = spec.figures.map((figure) => `<div class="r-figure"><span>${escape(figure.label)}</span><strong>${escape(figure.value)}</strong></div>`).join('');
  const tables = spec.tables
    .map(
      (table) => `
      <h2 class="r-section">${escape(table.name)}</h2>
      <table class="r-table">
        <thead><tr>${table.columns.map((column) => `<th>${escape(column.header)}</th>`).join('')}</tr></thead>
        <tbody>${table.rows
          .map((row) => `<tr>${row.map((value) => `<td>${isolate(value ?? '')}</td>`).join('')}</tr>`)
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
<style>${sheet.replace('var(--orientation)', spec.landscape ? 'landscape' : 'portrait')}${frameCss}</style>
</head>
<body>
${framed(`<header class="r-head">
  <img class="r-brand" src="${new URL('brand/ufuq.png', document.baseURI).href}" alt="UFUQ" />
  <p class="r-company">${escape(spec.brand)}</p>
</header>
<dl class="r-meta">${meta}</dl>
${figures ? `<section class="r-figures">${figures}</section>` : ''}
${chartHtml ? `<section class="r-charts">${chartHtml}</section>` : ''}
${tables}
<footer class="r-foot">${escape(spec.footer)}</footer>`)}
</body>
</html>`;

  printDocument(html);
}

/** Writes a complete HTML document into a hidden frame and opens the print dialog, where it saves as PDF. */
export function printDocument(html: string): void {
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
  const fonts = Promise.race([target.fonts?.ready ?? Promise.resolve(), new Promise((resolve) => setTimeout(resolve, 1500))]);
  const images = Promise.race([
    Promise.all(
      [...target.images].map((image) =>
        image.complete
          ? null
          : new Promise((resolve) => {
              image.addEventListener('load', resolve);
              image.addEventListener('error', resolve);
            }),
      ),
    ),
    new Promise((resolve) => setTimeout(resolve, 15000)),
  ]);
  Promise.all([fonts, images]).then(() => setTimeout(print, 250));
}
