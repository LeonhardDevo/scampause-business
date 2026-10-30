const INJECTION_PREFIX = /^[=+\-@\t\r]/;

export function csvCell(value: unknown): string {
  let text: string;
  if (value === null || value === undefined) text = '';
  else if (typeof value === 'string') text = value;
  else if (typeof value === 'number' || typeof value === 'boolean' || typeof value === 'bigint') text = String(value);
  else text = JSON.stringify(value);
  if (INJECTION_PREFIX.test(text)) text = `'${text}`;
  return `"${text.replace(/"/g, '""')}"`;
}

export function csvRow(values: readonly unknown[]): string {
  return values.map(csvCell).join(',');
}

export function toCsv(header: readonly string[], rows: ReadonlyArray<readonly unknown[]>): Buffer {
  const lines = [csvRow(header), ...rows.map(csvRow)];
  return Buffer.concat([Buffer.from([0xef, 0xbb, 0xbf]), Buffer.from(`${lines.join('\r\n')}\r\n`, 'utf8')]);
}

export function toNdjson(entries: ReadonlyArray<Record<string, unknown>>): Buffer {
  return Buffer.from(entries.map((entry) => JSON.stringify(entry)).join('\n') + (entries.length > 0 ? '\n' : ''), 'utf8');
}
