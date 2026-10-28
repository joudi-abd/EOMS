import { Employee } from './employees';
import { defaultPolicy, Holiday, Mission, parseDay, Resolution, WorkPolicy } from './hr';
import { Text } from './text';

/** ZKTeco ATTLOG status codes pushed by the UA300 Pro over ADMS. */
export type PunchStatus = 0 | 1 | 2 | 3 | 4 | 5;

export interface Punch {
  id: string;
  pin: string;
  at: string;
  status: PunchStatus;
  verify: 1;
}

export interface PinLink {
  pin: string;
  employeeId: string;
}

/** A manual record or correction. It sits on top of the device log and never replaces it. */
export interface Adjustment {
  id: string;
  employeeId: string;
  day: string;
  checkIn: string;
  checkOut: string;
  note: string;
  by: string;
}

export type DayMark = 'present' | 'late' | 'early' | 'incomplete' | 'review' | 'absent' | 'leave' | 'mission' | 'holiday' | 'off';

export interface DayRow {
  employeeId: string;
  mark: DayMark;
  checkIn: string | null;
  checkOut: string | null;
  minutes: number | null;
  lateBy: number | null;
  overtime: number;
  duplicate: boolean;
  corrected: boolean;
  projectId: string;
}

export interface LogRow {
  id: string;
  pin: string;
  employeeId: string | null;
  at: string;
  status: PunchStatus;
  duplicate: boolean;
}

export interface DayReport {
  day: string;
  workday: boolean;
  holiday: Text | null;
  expected: number;
  arrived: number;
  present: number;
  review: number;
  absent: number;
  onLeave: number;
  mission: number;
  off: number;
  late: number;
  early: number;
  incomplete: number;
  overtime: number;
  rows: DayRow[];
  log: LogRow[];
  unmatched: LogRow[];
  lastAt: string | null;
}

export interface DayInput {
  employees: Employee[];
  punches: Punch[];
  pins: PinLink[];
  adjustments: Adjustment[];
  leaves: { employeeId: string; status: string; from: string; until: string; kind?: string }[];
  policy: WorkPolicy;
  holidays: Holiday[];
  missions: Mission[];
  resolutions: Resolution[];
}

export const device = { model: 'UA300 Pro', protocol: 'ADMS' };

const arrival = new Set<PunchStatus>([0, 3, 4]);
const departure = new Set<PunchStatus>([1, 2, 5]);

export function minutesOf(value: string): number {
  const time = value.includes('T') ? value.slice(11, 16) : value.slice(0, 5);
  const [hour, minute] = time.split(':').map(Number);
  return hour * 60 + minute;
}

export function clock(total: number): string {
  return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}

export function officeToday(now = new Date()): string {
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const date = String(now.getDate()).padStart(2, '0');
  return `${now.getFullYear()}-${month}-${date}`;
}

export function holidayOn(day: string, holidays: Holiday[]): Holiday | undefined {
  return holidays.find((item) => item.day === day);
}

export function isWorkday(day: string, policy: WorkPolicy = defaultPolicy, holidays: Holiday[] = []): boolean {
  return policy.workdays.includes(parseDay(day).getDay()) && !holidayOn(day, holidays);
}

export function onRoll(employee: Employee, day: string): boolean {
  if (employee.hired && employee.hired > day) {
    return false;
  }
  if (employee.status === 'ended') {
    return Boolean(employee.endedOn) && day <= employee.endedOn;
  }
  return true;
}

