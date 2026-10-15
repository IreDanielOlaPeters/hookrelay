import { ApiErrorPanel } from '@/components/ApiErrorPanel';
import { BulkReplayForm } from '@/components/BulkReplayForm';
import { DeliveriesTable } from '@/components/DeliveriesTable';
import { FilterForm } from '@/components/FilterForm';
import { Notice } from '@/components/Notice';
import { PageHeader } from '@/components/PageHeader';
import { Pagination } from '@/components/Pagination';
import { ReplayBatchProgress } from '@/components/ReplayBatchProgress';
import { Time } from '@/components/Time';
import { api, load } from '@/lib/api';
import { dateBound } from '@/lib/format';
import { pageParam, param, type SearchParams } from '@/lib/params';

export const dynamic = 'force-dynamic';
export const metadata = { title: 'Dead letter' };

export default async function DeadLetterPage(props: { searchParams: Promise<SearchParams> }) {
  const searchParams = await props.searchParams;
  const destinationId = param(searchParams, 'destination_id');
  const from = param(searchParams, 'from');
  const to = param(searchParams, 'to');
  const status = param(searchParams, 'status') === 'failed' ? 'failed' : 'dead';
  const batchId = param(searchParams, 'batch');
  const page = pageParam(searchParams);

  const [deliveries, destinations, batch, batches] = await Promise.all([
    load(
      api.listDeliveries({
        status,
        destination_id: destinationId,
        from: dateBound(from, 'start'),
        to: dateBound(to, 'end'),
        page,
        per_page: 25,
      }),
    ),
    load(api.listDestinations()),
    batchId ? load(api.getReplayBatch(batchId)) : Promise.resolve(null),
    load(api.listReplayBatches()),
  ]);
  const destinationOptions = (destinations.ok ? destinations.data : []).map((d) => ({
    id: d.id,
    name: d.name,
  }));

  return (
    <>
      <PageHeader
        title="Dead letter"
        description="Dead: exhausted their retries. Failed: permanent 4xx or blocked target, never retried. Replay them individually or in bulk."
      />
      <nav className="mb-4 inline-flex rounded-md border border-gray-200 bg-white p-0.5 text-sm" aria-label="Status">
        {(['dead', 'failed'] as const).map((s) => (
          <a
            key={s}
            href={s === 'dead' ? '/dead-letter' : '/dead-letter?status=failed'}
            aria-current={status === s ? 'page' : undefined}
            className={`rounded-sm px-3 py-1 ${status === s ? 'bg-gray-900 text-white' : 'text-gray-600 hover:bg-gray-100'}`}
          >
            {s === 'dead' ? 'Dead' : 'Failed (4xx)'}
          </a>
        ))}
      </nav>
      {batch && batch.ok && <ReplayBatchProgress batch={batch.data} />}
      {batch && !batch.ok && <Notice tone="error">Couldn&apos;t load that replay batch.</Notice>}
      {!deliveries.ok ? (
        <ApiErrorPanel error={deliveries.error} />
      ) : (
        <>
          <section className="card">
            <FilterForm action="/dead-letter" resetHref={status === 'dead' ? '/dead-letter' : '/dead-letter?status=failed'}>
              {status === 'failed' && <input type="hidden" name="status" value="failed" />}
              <div className="w-full sm:w-56">
                <label className="label" htmlFor="f-dest">Destination</label>
                <select id="f-dest" name="destination_id" className="input" defaultValue={destinationId ?? ''}>
                  <option value="">All destinations</option>
                  {destinationOptions.map((d) => (
                    <option key={d.id} value={d.id}>{d.name}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="label" htmlFor="f-from">From</label>
                <input id="f-from" name="from" type="date" className="input" defaultValue={from} />
              </div>
              <div>
                <label className="label" htmlFor="f-to">To</label>
                <input id="f-to" name="to" type="date" className="input" defaultValue={to} />
              </div>
            </FilterForm>
            <DeliveriesTable
              deliveries={deliveries.data.data}
              empty={status === 'dead' ? 'The dead-letter queue is empty.' : 'No failed deliveries.'}
            />
            <Pagination
              meta={deliveries.data.meta}
              path="/dead-letter"
              query={{ destination_id: destinationId, from, to, status: status === 'dead' ? undefined : status }}
            />
          </section>

          <section className="card mt-6">
            <div className="card-header">
              <h2 className="card-title">Bulk replay</h2>
              <span className="text-xs text-gray-500">
                Queues a background job that re-queues every {status} delivery matching the filters below.
              </span>
            </div>
            <BulkReplayForm
              key={`${status}|${destinationId ?? ''}|${from ?? ''}|${to ?? ''}`}
              destinations={destinationOptions}
              defaults={{ destination_id: destinationId, from, to, status }}
            />
          </section>

          {batches.ok && batches.data.length > 0 && (
            <section className="card mt-6">
              <div className="card-header">
                <h2 className="card-title">Recent bulk replays</h2>
              </div>
              <div className="table-wrap">
                <table className="table">
                  <thead>
                    <tr>
                      <th>Started</th>
                      <th>Status filter</th>
                      <th>State</th>
                      <th className="text-right">Replayed</th>
                    </tr>
                  </thead>
                  <tbody>
                    {batches.data.slice(0, 5).map((b) => (
                      <tr key={b.id}>
                        <td>
                          <a className="link" href={`/dead-letter?batch=${b.id}`}>
                            <Time iso={b.created_at} />
                          </a>
                        </td>
                        <td>{b.filters.status ?? 'dead'}</td>
                        <td>{b.status}</td>
                        <td className="text-right tabular-nums">
                          {b.replayed} / {b.total}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
          )}
        </>
      )}
    </>
  );
}
