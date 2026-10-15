import { fullTimestamp, relativeTime } from '@/lib/format';

/** Relative time ("3m ago") with the full UTC timestamp in the tooltip. */
export function Time({ iso, className }: { iso: string | null | undefined; className?: string }) {
  if (!iso) return <span className="text-gray-400">—</span>;
  return (
    <time dateTime={iso} title={fullTimestamp(iso)} className={className}>
      {relativeTime(iso)}
    </time>
  );
}
