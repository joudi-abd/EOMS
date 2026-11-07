import { TestBed } from '@angular/core/testing';
import { Api } from '../core/api/api';
import { ApiEnrollmentView } from '../core/api/types';
import { Office } from './office';
import { Enrollment } from './enrollment';

function view(status: string, extra: Partial<ApiEnrollmentView> = {}): ApiEnrollmentView {
  return {
    enrollment: {
      id: 3,
      binding_id: 2,
      device_id: 1,
      device_user_id: '00900002',
      status,
      finger_id: 4,
    },
    commands: [],
    operator_confirmed: status === 'manually_confirmed',
    biometric_verified: false,
    physical_test_required: status === 'awaiting_physical_verification',
    can_confirm: status === 'awaiting_physical_verification',
    can_cancel: true,
    ...extra,
  };
}
const empty: ApiEnrollmentView = {
  enrollment: null,
  commands: [],
  operator_confirmed: false,
  biometric_verified: false,
  physical_test_required: false,
};

function setup(initial = empty) {
  let latest = initial;
  const get = vi.fn(async (url: string) => ({
    data:
      url === '/attendance/devices'
        ? [
            {
              id: 1,
              name: 'Device',
              timezone: 'Asia/Damascus',
              connection_mode: 'adms',
              is_active: true,
            },
          ]
        : latest,
  }));
  const post = vi.fn(async () => ({ data: view('queued_user') }));
  const all = vi.fn(async () => [
    { id: 2, employee_id: 1, device_user_id: '00900002', valid_from: '2020-01-01', valid_to: null },
  ]);
  const refreshEmployee = vi.fn().mockResolvedValue(undefined);
  TestBed.configureTestingModule({
    providers: [
      { provide: Api, useValue: { get, all, post } },
      { provide: Office, useValue: { done: vi.fn(), fail: vi.fn(() => ({})), refreshEmployee } },
    ],
  });
  return {
    service: TestBed.inject(Enrollment),
    post,
    get,
    all,
    refreshEmployee,
    setLatest: (value: ApiEnrollmentView) => {
      latest = value;
    },
  };
}

describe('Enrollment integration', () => {
  afterEach(() => {
    TestBed.inject(Enrollment).stop();
    TestBed.resetTestingModule();
  });

  it('restores a pending enrollment after closing and reopening', async () => {
    const { service, post } = setup(view('awaiting_physical_verification'));
    await service.open('1');
    service.stop();
    await service.open('1');
    expect(service.status().state).toBe('verify');
    post.mockResolvedValueOnce({ data: view('manually_confirmed') });
    await service.confirm('Physical test succeeded');
    expect(post).toHaveBeenCalledWith('/attendance/fingerprint-enrollments/3/confirm', {
      physical_test_passed: true,
      note: 'Physical test succeeded',
    });
    expect(service.status().state).toBe('enrolled');
  });

  it('does not present an unsuccessful timeout as ready to confirm', async () => {
    const { service, post } = setup(view('timeout', { can_confirm: false }));
    await service.open('1');
    expect(service.status().state).toBe('failed');
    await service.confirm('Not allowed to confirm');
    expect(post).not.toHaveBeenCalled();
  });

  it('supports historical timeouts only when the server explicitly permits confirmation', async () => {
    const { service } = setup(view('timeout', { can_confirm: true }));
    await service.open('1');
    expect(service.status().state).toBe('verify');
  });

  it('refreshes the employee table and dossier after successful confirmation', async () => {
    const { service, post, refreshEmployee } = setup(view('awaiting_physical_verification'));
    await service.open('1');
    post.mockResolvedValueOnce({ data: view('manually_confirmed') });
    await service.confirm('Employee recognized under correct PIN');
    expect(refreshEmployee).toHaveBeenCalledWith('1');
  });

  it('cancels tracking using the original task id and refreshes its employee', async () => {
    const { service, post, refreshEmployee } = setup(view('awaiting_physical_verification'));
    await service.open('1');
    post.mockResolvedValueOnce({ data: view('cancelled') });
    await service.cancel('Operator closed device enrollment screen');
    expect(post).toHaveBeenCalledWith('/attendance/fingerprint-enrollments/3/cancel', {
      note: 'Operator closed device enrollment screen',
    });
    expect(service.status().state).toBe('failed');
    expect(refreshEmployee).toHaveBeenCalledWith('1');
  });

  it('does not start a second command while physical confirmation is pending', async () => {
    const { service, post } = setup(view('awaiting_physical_verification'));
    await service.open('1');
    await service.start(2, 4);
    expect(post).not.toHaveBeenCalled();
  });

  it('ignores an old open response after switching employees', async () => {
    const { service, get } = setup();
    let resolve!: (value: { data: never[] }) => void;
    get.mockImplementationOnce(
      () =>
        new Promise((done) => {
          resolve = done;
        }),
    );
    const old = service.open('1');
    await service.open('2');
    resolve({ data: [] });
    await old;
    expect(service.bindings()).toEqual([]);
    expect(service.status().pin).toBe('');
  });

  it('ignores disabled devices and devices using bridge connection', async () => {
    const { service, get, all } = setup();
    get.mockResolvedValueOnce({
      data: [
        {
          id: 1,
          name: 'Disabled',
          timezone: 'Asia/Damascus',
          connection_mode: 'adms',
          is_active: false,
        },
        {
          id: 2,
          name: 'Bridge',
          timezone: 'Asia/Damascus',
          connection_mode: 'bridge',
          is_active: true,
        },
      ],
    });
    await service.open('1');
    expect(all).not.toHaveBeenCalled();
    expect(service.bindings()).toEqual([]);
  });
  it('does not resume a retired PIN binding after a return to service', async () => {
    const { service, all } = setup(view('awaiting_physical_verification'));
    const retired = { id: 2, employee_id: 1, device_user_id: '00900002', valid_from: '2020-01-01', valid_to: null, revoked_from: '2020-02-01' };
    all.mockResolvedValueOnce([retired]);
    await service.open('1');
    expect(service.bindings()).toEqual([]);
    expect(service.status().state).toBe('none');
  });

});
