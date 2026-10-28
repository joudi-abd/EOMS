import { Routes } from '@angular/router';
import { signedIn, signedOut } from './core/api/guards';
import { Dashboard } from './pages/dashboard/dashboard';
import { Access } from './pages/access/access';
import { Attendance } from './pages/attendance/attendance';
import { Employees } from './pages/employees/employees';
import { HrSettings } from './pages/hr-settings/hr-settings';
import { Leave } from './pages/leave/leave';
import { Login } from './pages/login/login';
import { Payroll } from './pages/payroll/payroll';
import { Requests } from './pages/requests/requests';
import { Later } from './pages/later/later';
import { Shell } from './shell/shell';

export const routes: Routes = [
  { path: 'login', component: Login, canActivate: [signedOut] },
  {
    path: '',
    component: Shell,
    canActivate: [signedIn],
    children: [
      { path: '', component: Dashboard },
      { path: 'modules/access', component: Access },
      { path: 'modules/employees', component: Employees },
      { path: 'modules/attendance', component: Attendance },
      { path: 'modules/leave', component: Leave },
      { path: 'modules/requests', component: Requests },
      { path: 'modules/payroll', component: Payroll },
      { path: 'modules/hrSettings', component: HrSettings },
      { path: 'modules/:id', component: Later },
    ],
  },
  { path: '**', redirectTo: '' },
];
