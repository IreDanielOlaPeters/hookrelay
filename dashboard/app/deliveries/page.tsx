import { ApiErrorPanel } from '@/components/ApiErrorPanel';
import { DeliveriesTable } from '@/components/DeliveriesTable';
import { FilterForm } from '@/components/FilterForm';
import { PageHeader } from '@/components/PageHeader';
import { Pagination } from '@/components/Pagination';
import { api, DELIVERY_STATUSES, load } from '@/lib/api';
import { pageParam, param, type SearchParams } from '@/lib/params';

export const dynamic = 'force-dynamic';
export const metadata = { title: 'Deliveries' };

export default async function DeliveriesPage({ searchParams }: { searchParams: SearchParams }) {
  const status = param(searchParams, 'status');
  const destinationId = param(searchParams, 'destination_id');
  const page = pageParam(searchParams);

  const [deliveries, destinations] = await Promise.all([
    load(api.listDeliveries({ status, destination_id: destinationId, page, per_page: 25 })),
    load(api.listDestinations()),
  ]);

  return (
    <>
      <PageHeader title="Deliveries" description="Every event fan-out to a destination, newest activity first." />
      {!deliveries.ok ? (
        <ApiErrorPanel error={deliveries.error} />
      ) : (
        <section className="card">
          <FilterForm action="/deliveries" resetHref="/deliveries">
            <div className="w-full sm:w-44">
              <label className="label" htmlFor="f-status">Status</label>
              <select id="f-status" name="status" className="input" defaultValue={status ?? ''}>
                <option value="">Any status</option>
                {DELIVERY_STATUSES.map((s) => (
                  <option key={s} value={s}>{s}</option>
                ))}
              </select>
            </div>
            <div className="w-full sm:w-56">
              <label className="label" htmlFor="f-dest">Destination</label>
              <select id="f-dest" name="destination_id" className="input" defaultValue={destinationId ?? ''}>
                <option value="">All destinations</option>
                {(destinations.ok ? destinations.data : []).map((d) => (
                  <option key={d.id} value={d.id}>{d.name}</option>
                ))}
              </select>
            </div>
          </FilterForm>
          <DeliveriesTable deliveries={deliveries.data.data} empty="No deliveries match." />
          <Pagination
            meta={deliveries.data.meta}
            path="/deliveries"
            query={{ status, destination_id: destinationId }}
          />
        </section>
      )}
    </>
  );
}
