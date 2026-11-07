import { inject, Injectable } from '@angular/core';
import { Session } from '../../core/access/session';
import { CopyKey } from '../../core/copy';
import { Language } from '../../core/language';
import { DossierAttachment, DossierSection, printDossier } from '../../core/reports/dossier';
import { stampNow } from '../../core/reports/report';
import { Employee } from '../../data/employees';
import { Office } from '../../data/office';
import { ContractStatus, documentKinds, EmployeeDocument, JobChange, jobEventLabel, Records } from '../../data/records';
import { fingerKey } from './employee-info/employee-info';

const pageLimit = 40;

const contractStatusKey: Record<ContractStatus, CopyKey> = {
  draft: 'contractDraft',
  active: 'contractActive',
  expired: 'contractExpired',
  terminated: 'contractTerminated',
};

function readAsDataUrl(blob: Blob): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result));
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(blob);
  });
}

/** PDF pages become images, so the appendix prints inside the same document. pdf.js loads only on export. */
async function pdfPages(blob: Blob): Promise<{ pages: string[]; truncated: boolean }> {
  const pdfjs = await import('pdfjs-dist');
  pdfjs.GlobalWorkerOptions.workerSrc = new URL('pdfjs/pdf.worker.min.mjs', document.baseURI).href;
  const task = pdfjs.getDocument({ data: new Uint8Array(await blob.arrayBuffer()) });
  try {
    const pdf = await task.promise;
    const pages: string[] = [];
    const count = Math.min(pdf.numPages, pageLimit);
    for (let number = 1; number <= count; number++) {
      const page = await pdf.getPage(number);
      const viewport = page.getViewport({ scale: 1.6 });
      const canvas = document.createElement('canvas');
      canvas.width = Math.ceil(viewport.width);
      canvas.height = Math.ceil(viewport.height);
      await page.render({ canvas, viewport }).promise;
      pages.push(canvas.toDataURL('image/jpeg', 0.86));
      page.cleanup();
    }
    return { pages, truncated: pdf.numPages > pageLimit };
  } finally {
    await task.destroy();
  }
}

/** Builds the printable employee file from what the server holds for this account to see. */
@Injectable({ providedIn: 'root' })
export class EmployeeDossier {
  private readonly office = inject(Office);
  private readonly records = inject(Records);
  private readonly session = inject(Session);
  private readonly language = inject(Language);

  async export(person: Employee): Promise<void> {
    const withDocuments = this.session.canAct('document.view');
    await Promise.all([
      this.records.loadContracts(person.id),
      this.records.loadHistory(person.id),
      withDocuments ? this.records.loadDocuments(person.id) : Promise.resolve(),
    ]);
    const documents = withDocuments ? (this.records.documents()[person.id] ?? []) : [];
    const attachments: DossierAttachment[] = [];
    const placed = new Map<string, string>();
    for (const document of documents) {
      if (!this.renderable(document)) {
        placed.set(document.id, this.t('notRenderable'));
        continue;
      }
      const attachment = await this.attachment(person, document, attachments.length + 1);
      attachments.push(attachment);
      placed.set(document.id, attachment.pages.length ? attachment.label : this.t('fetchFailed'));
    }

    const ar = this.language.lang() === 'ar';
    printDossier({
      rtl: ar,
      documentTitle: this.t('dossierTitle'),
      brand: this.t('brand'),
      name: this.text(person.name),
      line: [person.staffNo, this.text(person.title), this.department(person.department)].filter((part) => part && part !== '—').join(' · '),
      status: this.t(person.status),
      statusTone: person.status === 'active' ? 'ok' : person.status === 'ended' ? 'quiet' : 'wait',
      meta: [
        [this.t('reportBy'), `${this.text(this.session.account().name)} · ${this.session.roleLine()}`],
        [this.t('reportAt'), stampNow(ar ? 'ar-EG-u-nu-latn' : 'en-US')],
        [this.t('staffNo'), person.staffNo],
      ],
      sections: [
        this.personal(person),
        this.job(person),
        this.contracts(person),
        this.history(person),
        this.documents(documents, placed, withDocuments),
      ],
      attachmentsTitle: this.t('attachmentsTitle'),
      attachments,
      footer: this.t('dossierFooter'),
    });
  }

