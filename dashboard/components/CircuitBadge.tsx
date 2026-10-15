import type { Destination } from '@/lib/api';
import { fullTimestamp, relativeTime } from '@/lib/format';
import { Badge, type Tone } from './Badge';

const TONE: Record<string, Tone> = { closed: 'green', half_open: 'amber', open: 'red' };

export function CircuitBadge({ circuit }: { circuit: Destination['circuit'] }) {
  const label = circuit.state.replace('_', '-');
  const parts = [`${circuit.consecutive_failures} consecutive failures`];
  if (circuit.open_until) parts.push(`open until ${fullTimestamp(circuit.open_until)}`);
  return (
    <span className="inline-flex items-center gap-1.5">
      <Badge tone={TONE[circuit.state] ?? 'gray'} title={parts.join(', ')}>
        circuit {label}
      </Badge>
      {circuit.state === 'open' && circuit.open_until && (
        <span className="text-xs text-gray-500" title={fullTimestamp(circuit.open_until)}>
          retry {relativeTime(circuit.open_until)}
        </span>
      )}
    </span>
  );
}
