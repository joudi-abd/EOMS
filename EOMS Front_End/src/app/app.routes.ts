import { LeavesPage } from './pages/leaves/leaves';
import { Routes } from '@angular/router';
import { signedIn, signedOut } from './core/api/guards';
import { Alerts } from './pages/alerts/alerts';
import { Attendance } from './pages/attendance/attendance';
import { Dashboard } from './pages/dashboard/dashboard';
import { Access } from './pages/access/access';
import { Employees } from './pages/employees/employees';
import { FinancePage } from './pages/finance/finance';
import { Rates } from './pages/finance/rates/rates';
import { HrSettings } from './pages/hr-settings/hr-settings';
import { Login } from './pages/login/login';
import { Later } from './pages/later/later';
import { PayrollPage } from './pages/payroll/payroll-page/payroll-page';
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
      { path: 'modules/hrSettings', component: HrSettings },
      { path: 'modules/attendance', component: Attendance },
      { path: 'modules/leave', component: LeavesPage },
      { path: 'modules/payroll', component: PayrollPage },
      { path: 'modules/alerts', component: Alerts },
      { path: 'modules/finance', component: FinancePage },
      { path: 'modules/finance/rates', component: Rates },
      { path: 'modules/:id', component: Later },
    ],
  },
  { path: '**', redirectTo: '' },
];
