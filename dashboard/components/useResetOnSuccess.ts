'use client';

import { useEffect, useRef } from 'react';
import type { FormState } from '@/lib/form-state';

/** Returns a ref for a <form> that is reset after each successful submit. */
export function useResetOnSuccess(state: FormState | undefined) {
  const ref = useRef<HTMLFormElement>(null);
  useEffect(() => {
    if (state?.ok && state?.nonce) ref.current?.reset();
  }, [state?.ok, state?.nonce]);
  return ref;
}