export function buildDay(input: DayInput, day: string): DayReport {
  const { policy } = input;
  const start = minutesOf(policy.start);
  const end = minutesOf(policy.end);
  const holiday = holidayOn(day, input.holidays) ?? null;
  const workday = policy.workdays.includes(parseDay(day).getDay()) && !holiday;
  const linked = new Map(input.pins.map((item) => [item.pin, item.employeeId]));
  const todays = input.punches
    .filter((item) => item.at.slice(0, 10) === day)
    .slice()
    .sort((a, b) => a.at.localeCompare(b.at));
  const seen: Punch[] = [];
  const log: LogRow[] = todays.map((item) => {
    const duplicate = seen.some(
      (earlier) => earlier.pin === item.pin && earlier.status === item.status && Math.abs(minutesOf(earlier.at) - minutesOf(item.at)) <= 2,
    );
    seen.push(item);
    return { id: item.id, pin: item.pin, employeeId: linked.get(item.pin) ?? null, at: item.at, status: item.status, duplicate };
  });

  const rows: DayRow[] = input.employees
    .filter((employee) => onRoll(employee, day))
    .map((employee) => {
      const own = log.filter((item) => item.employeeId === employee.id && !item.duplicate);
      const adjustment = input.adjustments.find((item) => item.employeeId === employee.id && item.day === day);
      let checkIn: number | null = null;
      let checkOut: number | null = null;
      if (adjustment) {
        checkIn = adjustment.checkIn ? minutesOf(adjustment.checkIn) : null;
        checkOut = adjustment.checkOut ? minutesOf(adjustment.checkOut) : null;
      } else {
        const arrivals = own.filter((item) => arrival.has(item.status));
        const departures = own.filter((item) => departure.has(item.status));
        checkIn = arrivals.length ? minutesOf(arrivals[0].at) : null;
        checkOut = departures.length ? minutesOf(departures[departures.length - 1].at) : null;
      }
      const covered = input.leaves.some(
        (item) => item.employeeId === employee.id && item.status === 'approved' && item.kind !== 'permit' && item.from <= day && day <= item.until,
      );
      const mission = input.missions.find((item) => item.employeeId === employee.id && item.day === day);
      const resolved = input.resolutions.some((item) => item.employeeId === employee.id && item.day === day);
      let mark: DayMark;
      let minutes: number | null = null;
      let lateBy: number | null = null;
      let overtime = 0;
      if (checkIn === null) {
        mark = covered ? 'leave' : mission ? 'mission' : holiday ? 'holiday' : !workday ? 'off' : resolved ? 'absent' : 'review';
      } else if (checkOut === null || checkOut < checkIn) {
        mark = 'incomplete';
      } else {
        minutes = checkOut - checkIn;
        if (!workday) {
          mark = 'present';
          overtime = minutes;
        } else {
          overtime = Math.max(0, checkOut - end);
          if (checkIn > start + policy.grace) {
            mark = 'late';
            lateBy = checkIn - start;
          } else if (checkOut < end) {
            mark = 'early';
          } else {
            mark = 'present';
          }
        }
      }
      return {
        employeeId: employee.id,
        mark,
        checkIn: checkIn === null ? null : clock(checkIn),
        checkOut: checkOut === null ? null : clock(checkOut),
        minutes,
        lateBy,
        overtime,
        duplicate: log.some((item) => item.employeeId === employee.id && item.duplicate),
        corrected: Boolean(adjustment),
        projectId: mission?.projectId ?? '',
      };
    });

  const count = (mark: DayMark) => rows.filter((row) => row.mark === mark).length;
  const lastAt = input.punches.reduce<string | null>((latest, item) => (latest === null || item.at > latest ? item.at : latest), null);

  return {
    day,
    workday,
    holiday: holiday?.name ?? null,
    expected: rows.filter((row) => row.mark !== 'off' && row.mark !== 'holiday' && row.mark !== 'leave').length,
    arrived: rows.filter((row) => row.mark === 'present' || row.mark === 'late' || row.mark === 'early' || row.mark === 'incomplete').length,
    present: count('present'),
    review: count('review'),
    absent: count('absent'),
    onLeave: count('leave'),
    mission: count('mission'),
    off: count('off') + count('holiday'),
    late: count('late'),
    early: count('early'),
    incomplete: count('incomplete'),
    overtime: rows.reduce((sum, row) => sum + row.overtime, 0),
    rows,
    log,
    unmatched: log.filter((item) => item.employeeId === null),
    lastAt,
  };
}
