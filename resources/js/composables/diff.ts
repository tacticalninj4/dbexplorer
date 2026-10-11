export interface DiffLine {
    kind: 'same' | 'removed' | 'added';
    text: string;
}

/**
 * A line diff (longest common subsequence) of two texts, for showing what
 * re-approving a schedule would change. Rendered as text, never HTML.
 */
export function lineDiff(before: string, after: string): DiffLine[] {
    const a = before.split('\n');
    const b = after.split('\n');
    // lengths[i][j]: LCS length of a[i..] and b[j..]
    const lengths: number[][] = Array.from({ length: a.length + 1 }, () => new Array<number>(b.length + 1).fill(0));

    for (let i = a.length - 1; i >= 0; i--) {
        for (let j = b.length - 1; j >= 0; j--) {
            const row = lengths[i] as number[];
            row[j] = a[i] === b[j] ? (lengths[i + 1] as number[])[j + 1] as number + 1 : Math.max((lengths[i + 1] as number[])[j] as number, row[j + 1] as number);
        }
    }

    const lines: DiffLine[] = [];
    let i = 0;
    let j = 0;
    while (i < a.length && j < b.length) {
        if (a[i] === b[j]) {
            lines.push({ kind: 'same', text: a[i] as string });
            i++;
            j++;
        } else if (((lengths[i + 1] as number[])[j] as number) >= ((lengths[i] as number[])[j + 1] as number)) {
            lines.push({ kind: 'removed', text: a[i++] as string });
        } else {
            lines.push({ kind: 'added', text: b[j++] as string });
        }
    }
    while (i < a.length) {
        lines.push({ kind: 'removed', text: a[i++] as string });
    }
    while (j < b.length) {
        lines.push({ kind: 'added', text: b[j++] as string });
    }

    return lines;
}
