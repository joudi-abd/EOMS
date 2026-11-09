/** Wording added with the design pass: the top bar and the one-line description under each page title. */
const ar = {
  topSearch: 'بحث عن موظف بالاسم أو الرقم…',
  topSearchLabel: 'بحث عن موظف',
  ledeEmployees: 'ملفات الموظفين وأقسامهم ومسمياتهم. حفظ ملف جديد يعطيه رقمًا وظيفيًا تلقائيًا.',
  ledeAttendance: 'أيام الدوام كما حسبها النظام من بصمات الجهاز. التصحيح يُضاف فوق سجل الجهاز ولا يمحوه.',
  ledeLeave: 'الطلب يذهب إلى المدير المباشر ثم الموارد البشرية، والرصيد يُخصم بعد الاعتماد.',
  ledeAccess: 'حسابات الدخول وأدوارها وحالة كل حساب. الصلاحيات هي مجموع أدوار الحساب.',
  ledeHrSettings: 'الأقسام والمسميات، أنواع الإجازات وسياساتها، أجهزة البصمة وسياسة الدوام.',
  ledeRequests: 'طلبات الموظفين ومسار قرارها.',
  accStatTotal: 'كل الحسابات',
  accStatLinked: 'مرتبطة بملف موظف:',
  accStatActive: 'نشط',
  accStatDisabled: 'معطّل',
};

const en: Record<keyof typeof ar, string> = {
  topSearch: 'Search an employee by name or number…',
  topSearchLabel: 'Search employees',
  ledeEmployees: 'Employee files, departments and job titles. Saving a new file gives it an employee number automatically.',
  ledeAttendance: 'Workdays as the system computed them from device punches. A correction is added on top of the device record and never erases it.',
  ledeLeave: 'A request goes to the direct manager, then HR; the balance is deducted after approval.',
  ledeAccess: 'Sign-in accounts, their roles and status. Permissions are the union of an account’s roles.',
  ledeHrSettings: 'Departments and job titles, leave types and policies, fingerprint devices and the work schedule.',
  ledeRequests: 'Employee requests and their decision path.',
  accStatTotal: 'All accounts',
  accStatLinked: 'Linked to an employee file:',
  accStatActive: 'Active',
  accStatDisabled: 'Disabled',
};

export const designCopy = { ar, en };
