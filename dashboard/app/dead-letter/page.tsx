import { ApiErrorPanel } from '@/components/ApiErrorPanel';
import { BulkReplayForm } from '@/components/BulkReplayForm';
import { DeliveriesTable } from '@/components/DeliveriesTable';
import { FilterForm } from '@/components/FilterForm';
import { Notice } from '@/components/Notice';
import { PageHeader } from '@/components/PageHeader';
import { Pagination } from '@/components/Pagination';
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
  const replayed = param(searchParams, 'replayed');
  const page = pageParam(searchParams);

  const [deliveries, destinations] = await Promise.all([
    load(
      api.listDeliveries({
        status: 'dead',
        destination_id: destinationId,
        from: dateBound(from, 'start'),
        to: dateBound(to, 'end'),
        page,
        per_page: 25,
      }),
    ),
    load(api.listDestinations()),
  ]);
  const destinationOptions = (destinations.ok ? destinations.data : []).map((d) => ({
    id: d.id,
    name: d.name,
  }));
  const replayedCount = replayed !== undefined ? Number.parseInt(replayed, 10) : NaN;

  return (
    <>
      <PageHeader
        title="Dead letter"
        description="Deliveries that exhausted their attempts. Replay them individually or in bulk."
      />
      {Number.isFinite(replayedCount) && (
        <Notice tone="success">
          Replayed {replayedCount} dead {replayedCount === 1 ? 'delivery' : 'deliveries'}.
        </Notice>
      )}
      {!deliveries.ok ? (
        <ApiErrorPanel error={deliveries.error} />
      ) : (
        <>
          <section className="card">
            <FilterForm action="/dead-letter" resetHref="/dead-letter">
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
            <DeliveriesTable deliveries={deliveries.data.data} empty="The dead-letter queue is empty." />
            <Pagination
              meta={deliveries.data.meta}
              path="/dead-letter"
              query={{ destination_id: destinationId, from, to }}
            />
          </section>

          <section className="card mt-6">
            <div className="card-header">
              <h2 className="card-title">Bulk replay</h2>
              <span className="text-xs text-gray-500">
                Re-queues every dead delivery matching the filters below.
              </span>
            </div>
            <BulkReplayForm
              key={`${destinationId ?? ''}|${from ?? ''}|${to ?? ''}`}
              destinations={destinationOptions}
              defaults={{ destination_id: destinationId, from, to }}
            />
          </section>
        </>
      )}
    </>
  );
}
