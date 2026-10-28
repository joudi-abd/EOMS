import { ApiDepartment, ApiEmployee, ApiEmployeeStatus } from '../core/api/types';
import { Text } from './text';

/** `away` is the backend's `on_leave`; `leaving` marks an offboarding opened in this interface. */
export type EmployeeStatus = 'active' | 'away' | 'leaving' | 'ended';

export interface Department {
  id: string;
  name: Text;
  code: string;
  parentId: string;
}

/** Follows the ADMS enrollment: queued on the server, picked up by the device, finger placed. */
export type FingerprintState = 'none' | 'queued' | 'waiting' | 'enrolled' | 'failed';

export interface EmploymentContract {
  id: string;
  type: string;
  start: string;
  end: string;
  status: 'current' | 'previous' | 'closed';
}

export interface Employee {
  id: string;
  /** The login account (`usr_users.id`); empty when the server withholds it. */
  userId: string;
  name: Text;
  firstName: string;
  lastName: string;
  phone: string;
  email: string;
  birthDate: string;
  address: string;
  wallet: string;
  title: Text;
  titleId: string;
  department: string;
  managerId: string;
  hired: string;
  status: EmployeeStatus;
  endedOn: string;
  contractEnd: string;
  documents: Text[];
  projects: string[];
  nationalId: string;
  staffNo: string;
  /** The user number on the fingerprint device (`biometric_code`). */
  pin: string;
  fingerprint: FingerprintState;
  contracts: EmploymentContract[];
  /** The server sent contact and identity fields, so editing them is safe. */
  sensitive: boolean;
  /** The server sent the wallet number. */
  financial: boolean;
}

export function plain(value: string): Text {
  return { ar: value, en: value };
}

const statusFrom: Record<ApiEmployeeStatus, EmployeeStatus> = { active: 'active', on_leave: 'away', terminated: 'ended' };

const ref = (value: number | null | undefined): string => (value === null || value === undefined ? '' : String(value));

export function employeeFrom(item: ApiEmployee): Employee {
  const name = item.full_name || `${item.first_name} ${item.last_name}`.trim();
  return {
    id: String(item.id),
    userId: ref(item.user_id),
    name: plain(name),
    firstName: item.first_name,
    lastName: item.last_name,
    phone: item.phone ?? '',
    email: item.email ?? '',
    birthDate: item.birth_date ?? '',
    address: item.address ?? '',
    wallet: item.sham_cash_wallet ?? '',
    title: plain(item.job_title?.name ?? ''),
    titleId: ref(item.job_title_id),
    department: ref(item.department_id),
    managerId: ref(item.manager_id),
    hired: item.hire_date ?? '',
    status: statusFrom[item.status] ?? 'active',
    endedOn: item.termination_date ?? '',
    contractEnd: '',
    documents: [],
    projects: [],
    nationalId: '',
    staffNo: item.employee_no,
    pin: ref(item.biometric_code),
    fingerprint: item.biometric_code ? 'enrolled' : 'none',
    contracts: [],
    sensitive: 'email' in item,
    financial: 'sham_cash_wallet' in item,
  };
}

export function departmentFrom(item: ApiDepartment): Department {
  return { id: String(item.id), name: plain(item.name), code: item.code, parentId: ref(item.parent_id) };
}
