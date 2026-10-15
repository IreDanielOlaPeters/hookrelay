import type { FormState } from '@/lib/form-state';

/** Validation messages for one field (from a 422 response). */
export function FieldError({ state, name }: { state: FormState | undefined; name: string }) {
  const messages = state?.errors?.[name];
  if (!messages?.length) return null;
  return (
    <p className="mt-1 text-xs text-red-600" role="alert">
      {messages.join(' ')}
    </p>
  );
}

/** Form-level message: success (green) or error (red). Field errors are listed if their field isn't rendered. */
export function FormMessage({ state, fields = [] }: { state: FormState | undefined; fields?: string[] }) {
  // Next 14 hands back `undefined` as form state after an action redirects.
  if (!state?.message) return null;
  const orphan = Object.entries(state.errors ?? {}).filter(([k]) => !fields.includes(k));
  return (
    <div
      className={`rounded-md border px-3 py-2 text-sm ${
        state.ok ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-800'
      }`}
      role={state.ok ? 'status' : 'alert'}
    >
      {state.message}
      {orphan.length > 0 && (
        <ul className="mt-1 list-inside list-disc text-xs">
          {orphan.map(([k, v]) => (
            <li key={k}>
              <span className="mono">{k}</span>: {v.join(' ')}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