  private personal(person: Employee): DossierSection {
    const fields = [
      { label: this.t('fullName'), value: this.text(person.name) },
      { label: this.t('firstName'), value: person.firstName },
      { label: this.t('fatherName'), value: person.fatherName },
      { label: this.t('lastName'), value: person.lastName },
    ];
    if (person.sensitive) {
      fields.push(
        { label: this.t('birthDate'), value: this.date(person.birthDate) },
        { label: this.t('phone'), value: person.phone },
        { label: this.t('email'), value: person.email },
        { label: this.t('address'), value: person.address },
      );
    }
    if (person.financial) fields.push({ label: this.t('wallet'), value: person.wallet });
    return { title: this.t('sectionPersonal'), fields, note: person.sensitive ? '' : this.t('sensitiveNote') };
  }

  private job(person: Employee): DossierSection {
    const account = this.office.accounts().find((item) => item.id === person.id);
    const login = account
      ? `${account.enabled ? this.t('accountOn') : this.t('accountOff')} · ${account.email}`
      : person.hasAccount
        ? this.t('accountLinked')
        : this.t('noLoginAccount');
    const fields = [
      { label: this.t('staffNo'), value: person.staffNo },
      { label: this.t('jobTitle'), value: this.text(person.title) },
      { label: this.t('department'), value: this.department(person.department) },
      { label: this.t('manager'), value: person.managerId ? this.text(this.office.personName(person.managerId)) : this.t('noDirectManager') },
      { label: this.t('hired'), value: this.date(person.hired) },
      { label: this.t('state'), value: this.t(person.status) },
      { label: this.t('loginAccount'), value: login },
      { label: this.t('fingerTitle'), value: `${this.t(fingerKey[person.fingerprint])}${person.pin ? ` · ${this.t('pinLabel')} ${person.pin}` : ''}` },
    ];
    if (person.currentServiceStart && person.currentServiceStart !== person.hired && person.status !== 'ended') {
      fields.push({ label: this.t('currentServiceStart'), value: this.date(person.currentServiceStart) });
    }
    if (person.endedOn) {
      fields.push({ label: this.t('offboardEnded'), value: this.date(person.endedOn) }, { label: this.t('endReason'), value: person.endReason });
    }
    return { title: this.t('sectionJob'), fields };
  }

  private contracts(person: Employee): DossierSection {
    const rows = this.records.contracts()[person.id] ?? [];
    const salary = rows.some((row) => row.salary);
    const columns = [this.t('contractNumber'), this.t('contractType'), this.t('contractStart'), this.t('contractEnd'), this.t('state')];
    if (salary) columns.push(this.t('baseSalary'));
    return {
      title: this.t('tabContracts'),
      empty: this.t('noContracts'),
      table: {
        columns,
        rows: rows.map((row) => {
          const state = [
            this.t(contractStatusKey[row.status]),
            row.terminatedAt ? this.date(row.terminatedAt) : '',
            row.scheduledAt ? `${this.t('scheduledFor')} ${this.date(row.scheduledAt)}` : '',
          ]
            .filter(Boolean)
            .join(' · ');
          const cells = [
            row.number,
            this.t(row.type === 'fixed_term' ? 'contractFixed' : 'contractOpen'),
            this.date(row.start),
            row.end ? this.date(row.end) : this.t('noEnd'),
            state,
          ];
          if (salary) cells.push(row.salary ? `${row.salary} ${row.currency}` : '');
          return cells;
        }),
      },
    };
  }

  private history(person: Employee): DossierSection {
    const rows = this.records.history()[person.id] ?? [];
    return {
      title: this.t('historyTitle'),
      empty: this.t('noHistory'),
      table: {
        columns: [this.t('effectiveDate'), this.t('eventCol'), this.t('changeCol'), this.t('reasonCol'), this.t('historyActor')],
        rows: rows.map((row) => [this.date(row.effective), this.t(jobEventLabel[row.event]), this.changes(row), row.reason, row.recordedBy || this.t('historySystemActor')]),
      },
    };
  }

