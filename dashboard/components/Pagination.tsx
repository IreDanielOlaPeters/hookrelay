import Link from 'next/link';
import type { PaginationMeta } from '@/lib/api';
import { withQuery } from '@/lib/params';

/** Prev/next pagination that preserves the current filters. */
export function Pagination({
  meta,
  path,
  query,
}: {
  meta: PaginationMeta;
  path: string;
  query: Record<string, string | undefined>;
}) {
  const { current_page: page, last_page: last, total, from, to } = meta;
  const href = (p: number) => withQuery(path, { ...query, page: p > 1 ? p : undefined });
  return (
    <div className="flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 px-4 py-3 text-sm text-gray-600">
      <span>
        {total === 0 ? 'No results' : `${from ?? 0}–${to ?? 0} of ${total}`}
        {last > 1 && ` · page ${page} of ${last}`}
      </span>
      <div className="flex gap-2">
        {page > 1 ? (
          <Link className="btn-secondary btn-sm" href={href(page - 1)}>
            Previous
          </Link>
        ) : (
          <span className="btn-secondary btn-sm opacity-50">Previous</span>
        )}
        {page < last ? (
          <Link className="btn-secondary btn-sm" href={href(page + 1)}>
            Next
          </Link>
        ) : (
          <span className="btn-secondary btn-sm opacity-50">Next</span>
        )}
      </div>
    </div>
  );
}
