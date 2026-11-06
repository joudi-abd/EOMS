import { effect, inject, Injectable, signal, untracked, WritableSignal } from '@angular/core';
import { Api, statusOf } from '../core/api/api';
import { Auth } from '../core/api/auth';
import { ApiContract, ApiContractStatus, ApiContractType, ApiCurrency, ApiDocument, ApiItem, ApiJobChange } from '../core/api/types';
import { CopyKey } from '../core/copy';
import { Office, Outcome } from './office';

export type ContractStatus = ApiContractStatus;
export type ContractType = ApiContractType;

export interface Contract {
  id: string;
  number: string;
  type: ContractType;
  status: ContractStatus;
  start: string;
  end: string;
  terminatedAt: string;
  scheduledAt: string;
  scheduledReason: string;
  renewedFrom: string;
  /** Empty unless the server sent the salary to this account. */
  salary: string;
  currency: string;
}

export interface ContractEntry {
  number: string;
  type: ContractType;
  start: string;
  end: string;
  salary: string;
  currency: string;
}

/** Each pair is the value before and after; ids refer to departments, job titles, and employees. */
export interface JobChange {
  id: string;
  event: 'hired' | 'assignment_changed';
  reason: string;
  effective: string;
  department: [string, string];
  title: [string, string];
  manager: [string, string];
}

export interface EmployeeDocument {
  id: string;
  title: string;
  type: string;
  fileName: string;
  size: number;
  description: string;
  createdAt: string;
}

export const documentLimit = 10 * 1024 * 1024;
export const documentTypes = '.pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx';

/** Suggested document types; the server accepts any short label. */
export const documentKinds: { id: string; label: CopyKey }[] = [
  { id: 'identity', label: 'docIdentity' },
  { id: 'certificate', label: 'docCertificate' },
  { id: 'contract', label: 'docContract' },
  { id: 'other', label: 'docOther' },
];

const ref = (value: number | null | undefined): string => (value === null || value === undefined ? '' : String(value));

function contractFrom(item: ApiContract): Contract {
  return {
    id: String(item.id),
    number: item.contract_number,
    type: item.type,
    status: item.status,
    start: item.start_date,
    end: item.end_date ?? '',
    terminatedAt: item.terminated_at ?? '',
    scheduledAt: item.scheduled_termination_at ?? '',
    scheduledReason: item.scheduled_termination_reason ?? '',
    renewedFrom: ref(item.renewed_from_id),
    salary: item.base_salary ?? '',
    currency: item.currency_code ?? '',
  };
}

function changeFrom(item: ApiJobChange): JobChange {
  return {
    id: String(item.id),
    event: item.event_type,
    reason: item.reason ?? '',
    effective: item.effective_date ?? '',
    department: [ref(item.old_department_id), ref(item.new_department_id)],
    title: [ref(item.old_job_title_id), ref(item.new_job_title_id)],
    manager: [ref(item.old_manager_id), ref(item.new_manager_id)],
  };
}

function documentFrom(item: ApiDocument): EmployeeDocument {
  return {
    id: String(item.id),
    title: item.title,
    type: item.document_type,
    fileName: item.original_name,
    size: item.file_size,
    description: item.description ?? '',
    createdAt: item.created_at ?? '',
  };
}

/** Contracts, job history, and documents of one employee file at a time, read from the server when the file opens. */
@Injectable({ providedIn: 'root' })
export class Records {
  private readonly api = inject(Api);
  private readonly auth = inject(Auth);
  private readonly office = inject(Office);

  readonly contracts = signal<Record<string, Contract[]>>({});
  readonly history = signal<Record<string, JobChange[]>>({});
  readonly documents = signal<Record<string, EmployeeDocument[]>>({});
  readonly currencies = signal<ApiCurrency[]>([]);
  /** Keys such as `contracts:12` while a list is on its way. */
  readonly pending = signal<ReadonlySet<string>>(new Set());

  constructor() {
    effect(() => {
      this.auth.userId();
      untracked(() => {
        this.contracts.set({});
        this.history.set({});
        this.documents.set({});
        this.currencies.set([]);
      });
    });
  }

  isLoading(kind: 'contracts' | 'history' | 'documents', employeeId: string): boolean {
    return this.pending().has(`${kind}:${employeeId}`);
  }

  // Contracts

  loadContracts(employeeId: string): Promise<void> {
    return this.load('contracts', employeeId, this.contracts, () => this.api.all<ApiContract>(`/employees/${employeeId}/contracts`), contractFrom);
  }

  async loadCurrencies(): Promise<void> {
    if (this.currencies().length) return;
    try {
      const result = await this.api.get<ApiItem<ApiCurrency[]>>('/settings/currencies');
      this.currencies.set(result.data);
    } catch (error) {
      if (statusOf(error) !== 403) this.office.fail(error);
    }
  }

