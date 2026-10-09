/**
 * A small PostgreSQL tokenizer for the editor: it finds the statement under
 * the cursor (or the selection) to run.
 *
 * It deliberately does not classify statements: the server classifies (PRD
 * S11) and drives every confirmation, so the SPA holds no SQL-classifier copy
 * to drift out of sync. The database login is the enforcement.
 */

export interface Statement {
    /** Offsets into the source, without surrounding whitespace. */
    from: number;
    to: number;
}

/** Split on top-level semicolons, skipping comments, strings, quoted names and dollar quotes. */
export function statements(sql: string): Statement[] {
    const result: Statement[] = [];
    let current: Statement | null = null;
    let depth = 0;
    let i = 0;

    const touch = (from: number, to: number): void => {
        current ??= { from, to };
        current.to = to;
    };

    while (i < sql.length) {
        const ch = sql[i] as string;
        const next = sql[i + 1];
        const start = i;

        if (/\s/.test(ch)) {
            i++;
        } else if (ch === '-' && next === '-') {
            const end = sql.indexOf('\n', i);
            i = end === -1 ? sql.length : end;
        } else if (ch === '/' && next === '*') {
            let nesting = 1;
            i += 2;
            while (i < sql.length && nesting > 0) {
                if (sql.startsWith('/*', i)) {
                    nesting++;
                    i += 2;
                } else if (sql.startsWith('*/', i)) {
                    nesting--;
                    i += 2;
                } else {
                    i++;
                }
            }
        } else if (ch === ';' && depth === 0) {
            if (current) {
                result.push(current);
            }
            current = null;
            i++;
        } else if (ch === "'" || ((ch === 'E' || ch === 'e') && next === "'")) {
            const escapes = ch !== "'";
            i += escapes ? 2 : 1;
            while (i < sql.length) {
                if (escapes && sql[i] === '\\') {
                    i += 2;
                } else if (sql[i] === "'" && sql[i + 1] === "'") {
                    i += 2;
                } else if (sql[i] === "'") {
                    i++;
                    break;
                } else {
                    i++;
                }
            }
            touch(start, i);
        } else if (ch === '"') {
            i++;
            while (i < sql.length) {
                if (sql[i] === '"' && sql[i + 1] === '"') {
                    i += 2;
                } else if (sql[i] === '"') {
                    i++;
                    break;
                } else {
                    i++;
                }
            }
            touch(start, i);
        } else if (ch === '$' && /^\$([A-Za-z_][A-Za-z0-9_]*)?\$/.test(sql.slice(i, i + 65))) {
            const tag = (/^\$([A-Za-z_][A-Za-z0-9_]*)?\$/.exec(sql.slice(i, i + 65)) as RegExpExecArray)[0];
            const end = sql.indexOf(tag, i + tag.length);
            i = end === -1 ? sql.length : end + tag.length;
            touch(start, i);
        } else if (/[A-Za-z_]/.test(ch)) {
            while (i < sql.length && /[A-Za-z0-9_$]/.test(sql[i] as string)) {
                i++;
            }
            touch(start, i);
        } else {
            if (ch === '(') {
                depth++;
            } else if (ch === ')') {
                depth = Math.max(0, depth - 1);
            }
            i++;
            touch(start, i);
        }
    }

    if (current) {
        result.push(current);
    }

    return result;
}

/**
 * What to run for a cursor or selection: the selection when there is one,
 * otherwise the statement the cursor is in or right after.
 */
export function statementAt(sql: string, from: number, to: number): { text: string; offset: number } {
    if (from !== to) {
        return { text: sql.slice(from, to), offset: from };
    }

    const all = statements(sql);
    const found = all.find((s) => from >= s.from && from <= s.to + 1)
        ?? [...all].reverse().find((s) => s.to <= from)
        ?? all[0];

    return found ? { text: sql.slice(found.from, found.to), offset: found.from } : { text: '', offset: 0 };
}

/**
 * The string index of PostgreSQL's 1-based error position, which counts
 * characters (code points) where JavaScript strings count UTF-16 units.
 */
export function positionToIndex(sql: string, position: number): number {
    return Array.from(sql).slice(0, Math.max(0, position - 1)).join('').length;
}

/** 1-based line and 0-based column of a 1-based character position, for the error caret. */
export function locate(sql: string, position: number): { line: number; column: number; text: string } {
    const before = sql.slice(0, positionToIndex(sql, position));
    const lines = before.split('\n');
    const line = lines.length;
    const column = Array.from(lines[lines.length - 1] ?? '').length;
    // Tabs as single spaces keep the caret under the right character.
    const text = (sql.split('\n')[line - 1] ?? '').replace(/\t/g, ' ');

    return { line, column, text };
}
