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
  first_name: string;
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
  biometric_code?: number | null;
  user_id?: number | null;
  sham_cash_wallet?: string | null;
}

export interface ApiPage<T> {
  data: T[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}

export interface ApiItem<T> {
  data: T;
}
