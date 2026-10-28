import { CopyKey } from '../../core/copy';
import { DayMark, PunchStatus } from '../../data/attendance';
import { tone } from '../../shared/charts/chart';

/** Chart fill for each attendance state. */
export const markFill: Record<DayMark, string> = {
  present: tone.ok,
  late: tone.wait,
  early: tone.violet,
  incomplete: tone.sky,
  review: tone.late,
  absent: '#991b1b',
  leave: tone.info,
  mission: tone.teal,
  holiday: tone.quiet,
  off: tone.quiet,
};

export const markKey: Record<DayMark, CopyKey> = {
  present: 'present',
  late: 'lateArrival',
  early: 'earlyLeave',
  incomplete: 'incomplete',
  review: 'reviewMark',
  absent: 'absent',
  leave: 'onLeave',
  mission: 'missionMark',
  holiday: 'holidayMark',
  off: 'offDay',
};

export const markWeight: Record<DayMark, number> = {
  review: 0,
  absent: 1,
  incomplete: 2,
  late: 3,
  early: 4,
  mission: 5,
  leave: 6,
  present: 7,
  holiday: 8,
  off: 9,
};

export function markTone(mark: DayMark): string {
  if (mark === 'present' || mark === 'leave' || mark === 'mission') return 'ok';
  if (mark === 'absent' || mark === 'review') return 'late';
  if (mark === 'off' || mark === 'holiday') return '';
  return 'wait';
}

export const directionKey: Record<PunchStatus, CopyKey> = {
  0: 'checkIn',
  1: 'checkOut',
  2: 'breakOut',
  3: 'breakIn',
  4: 'overtimeIn',
  5: 'overtimeOut',
};
