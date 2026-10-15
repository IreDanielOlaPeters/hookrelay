import Link from 'next/link';
import { replayDeliveryAction } from '@/app/actions';
import type { Delivery } from '@/lib/api';
import { shortId } from '@/lib/format';
import { ActionButton } from './ActionButton';
import { EmptyRow } from './EmptyRow';
import { StatusBadge } from './StatusBadge';
import { Time } from './Time';

/** Deliveries list used on /deliveries, /dead-letter and the overview's recent failures. */
export function DeliveriesTable({
  deliveries,
  replay = true,
  empty = 'No deliveries.',
}: {
  deliveries: Delivery[];
  replay?: boolean;
  empty?: string;
}) {
  const cols = replay ? 8 : 7;
  return (
    <div className="table-wrap">
      <table className="table">
        <thead>
          <tr>
            <th>Updated</th>
            <th>Status</th>
            <th>Destination</th>
            <th>Event</th>
            <th>Attempts</th>
            <th>Next attempt</th>
            <th>Last error</th>
            {replay && <th className="text-right">Actions</th>}
          </tr>
        </thead>
        <tbody>
          {deliveries.length === 0 && <EmptyRow colSpan={cols}>{empty}</EmptyRow>}
          {deliveries.map((d) => (
            <tr key={d.id}>
              <td><Time iso={d.updated_at} /></td>
              <td><StatusBadge status={d.status} /></td>
              <td>
                <div className="font-medium text-gray-900">{d.destination?.name ?? '—'}</div>
                <div className="mono max-w-[18rem] truncate text-gray-500" title={d.destination?.url}>
                  {d.destination?.url}
                </div>
              </td>
              <td>
                <Link href={`/events/${d.event_id}`} className="link mono" title={d.event_id}>
                  {shortId(d.event_id)}
                </Link>
                {d.event?.received_at && (
                  <div className="text-xs text-gray-500">
                    received <Time iso={d.event.received_at} />
                  </div>
                )}
              </td>
              <td className="tabular-nums">
                {d.attempt_count}/{d.max_attempts}
              </td>
              <td><Time iso={d.next_attempt_at} /></td>
              <td>
                {d.last_error ? (
                  <span className="mono block max-w-88 truncate text-red-700" title={d.last_error}>
                    {d.last_error}
                  </span>
                ) : (
                  <span className="text-gray-400">—</span>
                )}
              </td>
              {replay && (
                <td className="text-right">
                  {d.status !== 'delivering' && (
                    <ActionButton
                      action={replayDeliveryAction}
                      fields={{ id: d.id }}
                      label="Replay"
                      pendingLabel="Replaying…"
                    />
                  )}
                </td>
              )}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
