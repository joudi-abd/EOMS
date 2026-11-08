export function leaveClock(value: string | null | undefined): string {
  return (value ?? '').slice(0, 5);
}

export function leaveTone(status: string): string {
  if (status === 'approved') return 'ok';
  if (status === 'pending') return 'wait';
  if (status === 'rejected' || status === 'cancelled' || status === 'expired') return 'late';
  return '';
}

export function leaveShift(iso: string, days: number): string {
  if (!iso) return '';
  const [year, month, day] = iso.split('-').map(Number);
  const date = new Date(year, month - 1, day + days);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

const labels: Record<string, [string, string]> = {
  pending: ['بانتظار الموافقة', 'Pending'], approved: ['معتمدة', 'Approved'], rejected: ['مرفوضة', 'Rejected'],
  withdrawn: ['مسحوبة', 'Withdrawn'], cancelled: ['ملغاة', 'Cancelled'], expired: ['منتهية', 'Expired'],
  approve: ['اعتماد', 'Approve'], reject: ['رفض', 'Reject'], withdraw: ['سحب الطلب', 'Withdraw'],
  cancel: ['طلب إلغاء', 'Request cancellation'], shorten: ['طلب تقصير', 'Request shortening'],
  'approve-change': ['اعتماد الإلغاء / التقصير', 'Approve change'], 'reject-change': ['رفض التغيير', 'Reject change'],
};

export function leaveLabel(arabic: boolean, status: string): string {
  const pair = labels[status];
  return pair ? (arabic ? pair[0] : pair[1]) : status;
}
