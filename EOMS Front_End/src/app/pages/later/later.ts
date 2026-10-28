import { Component, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { map } from 'rxjs';
import { Session } from '../../core/access/session';
import { CopyKey, ModuleId, moduleIds } from '../../core/copy';
import { Language } from '../../core/language';

@Component({
  selector: 'app-later',
  imports: [RouterLink],
  templateUrl: './later.html',
  styleUrl: './later.scss',
})
export class Later {
  protected readonly language = inject(Language);
  private readonly session = inject(Session);
  private readonly id = toSignal(
    inject(ActivatedRoute).paramMap.pipe(map((params) => params.get('id') ?? '')),
    { initialValue: '' },
  );

  protected known(): boolean {
    return (moduleIds as readonly string[]).includes(this.id());
  }

  protected allowed(): boolean {
    return this.known() && this.session.can(this.id() as ModuleId);
  }

  protected title(): string {
    const id = this.id();
    if ((moduleIds as readonly string[]).includes(id)) {
      return this.language.t(id as CopyKey);
    }
    return this.language.t('dashboard');
  }

  protected t(key: CopyKey): string {
    return this.language.t(key);
  }
}
