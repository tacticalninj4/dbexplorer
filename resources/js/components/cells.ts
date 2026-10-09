// Parsing helpers for type-aware grid cells. Values arrive as the API sends
// them: json/jsonb and arrays as PostgreSQL text, bytea as `\x…` hex.

/** Elements of a one-dimensional PostgreSQL array literal, or null if it isn't one. */
export function parseArray(literal: string): (string | null)[] | null {
    // Skip an explicit lower bound, e.g. `[0:2]={1,2,3}`.
    const body = literal.replace(/^\[[^\]]*\]=/, '');

    if (!body.startsWith('{') || !body.endsWith('}')) {
        return null;
    }

    const inner = body.slice(1, -1);
    const items: (string | null)[] = [];

    if (inner === '') {
        return items;
    }

    let i = 0;

    while (i <= inner.length) {
        if (inner[i] === '{') {
            return null; // Multidimensional: shown as text.
        }

        if (inner[i] === '"') {
            let value = '';
            i++;
            while (i < inner.length && inner[i] !== '"') {
                if (inner[i] === '\\') {
                    i++;
                }
                value += inner[i] ?? '';
                i++;
            }
            items.push(value);
            i++; // closing quote
        } else {
            const end = inner.indexOf(',', i);
            const raw = inner.slice(i, end === -1 ? inner.length : end).trim();
            items.push(raw === 'NULL' ? null : raw);
            i = end === -1 ? inner.length : end;
        }

        if (inner[i] !== ',') {
            break;
        }
        i++;
    }

    return items;
}

export function isJsonType(type: string): boolean {
    return type === 'json' || type === 'jsonb';
}

export function isArrayType(type: string): boolean {
    return type.startsWith('_');
}

export function isNumericType(type: string): boolean {
    return ['int2', 'int4', 'int8', 'float4', 'float8', 'numeric', 'money', 'oid'].includes(type);
}

/** Byte length and a short spaced hex preview of a `\x…` bytea value. */
export function byteaPreview(value: string, bytes = 16): { size: number; hex: string; more: boolean } {
    const hex = value.startsWith('\\x') ? value.slice(2) : value;
    const size = Math.floor(hex.length / 2);
    const shown = hex.slice(0, bytes * 2).match(/../g) ?? [];

    return { size, hex: shown.join(' '), more: size > bytes };
}
