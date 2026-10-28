import { Text } from './text';

/** Weekdays use Date.getDay(): 0 Sunday … 6 Saturday. */
export interface WorkPolicy {
  start: string;
  end: string;
  grace: number;
  workdays: number[];
}

export const defaultPolicy: WorkPolicy = { start: '09:00', end: '16:00', grace: 45, workdays: [6, 0, 1, 2, 3, 4] };

export const weekOrder = [6, 0, 1, 2, 3, 4, 5];

export interface NamedItem {
  id: string;
  name: Text;
}

export interface Holiday {
  id: string;
  day: string;
  name: Text;
}

export interface LeaveTypeDef extends NamedItem {
  unit: 'day' | 'hour';
}

export interface ContractTypeDef extends NamedItem {
  fixed: boolean;
}

export const defaultLeaveTypes: LeaveTypeDef[] = [
  { id: 'annual', name: { ar: 'سنوية', en: 'Annual' }, unit: 'day' },
  { id: 'sick', name: { ar: 'مرضية', en: 'Sick' }, unit: 'day' },
  { id: 'unpaid', name: { ar: 'غير مدفوعة', en: 'Unpaid' }, unit: 'day' },
  { id: 'occasion', name: { ar: 'مناسبة', en: 'Occasion' }, unit: 'day' },
  { id: 'hourly', name: { ar: 'بالساعات', en: 'Hourly' }, unit: 'hour' },
];

export const defaultContractTypes: ContractTypeDef[] = [
  { id: 'fixed', name: { ar: 'محدد المدة', en: 'Fixed term' }, fixed: true },
  { id: 'open', name: { ar: 'غير محدد المدة', en: 'Open-ended' }, fixed: false },
];

export type HistoryField = 'name' | 'department' | 'title' | 'manager' | 'status' | 'phone' | 'email' | 'contract';

export interface HistoryEntry {
  id: string;
  employeeId: string;
  at: string;
  actorId: string;
  field: HistoryField;
  from: Text;
  to: Text;
}

export interface Evaluation {
  id: string;
  employeeId: string;
  at: string;
  actorId: string;
  note: string;
}

export interface Mission {
  id: string;
  employeeId: string;
  day: string;
  projectId: string;
  note: string;
  actorId: string;
}

export interface Resolution {
  id: string;
  employeeId: string;
  day: string;
  note: string;
  actorId: string;
}

export type FlowStatus = 'pending_manager' | 'pending_hr' | 'approved' | 'rejected';
/** 'self' marks a company director's own request: nobody sits above that authority. */
export type FlowStage = 'manager' | 'hr' | 'self';

export interface FlowDecision {
  stage: FlowStage;
  actorId: string;
  decision: 'approved' | 'rejected';
  at: string;
}

export type RequestKind = 'correction' | 'equipment' | 'resignation';

export interface HrRequest {
  id: string;
  employeeId: string;
  kind: RequestKind;
  filedAt: string;
  day: string;
  checkIn: string;
  checkOut: string;
  item: string;
  details: string;
  status: FlowStatus;
  decisions: FlowDecision[];
}

export interface Custody {
  id: string;
  employeeId: string;
  item: string;
  since: string;
  returnedAt: string;
  returnedTo: string;
  requestId: string;
}

export type LeavingReason = 'resignation' | 'termination' | 'contractEnd' | 'other';

export interface Offboarding {
  employeeId: string;
  previousStatus: 'active' | 'away';
  reason: LeavingReason;
  lastDay: string;
  note: string;
  startedBy: string;
  startedAt: string;
  status: 'open' | 'closed';
  closedBy: string;
  closedAt: string;
  requestId: string;
}

export type RunStatus = 'draft' | 'review' | 'approval' | 'approved';

export interface PayrollRun {
  id: string;
  period: string;
  status: RunStatus;
  employeeIds: string[];
  createdBy: string;
  preparedBy: string;
  reviewedBy: string;
  approvedBy: string;
  returnNote: string;
  updatedAt: string;
}

export type PayMethod = 'cash' | 'wallet';

export interface PayRecord {
  id: string;
  runId: string;
  employeeId: string;
  method: PayMethod;
  reference: string;
  paidOn: string;
  actorId: string;
}

export function stamp(now = new Date()): string {
  const part = (value: number) => String(value).padStart(2, '0');
  return `${now.getFullYear()}-${part(now.getMonth() + 1)}-${part(now.getDate())}T${part(now.getHours())}:${part(now.getMinutes())}`;
}

export function isoDay(date: Date): string {
  const part = (value: number) => String(value).padStart(2, '0');
  return `${date.getFullYear()}-${part(date.getMonth() + 1)}-${part(date.getDate())}`;
}

export function parseDay(day: string): Date {
  const [year, month, date] = day.split('-').map(Number);
  return new Date(year, month - 1, date);
}

export function monthBounds(period: string): { first: string; last: string } {
  const [year, month] = period.split('-').map(Number);
  return { first: `${period}-01`, last: isoDay(new Date(year, month, 0)) };
}

export function daysBetween(from: string, to: string): string[] {
  if (!from || !to || from > to) {
    return [];
  }
  const days: string[] = [];
  const cursor = parseDay(from);
  const last = parseDay(to);
  while (cursor <= last && days.length < 400) {
    days.push(isoDay(cursor));
    cursor.setDate(cursor.getDate() + 1);
  }
  return days;
}
