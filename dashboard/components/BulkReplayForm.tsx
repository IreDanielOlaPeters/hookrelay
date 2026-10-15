'use client';

import { useFormState } from 'react-dom';
import { bulkReplayAction } from '@/app/actions';
import { FieldError, FormMessage } from './FieldError';
import { SubmitButton } from './SubmitButton';

type Option = { id: string; name: string };

/** Bulk-replays dead deliveries matching the given filters (defaults to the current page filters). */
export function BulkReplayForm({
  destinations,
  defaults,
}: {
  destinations: Option[];
  defaults: { destination_id?: string; from?: string; to?: string };
}) {
  const [state, action] = useFormState(bulkReplayAction, {});
  return (
    <form
      action={action}
      className="space-y-3 p-4"
      onSubmit={(e) => {
        if (!window.confirm('Replay every dead delivery matching these filters?')) e.preventDefault();
      }}
    >
      <FormMessage state={state} fields={['destination_id', 'from', 'to']} />
      <div className="grid gap-3 sm:grid-cols-[1fr_auto_auto_auto] sm:items-end">
        <div>
          <label className="label" htmlFor="bulk-dest">Destination</label>
          <select
            id="bulk-dest"
            name="destination_id"
            className="input"
            defaultValue={defaults.destination_id ?? ''}
          >
            <option value="">All destinations</option>
            {destinations.map((d) => (
              <option key={d.id} value={d.id}>{d.name}</option>
            ))}
          </select>
          <FieldError state={state} name="destination_id" />
        </div>
        <div>
          <label className="label" htmlFor="bulk-from">From</label>
          <input id="bulk-from" name="from" type="date" className="input" defaultValue={defaults.from} />
          <FieldError state={state} name="from" />
        </div>
        <div>
          <label className="label" htmlFor="bulk-to">To</label>
          <input id="bulk-to" name="to" type="date" className="input" defaultValue={defaults.to} />
          <FieldError state={state} name="to" />
        </div>
        <SubmitButton pendingLabel="Replaying…">Bulk replay dead</SubmitButton>
      </div>
    </form>
  );
}
