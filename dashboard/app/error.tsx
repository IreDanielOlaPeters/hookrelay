'use client';

export default function Error({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return (
    <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
      <p className="font-semibold">Something went wrong loading this page.</p>
      <p className="mt-1">
        The HookRelay API may be unavailable. {error.digest && <span className="mono">({error.digest})</span>}
      </p>
      <button type="button" onClick={reset} className="btn-secondary btn-sm mt-3">
        Try again
      </button>
    </div>
  );
}
