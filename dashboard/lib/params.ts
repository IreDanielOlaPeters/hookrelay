/** Helpers for reading Next.js `searchParams` in pages. */
export type SearchParams = Record<string, string | string[] | undefined>;

export function param(sp: SearchParams, key: string): string | undefined {
  const v = sp[key];
  const s = Array.isArray(v) ? v[0] : v;
  return s && s.trim() !== '' ? s.trim() : undefined;
}

export function pageParam(sp: SearchParams): number {
  const n = Number.parseInt(param(sp, 'page') ?? '1', 10);
  return Number.isFinite(n) && n > 0 ? n : 1;
}

/** Build `path?query` keeping only non-empty values. */
export function withQuery(path: string, query: Record<string, string | number | undefined>): string {
  const qs = new URLSearchParams();
  for (const [k, v] of Object.entries(query)) {
    if (v !== undefined && v !== '') qs.set(k, String(v));
  }
  const s = qs.toString();
  return s ? `${path}?${s}` : path;
}
