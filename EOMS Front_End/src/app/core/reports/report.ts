export interface ReportColumn {
  header: string;
  width?: number;
  numeric?: boolean;
}

export interface ReportTable {
  name: string;
  columns: ReportColumn[];
  rows: (string | number)[][];
}

export interface ReportFigure {
  label: string;
  value: string;
}

/** One description feeds both the Excel workbook and the printable PDF document. */
export interface ReportSpec {
  file: string;
  title: string;
  subtitle: string;
  brand: string;
  meta: [string, string][];
  figures: ReportFigure[];
  tables: ReportTable[];
  rtl: boolean;
  landscape?: boolean;
  footer: string;
}

export function stampNow(locale: string): string {
  return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date());
}

export function download(name: string, blob: Blob): void {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = name;
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1500);
}
