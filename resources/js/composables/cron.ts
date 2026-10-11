/**
 * A tiny reader for the five-field cron expressions schedules use
 * (dragonmantank/cron-expression on the server, which validates). It
 * describes common forms in words and estimates the next runs; anything it
 * doesn't understand falls back to the raw expression. No dependency.
 */

const ALIASES: Record<string, string> = {
    '@yearly': '0 0 1 1 *',
    '@annually': '0 0 1 1 *',
    '@monthly': '0 0 1 * *',
    '@weekly': '0 0 * * 0',
    '@daily': '0 0 * * *',
    '@midnight': '0 0 * * *',
    '@hourly': '0 * * * *',
};

const MONTHS = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
const DAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

interface Field {
    raw: string;
    values: Set<number>;
    /** `*` (or `?`): no restriction. */
    any: boolean;
}

interface Parsed {
    minute: Field;
    hour: Field;
    day: Field;
    month: Field;
    weekday: Field;
}

function parseField(raw: string, min: number, max: number, names: string[] = [], offset = 0): Field | null {
    const values = new Set<number>();
    const any = raw === '*' || raw === '?';
    const value = (text: string): number | null => {
        const index = names.indexOf(text.toLowerCase());
        if (index !== -1) {
            return index + offset;
        }

        return /^\d+$/.test(text) ? Number(text) : null;
    };

    for (const part of raw.split(',')) {
        const match = /^(\*|\?|[A-Za-z0-9]+)(?:-([A-Za-z0-9]+))?(?:\/(\d+))?$/.exec(part);
        if (!match) {
            return null;
        }
        const [, start, end, step] = match;
        const from = start === '*' || start === '?' ? min : value(start as string);
        const to = end !== undefined ? value(end) : start === '*' || start === '?' || step !== undefined ? max : from;
        const every = step !== undefined ? Number(step) : 1;
        if (from === null || to === null || every < 1) {
            return null;
        }
        if (from < min || to > max || from > to) {
            return null;
        }
        for (let n = from; n <= to; n += every) {
            values.add(n);
        }
    }

    return { raw, values, any };
}

export function parseCron(expression: string): Parsed | null {
    const text = ALIASES[expression.trim().toLowerCase()] ?? expression.trim();
    const parts = text.split(/\s+/);
    if (parts.length !== 5) {
        return null;
    }
    const [minute, hour, day, month, weekday] = [
        parseField(parts[0] as string, 0, 59),
        parseField(parts[1] as string, 0, 23),
        parseField(parts[2] as string, 1, 31),
        parseField(parts[3] as string, 1, 12, MONTHS, 1),
        parseField(parts[4] as string, 0, 7, DAYS),
    ];
    // Day of week 7 is Sunday too.
    if (weekday?.values.delete(7)) {
        weekday.values.add(0);
    }

    return minute && hour && day && month && weekday ? { minute, hour, day, month, weekday } : null;
}

const pad = (n: number) => String(n).padStart(2, '0');
const sorted = (field: Field) => [...field.values].sort((a, b) => a - b);

function list(items: string[]): string {
    return items.length <= 1 ? (items[0] ?? '') : `${items.slice(0, -1).join(', ')} and ${items[items.length - 1]}`;
}

function times(cron: Parsed): string | null {
    const minutes = sorted(cron.minute);
    const hours = sorted(cron.hour);
    if (cron.minute.any || cron.hour.any || minutes.length * hours.length > 6) {
        return null;
    }

    return list(hours.flatMap((h) => minutes.map((m) => `${pad(h)}:${pad(m)}`)));
}

function stepOf(field: Field): number | null {
    const match = /^\*\/(\d+)$/.exec(field.raw);

    return match ? Number(match[1]) : null;
}

