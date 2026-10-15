/** State returned by server actions to the client forms that call them (via useActionState). */
export interface FormState {
  ok?: boolean;
  message?: string;
  errors?: Record<string, string[]>;
  /**
   * Submitted values, echoed back on failure. React 19 resets a form after its action runs;
   * fields use these as defaultValue so a validation error doesn't wipe what the user typed.
   * Secrets are never echoed.
   */
  values?: Record<string, string>;
  /** Bumped on each successful submit so forms can reset themselves. */
  nonce?: number;
}

export const initialFormState: FormState = {};

const SECRET_FIELDS = new Set(['signing_secret', 'auth_header', 'password']);

/** On failure, echo the submitted (non-secret) values so the form can restore them. */
export function keepValues(fd: FormData, state: FormState): FormState {
  if (state.ok) return state;
  const values: Record<string, string> = {};
  fd.forEach((v, k) => {
    if (typeof v === 'string' && !k.startsWith('$') && !SECRET_FIELDS.has(k)) values[k] = v;
  });
  return { ...state, values };
}
