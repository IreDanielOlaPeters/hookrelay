import type { ReactNode } from 'react';

export type Tone = 'gray' | 'green' | 'amber' | 'red' | 'blue';

const TONES: Record<Tone, string> = {
  gray: 'bg-gray-50 text-gray-600 ring-gray-500/20',
  green: 'bg-green-50 text-green-700 ring-green-600/20',
  amber: 'bg-amber-50 text-amber-800 ring-amber-600/20',
  red: 'bg-red-50 text-red-700 ring-red-600/20',
  blue: 'bg-blue-50 text-blue-700 ring-blue-600/20',
};

export function Badge({
  tone = 'gray',
  title,
  children,
}: {
  tone?: Tone;
  title?: string;
  children: ReactNode;
}) {
  return (
    <span
      title={title}
      className={`inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${TONES[tone]}`}
    >
      {children}
    </span>
  );
}
