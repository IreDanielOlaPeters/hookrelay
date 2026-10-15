'use client';

import { useActionState } from 'react';
import { createSourceAction } from '@/app/actions';
import { FieldError, FormMessage } from './FieldError';
import { SubmitButton } from './SubmitButton';
import { useResetOnSuccess } from './useResetOnSuccess';

const PROVIDERS = ['generic', 'stripe', 'github', 'shopify', 'woocommerce'];
const FIELDS = ['name', 'slug', 'provider', 'signing_secret', 'dedupe_window_seconds', 'rate_limit_rps'];

export function CreateSourceForm() {
  const [state, action] = useActionState(createSourceAction, {});
  const ref = useResetOnSuccess(state);
  return (
    <form ref={ref} action={action} className="space-y-3 p-4">
      <FormMessage state={state} fields={FIELDS} />
      <div className="grid gap-3 sm:grid-cols-2">
        <div>
          <label className="label" htmlFor="src-name">Name</label>
          <input id="src-name" name="name" required className="input" placeholder="Stripe production" defaultValue={state?.values?.name} />
          <FieldError state={state} name="name" />
        </div>
        <div>
          <label className="label" htmlFor="src-slug">Slug</label>
          <input
            id="src-slug"
            name="slug"
            defaultValue={state?.values?.slug}
            required
            pattern="[a-z0-9][a-z0-9_-]*"
            title="Lowercase letters, digits, dashes and underscores"
            className="input font-mono"
            placeholder="stripe-prod"
          />
          <FieldError state={state} name="slug" />
        </div>
        <div>
          <label className="label" htmlFor="src-provider">Provider</label>
          <select id="src-provider" name="provider" className="input" defaultValue={state?.values?.provider ?? 'generic'}>
            {PROVIDERS.map((p) => (
              <option key={p} value={p}>{p}</option>
            ))}
          </select>
          <FieldError state={state} name="provider" />
        </div>
        <div>
          <label className="label" htmlFor="src-dedupe">Dedupe window (seconds)</label>
          <input
            id="src-dedupe"
            name="dedupe_window_seconds"
            defaultValue={state?.values?.dedupe_window_seconds}
            type="number"
            min={0}
            className="input"
            placeholder="86400"
          />
          <FieldError state={state} name="dedupe_window_seconds" />
        </div>
        <div>
          <label className="label" htmlFor="src-rps">Ingest rate limit (req/s, optional)</label>
          <input
            id="src-rps"
            name="rate_limit_rps"
            defaultValue={state?.values?.rate_limit_rps}
            type="number"
            min={1}
            className="input"
            placeholder="unlimited"
          />
          <FieldError state={state} name="rate_limit_rps" />
        </div>
        <div>
          <label className="label" htmlFor="src-secret">Signing secret (optional)</label>
          <input
            id="src-secret"
            name="signing_secret"
            type="password"
            autoComplete="off"
            className="input font-mono"
            placeholder="whsec_…"
          />
          <FieldError state={state} name="signing_secret" />
        </div>
      </div>
      <SubmitButton pendingLabel="Creating…">Create source</SubmitButton>
    </form>
  );
}
