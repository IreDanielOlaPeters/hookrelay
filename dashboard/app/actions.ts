'use server';

import { revalidatePath } from 'next/cache';
import { redirect, unstable_rethrow } from 'next/navigation';
import {
  api,
  ApiError,
  ApiUnreachableError,
  PROVIDERS,
  type BulkReplayInput,
  type DestinationInput,
  type Provider,
  type SourceInput,
} from '@/lib/api';
import { dateBound } from '@/lib/format';
import { keepValues, type FormState } from '@/lib/form-state';
import { safeNext } from '@/lib/security';
import { clearSessionCookie, setSessionCookie } from '@/lib/session';
import { withQuery } from '@/lib/params';

// ---------------------------------------------------------------------------
// FormData helpers
// ---------------------------------------------------------------------------

function str(fd: FormData, key: string): string | undefined {
  const v = fd.get(key);
  if (typeof v !== 'string') return undefined;
  const t = v.trim();
  return t === '' ? undefined : t;
}

function int(fd: FormData, key: string): number | undefined {
  const v = str(fd, key);
  if (v === undefined) return undefined;
  const n = Number(v);
  return Number.isFinite(n) ? n : undefined;
}

function bool(fd: FormData, key: string): boolean {
  const v = fd.get(key);
  return v === 'on' || v === 'true' || v === '1';
}

function failure(err: unknown): FormState {
  // A redirect thrown inside an API call (expired session -> /login) must not become a form error.
  unstable_rethrow(err);
  if (err instanceof ApiError) {
    if (err.status === 422) {
      return { ok: false, message: err.message || 'Please fix the errors below.', errors: err.errors };
    }
    return { ok: false, message: `${err.message} (HTTP ${err.status})` };
  }
  if (err instanceof ApiUnreachableError) {
    return { ok: false, message: 'The HookRelay API is unreachable. Try again shortly.' };
  }
  console.error(err);
  return { ok: false, message: 'Unexpected error. See the dashboard server logs.' };
}

async function run(fn: () => Promise<unknown>, message: string): Promise<FormState> {
  try {
    await fn();
  } catch (err) {
    return failure(err);
  }
  revalidatePath('/', 'layout');
  return { ok: true, message, nonce: Date.now() };
}

function requireId(fd: FormData): string {
  const id = str(fd, 'id');
  if (!id) throw new ApiError(400, 'Missing id.');
  return id;
}

// ---------------------------------------------------------------------------
// Sources
// ---------------------------------------------------------------------------

export async function createSourceAction(_prev: FormState, fd: FormData): Promise<FormState> {
  const provider = str(fd, 'provider');
  const input: SourceInput = {
    name: str(fd, 'name') ?? '',
    slug: str(fd, 'slug') ?? '',
    provider: PROVIDERS.includes(provider as Provider) ? (provider as Provider) : undefined,
    signing_secret: str(fd, 'signing_secret'),
    dedupe_window_seconds: int(fd, 'dedupe_window_seconds'),
    rate_limit_rps: int(fd, 'rate_limit_rps'),
  };
  return keepValues(fd, await run(() => api.createSource(input), `Source “${input.name}” created.`));
}

export async function deleteSourceAction(_prev: FormState, fd: FormData): Promise<FormState> {
  return run(async () => api.deleteSource(requireId(fd)), 'Source deleted.');
}

export async function sendTestWebhookAction(_prev: FormState, fd: FormData): Promise<FormState> {
  let eventId: string;
  try {
    eventId = (await api.sendTestWebhook(requireId(fd), str(fd, 'body'))).event_id;
  } catch (err) {
    return keepValues(fd, failure(err));
  }
  revalidatePath('/', 'layout');
  redirect(`/events/${eventId}`);
}

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------

export async function loginAction(_prev: FormState, fd: FormData): Promise<FormState> {
  const email = str(fd, 'email') ?? '';
  const password = typeof fd.get('password') === 'string' ? (fd.get('password') as string) : '';
  try {
    const session = await api.login(email, password);
    await setSessionCookie(session.token, session.expires_at);
  } catch (err) {
    if (err instanceof ApiError && err.status === 429) {
      return keepValues(fd, { ok: false, message: 'Too many sign-in attempts. Wait a minute and try again.' });
    }
    if (err instanceof ApiError && err.status === 422) {
      return keepValues(fd, { ok: false, message: err.message || 'These credentials do not match our records.' });
    }
    return keepValues(fd, failure(err));
  }
  redirect(safeNext(str(fd, 'next')));
}

