import type { ReplayBatch } from '@/lib/api';
import { AutoRefresh } from './AutoRefresh';
import { Time } from './Time';

const LABEL: Record<ReplayBatch['status'], string> = {
  queued: 'Queued',
  running: 'Replaying…',
  completed: 'Completed',
  failed: 'Failed',
};

/** Progress of one bulk replay batch; keeps the page refreshing until it finishes. */
export function ReplayBatchProgress({ batch }: { batch: ReplayBatch }) {
  const active = batch.status === 'queued' || batch.status === 'running';
  const pct = Math.round((batch.status === 'completed' ? 1 : batch.progress) * 100);
  const tone = batch.status === 'failed' ? 'bg-red-500' : batch.status === 'completed' ? 'bg-green-600' : 'bg-blue-600';

  return (
    <section className="card mb-4 p-4" aria-live="polite">
      <AutoRefresh active={active} />
      <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2 text-sm">
        <span className="font-medium text-gray-900">
          Bulk replay ({batch.filters.status ?? 'dead'}): {LABEL[batch.status]}
        </span>
        <span className="tabular-nums text-gray-600">
          {batch.processed} / {batch.total} processed · {batch.replayed} replayed
        </span>
      </div>
      <div
        className="h-2 w-full overflow-hidden rounded-full bg-gray-100"
        role="progressbar"
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={pct}
        aria-label="Bulk replay progress"
      >
        <div className={`h-full ${tone} transition-[width] duration-500`} style={{ width: `${pct}%` }} />
      </div>
      <div className="mt-2 text-xs text-gray-500">
        Started <Time iso={batch.started_at ?? batch.created_at} />
        {batch.finished_at && (
          <>
            {' '}· finished <Time iso={batch.finished_at} />
          </>
        )}
        {batch.error && <span className="text-red-700"> · {batch.error}</span>}
      </div>
    </section>
  );
}
