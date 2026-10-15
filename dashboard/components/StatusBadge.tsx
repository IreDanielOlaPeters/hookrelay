import type { DeliveryStatus } from '@/lib/api';

const STYLES: Record<DeliveryStatus, string> = {
  pending: 'bg-amber-50 text-amber-800 ring-amber-600/20',
  delivering: 'bg-blue-50 text-blue-700 ring-blue-600/20',
  succeeded: 'bg-green-50 text-green-700 ring-green-600/20',
  failed: 'bg-orange-50 text-orange-700 ring-orange-600/20',
  dead: 'bg-red-50 text-red-700 ring-red-600/20',
};

const DOTS: Record<DeliveryStatus, string> = {
  pending: 'bg-amber-500',
  delivering: 'bg-blue-500',
  succeeded: 'bg-green-500',
  failed: 'bg-orange-500',
  dead: 'bg-red-500',
};

/** Coloured pill for a delivery status. Pass `count` to render a compact "status: n" badge. */
export function StatusBadge({ status, count }: { status: DeliveryStatus; count?: number }) {
  const style = STYLES[status] ?? 'bg-gray-50 text-gray-700 ring-gray-500/20';
  return (
    <span
      className={`inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${style}`}
      title={count !== undefined ? `${count} ${status}` : status}
    >
      <span className={`h-1.5 w-1.5 rounded-full ${DOTS[status] ?? 'bg-gray-400'}`} aria-hidden />
      {status}
      {count !== undefined && <span className="tabular-nums">{count}</span>}
    </span>
  );
}
