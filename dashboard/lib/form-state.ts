/** State returned by server actions to the client forms that call them (via useFormState). */
export interface FormState {
  ok?: boolean;
  message?: string;
  errors?: Record<string, string[]>;
  /** Bumped on each successful submit so forms can reset themselves. */
  nonce?: number;
}

export const initialFormState: FormState = {};
