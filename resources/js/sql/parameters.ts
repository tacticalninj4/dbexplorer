/**
 * The `:name` placeholders of a saved query, in order of first use, found
 * the way the server's Lexer finds them: never inside strings, comments,
 * quoted identifiers or dollar quotes, and `::` is a cast, not a
 * placeholder. Only a hint for the parameter editor; the server binds.
 */
export function namedParameters(sql: string): string[] {
    const found: string[] = [];
    const identStart = /[A-Za-z_\u0080-￿]/;
    const identChar = /[A-Za-z0-9_$\u0080-￿]/;
    let i = 0;

    const skipQuoted = (from: number, quote: string, backslash: boolean): number => {
        let j = from;
        while (j < sql.length) {
            if (backslash && sql[j] === '\\') {
                j += 2;
            } else if (sql[j] === quote && sql[j + 1] === quote) {
                j += 2;
            } else if (sql[j] === quote) {
                return j + 1;
            } else {
                j++;
            }
        }

        return sql.length;
    };

    while (i < sql.length) {
        const ch = sql[i] as string;
        const next = sql[i + 1] ?? '';

        if (ch === '-' && next === '-') {
            const end = sql.indexOf('\n', i);
            i = end === -1 ? sql.length : end;
        } else if (ch === '/' && next === '*') {
            let depth = 1;
            i += 2;
            while (i < sql.length && depth > 0) {
                if (sql.startsWith('/*', i)) {
                    depth++;
                    i += 2;
                } else if (sql.startsWith('*/', i)) {
                    depth--;
                    i += 2;
                } else {
                    i++;
                }
            }
        } else if (ch === "'" || ch === '"') {
            i = skipQuoted(i + 1, ch, false);
        } else if ((ch === 'E' || ch === 'e') && next === "'") {
            i = skipQuoted(i + 2, "'", true);
        } else if (identStart.test(ch)) {
            while (i < sql.length && identChar.test(sql[i] as string)) {
                i++;
            }
        } else if (ch === '$' && /^\$([A-Za-z_][A-Za-z0-9_]*)?\$/.test(sql.slice(i, i + 65))) {
            const tag = (/^\$([A-Za-z_][A-Za-z0-9_]*)?\$/.exec(sql.slice(i, i + 65)) as RegExpExecArray)[0];
            const end = sql.indexOf(tag, i + tag.length);
            i = end === -1 ? sql.length : end + tag.length;
        } else if (ch === ':' && next === ':') {
            i += 2;
        } else if (ch === ':' && identStart.test(next)) {
            let j = i + 2;
            while (j < sql.length && identChar.test(sql[j] as string)) {
                j++;
            }
            const name = sql.slice(i + 1, j);
            if (!found.includes(name)) {
                found.push(name);
            }
            i = j;
        } else {
            i++;
        }
    }

    return found;
}