export async function logoutAction(): Promise<void> {
  try {
    await api.logout();
  } catch {
    // Already expired or API unreachable: clearing the cookie is what matters.
  }
  await clearSessionCookie();
  redirect('/login');
}

// ---------------------------------------------------------------------------
// Destinations
// ---------------------------------------------------------------------------

export async function createDestinationAction(_prev: FormState, fd: FormData): Promise<FormState> {
  const input: DestinationInput = {
    name: str(fd, 'name') ?? '',
    url: str(fd, 'url') ?? '',
    auth_header: str(fd, 'auth_header'),
    timeout_ms: int(fd, 'timeout_ms'),
    rate_limit_rps: int(fd, 'rate_limit_rps'),
    max_attempts: int(fd, 'max_attempts'),
    enabled: bool(fd, 'enabled'),
  };
  return keepValues(
    fd,
    await run(() => api.createDestination(input), `Destination “${input.name}” created.`),
  );
}

export async function setDestinationEnabledAction(
  _prev: FormState,
  fd: FormData,
): Promise<FormState> {
  const enabled = bool(fd, 'enabled');
  return run(
    async () => api.updateDestination(requireId(fd), { enabled }),
    enabled ? 'Enabled.' : 'Disabled.',
  );
}

export async function deleteDestinationAction(_prev: FormState, fd: FormData): Promise<FormState> {
  return run(async () => api.deleteDestination(requireId(fd)), 'Destination deleted.');
}

// ---------------------------------------------------------------------------
// Connections
// ---------------------------------------------------------------------------

export async function createConnectionAction(_prev: FormState, fd: FormData): Promise<FormState> {
  const state = await run(
    () =>
      api.createConnection({
        source_id: str(fd, 'source_id') ?? '',
        destination_id: str(fd, 'destination_id') ?? '',
        enabled: bool(fd, 'enabled'),
      }),
    'Connection created.',
  );
  return keepValues(fd, state);
}

export async function setConnectionEnabledAction(
  _prev: FormState,
  fd: FormData,
): Promise<FormState> {
  const enabled = bool(fd, 'enabled');
  return run(
    async () => api.updateConnection(Number(requireId(fd)), { enabled }),
    enabled ? 'Enabled.' : 'Disabled.',
  );
}

export async function deleteConnectionAction(_prev: FormState, fd: FormData): Promise<FormState> {
  return run(async () => api.deleteConnection(Number(requireId(fd))), 'Connection deleted.');
}

// ---------------------------------------------------------------------------
// Replays
// ---------------------------------------------------------------------------

export async function replayDeliveryAction(_prev: FormState, fd: FormData): Promise<FormState> {
  try {
    await api.replayDelivery(requireId(fd));
  } catch (err) {
    if (err instanceof ApiError && err.status === 409) {
      return { ok: false, message: 'Delivery is in flight; try again once this attempt finishes.' };
    }
    return failure(err);
  }
  revalidatePath('/', 'layout');
  return { ok: true, message: 'Queued for replay.', nonce: Date.now() };
}

export async function bulkReplayAction(_prev: FormState, fd: FormData): Promise<FormState> {
  const destinationId = str(fd, 'destination_id');
  const fromDate = str(fd, 'from');
  const toDate = str(fd, 'to');
  const status = str(fd, 'status') === 'failed' ? 'failed' : 'dead';
  const input: BulkReplayInput = {
    status,
    destination_id: destinationId,
    from: dateBound(fromDate, 'start'),
    to: dateBound(toDate, 'end'),
  };

  let batchId: string;
  try {
    batchId = (await api.bulkReplay(input)).id;
  } catch (err) {
    return keepValues(fd, failure(err));
  }

  revalidatePath('/', 'layout');
  redirect(
    withQuery('/dead-letter', {
      destination_id: destinationId,
      from: fromDate,
      to: toDate,
      status: status === 'dead' ? undefined : status,
      batch: batchId,
    }),
  );
}
