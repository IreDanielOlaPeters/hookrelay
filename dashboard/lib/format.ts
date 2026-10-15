/** Formatting helpers shared by server and client components (no secrets here). */

/** 12 -> "12 ms", 1534 -> "1.53 s", 45000 -> "45 s", 125000 -> "2m 5s", 7_380_000 -> "2h 3m". */
export function formatDuration(ms: number | null | undefined): string {
  if (ms === null || ms === undefined || Number.isNaN(ms)) return '—';
  if (ms < 1000) return `${Math.round(ms)} ms`;
  const s = ms / 1000;
  if (s < 10) return `${trimZeros(s.toFixed(2))} s`;
  if (s < 60) return `${trimZeros(s.toFixed(1))} s`;
  const totalSec = Math.round(s);
  const days = Math.floor(totalSec / 86400);
  const hours = Math.floor((totalSec % 86400) / 3600);
  const minutes = Math.floor((totalSec % 3600) / 60);
  const seconds = totalSec % 60;
  if (days > 0) return hours ? `${days}d ${hours}h` : `${days}d`;
  if (hours > 0) return minutes ? `${hours}h ${minutes}m` : `${hours}h`;
  return seconds ? `${minutes}m ${seconds}s` : `${minutes}m`;
}

/** Seconds -> human duration ("1d", "15m"). */
export function formatSeconds(sec: number | null | undefined): string {
  if (sec === null || sec === undefined) return '—';
  if (sec === 0) return '0 s';
  return formatDuration(sec * 1000);
}

function trimZeros(n: string): string {
  return n.includes('.') ? n.replace(/\.?0+$/, '') : n;
}

/** "3m ago", "in 5m", "just now". */
export function relativeTime(iso: string | null | undefined, now: number = Date.now()): string {
  if (!iso) return '—';
  const t = Date.parse(iso);
  if (Number.isNaN(t)) return iso;
  const diffSec = Math.round((t - now) / 1000);
  const abs = Math.abs(diffSec);
  if (abs < 5) return 'just now';
  let value: string;
  if (abs < 60) value = `${abs}s`;
  else if (abs < 3600) value = `${Math.floor(abs / 60)}m`;
  else if (abs < 86400) value = `${Math.floor(abs / 3600)}h`;
  else if (abs < 86400 * 30) value = `${Math.floor(abs / 86400)}d`;
  else if (abs < 86400 * 365) value = `${Math.floor(abs / (86400 * 30))}mo`;
  else value = `${Math.floor(abs / (86400 * 365))}y`;
  return diffSec < 0 ? `${value} ago` : `in ${value}`;
}

/** Full, unambiguous timestamp for tooltips: "2026-10-15 12:34:56 UTC". */
export function fullTimestamp(iso: string | null | undefined): string {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toISOString().replace('T', ' ').replace(/\.\d+Z$/, ' UTC').replace(/Z$/, ' UTC');
}

export function formatBytes(n: number | null | undefined): string {
  if (n === null || n === undefined) return '—';
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${trimZeros((n / 1024).toFixed(1))} KB`;
  return `${trimZeros((n / (1024 * 1024)).toFixed(1))} MB`;
}

export function formatNumber(n: number | null | undefined): string {
  if (n === null || n === undefined) return '—';
  return new Intl.NumberFormat('en-US').format(n);
}

export function truncate(s: string | null | undefined, max = 16): string {
  if (!s) return '';
  return s.length > max ? `${s.slice(0, max - 1)}…` : s;
}

export function shortId(id: string | number | null | undefined): string {
  if (id === null || id === undefined) return '';
  const s = String(id);
  return s.length > 8 ? s.slice(0, 8) : s;
}

/** If the body parses as JSON, return it pretty-printed; otherwise null. */
export function prettyJson(body: string | null | undefined): string | null {
  if (!body) return null;
  const trimmed = body.trim();
  if (!trimmed || !'{["'.includes(trimmed[0]) && !/^(-?\d|true|false|null)/.test(trimmed)) {
    return null;
  }
  try {
    return JSON.stringify(JSON.parse(trimmed), null, 2);
  } catch {
    return null;
  }
}

/** Convert a YYYY-MM-DD date input into an inclusive UTC datetime bound for the API. */
export function dateBound(date: string | undefined, edge: 'start' | 'end'): string | undefined {
  if (!date || !/^\d{4}-\d{2}-\d{2}$/.test(date)) return undefined;
  return edge === 'start' ? `${date}T00:00:00Z` : `${date}T23:59:59Z`;
}
