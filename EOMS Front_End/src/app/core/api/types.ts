/** Shapes returned by the EOMS backend (`/api/v1`). */

export interface ApiUser {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  status: 'active' | 'disabled';
  roles: string[];
  last_login_at: string | null;
  created_at: string | null;
}

export interface ApiDepartment {
  id: number;
  parent_id: number | null;
  name: string;
  code: string;
}

export interface ApiJobTitle {
  id: number;
  name: string;
}

export type ApiEmployeeStatus = 'active' | 'on_leave' | 'terminated';

/** Sensitive fields arrive only with `employees.view_sensitive`; the wallet only with `employees.view_financial`. */
export interface ApiEmployee {
  id: number;
  employee_no: string;
  has_account?: boolean;
  first_name: string;
  father_name?: string | null;
  last_name: string;
  full_name: string;
  department_id: number | null;
  job_title_id: number | null;
  manager_id: number | null;
  status: ApiEmployeeStatus;
  hire_date: string;
  department?: ApiDepartment | null;
  job_title?: ApiJobTitle | null;
  birth_date?: string | null;
  email?: string | null;
  phone?: string | null;
  address?: string | null;
  termination_date?: string | null;
  termination_reason?: string | null;
  biometric_code?: number | null;
  user_id?: number | null;
  sham_cash_wallet?: string | null;
}

export type ApiContractStatus = 'draft' | 'active' | 'expired' | 'terminated';
export type ApiContractType = 'fixed_term' | 'indefinite';

/** The salary fields arrive only with `employees.view_financial`. */
export interface ApiContract {
  id: number;
  employee_id: number;
  contract_number: string;
  type: ApiContractType;
  status: ApiContractStatus;
  start_date: string;
  end_date: string | null;
  terminated_at: string | null;
  scheduled_termination_at: string | null;
  scheduled_termination_reason: string | null;
  renewed_from_id: number | null;
  base_salary?: string | null;
  currency_code?: string | null;
}

export interface ApiJobChange {
  id: number;
  employee_id: number;
  event_type: 'hired' | 'assignment_changed';
  reason: string | null;
  old_department_id: number | null;
  new_department_id: number | null;
  old_job_title_id: number | null;
  new_job_title_id: number | null;
  old_manager_id: number | null;
  new_manager_id: number | null;
  effective_date: string | null;
}

export interface ApiDocument {
  id: number;
  employee_id: number;
  title: string;
  document_type: string;
  original_name: string;
  mime_type: string;
  file_size: number;
  description: string | null;
  created_at: string | null;
}

export interface ApiCurrency {
  id: number;
  code: string;
  name: string;
  symbol: string;
}

export interface ApiPage<T> {
  data: T[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}

export interface ApiItem<T> {
  data: T;
}

export type ApiDayStatus = 'pending' | 'present' | 'incomplete' | 'absent' | 'absent_unconfirmed' | 'day_off' | 'outside_service';

export interface ApiAttendanceEmployee {
  id: number;
  employee_no: string;
  name: string;
  department_id: number | null;
}

export interface ApiAttendancePolicySnap {
  id: number;
  timezone: string;
  start_time: string;
  end_time: string;
  grace_minutes: number;
  grace_mode: string;
  late_block_minutes: number;
  overtime_multiplier: string | number;
  pairing_mode: string;
}

export interface ApiAttendanceDay {
  id: number;
  employee: ApiAttendanceEmployee;
  work_date: string;
  policy: ApiAttendancePolicySnap;
  status: ApiDayStatus;
  first_in: string | null;
  last_out: string | null;
  worked_minutes: number | null;
  late_actual_minutes: number | null;
  late_minutes: number | null;
  late_hours: number | null;
  early_minutes: number | null;
  extra_minutes_unapproved: number | null;
  flags: string[];
  coverage_complete: boolean;
  pending_changes: boolean;
  version: number;
  approved_at: string | null;
  review_status: 'approved' | 'draft';
  permissions: {
    can_request_correction: boolean;
    can_approve: boolean;
    can_reopen: boolean;
  };
}

export interface ApiAttendancePunch {
  id: number;
  device_id: number;
  employee_id: number | null;
  external_id: string;
  device_user_id: string;
  occurred_at: string | null;
  direction: 'in' | 'out' | 'unknown' | string;
  received_at: string | null;
}

export interface ApiAttendanceCorrection {
  id: number;
  day_id: number;
  type: 'times' | 'absence' | string;
  first_in: string | null;
  last_out: string | null;
  reason: string;
  status: 'pending' | 'approved' | 'rejected' | string;
  base_version: number;
  requested_by: number;
  reviewed_by: number | null;
  reviewed_at: string | null;
  review_reason: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface ApiAttendanceBundle {
  day: ApiAttendanceDay;
  punches: ApiAttendancePunch[];
  corrections: ApiAttendanceCorrection[];
}

export interface ApiAttendanceReport {
  days: ApiAttendanceDay[];
  summary: {
    days: number;
    by_status: Record<string, number>;
    worked_minutes: number;
    late_minutes: number;
    early_minutes: number;
    approved_days: number;
    unknown_worked_days: number;
    pending_change_days: number;
    financial_effect_applied: boolean;
  };
}

/** One version of the attendance policy (`/attendance/policies`); a version rules from `effective_from` until the next one. */
export interface ApiPolicy {
  id: number;
  effective_from: string;
  timezone: string;
  working_days: number[];
  start_time: string;
  end_time: string;
  grace_minutes: number;
  grace_mode: string;
  late_block_minutes: number;
  overtime_multiplier: string | number;
  pairing_mode: string;
  duplicate_seconds: number;
  finalize_after_minutes: number;
}

export interface ApiDevice {
  id: number;
  name: string;
  model: string;
  serial_number: string;
  timezone: string;
  is_active: boolean;
  connection_mode: 'bridge' | 'adms' | string;
  adms_source_ip: string | null;
  last_seen_at: string | null;
  complete_through: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface ApiDeviceToken {
  device: ApiDevice;
  token: string;
}

export interface ApiBinding {
  id: number;
  device_id: number;
  employee_id: number;
  device_user_id: string;
  valid_from: string;
  valid_to: string | null;
  created_at: string | null;
  updated_at: string | null;
}

/** A punch the device sent with a clock the server refused to trust until someone reviews it. */
export interface ApiQuarantine {
  id: number;
  device_id: number;
  device_user_id: string;
  occurred_at: string | null;
  direction: string;
  reason: string;
  status: 'pending' | 'released' | 'dismissed' | string;
  received_at: string | null;
  reviewed_by: number | null;
  reviewed_at: string | null;
  review_note: string | null;
  punch_id: number | null;
}

export interface ApiEnrollmentRow {
  id: number;
  device_id: number;
  binding_id: number;
  device_user_id: string;
  finger_id: number;
  status: string;
}

export interface ApiEnrollmentView {
  enrollment: ApiEnrollmentRow | null;
  commands: { id: number; kind: string; status: string; return_code: string | null }[];
  biometric_verified: boolean;
  operator_confirmed: boolean;
  physical_test_required: boolean;
}
