import { inject, Injectable } from '@angular/core';
import { Session } from '../access/session';
import { Language } from '../language';
import { exportExcel } from './excel';
import { exportPdf } from './pdf';
import { ReportFigure, ReportSpec, ReportTable, stampNow } from './report';

export interface ReportDraft {
  file: string;
  title: string;
  subtitle: string;
  meta?: [string, string][];
  figures?: ReportFigure[];
  tables: ReportTable[];
  landscape?: boolean;
}

/** Stamps every report with who issued it, when, and in which language, then hands it to Excel or PDF. */
@Injectable({ providedIn: 'root' })
export class Reporter {
  private readonly language = inject(Language);
  private readonly session = inject(Session);

  private complete(draft: ReportDraft): ReportSpec {
    const ar = this.language.lang() === 'ar';
    const locale = ar ? 'ar-EG-u-nu-latn' : 'en-US';
    return {
      file: draft.file,
      title: draft.title,
      subtitle: draft.subtitle,
      brand: this.language.t('brand'),
      meta: [
        ...(draft.meta ?? []),
        [this.language.t('reportBy'), `${this.language.text(this.session.account().name)} · ${this.session.roleLine()}`],
        [this.language.t('reportAt'), stampNow(locale)],
      ],
      figures: draft.figures ?? [],
      tables: draft.tables,
      rtl: ar,
      landscape: draft.landscape,
      footer: this.language.t('reportFooter'),
    };
  }

  async excel(draft: ReportDraft): Promise<void> {
    await exportExcel(this.complete(draft));
  }

  pdf(draft: ReportDraft, charts?: Element | null): void {
    exportPdf(this.complete(draft), charts);
  }
}
