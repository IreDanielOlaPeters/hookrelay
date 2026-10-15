'use client';

import { useFormState } from 'react-dom';
import type { FormState } from '@/lib/form-state';
import { SubmitButton } from './SubmitButton';

type Action = (prev: FormState, formData: FormData) => Promise<FormState>;

/**
 * A single-button form bound to a server action (replay, delete, toggle...).
 * Errors returned by the action are shown inline next to the button.
 */
export function ActionButton({
  action,
  fields,
  label,
  pendingLabel,
  confirm,
  className = 'btn-secondary btn-sm',
}: {
  action: Action;
  fields: Record<string, string | number | boolean>;
  label: string;
  pendingLabel?: string;
  confirm?: string;
  className?: string;
}) {
  const [state, formAction] = useFormState(action, {});
  return (
    <form
      action={formAction}
      className="inline-flex flex-col items-start gap-1"
      onSubmit={(e) => {
        if (confirm && !window.confirm(confirm)) e.preventDefault();
      }}
    >
      {Object.entries(fields).map(([k, v]) => (
        <input key={k} type="hidden" name={k} value={String(v)} />
      ))}
      <SubmitButton className={className} pendingLabel={pendingLabel ?? `${label}…`}>
        {label}
      </SubmitButton>
      {state?.message && (
        <span
          className={`max-w-[16rem] whitespace-normal text-xs ${state?.ok ? 'text-green-700' : 'text-red-600'}`}
          role={state?.ok ? 'status' : 'alert'}
        >
          {state?.message}
        </span>
      )}
    </form>
  );
}
