import type { ApiFailure } from '@/lib/api';

const TITLES: Record<ApiFailure['kind'], string> = {
  unreachable: 'The HookRelay API is unreachable',
  unauthorized: 'The API rejected the admin token',
  not_found: 'Not found',
  http: 'The API returned an error',
};

export function ApiErrorPanel({ error }: { error: ApiFailure }) {
  return (
    <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
      <p className="font-semibold">{TITLES[error.kind]}</p>
      <p className="mt-1">
        {error.kind === 'unreachable' && (
          <>
            Could not connect to <code className="mono">{error.url}</code>. Check that the API is
            running and that <code className="mono">API_URL</code> is set correctly.
          </>
        )}
        {error.kind === 'unauthorized' && (
          <>
            Check that <code className="mono">ADMIN_TOKEN</code> matches the API&apos;s admin token.
          </>
        )}
        {(error.kind === 'http' || error.kind === 'not_found') && (
          <>
            {error.status ? `HTTP ${error.status}: ` : ''}
            {error.message}
          </>
        )}
      </p>
    </div>
  );
}
