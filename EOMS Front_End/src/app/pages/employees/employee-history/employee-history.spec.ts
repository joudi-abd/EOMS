import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Language } from '../../../core/language';
import { ApiJobChange } from '../../../core/api/types';
import { Employee } from '../../../data/employees';
import { Office } from '../../../data/office';
import { changeFrom, Records } from '../../../data/records';
import { EmployeeHistory } from './employee-history';

const row: ApiJobChange = {
  id: 9, employee_id: 1, event_type: 'contract_renewal_created', reason: 'Renewal agreed', effective_date: '2026-11-07',
  old_department_id: null, new_department_id: null, old_job_title_id: null, new_job_title_id: null, old_manager_id: null, new_manager_id: null,
  contract: { id: 2, number: 'CT-2', previous_id: 1, previous_number: 'CT-1', start_date: '2027-01-01', end_date: '2027-12-31', scheduled_for: null },
  recorded_by: { id: 7, name: 'HR Tester' }, recorded_at: '2026-11-07T09:00:00Z',
};

async function render(lang: 'ar' | 'en', event: ApiJobChange['event_type'] = 'contract_renewal_created') {
  TestBed.configureTestingModule({ imports: [EmployeeHistory], providers: [
    { provide: Records, useValue: { history: signal({ '1': [changeFrom({ ...row, event_type: event })] }), isLoading: () => false, loadHistory: vi.fn().mockResolvedValue(undefined) } },
    { provide: Office, useValue: { departmentList: () => [], titles: () => [], personName: () => ({ ar: '—', en: '—' }) } },
  ] });
  TestBed.inject(Language).set(lang);
  const fixture = TestBed.createComponent(EmployeeHistory);
  fixture.componentRef.setInput('person', { id: '1' } as Employee);
  fixture.detectChanges();
  await fixture.whenStable();
  return fixture.nativeElement.textContent as string;
}

describe('Employee timeline rendering', () => {
  afterEach(() => TestBed.resetTestingModule());
  it('shows Arabic renewal and previous contract instead of a generic assignment change', async () => {
    const text = await render('ar');
    expect(text).toContain('إنشاء مسودة تجديد عقد');
    expect(text).toContain('CT-2');
    expect(text).toContain('CT-1');
    expect(text).toContain('HR Tester');
    expect(text).not.toContain('تغيير وظيفي');
  });
  it('shows English termination using the contract-specific label', async () => {
    const text = await render('en', 'contract_terminated');
    expect(text).toContain('Contract terminated');
    expect(text).not.toContain('Employment terminated');
  });
});
