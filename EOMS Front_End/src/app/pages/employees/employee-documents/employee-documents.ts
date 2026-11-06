import { Component, computed, effect, inject, input, signal, untracked } from '@angular/core';
import { Session } from '../../../core/access/session';
import { Ui } from '../../../core/ui';
import { Employee } from '../../../data/employees';
import { documentKinds, documentLimit, documentTypes, EmployeeDocument, Records } from '../../../data/records';

@Component({
  selector: 'app-employee-documents',
  templateUrl: './employee-documents.html',
  styleUrl: './employee-documents.scss',
})
export class EmployeeDocuments extends Ui {
  readonly person = input.required<Employee>();
  protected readonly records = inject(Records);
  protected readonly session = inject(Session);
  protected readonly kinds = documentKinds;
  protected readonly accept = documentTypes;
  protected readonly problem = signal('');
  protected readonly saving = signal(false);
  protected readonly removing = signal('');

  protected readonly rows = computed(() => this.records.documents()[this.person().id] ?? []);
  protected readonly loading = computed(() => this.records.isLoading('documents', this.person().id));
  protected readonly manages = computed(() => this.session.canAct('document.manage') && this.session.mayManage(this.person().id));

  constructor() {
    super();
    effect(() => {
      const id = this.person().id;
      untracked(() => void this.records.loadDocuments(id));
    });
  }

  protected kindLabel(id: string): string {
    const match = documentKinds.find((item) => item.id === id);
    return match ? this.t(match.label) : id;
  }

  protected size(bytes: number): string {
    if (bytes < 1024) return `${this.num(bytes)} B`;
    if (bytes < 1024 * 1024) return `${this.num(Math.round(bytes / 1024))} KB`;
    return `${this.num(Math.round((bytes / 1024 / 1024) * 10) / 10)} MB`;
  }

  protected icon(name: string): string {
    const ext = name.split('.').pop()?.toLowerCase() ?? '';
    if (ext === 'pdf') return 'fa-file-pdf';
    if (['jpg', 'jpeg', 'png', 'webp'].includes(ext)) return 'fa-file-image';
    if (ext === 'docx') return 'fa-file-word';
    if (ext === 'xlsx') return 'fa-file-excel';
    return 'fa-file-lines';
  }

  protected download(document: EmployeeDocument): void {
    void this.records.download(this.person().id, document);
  }

  protected async remove(document: EmployeeDocument): Promise<void> {
    if (this.removing()) return;
    this.removing.set(document.id);
    await this.records.removeDocument(this.person().id, document.id);
    this.removing.set('');
  }

  protected readonly picked = signal<File | null>(null);
  protected readonly dragging = signal(false);
  protected readonly draftTitle = signal('');

  protected onPick(event: Event): void {
    const input = event.target as HTMLInputElement;
    this.take(input.files?.[0] ?? null);
    input.value = '';
  }

  protected onDrag(event: DragEvent, over: boolean): void {
    event.preventDefault();
    this.dragging.set(over);
  }

  protected onDrop(event: DragEvent): void {
    event.preventDefault();
    this.dragging.set(false);
    this.take(event.dataTransfer?.files?.[0] ?? null);
  }

  protected clearFile(): void {
    this.picked.set(null);
  }

  protected onTitle(event: Event): void {
    this.draftTitle.set((event.target as HTMLInputElement).value);
  }

  protected async upload(event: Event): Promise<void> {
    event.preventDefault();
    if (this.saving()) return;
    const form = event.target as HTMLFormElement;
    const data = new FormData(form);
    const file = this.picked();
    if (!file) {
      this.problem.set(this.t('chooseFile'));
      return;
    }
    this.saving.set(true);
    this.problem.set('');
    const outcome = await this.records.upload(
      this.person().id,
      { title: this.draftTitle(), type: String(data.get('type') ?? ''), description: String(data.get('description') ?? '') },
      file,
    );
    this.saving.set(false);
    if (!outcome.ok) {
      this.problem.set(outcome.error ?? '');
      return;
    }
    form.reset();
    this.picked.set(null);
    this.draftTitle.set('');
  }

  /** The same checks the server makes, so a wrong file is caught before it travels. */
  private take(file: File | null): void {
    if (!file) return;
    const ext = `.${file.name.split('.').pop()?.toLowerCase() ?? ''}`;
    if (!documentTypes.split(',').includes(ext)) {
      this.problem.set(this.t('fileTypeRefused'));
      return;
    }
    if (file.size > documentLimit) {
      this.problem.set(this.t('fileTooLarge'));
      return;
    }
    this.problem.set('');
    this.picked.set(file);
    if (!this.draftTitle().trim()) this.draftTitle.set(file.name.replace(/\.[^.]+$/, ''));
  }
}
