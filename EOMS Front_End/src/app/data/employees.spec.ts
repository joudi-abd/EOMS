import { ApiEmployee } from '../core/api/types';
import { employeeFrom } from './employees';

const base: ApiEmployee = {
  id: 7,
  employee_no: 'U-00000007',
  first_name: 'سارة',
  last_name: 'كنعان',
  full_name: 'سارة كنعان',
  department_id: 2,
  job_title_id: 3,
  manager_id: null,
  status: 'active',
  hire_date: '2026-10-01',
  department: { id: 2, parent_id: null, name: 'الهندسة', code: 'ENG' },
  job_title: { id: 3, name: 'مهندسة' },
};

describe('employeeFrom', () => {
  it('maps the server record onto the interface model', () => {
    const person = employeeFrom(base);
    expect(person.id).toBe('7');
    expect(person.name.ar).toBe('سارة كنعان');
    expect(person.staffNo).toBe('U-00000007');
    expect(person.department).toBe('2');
    expect(person.titleId).toBe('3');
    expect(person.title.ar).toBe('مهندسة');
    expect(person.managerId).toBe('');
  });

  it('maps the backend statuses', () => {
    expect(employeeFrom({ ...base, status: 'on_leave' }).status).toBe('away');
    expect(employeeFrom({ ...base, status: 'terminated' }).status).toBe('ended');
  });

  it('marks which withheld fields the server actually sent', () => {
    const hidden = employeeFrom(base);
    expect(hidden.sensitive).toBe(false);
    expect(hidden.financial).toBe(false);
    const shown = employeeFrom({ ...base, email: null, phone: '0944', user_id: 12, biometric_code: 1004, sham_cash_wallet: null });
    expect(shown.sensitive).toBe(true);
    expect(shown.financial).toBe(true);
    expect(shown.userId).toBe('12');
    expect(shown.pin).toBe('1004');
    expect(shown.fingerprint).toBe('enrolled');
  });
});
