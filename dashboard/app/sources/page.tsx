import {
  deleteConnectionAction,
  deleteSourceAction,
  setConnectionEnabledAction,
} from '@/app/actions';
import { ActionButton } from '@/components/ActionButton';
import { ApiErrorPanel } from '@/components/ApiErrorPanel';
import { Badge } from '@/components/Badge';
import { CreateConnectionForm } from '@/components/CreateConnectionForm';
import { CreateSourceForm } from '@/components/CreateSourceForm';
import { EmptyRow } from '@/components/EmptyRow';
import { PageHeader } from '@/components/PageHeader';
import { Time } from '@/components/Time';
import { api, load } from '@/lib/api';
import { formatSeconds } from '@/lib/format';

export const dynamic = 'force-dynamic';
export const metadata = { title: 'Sources' };

export default async function SourcesPage() {
  const [sources, destinations, connections] = await Promise.all([
    load(api.listSources()),
    load(api.listDestinations()),
    load(api.listConnections()),
  ]);

  if (!sources.ok) {
    return (
      <>
        <PageHeader title="Sources" />
        <ApiErrorPanel error={sources.error} />
      </>
    );
  }

  return (
    <>
      <PageHeader
        title="Sources"
        description="Inbound endpoints. Point your provider's webhook at the ingest URL."
      />

      <section className="card">
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Provider</th>
                <th>Ingest URL</th>
                <th>Signing secret</th>
                <th>Dedupe window</th>
                <th>Created</th>
                <th className="text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {sources.data.length === 0 && <EmptyRow colSpan={7}>No sources yet.</EmptyRow>}
              {sources.data.map((s) => (
                <tr key={s.id}>
                  <td>
                    <div className="font-medium text-gray-900">{s.name}</div>
                    <div className="mono text-gray-500">{s.slug}</div>
                  </td>
                  <td><Badge>{s.provider}</Badge></td>
                  <td>
                    <code className="mono select-all rounded bg-gray-50 px-1.5 py-0.5 text-gray-800">
                      {s.ingest_url}
                    </code>
                  </td>
                  <td>
                    {s.has_signing_secret ? <Badge tone="green">set</Badge> : <Badge>none</Badge>}
                  </td>
                  <td className="tabular-nums">{formatSeconds(s.dedupe_window_seconds)}</td>
                  <td><Time iso={s.created_at} /></td>
                  <td className="text-right">
                    <ActionButton
                      action={deleteSourceAction}
                      fields={{ id: s.id }}
                      label="Delete"
                      pendingLabel="Deleting…"
                      className="btn-danger btn-sm"
                      confirm={`Delete source “${s.name}”? Its ingest URL will stop accepting webhooks.`}
                    />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <section className="card mt-6">
        <div className="card-header">
          <h2 className="card-title">New source</h2>
        </div>
        <CreateSourceForm />
      </section>

      <h2 className="mb-1 mt-10 text-lg font-semibold text-gray-900">Connections</h2>
      <p className="mb-4 text-sm text-gray-500">
        Each event received on a source is delivered to every enabled destination connected to it.
      </p>

      {!connections.ok ? (
        <ApiErrorPanel error={connections.error} />
      ) : (
        <section className="card">
          <div className="table-wrap">
            <table className="table">
              <thead>
                <tr>
                  <th>Source</th>
                  <th />
                  <th>Destination</th>
                  <th>Status</th>
                  <th>Created</th>
                  <th className="text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                {connections.data.length === 0 && <EmptyRow colSpan={6}>No connections yet.</EmptyRow>}
                {connections.data.map((c) => (
                  <tr key={c.id}>
                    <td>
                      <span className="font-medium text-gray-900">{c.source?.name}</span>{' '}
                      <span className="mono text-gray-500">{c.source?.slug}</span>
                    </td>
                    <td className="text-gray-400" aria-hidden>→</td>
                    <td className="font-medium text-gray-900">{c.destination?.name}</td>
                    <td>
                      {c.enabled ? <Badge tone="green">enabled</Badge> : <Badge>disabled</Badge>}
                    </td>
                    <td><Time iso={c.created_at} /></td>
                    <td className="text-right">
                      <div className="inline-flex gap-2">
                        <ActionButton
                          action={setConnectionEnabledAction}
                          fields={{ id: c.id, enabled: !c.enabled }}
                          label={c.enabled ? 'Disable' : 'Enable'}
                        />
                        <ActionButton
                          action={deleteConnectionAction}
                          fields={{ id: c.id }}
                          label="Delete"
                          pendingLabel="Deleting…"
                          className="btn-danger btn-sm"
                          confirm={`Disconnect ${c.source?.name} from ${c.destination?.name}?`}
                        />
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="border-t border-gray-200">
            <h3 className="px-4 pt-4 text-sm font-semibold text-gray-900">Connect a source to a destination</h3>
            {destinations.ok ? (
              <CreateConnectionForm
                sources={sources.data.map((s) => ({ id: s.id, name: `${s.name} (${s.slug})` }))}
                destinations={destinations.data.map((d) => ({ id: d.id, name: d.name }))}
              />
            ) : (
              <div className="p-4">
                <ApiErrorPanel error={destinations.error} />
              </div>
            )}
          </div>
        </section>
      )}
    </>
  );
}