  private documents(list: EmployeeDocument[], placed: Map<string, string>, allowed: boolean): DossierSection {
    if (!allowed) return { title: this.t('staffDocuments'), empty: this.t('documentsHidden') };
    return {
      title: this.t('staffDocuments'),
      empty: this.t('noDocuments'),
      note: list.some((item) => !this.renderable(item)) ? this.t('notRenderableNote') : '',
      table: {
        columns: [this.t('documentTitle'), this.t('documentType'), this.t('documentFile'), this.t('sizeCol'), this.t('dateCol'), this.t('attachedAs')],
        rows: list.map((item) => [item.title, this.kind(item.type), item.fileName, this.size(item.size), this.date(item.createdAt), placed.get(item.id) ?? '']),
      },
    };
  }

  private async attachment(person: Employee, document: EmployeeDocument, number: number): Promise<DossierAttachment> {
    const label = `${this.t('attachmentLabel')} ${this.language.formatNumber(number)}`;
    const meta = [this.kind(document.type), document.fileName, this.size(document.size), this.date(document.createdAt), document.description]
      .filter((part) => part && part !== '—')
      .join(' · ');
    const base = { label, title: document.title, meta, pages: [] as string[], note: '' };
    const ext = document.fileName.split('.').pop()?.toLowerCase() ?? '';
    try {
      const blob = await this.records.file(person.id, document.id);
      if (ext === 'pdf' || blob.type === 'application/pdf') {
        const { pages, truncated } = await pdfPages(blob);
        return { ...base, pages, note: truncated ? this.t('pagesTruncated') : '' };
      }
      return { ...base, pages: [await readAsDataUrl(blob)] };
    } catch {
      return { ...base, note: this.t('fetchFailed') };
    }
  }

  /** Word and Excel files cannot be drawn in the browser; the index points to them instead. */
  private renderable(document: EmployeeDocument): boolean {
    const ext = document.fileName.split('.').pop()?.toLowerCase() ?? '';
    return ext !== 'docx' && ext !== 'xlsx';
  }

  private changes(row: JobChange): string {
    if (row.contract) {
      const c = row.contract;
      return [
        `${this.t('contractNumber')}: ${c.number}`,
        c.previous_number ? `${this.t('historyPreviousContract')}: ${c.previous_number}` : '',
        `${this.t('contractStart')}: ${this.date(c.start_date)}`,
        c.end_date ? `${this.t('historyContractEnd')}: ${this.date(c.end_date)}` : '',
        c.scheduled_for ? `${this.t('historyScheduledDate')}: ${this.date(c.scheduled_for)}` : '',
      ].filter(Boolean).join(' · ');
    }
    const pairs: [CopyKey, [string, string], (id: string) => string][] = [
      ['fieldDepartment', row.department, (id) => this.department(id)],
      ['fieldTitle', row.title, (id) => this.title(id)],
      ['fieldManager', row.manager, (id) => (id ? this.text(this.office.personName(id)) : '—')],
    ];
    const ar = this.language.lang() === 'ar';
    const arrow = ar ? '←' : '→';
    return pairs
      .filter(([, [from, to]]) => row.event === 'hired' || from !== to)
      .map(([key, [from, to], name]) => (row.event === 'hired' ? `${this.t(key)}: ${name(to)}` : `${this.t(key)}: ${name(from)} ${arrow} ${name(to)}`))
      .join(ar ? '، ' : ', ');
  }

  private t(key: CopyKey): string {
    return this.language.t(key);
  }

  private text(value: { ar: string; en: string }): string {
    return this.language.text(value);
  }

  private date(iso: string): string {
    return iso ? this.language.formatFullDate(iso.slice(0, 10)) : '—';
  }

  private department(id: string): string {
    const match = this.office.departmentList().find((item) => item.id === id);
    return match ? this.text(match.name) : '—';
  }

  private title(id: string): string {
    const match = this.office.titles().find((item) => item.id === id);
    return match ? this.text(match.name) : '—';
  }

  private kind(id: string): string {
    const match = documentKinds.find((item) => item.id === id);
    return match ? this.t(match.label) : id;
  }

  private size(bytes: number): string {
    if (bytes < 1024) return `${this.language.formatNumber(bytes)} B`;
    if (bytes < 1024 * 1024) return `${this.language.formatNumber(Math.round(bytes / 1024))} KB`;
    return `${this.language.formatNumber(Math.round((bytes / 1024 / 1024) * 10) / 10)} MB`;
  }
}