  /** A new contract, or a renewal of `renewFrom`, is saved as a draft. */
  createContract(employeeId: string, entry: ContractEntry, renewFrom = ''): Promise<Outcome> {
    const body: Record<string, unknown> = {
      contract_number: entry.number.trim(),
      type: entry.type,
      start_date: entry.start,
      end_date: entry.type === 'fixed_term' ? entry.end : null,
    };
    if (entry.salary.trim()) {
      body['base_salary'] = Number(entry.salary);
      body['currency_code'] = entry.currency;
    }
    const path = renewFrom ? `/employees/${employeeId}/contracts/${renewFrom}/renew` : `/employees/${employeeId}/contracts`;
    return this.contractStep(employeeId, () => this.api.post(path, body), 'savedContract');
  }

  activate(employeeId: string, contractId: string): Promise<Outcome> {
    return this.contractStep(employeeId, () => this.api.post(`/employees/${employeeId}/contracts/${contractId}/activate`), 'activatedContract');
  }

  endContract(employeeId: string, contractId: string, date: string, reason: string): Promise<Outcome> {
    return this.contractStep(
      employeeId,
      () => this.api.post(`/employees/${employeeId}/contracts/${contractId}/terminate`, { terminated_at: date, reason: reason.trim() }),
      'terminatedContract',
    );
  }

  scheduleEnd(employeeId: string, contractId: string, date: string, reason: string): Promise<Outcome> {
    return this.contractStep(
      employeeId,
      () => this.api.post(`/employees/${employeeId}/contracts/${contractId}/schedule-termination`, { terminated_at: date, reason: reason.trim() }),
      'scheduledContract',
    );
  }

  cancelScheduledEnd(employeeId: string, contractId: string): Promise<Outcome> {
    return this.contractStep(employeeId, () => this.api.post(`/employees/${employeeId}/contracts/${contractId}/cancel-scheduled-termination`), 'unscheduledContract');
  }

  // Job history

  loadHistory(employeeId: string): Promise<void> {
    return this.load('history', employeeId, this.history, () => this.api.all<ApiJobChange>(`/employees/${employeeId}/job-history`), changeFrom);
  }

  // Documents

  loadDocuments(employeeId: string): Promise<void> {
    return this.load('documents', employeeId, this.documents, () => this.api.all<ApiDocument>(`/employees/${employeeId}/documents`), documentFrom);
  }

  async upload(employeeId: string, entry: { title: string; type: string; description: string }, file: File): Promise<Outcome> {
    const form = new FormData();
    form.append('title', entry.title.trim());
    if (entry.type.trim()) form.append('document_type', entry.type.trim());
    if (entry.description.trim()) form.append('description', entry.description.trim());
    form.append('file', file, file.name);
    try {
      await this.api.post(`/employees/${employeeId}/documents`, form);
      await this.loadDocuments(employeeId);
      this.office.done('uploadedDocument');
      return { ok: true };
    } catch (error) {
      return this.office.fail(error);
    }
  }

  file(employeeId: string, documentId: string): Promise<Blob> {
    return this.api.blob(`/employees/${employeeId}/documents/${documentId}/download`);
  }

  async download(employeeId: string, document: EmployeeDocument): Promise<void> {
    try {
      const blob = await this.file(employeeId, document.id);
      const url = URL.createObjectURL(blob);
      const link = globalThis.document.createElement('a');
      link.href = url;
      link.download = document.fileName;
      link.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (error) {
      this.office.fail(error);
    }
  }

  async removeDocument(employeeId: string, documentId: string): Promise<Outcome> {
    try {
      await this.api.delete(`/employees/${employeeId}/documents/${documentId}`);
      await this.loadDocuments(employeeId);
      this.office.done('removedDocument');
      return { ok: true };
    } catch (error) {
      return this.office.fail(error);
    }
  }

  // Internals

  private async contractStep(employeeId: string, run: () => Promise<unknown>, message: CopyKey): Promise<Outcome> {
    try {
      await run();
      await this.loadContracts(employeeId);
      this.office.done(message);
      return { ok: true };
    } catch (error) {
      return this.office.fail(error);
    }
  }

  private async load<A, T>(
    kind: 'contracts' | 'history' | 'documents',
    employeeId: string,
    store: WritableSignal<Record<string, T[]>>,
    run: () => Promise<A[]>,
    map: (item: A) => T,
  ): Promise<void> {
    const key = `${kind}:${employeeId}`;
    this.pending.update((set) => new Set(set).add(key));
    try {
      const rows = await run();
      store.update((all) => ({ ...all, [employeeId]: rows.map(map) }));
    } catch (error) {
      if (statusOf(error) !== 403) this.office.fail(error);
    } finally {
      this.pending.update((set) => {
        const next = new Set(set);
        next.delete(key);
        return next;
      });
    }
  }
}
