import { ModuleId } from '../core/copy';

export const moduleIcon: Record<ModuleId | 'dashboard', string> = {
  dashboard: 'fa-solid fa-table-cells-large',
  access: 'fa-solid fa-user-shield',
  employees: 'fa-solid fa-users',
  attendance: 'fa-solid fa-user-clock',
  leave: 'fa-solid fa-calendar-check',
  requests: 'fa-solid fa-inbox',
  hrSettings: 'fa-solid fa-sliders',
  payroll: 'fa-solid fa-wallet',
  projects: 'fa-solid fa-drafting-compass',
  clients: 'fa-solid fa-file-contract',
  finance: 'fa-solid fa-coins',
  procurement: 'fa-solid fa-truck',
  documents: 'fa-solid fa-folder-open',
  assets: 'fa-solid fa-toolbox',
  meetings: 'fa-solid fa-comments',
  alerts: 'fa-solid fa-bell',
};
