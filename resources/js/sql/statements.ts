import type { Classification } from '../types';

/**
 * A small PostgreSQL tokenizer for the editor: it finds the statement under
 * the cursor and gives a first guess at its classification before it runs.
 *
 * UX only, like the server's Classifier (PRD S11). The server classifies again
 * and the database login is the enforcement; this only decides whether to ask
 * "are you sure?" first.
 */

interface Word {
    text: string;
    depth: number;
}

export interface Statement {
    /** Offsets into the source, without surrounding whitespace. */
    from: number;
    to: number;
    words: Word[];
}

/** Split on top-level semicolons, skipping comments, strings, quoted names and dollar quotes. */
export function statements(sql: string): Statement[] {
    const result: Statement[] = [];
    let current: Statement | null = null;
    let depth = 0;
    let i = 0;

    const touch = (from: number, to: number): Statement => {
        current ??= { from, to, words: [] };
        current.to = to;

        return current;
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
            touch(start, i).words.push({ text: sql.slice(start, i).toUpperCase(), depth });
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

const DDL = ['CREATE', 'COMMENT', 'GRANT', 'REVOKE', 'REINDEX', 'CLUSTER', 'REFRESH', 'VACUUM', 'ANALYZE', 'ANALYSE', 'SECURITY', 'IMPORT', 'REASSIGN', 'DO'];

const SEVERITY: Classification[] = ['read', 'dml', 'ddl', 'unknown', 'destructive'];

function worst(a: Classification, b: Classification): Classification {
    return SEVERITY.indexOf(a) >= SEVERITY.indexOf(b) ? a : b;
}

function classifyWords(words: Word[]): Classification {
    const first = words[0]?.text;
    const base = words[0]?.depth ?? 0;
    const top = (word: string) => words.some((w) => w.text === word && w.depth === base);
    const any = (...list: string[]) => words.some((w) => list.includes(w.text));

    switch (first) {
        case undefined:
            return 'unknown';
        case 'SELECT':
            if (top('INTO')) {
                return 'ddl';
            }
            return words.some((w, i) => w.text === 'FOR' && ['UPDATE', 'SHARE', 'NO', 'KEY'].includes(words[i + 1]?.text ?? '')) ? 'dml' : 'read';
        case 'VALUES':
        case 'TABLE':
        case 'SHOW':
            return 'read';
        case 'EXPLAIN':
            return words.slice(1, 4).some((w) => w.text === 'ANALYZE' || w.text === 'ANALYSE')
                ? classifyWords(words.slice(1).filter((w) => !['ANALYZE', 'ANALYSE', 'VERBOSE', 'BUFFERS'].includes(w.text)))
                : 'read';
        case 'WITH': {
            // The most severe of the CTE bodies and the main statement.
            let result: Classification = any('INSERT', 'MERGE') ? 'dml' : 'read';
            words.forEach((w, i) => {
                if (w.text === 'UPDATE' || w.text === 'DELETE') {
                    const rest = words.slice(i + 1);
                    const hasWhere = rest.some((r) => r.text === 'WHERE' && r.depth === w.depth);
                    result = worst(result, hasWhere ? 'dml' : 'destructive');
                }
            });
            return result;
        }
        case 'INSERT':
        case 'MERGE':
        case 'CALL':
            return 'dml';
        case 'UPDATE':
        case 'DELETE':
            return top('WHERE') ? 'dml' : 'destructive';
        case 'DROP':
        case 'TRUNCATE':
            return 'destructive';
        case 'ALTER':
            return any('DROP') ? 'destructive' : 'ddl';
        case 'COPY':
            return top('STDOUT') ? 'read' : top('STDIN') ? 'dml' : 'ddl';
        case 'SET':
        case 'RESET':
            return any('ROLE', 'AUTHORIZATION') ? 'ddl' : 'unknown';
        default:
            return DDL.includes(first) ? 'ddl' : 'unknown';
    }
}

/** The most severe classification among the statements in `sql`. */
export function classify(sql: string): Classification {
    const all = statements(sql);

    return all.length === 0 ? 'unknown' : all.map((s) => classifyWords(s.words)).reduce(worst);
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
