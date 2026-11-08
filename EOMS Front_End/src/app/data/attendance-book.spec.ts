import { TestBed } from '@angular/core/testing';
import { Api } from '../core/api/api';
import { Session } from '../core/access/session';
import { AttendanceBook } from './attendance-book';
import { Office } from './office';

describe('Attendance period endpoints', () => {
  const post = vi.fn();
  const blob = vi.fn();
  const done = vi.fn();
  const fail = vi.fn();

  beforeEach(() => {
    post.mockReset();
    blob.mockReset().mockResolvedValue(new Blob(['date,employee_no']));
    done.mockReset();
    fail.mockReset();
    vi.stubGlobal('URL', { createObjectURL: () => 'blob:attendance', revokeObjectURL: () => undefined });
    TestBed.configureTestingModule({
      providers: [
        { provide: Api, useValue: { post, blob } },
        { provide: Office, useValue: { done, fail } },
        { provide: Session, useValue: { has: () => false } },
      ],
    });
  });

  afterEach(() => TestBed.resetTestingModule());

  it('downloads the official day file with the same filters the report is showing', async () => {
    const saved = await TestBed.inject(AttendanceBook).exportCsv('2026-11-01', '2026-11-08', { employeeId: '7', departmentId: '2' });
    expect(saved).toBe(true);
    expect(blob).toHaveBeenCalledWith('/attendance/days/export', { from: '2026-11-01', to: '2026-11-08', employee_id: 7, department_id: 2 });
  });

  it('approves a period and clears cached reports so the screen reloads', async () => {
    post.mockResolvedValueOnce({ data: { approved: 3, already_approved: 1, outside_service: 2, skipped: 4 } });
    const book = TestBed.inject(AttendanceBook);
    book.reports.set({ stale: [] });
    const result = await book.approveBulk('2026-11-01', '2026-11-08', { departmentId: '2' });
    expect(post).toHaveBeenCalledWith('/attendance/days/approve-bulk', { from: '2026-11-01', to: '2026-11-08', department_id: 2 });
    expect(result?.approved).toBe(3);
    expect(book.reports()).toEqual({});
    expect(done).toHaveBeenCalledWith('bulkApproved');
  });
});
