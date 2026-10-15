import type { Attempt } from '@/lib/api';
import { formatDuration, fullTimestamp, prettyJson } from '@/lib/format';
import { Time } from './Time';

function outcome(a: Attempt): { label: string; tone: string; dot: string } {
  if (a.error) return { label: a.error, tone: 'text-red-700', dot: 'bg-red-500' };
  if (a.response_status === null) return { label: 'no response', tone: 'text-gray-600', dot: 'bg-gray-400' };
  if (a.response_status >= 200 && a.response_status < 300) {
    return { label: `HTTP ${a.response_status}`, tone: 'text-green-700', dot: 'bg-green-500' };
  }
  return { label: `HTTP ${a.response_status}`, tone: 'text-orange-700', dot: 'bg-orange-500' };
}

/** Vertical list of delivery attempts, newest last, with collapsible response bodies. */
export function AttemptTimeline({ attempts }: { attempts: Attempt[] }) {
  if (attempts.length === 0) {
    return <p className="text-sm text-gray-400">No attempts yet.</p>;
  }
  const sorted = [...attempts].sort(
    (a, b) => Date.parse(a.started_at) - Date.parse(b.started_at) || a.id - b.id,
  );
  return (
    <ol className="relative ml-1.5 border-l border-gray-200">
      {sorted.map((a, i) => {
        const o = outcome(a);
        const body = a.response_body ?? '';
        const pretty = prettyJson(body);
        return (
          <li key={a.id} className="mb-3 ml-4 last:mb-0">
            <span className={`absolute left-[-5px] mt-1.5 h-2.5 w-2.5 rounded-full ring-2 ring-white ${o.dot}`} />
            <div className="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 text-sm">
              <span className="text-xs text-gray-500">#{i + 1}</span>
              <Time iso={a.started_at} className="text-gray-700" />
              <span className="mono hidden text-gray-400 sm:inline">{fullTimestamp(a.started_at)}</span>
              <span className={`mono font-medium ${o.tone} break-all`}>{o.label}</span>
              <span className="text-xs tabular-nums text-gray-500">{formatDuration(a.duration_ms)}</span>
            </div>
            {body !== '' && (
              <details className="mt-1">
                <summary className="cursor-pointer select-none text-xs text-gray-500 hover:text-gray-800">
                  Response body ({body.length} chars)
                </summary>
                <pre className="mt-1 max-h-64 overflow-auto rounded-md bg-gray-50 p-2 font-mono text-xs text-gray-800">
                  {pretty ?? body}
                </pre>
              </details>
            )}
          </li>
        );
      })}
    </ol>
  );
}
