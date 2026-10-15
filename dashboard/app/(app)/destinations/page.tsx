import { deleteDestinationAction, setDestinationEnabledAction } from '@/app/actions';
import { ActionButton } from '@/components/ActionButton';
import { ApiErrorPanel } from '@/components/ApiErrorPanel';
import { Badge } from '@/components/Badge';
import { CircuitBadge } from '@/components/CircuitBadge';
import { CreateDestinationForm } from '@/components/CreateDestinationForm';
import { EmptyRow } from '@/components/EmptyRow';
import { PageHeader } from '@/components/PageHeader';
import { api, load } from '@/lib/api';
import { formatDuration } from '@/lib/format';

export const dynamic = 'force-dynamic';
export const metadata = { title: 'Destinations' };

export default async function DestinationsPage() {
  const destinations = await load(api.listDestinations());

  return (
    <>
      <PageHeader title="Destinations" description="Where HookRelay forwards events." />
      {!destinations.ok ? (
        <ApiErrorPanel error={destinations.error} />
      ) : (
        <section className="card">
          <div className="table-wrap">
            <table className="table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Status</th>
                  <th>Circuit</th>
                  <th>Timeout</th>
                  <th>Rate limit</th>
                  <th>Max attempts</th>
                  <th>Auth</th>
                  <th className="text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                {destinations.data.length === 0 && <EmptyRow colSpan={8}>No destinations yet.</EmptyRow>}
                {destinations.data.map((d) => (
                  <tr key={d.id}>
                    <td>
                      <div className="font-medium text-gray-900">{d.name}</div>
                      <div className="mono max-w-[20rem] truncate text-gray-500" title={d.url}>
                        {d.url}
                      </div>
                    </td>
                    <td>{d.enabled ? <Badge tone="green">enabled</Badge> : <Badge>disabled</Badge>}</td>
                    <td><CircuitBadge circuit={d.circuit} /></td>
                    <td className="tabular-nums">{formatDuration(d.timeout_ms)}</td>
                    <td className="tabular-nums">
                      {d.rate_limit_rps ? `${d.rate_limit_rps}/s` : <span className="text-gray-400">none</span>}
                    </td>
                    <td className="tabular-nums">{d.max_attempts}</td>
                    <td>{d.has_auth_header ? <Badge tone="blue">header</Badge> : <Badge>none</Badge>}</td>
                    <td className="text-right">
                      <div className="inline-flex gap-2">
                        <ActionButton
                          action={setDestinationEnabledAction}
                          fields={{ id: d.id, enabled: !d.enabled }}
                          label={d.enabled ? 'Disable' : 'Enable'}
                        />
                        <ActionButton
                          action={deleteDestinationAction}
                          fields={{ id: d.id }}
                          label="Delete"
                          pendingLabel="Deleting…"
                          className="btn-danger btn-sm"
                          confirm={`Delete destination “${d.name}”?`}
                        />
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      <section className="card mt-6">
        <div className="card-header">
          <h2 className="card-title">New destination</h2>
        </div>
        <CreateDestinationForm />
      </section>
    </>
  );
}
