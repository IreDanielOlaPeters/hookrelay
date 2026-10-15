import 'server-only';
import { redirect, unstable_rethrow } from 'next/navigation';
import { cache } from 'react';
import { sessionToken } from '@/lib/session';

/*
 * Typed server-side client for the HookRelay management API (docs/api.md).
 * Calls carry the signed-in user's session token (httpOnly cookie), so the API enforces
 * that user's role. This module must never be imported from a client component.
 */

// ---------------------------------------------------------------------------
// Types mirroring docs/api.md
// ---------------------------------------------------------------------------

export type Provider = 'generic' | 'stripe' | 'github' | 'shopify' | 'woocommerce';
export const PROVIDERS: Provider[] = ['generic', 'stripe', 'github', 'shopify', 'woocommerce'];

export type DeliveryStatus = 'pending' | 'delivering' | 'succeeded' | 'failed' | 'dead';
export const DELIVERY_STATUSES: DeliveryStatus[] = [
  'pending',
  'delivering',
  'succeeded',
  'failed',
  'dead',
];

export type CircuitState = 'closed' | 'open' | 'half_open';

export interface Source {
  id: string;
  name: string;
  slug: string;
  provider: Provider;
  has_signing_secret: boolean;
  dedupe_window_seconds: number;
  rate_limit_rps: number | null;
  ingest_url: string;
  created_at: string;
  updated_at: string;
}

export interface Destination {
  id: string;
  name: string;
  url: string;
  has_auth_header: boolean;
  timeout_ms: number;
  rate_limit_rps: number | null;
  max_attempts: number;
  enabled: boolean;
  circuit: {
    state: CircuitState;
    consecutive_failures: number;
    open_until: string | null;
  };
  created_at: string;
  updated_at: string;
}

export interface Connection {
  id: number;
  source_id: string;
  destination_id: string;
  enabled: boolean;
  source: { id: string; name: string; slug: string };
  destination: { id: string; name: string };
  created_at: string;
}

export type DeliveryCounts = Record<DeliveryStatus, number>;

export interface EventSummary {
  id: string;
  source_id: string;
  source: { id: string; name: string; slug: string; provider: Provider };
  idempotency_key: string | null;
  content_type: string | null;
  verified: boolean;
  received_at: string;
  body_size: number;
  delivery_counts: DeliveryCounts;
}

export interface Attempt {
  id: number;
  started_at: string;
  duration_ms: number | null;
  response_status: number | null;
  response_body: string | null;
  error: string | null;
}

export interface Delivery {
  id: string;
  event_id: string;
  destination_id: string;
  destination: { id: string; name: string; url: string };
  event: { id: string; source_id: string; received_at: string };
  status: DeliveryStatus;
  attempt_count: number;
  max_attempts: number;
  next_attempt_at: string | null;
  last_error: string | null;
  created_at: string;
  updated_at: string;
  attempts?: Attempt[];
}

export interface EventDetail extends EventSummary {
  headers: Record<string, string[]>;
  body: string;
  body_encoding: 'utf8' | 'base64';
  deliveries: Delivery[];
}

export interface PaginationMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
}

export interface Paginated<T> {
  data: T[];
  links: { first: string | null; last: string | null; prev: string | null; next: string | null };
  meta: PaginationMeta;
}

export interface Stats {
  window_hours: number;
  events_received: number;
  deliveries: DeliveryCounts & { total: number };
  latency_ms: {
    attempt: { p50: number | null; p95: number | null };
    end_to_end: { p50: number | null; p95: number | null };
  };
}

export interface SourceInput {
  name: string;
  slug: string;
  provider?: Provider;
  signing_secret?: string | null;
  dedupe_window_seconds?: number;
  rate_limit_rps?: number;
}

export interface DestinationInput {
  name: string;
  url: string;
  auth_header?: string;
  timeout_ms?: number;
  rate_limit_rps?: number | null;
  max_attempts?: number;
  enabled?: boolean;
}

export interface ConnectionInput {
  source_id: string;
  destination_id: string;
  enabled?: boolean;
}

export interface BulkReplayInput {
  status?: 'dead' | 'failed' | 'succeeded';
  destination_id?: string;
  from?: string;
  to?: string;
}

export type Role = 'admin' | 'demo';

