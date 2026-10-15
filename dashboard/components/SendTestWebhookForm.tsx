'use client';

import { useActionState } from 'react';
import { sendTestWebhookAction } from '@/app/actions';
import { FieldError, FormMessage } from './FieldError';
import { SubmitButton } from './SubmitButton';

const SAMPLE = JSON.stringify({ type: 'order.created', data: { id: 1001, total: 42.5, currency: 'CAD' } }, null, 2);

/** Sends a webhook through the normal pipeline (ingestion -> outbox -> workers), then opens the event. */
export function SendTestWebhookForm({ sources }: { sources: { id: string; name: string }[] }) {
  const [state, action] = useActionState(sendTestWebhookAction, {});
  return (
    <form action={action} className="space-y-3 p-4">
      <FormMessage state={state} fields={['id', 'body']} />
      <div>
        <label className="label" htmlFor="test-source">Source</label>
        <select id="test-source" name="id" required className="input" defaultValue={state?.values?.id ?? sources[0]?.id ?? ''}>
          {sources.map((s) => (
            <option key={s.id} value={s.id}>{s.name}</option>
          ))}
        </select>
      </div>
      <div>
        <label className="label" htmlFor="test-body">Body</label>
        <textarea
          id="test-body"
          name="body"
          rows={6}
          className="input font-mono text-xs"
          defaultValue={state?.values?.body ?? SAMPLE}
        />
        <FieldError state={state} name="body" />
      </div>
      <SubmitButton pendingLabel="Sending…">Send test webhook</SubmitButton>
    </form>
  );
}