function days(cron: Parsed): string | null {
    const { day, month, weekday } = cron;
    if (!month.any) {
        if (weekday.any && !day.any && day.values.size === 1 && month.values.size === 1) {
            return `every year on ${MONTH_NAMES[(sorted(month)[0] as number) - 1]} ${sorted(day)[0]}`;
        }

        return null;
    }
    if (day.any && weekday.any) {
        return 'every day';
    }
    if (day.any) {
        const values = sorted(weekday);
        if (values.join() === '1,2,3,4,5') {
            return 'every weekday (Monday to Friday)';
        }
        if (values.join() === '0,6') {
            return 'every weekend day';
        }

        return `every ${list(values.map((n) => DAY_NAMES[n] as string))}`;
    }
    if (weekday.any) {
        const values = sorted(day);
        const ordinal = (n: number) => `${n}${n % 10 === 1 && n !== 11 ? 'st' : n % 10 === 2 && n !== 12 ? 'nd' : n % 10 === 3 && n !== 13 ? 'rd' : 'th'}`;

        return values.length <= 4 ? `on the ${list(values.map(ordinal))} of every month` : null;
    }

    return null;
}

/** "Every day at 06:00", or null when the form isn't a common one. */
export function describeCron(expression: string): string | null {
    const cron = parseCron(expression);
    if (!cron) {
        return null;
    }
    const everyDay = cron.day.any && cron.month.any && cron.weekday.any;
    const minuteStep = stepOf(cron.minute);
    const hourStep = stepOf(cron.hour);

    if (everyDay && cron.hour.any) {
        if (cron.minute.any) {
            return 'Every minute';
        }
        if (minuteStep !== null) {
            return `Every ${minuteStep} minutes`;
        }
        if (cron.minute.values.size === 1) {
            const minute = sorted(cron.minute)[0] as number;

            return minute === 0 ? 'Every hour, on the hour' : `Every hour at ${minute} minutes past`;
        }

        return null;
    }
    if (everyDay && hourStep !== null && cron.minute.values.size === 1) {
        return `Every ${hourStep} hours at ${sorted(cron.minute)[0] as number} minutes past`;
    }

    const at = times(cron);
    const on = days(cron);
    if (at === null || on === null) {
        return null;
    }

    return `${on.charAt(0).toUpperCase()}${on.slice(1)} at ${at}`;
}

interface WallClock {
    month: number;
    day: number;
    weekday: number;
    hour: number;
    minute: number;
}

function wallClock(date: Date, timeZone: string): WallClock {
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-US', { timeZone, hourCycle: 'h23', month: 'numeric', day: 'numeric', weekday: 'short', hour: 'numeric', minute: 'numeric' })
            .formatToParts(date)
            .map((part) => [part.type, part.value]),
    );

    return {
        month: Number(parts.month),
        day: Number(parts.day),
        weekday: DAYS.indexOf(String(parts.weekday).toLowerCase().slice(0, 3)),
        hour: Number(parts.hour) % 24,
        minute: Number(parts.minute),
    };
}

/**
 * The next `count` times the expression matches, evaluated on the wall
 * clock of `timeZone` (as the server does), or [] when it can't be read.
 * Day of month and day of week, when both are restricted, match either.
 */
export function nextRuns(expression: string, timeZone: string, count = 3, from: Date = new Date()): Date[] {
    const cron = parseCron(expression);
    if (!cron) {
        return [];
    }
    try {
        new Intl.DateTimeFormat('en-US', { timeZone });
    } catch {
        return [];
    }

    const runs: Date[] = [];
    let time = Math.floor(from.getTime() / 60000) * 60000 + 60000;

    for (let guard = 0; guard < 20000 && runs.length < count; guard++) {
        const clock = wallClock(new Date(time), timeZone);
        const dayMatches = cron.day.any || cron.weekday.any ? cron.day.values.has(clock.day) && cron.weekday.values.has(clock.weekday) : cron.day.values.has(clock.day) || cron.weekday.values.has(clock.weekday);

        if (!cron.month.values.has(clock.month) || !dayMatches) {
            time += ((23 - clock.hour) * 60 + (60 - clock.minute)) * 60000;
        } else if (!cron.hour.values.has(clock.hour)) {
            time += (60 - clock.minute) * 60000;
        } else if (!cron.minute.values.has(clock.minute)) {
            time += 60000;
        } else {
            runs.push(new Date(time));
            time += 60000;
        }
    }

    return runs;
}