export interface User {
  id: number;
  name: string;
  email: string;
  role: Role;
}

export interface Me {
  user: User;
  demo_mode: boolean;
}

export interface LoginResponse {
  token: string;
  expires_at: string;
  user: User;
}

export interface DemoInfo {
  demo_mode: boolean;
  email?: string;
  password?: string;
}

// ---------------------------------------------------------------------------
// Errors
// ---------------------------------------------------------------------------

export type ValidationErrors = Record<string, string[]>;

/** The API answered with a non-2xx status. */
export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly errors?: ValidationErrors,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

/** The API could not be reached at all (connection refused, DNS, timeout). */
export class ApiUnreachableError extends Error {
  constructor(public readonly url: string, cause?: unknown) {
    super(`Could not reach the HookRelay API at ${url}`);
    this.name = 'ApiUnreachableError';
    this.cause = cause;
  }
}

// ---------------------------------------------------------------------------
// Transport
// ---------------------------------------------------------------------------

type Query = Record<string, string | number | undefined | null>;

export function apiBaseUrl(): string {
  return (process.env.API_URL || 'http://localhost:8080').replace(/\/+$/, '');
}

const TIMEOUT_MS = 10_000;

async function request<T>(
  method: 'GET' | 'POST' | 'PUT' | 'DELETE',
  path: string,
  opts: { query?: Query; body?: unknown; auth?: boolean } = {},
): Promise<T> {
  const auth = opts.auth ?? true;
  const base = apiBaseUrl();
  const url = new URL(base + path);
  for (const [k, v] of Object.entries(opts.query ?? {})) {
    if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, String(v));
  }

  const headers: Record<string, string> = { Accept: 'application/json' };
  if (auth) {
    const token = await sessionToken();
    if (!token) redirect('/login');
    headers.Authorization = `Bearer ${token}`;
  }
  if (opts.body !== undefined) headers['Content-Type'] = 'application/json';

  let res: Response;
  try {
    res = await fetch(url, {
      method,
      headers,
      body: opts.body !== undefined ? JSON.stringify(opts.body) : undefined,
      cache: 'no-store',
      signal: AbortSignal.timeout(TIMEOUT_MS),
    });
  } catch (err) {
    throw new ApiUnreachableError(base, err);
  }

  if (res.status === 204) return undefined as T;

  const text = await res.text();
  let json: unknown = undefined;
  if (text) {
    try {
      json = JSON.parse(text);
    } catch {
      json = undefined;
    }
  }

  // Session expired or revoked: back to the login page.
  if (res.status === 401 && auth) redirect('/login?expired=1');

  if (!res.ok) {
    const body = (json ?? {}) as { message?: string; errors?: ValidationErrors };
    throw new ApiError(
      res.status,
      body.message || `API responded with HTTP ${res.status} ${res.statusText}`.trim(),
      body.errors,
    );
  }

  return json as T;
}

// ---------------------------------------------------------------------------
// Endpoints
// ---------------------------------------------------------------------------

type Data<T> = { data: T };

/** Collection endpoints are documented as Laravel resources (`{data: [...]}`); accept a bare array too. */
function unwrapList<T>(res: Data<T[]> | T[]): T[] {
  return Array.isArray(res) ? res : (res?.data ?? []);
}

