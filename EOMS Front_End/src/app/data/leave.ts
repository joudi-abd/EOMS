import { FlowDecision } from './hr';
import { Text } from './text';

/** 'permit' is a leave counted in hours; 'leave' is counted in days. */
export type LeaveKind = 'leave' | 'permit';

export interface Leave {
  id: string;
  employeeId: string;
  employee: Text;
  kind: LeaveKind;
  typeId: string;
  type: Text;
  days: number;
  from: string;
  until: string;
  start: string;
  end: string;
  reason: string;
  status: 'pending_manager' | 'pending_hr' | 'approved' | 'rejected';
  decisions: FlowDecision[];
}

export function validIsoDate(value: string): boolean {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  if (!match) {
    return false;
  }
  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);
  const date = new Date(year, month - 1, day);
  return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day;
}

export function validClock(value: string): boolean {
  return /^([01]\d|2[0-3]):[0-5]\d$/.test(value);
}

export function inclusiveUntil(from: string, days: number): string {
  const [year, month, day] = from.split('-').map(Number);
  const date = new Date(year, month - 1, day);
  date.setDate(date.getDate() + days - 1);
  const part = (value: number) => String(value).padStart(2, '0');
  return `${date.getFullYear()}-${part(date.getMonth() + 1)}-${part(date.getDate())}`;
}
