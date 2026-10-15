'use client';

import type { ReactNode } from 'react';
import { useFormStatus } from 'react-dom';

export function SubmitButton({
  children,
  pendingLabel,
  className = 'btn-primary',
}: {
  children: ReactNode;
  pendingLabel?: ReactNode;
  className?: string;
}) {
  const { pending } = useFormStatus();
  return (
    <button type="submit" className={className} disabled={pending} aria-disabled={pending}>
      {pending ? (pendingLabel ?? 'Working…') : children}
    </button>
  );
}