export const api = {
  // Auth
  login: (email: string, password: string) =>
    request<LoginResponse>('POST', '/api/auth/login', { body: { email, password }, auth: false }),
  demoInfo: () => request<DemoInfo>('GET', '/api/auth/demo', { auth: false }),
  logout: () => request<void>('POST', '/api/auth/logout'),

  stats: () => request<Stats>('GET', '/api/stats'),

  // Sources
  listSources: async () => unwrapList(await request<Data<Source[]> | Source[]>('GET', '/api/sources')),
  createSource: async (input: SourceInput) =>
    (await request<Data<Source>>('POST', '/api/sources', { body: input })).data,
  sendTestWebhook: (id: string, body?: string) =>
    request<{ event_id: string; deliveries: number }>(
      'POST',
      `/api/sources/${encodeURIComponent(id)}/test`,
      { body: body ? { body } : {} },
    ),
  deleteSource: (id: string) =>
    request<void>('DELETE', `/api/sources/${encodeURIComponent(id)}`),

  // Destinations
  listDestinations: async () =>
    unwrapList(await request<Data<Destination[]> | Destination[]>('GET', '/api/destinations')),
  createDestination: async (input: DestinationInput) =>
    (await request<Data<Destination>>('POST', '/api/destinations', { body: input })).data,
  updateDestination: async (id: string, input: Partial<DestinationInput>) =>
    (
      await request<Data<Destination>>('PUT', `/api/destinations/${encodeURIComponent(id)}`, {
        body: input,
      })
    ).data,
  deleteDestination: (id: string) =>
    request<void>('DELETE', `/api/destinations/${encodeURIComponent(id)}`),

  // Connections
  listConnections: async (query: { source_id?: string } = {}) =>
    unwrapList(
      await request<Data<Connection[]> | Connection[]>('GET', '/api/connections', { query }),
    ),
  createConnection: async (input: ConnectionInput) =>
    (await request<Data<Connection>>('POST', '/api/connections', { body: input })).data,
  updateConnection: async (id: number, input: { enabled: boolean }) =>
    (await request<Data<Connection>>('PUT', `/api/connections/${id}`, { body: input })).data,
  deleteConnection: (id: number) => request<void>('DELETE', `/api/connections/${id}`),

  // Events
  listEvents: (query: {
    source_id?: string;
    from?: string;
    to?: string;
    page?: number;
    per_page?: number;
  }) => request<Paginated<EventSummary>>('GET', '/api/events', { query }),
  getEvent: async (id: string) =>
    (await request<Data<EventDetail>>('GET', `/api/events/${encodeURIComponent(id)}`)).data,

  // Deliveries
  listDeliveries: (query: {
    status?: string;
    destination_id?: string;
    event_id?: string;
    has_error?: 1;
    from?: string;
    to?: string;
    page?: number;
    per_page?: number;
  }) => request<Paginated<Delivery>>('GET', '/api/deliveries', { query }),
  getDelivery: async (id: string) =>
    (await request<Data<Delivery>>('GET', `/api/deliveries/${encodeURIComponent(id)}`)).data,
  replayDelivery: async (id: string) =>
    (await request<Data<Delivery>>('POST', `/api/deliveries/${encodeURIComponent(id)}/replay`))
      .data,
  bulkReplay: (input: BulkReplayInput) =>
    request<{ replayed: number }>('POST', '/api/deliveries/replay', { body: input }),
};

// ---------------------------------------------------------------------------
// Helpers for pages
// ---------------------------------------------------------------------------

/** Serializable description of a failed API call, rendered by <ApiErrorPanel>. */
export interface ApiFailure {
  kind: 'unreachable' | 'unauthorized' | 'not_found' | 'http';
  status?: number;
  message: string;
  url: string;
}

export function describeError(err: unknown): ApiFailure {
  const url = apiBaseUrl();
  if (err instanceof ApiUnreachableError) {
    return { kind: 'unreachable', message: err.message, url };
  }
  if (err instanceof ApiError) {
    if (err.status === 401 || err.status === 403) {
      return { kind: 'unauthorized', status: err.status, message: err.message, url };
    }
    if (err.status === 404) {
      return { kind: 'not_found', status: 404, message: err.message, url };
    }
    return { kind: 'http', status: err.status, message: err.message, url };
  }
  return { kind: 'http', message: err instanceof Error ? err.message : String(err), url };
}

export type Loaded<T> = { ok: true; data: T } | { ok: false; error: ApiFailure };

/** Await a promise, turning API errors into a value instead of a thrown exception. */
export async function load<T>(promise: Promise<T>): Promise<Loaded<T>> {
  try {
    return { ok: true, data: await promise };
  } catch (err) {
    // Let Next's redirect (e.g. to /login on 401) and not-found errors propagate.
    unstable_rethrow(err);
    return { ok: false, error: describeError(err) };
  }
}

/** The signed-in user, fetched once per request (redirects to /login when the session is gone). */
export const getMe = cache(() => request<Me>('GET', '/api/auth/me'));

/** Only admins may delete data or change settings; the API enforces it, the UI hides the controls. */
export function canManage(me: Me): boolean {
  return me.user.role === 'admin';
}
