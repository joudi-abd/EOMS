/**
 * Wording for the finance "overview" area. Every key starts with `fov` so areas never collide.
 * Shared words (amount, date, voucher types, statuses…) live in `copy-finance.ts`; use those instead of repeating them.
 */
const ar = {
  // Setup line
  fovSetupLead: 'اكتمل',
  fovOf: 'من',
  fovSetupSteps: 'خطوات إعداد المالية.',
  fovSetupLink: 'أكمل الإعداد',

  // Liquidity
  fovLiquidity: 'السيولة',
  fovAllBoxes: 'كل الصناديق',

  // Needs attention
  fovAttention: 'يحتاج انتباهك',
  fovNegative: 'رصيد سالب',
  fovNearLimit: 'قريب من حد السالب',
  fovCountWaiting: 'جرد بانتظار الاعتماد',
  fovOverdueCustody: 'عُهد متأخرة',
  fovOlderThan: 'أقدم من',
  fovOverBudget: 'تجاوز الموازنة',
  fovLines: 'بنود',
  fovAllClear: 'لا شيء يحتاج انتباهك اليوم.',

  // Money in and out
  fovFlow: 'الوارد والصادر',
  fovFlowHint: 'حركة الصناديق دون التحويل والتصريف بينها.',
  fovThisMonth: 'هذا الشهر',
  fovLastMonth: 'الشهر الماضي',
  fovChartIn: 'الرسم بمعادل',
  fovNoFlow: 'لا حركة في الشهرين.',

  // Projects
  fovTopProjects: 'أعلى 5 مشاريع ربحاً',
  fovTopProjectsHint: 'صافي الربح بعملة العقد.',
  fovNoProjects: 'لا أرباح مسجّلة على المشاريع بعد.',

  // Owed
  fovOwed: 'المستحقات',
  fovToContractors: 'للمقاولين',
  fovPayable: 'رصيدهم المستحق',
  fovAgreementsLeft: 'المتبقي على الاتفاقيات',
  fovFromClients: 'من العملاء',
  fovReceivable: 'المستحق لنا',
  fovNothingOwed: 'لا شيء.',

  // Partner drawings
  fovDrawings: 'مسحوبات الشركاء هذا الشهر',
  fovPersonal: 'شخصية',
  fovCompanyExpense: 'مصروف شركة',
  fovVouchers: 'السندات',
  fovNoDrawings: 'لا مسحوبات هذا الشهر.',

  // Rates
  fovRatesUsed: 'الأسعار المستخدمة',
  fovHistorical: 'كل حركة بسعر يومها',
  fovBaseOnly: 'العملة الأساسية فقط',
};

const en: Record<keyof typeof ar, string> = {
  fovSetupLead: 'Done:',
  fovOf: 'of',
  fovSetupSteps: 'finance setup steps.',
  fovSetupLink: 'Finish setup',

  fovLiquidity: 'Liquidity',
  fovAllBoxes: 'All boxes',

  fovAttention: 'Needs your attention',
  fovNegative: 'Negative balance',
  fovNearLimit: 'Near its negative limit',
  fovCountWaiting: 'Count awaiting approval',
  fovOverdueCustody: 'Overdue custody',
  fovOlderThan: 'older than',
  fovOverBudget: 'Over budget',
  fovLines: 'lines',
  fovAllClear: 'Nothing needs your attention today.',

  fovFlow: 'Money in and out',
  fovFlowHint: 'Box movements, without transfers and exchanges between boxes.',
  fovThisMonth: 'This month',
  fovLastMonth: 'Last month',
  fovChartIn: 'Chart in',
  fovNoFlow: 'No movements in either month.',

  fovTopProjects: 'Top 5 projects by profit',
  fovTopProjectsHint: 'Net profit in the contract currency.',
  fovNoProjects: 'No project profit recorded yet.',

  fovOwed: 'Owed',
  fovToContractors: 'To contractors',
  fovPayable: 'Their balance due',
  fovAgreementsLeft: 'Left on agreements',
  fovFromClients: 'From clients',
  fovReceivable: 'Due to us',
  fovNothingOwed: 'Nothing.',

  fovDrawings: 'Partner drawings this month',
  fovPersonal: 'Personal',
  fovCompanyExpense: 'Company expense',
  fovVouchers: 'Vouchers',
  fovNoDrawings: 'No drawings this month.',

  fovRatesUsed: 'Rates used',
  fovHistorical: 'each movement at its own day’s rate',
  fovBaseOnly: 'base currency only',
};

export const finOverviewCopy = { ar, en };
