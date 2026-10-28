import { download, ReportSpec } from './report';

type Cell = string | number;

const ink = 'FF111827';
const muted = 'FF4B5563';
const action = 'FF2563EB';
const line = 'FFE5E7EB';
const zebra = 'FFF8FAFC';
const tint = 'FFEFF6FF';

function columnLetter(index: number): string {
  let name = '';
  let value = index;
  while (value > 0) {
    const rest = (value - 1) % 26;
    name = String.fromCharCode(65 + rest) + name;
    value = Math.floor((value - 1) / 26);
  }
  return name;
}

function widthOf(values: Cell[], header: string, preset?: number): number {
  if (preset) return preset;
  const longest = Math.max(header.length, ...values.map((value) => String(value ?? '').length));
  return Math.min(48, Math.max(10, Math.round(longest * 1.15) + 2));
}

/** Builds a real .xlsx file: titled sheets, a styled header row, frozen panes, filters, and right-to-left view for Arabic. */
export async function exportExcel(spec: ReportSpec): Promise<void> {
  const module = await import('exceljs');
  const ExcelJS = ((module as unknown as { default?: typeof module }).default ?? module) as typeof module;
  const book = new ExcelJS.Workbook();
  book.creator = spec.rtl ? 'إدارة شركة أفق إمبريوم غروب للعمران' : 'UFUQ Imperium Group for Urbanism';
  book.created = new Date();
  book.title = spec.title;

  spec.tables.forEach((table, sheetIndex) => {
    const count = Math.max(1, table.columns.length);
    const last = columnLetter(count);
    const sheet = book.addWorksheet(table.name.replace(/[\\/?*[\]:]/g, ' ').slice(0, 31) || `Sheet ${sheetIndex + 1}`, {
      views: [{ rightToLeft: spec.rtl, showGridLines: false }],
      pageSetup: { orientation: spec.landscape ? 'landscape' : 'portrait', fitToPage: true, fitToWidth: 1, fitToHeight: 0, paperSize: 9 },
    });

    sheet.mergeCells(`A1:${last}1`);
    const title = sheet.getCell('A1');
    title.value = spec.title;
    title.font = { name: 'Arial', size: 16, bold: true, color: { argb: ink } };
    title.alignment = { vertical: 'middle', horizontal: spec.rtl ? 'right' : 'left', readingOrder: spec.rtl ? 'rtl' : 'ltr' };
    sheet.getRow(1).height = 30;

    sheet.mergeCells(`A2:${last}2`);
    const subtitle = sheet.getCell('A2');
    subtitle.value = [spec.subtitle, ...spec.meta.map(([label, value]) => `${label}: ${value}`)].filter(Boolean).join('  ·  ');
    subtitle.font = { name: 'Arial', size: 10, color: { argb: muted } };
    subtitle.alignment = { wrapText: true, vertical: 'top', horizontal: spec.rtl ? 'right' : 'left', readingOrder: spec.rtl ? 'rtl' : 'ltr' };
    sheet.getRow(2).height = 32;

    let cursor = 4;
    if (sheetIndex === 0 && spec.figures.length) {
      spec.figures.forEach((figure, index) => {
        const column = (index % count) + 1;
        const row = cursor + Math.floor(index / count) * 2;
        const label = sheet.getCell(row, column);
        const value = sheet.getCell(row + 1, column);
        label.value = figure.label;
        label.font = { name: 'Arial', size: 9, color: { argb: muted } };
        value.value = figure.value;
        value.font = { name: 'Arial', size: 14, bold: true, color: { argb: ink } };
        for (const cell of [label, value]) {
          cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: tint } };
          cell.alignment = { horizontal: 'center', vertical: 'middle', readingOrder: spec.rtl ? 'rtl' : 'ltr' };
        }
      });
      cursor += Math.ceil(spec.figures.length / count) * 2 + 1;
    }

    const headerRow = sheet.getRow(cursor);
    table.columns.forEach((column, index) => {
      const cell = headerRow.getCell(index + 1);
      cell.value = column.header;
      cell.font = { name: 'Arial', size: 10, bold: true, color: { argb: 'FFFFFFFF' } };
      cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: action } };
      cell.alignment = { horizontal: 'center', vertical: 'middle', wrapText: true, readingOrder: spec.rtl ? 'rtl' : 'ltr' };
      cell.border = { bottom: { style: 'thin', color: { argb: action } } };
    });
    headerRow.height = 26;

    table.rows.forEach((values, rowIndex) => {
      const row = sheet.getRow(cursor + 1 + rowIndex);
      values.forEach((value, index) => {
        const cell = row.getCell(index + 1);
        const numeric = table.columns[index]?.numeric && typeof value === 'number';
        cell.value = value;
        cell.font = { name: 'Arial', size: 10, color: { argb: ink } };
        cell.alignment = { horizontal: numeric ? 'center' : spec.rtl ? 'right' : 'left', vertical: 'middle', readingOrder: spec.rtl ? 'rtl' : 'ltr' };
        cell.border = { bottom: { style: 'hair', color: { argb: line } } };
        if (rowIndex % 2 === 1) {
          cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: zebra } };
        }
      });
      row.height = 20;
    });

    table.columns.forEach((column, index) => {
      sheet.getColumn(index + 1).width = widthOf(table.rows.map((row) => row[index]), column.header, column.width);
    });

    sheet.views = [{ rightToLeft: spec.rtl, showGridLines: false, state: 'frozen', ySplit: cursor, xSplit: 0 }];
    if (table.rows.length) {
      sheet.autoFilter = { from: { row: cursor, column: 1 }, to: { row: cursor + table.rows.length, column: count } };
    }

    const foot = sheet.getCell(cursor + table.rows.length + 2, 1);
    foot.value = spec.footer;
    foot.font = { name: 'Arial', size: 9, italic: true, color: { argb: muted } };
  });

  const buffer = await book.xlsx.writeBuffer();
  download(`${spec.file}.xlsx`, new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }));
}
