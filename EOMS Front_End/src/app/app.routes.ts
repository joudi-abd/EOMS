import { LeavesPage } from './pages/leaves/leaves';
import { Routes } from '@angular/router';
import { signedIn, signedOut } from './core/api/guards';
import { Alerts } from './pages/alerts/alerts';
import { Attendance } from './pages/attendance/attendance';
import { Dashboard } from './pages/dashboard/dashboard';
import { Access } from './pages/access/access';
import { Employees } from './pages/employees/employees';
import { FinOverviewPage } from './pages/finance/overview/overview';
import { FinBoxesPage } from './pages/finance/boxes/boxes';
import { FinVouchersPage } from './pages/finance/vouchers/vouchers';
import { FinPartiesPage } from './pages/finance/parties/parties';
import { FinProjectsPage } from './pages/finance/projects/projects';
import { FinAdvancesPage } from './pages/finance/advances/advances';
import { FinAssetsPage } from './pages/finance/assets/assets';
import { FinSitePage } from './pages/finance/site/site';
import { FinReportsPage } from './pages/finance/reports/reports';
import { FinSettingsPage } from './pages/finance/settings/settings';
import { HrSettings } from './pages/hr-settings/hr-settings';
import { Login } from './pages/login/login';
import { Later } from './pages/later/later';
import { PayrollPage } from './pages/payroll/payroll-page/payroll-page';
import { TasksPage } from './pages/tasks/tasks-page';
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
      { path: 'modules/requests', redirectTo: '/modules/leave', pathMatch: 'full' },
      { path: 'modules/payroll', component: PayrollPage },
      { path: 'modules/tasks', component: TasksPage },
      { path: 'modules/alerts', component: Alerts },
      { path: 'modules/finOverview', component: FinOverviewPage },
      { path: 'modules/finBoxes', component: FinBoxesPage },
      { path: 'modules/finVouchers', component: FinVouchersPage },
      { path: 'modules/finParties', component: FinPartiesPage },
      { path: 'modules/finProjects', component: FinProjectsPage },
      { path: 'modules/finAdvances', component: FinAdvancesPage },
      { path: 'modules/finAssets', component: FinAssetsPage },
      { path: 'modules/finSite', component: FinSitePage },
      { path: 'modules/finReports', component: FinReportsPage },
      { path: 'modules/finSettings', component: FinSettingsPage },
      // Old finance addresses open the closest new area.
      { path: 'modules/finCash', redirectTo: '/modules/finBoxes', pathMatch: 'full' },
      { path: 'modules/finInvoices', redirectTo: '/modules/finVouchers', pathMatch: 'full' },
      { path: 'modules/finItems', redirectTo: '/modules/finOverview', pathMatch: 'full' },
      { path: 'modules/finLedger', redirectTo: '/modules/finReports', pathMatch: 'full' },
      { path: 'modules/finance/rates', redirectTo: '/modules/finSettings', pathMatch: 'full' },
      { path: 'modules/finance', redirectTo: '/modules/finOverview', pathMatch: 'full' },
      { path: 'modules/:id', component: Later },
    ],
  },
  { path: '**', redirectTo: '' },
];
