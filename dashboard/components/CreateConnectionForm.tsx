'use client';

import { useActionState } from 'react';
import { createConnectionAction } from '@/app/actions';
import { FieldError, FormMessage } from './FieldError';
import { SubmitButton } from './SubmitButton';

type Option = { id: string; name: string };

export function CreateConnectionForm({
  sources,
  destinations,
}: {
  sources: Option[];
  destinations: Option[];
}) {
  const [state, action] = useActionState(createConnectionAction, {});
  const disabled = sources.length === 0 || destinations.length === 0;
  return (
    <form action={action} className="space-y-3 p-4">
      <FormMessage state={state} fields={['source_id', 'destination_id', 'enabled']} />
      {disabled && (
        <p className="text-sm text-gray-500">Create at least one source and one destination first.</p>
      )}
      <div className="grid gap-3 sm:grid-cols-[1fr_1fr_auto_auto] sm:items-end">
        <div>
          <label className="label" htmlFor="con-source">Source</label>
          <select id="con-source" name="source_id" required className="input" defaultValue={state?.values?.source_id ?? ''}>
            <option value="" disabled>Select a source…</option>
            {sources.map((s) => (
              <option key={s.id} value={s.id}>{s.name}</option>
            ))}
          </select>
          <FieldError state={state} name="source_id" />
        </div>
        <div>
          <label className="label" htmlFor="con-dest">Destination</label>
          <select id="con-dest" name="destination_id" required className="input" defaultValue={state?.values?.destination_id ?? ''}>
            <option value="" disabled>Select a destination…</option>
            {destinations.map((d) => (
              <option key={d.id} value={d.id}>{d.name}</option>
            ))}
          </select>
          <FieldError state={state} name="destination_id" />
        </div>
        <label className="inline-flex items-center gap-2 pb-1.5 text-sm text-gray-700">
          <input name="enabled" type="checkbox" defaultChecked={state?.values ? state.values.enabled === 'on' : true} className="h-4 w-4 rounded-sm border-gray-300" />
          Enabled
        </label>
        <div>
          {disabled ? (
            <button type="button" className="btn-primary" disabled>Connect</button>
          ) : (
            <SubmitButton pendingLabel="Connecting…">Connect</SubmitButton>
          )}
        </div>
      </div>
    </form>
  );
}
