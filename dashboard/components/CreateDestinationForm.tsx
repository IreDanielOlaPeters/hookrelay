'use client';

import { useFormState } from 'react-dom';
import { createDestinationAction } from '@/app/actions';
import { FieldError, FormMessage } from './FieldError';
import { SubmitButton } from './SubmitButton';
import { useResetOnSuccess } from './useResetOnSuccess';

const FIELDS = [
  'name',
  'url',
  'auth_header',
  'timeout_ms',
  'rate_limit_rps',
  'max_attempts',
  'enabled',
];

export function CreateDestinationForm() {
  const [state, action] = useFormState(createDestinationAction, {});
  const ref = useResetOnSuccess(state);
  return (
    <form ref={ref} action={action} className="space-y-3 p-4">
      <FormMessage state={state} fields={FIELDS} />
      <div className="grid gap-3 sm:grid-cols-2">
        <div>
          <label className="label" htmlFor="dst-name">Name</label>
          <input id="dst-name" name="name" required className="input" placeholder="Billing service" />
          <FieldError state={state} name="name" />
        </div>
        <div>
          <label className="label" htmlFor="dst-url">URL</label>
          <input
            id="dst-url"
            name="url"
            type="url"
            required
            className="input font-mono"
            placeholder="https://example.com/webhooks"
          />
          <FieldError state={state} name="url" />
        </div>
        <div className="sm:col-span-2">
          <label className="label" htmlFor="dst-auth">Auth header (optional)</label>
          <input
            id="dst-auth"
            name="auth_header"
            type="password"
            autoComplete="off"
            className="input font-mono"
            placeholder="Bearer abc123"
          />
          <FieldError state={state} name="auth_header" />
        </div>
        <div>
          <label className="label" htmlFor="dst-timeout">Timeout (ms)</label>
          <input id="dst-timeout" name="timeout_ms" type="number" min={1} className="input" placeholder="10000" />
          <FieldError state={state} name="timeout_ms" />
        </div>
        <div>
          <label className="label" htmlFor="dst-rps">Rate limit (req/s, optional)</label>
          <input id="dst-rps" name="rate_limit_rps" type="number" min={1} className="input" placeholder="unlimited" />
          <FieldError state={state} name="rate_limit_rps" />
        </div>
        <div>
          <label className="label" htmlFor="dst-max">Max attempts</label>
          <input id="dst-max" name="max_attempts" type="number" min={1} className="input" placeholder="6" />
          <FieldError state={state} name="max_attempts" />
        </div>
        <div className="flex items-end">
          <label className="inline-flex items-center gap-2 text-sm text-gray-700">
            <input name="enabled" type="checkbox" defaultChecked className="h-4 w-4 rounded border-gray-300" />
            Enabled
          </label>
          <FieldError state={state} name="enabled" />
        </div>
      </div>
      <SubmitButton pendingLabel="Creating…">Create destination</SubmitButton>
    </form>
  );
}
