import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { Session } from '../../../core/access/session';
import { Language } from '../../../core/language';
import { Employee } from '../../../data/employees';
import { Office } from '../../../data/office';
import { Records } from '../../../data/records';
import { EmployeeLeaving } from './employee-leaving';

function setup(allowed = true) {
  const rehire = vi.fn().mockResolvedValue({ ok: true });
  const loadHistory = vi.fn().mockResolvedValue(undefined);
  TestBed.configureTestingModule({ imports: [EmployeeLeaving], providers: [provideRouter([]),
    { provide: Session, useValue: { account: () => ({ id: 'hr' }), mayEdit: () => allowed } },
    { provide: Office, useValue: { rehire, policyVersions: () => [{ timezone: 'Asia/Damascus' }] } },
    { provide: Records, useValue: { contracts: signal({}), loadContracts: vi.fn(), loadHistory } },
  ] });
  TestBed.inject(Language).set('ar');
  const fixture = TestBed.createComponent(EmployeeLeaving);
  fixture.componentRef.setInput('person', { id: '7', hired: '2026-01-01', status: 'ended', endedOn: '2026-11-02', endReason: 'Original end' } as Employee);
  fixture.detectChanges();
  return { fixture, rehire, loadHistory };
}

describe('Return to service form', () => {
  afterEach(() => TestBed.resetTestingModule());

  it('shows the return form only to permitted HR users', () => {
    const { fixture } = setup();
    expect(fixture.nativeElement.textContent).toContain('إعادة للخدمة');
    expect(fixture.nativeElement.querySelector('input[name="return_date"]').min).toBe('2026-11-03');
  });

  it('hides the return form from a viewer', () => {
    const { fixture } = setup(false);
    expect(fixture.nativeElement.querySelector('input[name="return_date"]')).toBeNull();
  });

  it('requires consent before sending a new service period', async () => {
    const { fixture, rehire } = setup();
    const form = fixture.nativeElement.querySelector('form') as HTMLFormElement;
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await fixture.whenStable();
    expect(rehire).not.toHaveBeenCalled();
    expect(fixture.nativeElement.textContent).toContain('يجب تأكيد');
  });

  it('submits the date and reason and reloads history after success', async () => {
    const { fixture, rehire, loadHistory } = setup();
    const form = fixture.nativeElement.querySelector('form') as HTMLFormElement;
    (form.elements.namedItem('return_date') as HTMLInputElement).value = '2026-11-07';
    (form.elements.namedItem('return_reason') as HTMLInputElement).value = 'Approved return';
    (form.elements.namedItem('confirm_period') as HTMLInputElement).checked = true;
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await fixture.whenStable();
    expect(rehire).toHaveBeenCalledWith('7', '2026-11-07', 'Approved return', 'hr');
    expect(loadHistory).toHaveBeenCalledWith('7');
  });
});
