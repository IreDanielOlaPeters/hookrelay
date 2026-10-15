import type { ReactNode } from 'react';

const TONES = {
  success: 'border-green-200 bg-green-50 text-green-800',
  info: 'border-gray-200 bg-white text-gray-700',
  error: 'border-red-200 bg-red-50 text-red-800',
};

export function Notice({
  tone = 'info',
  children,
}: {
  tone?: keyof typeof TONES;
  children: ReactNode;
}) {
  return (
    <div className={`mb-4 rounded-lg border px-4 py-3 text-sm ${TONES[tone]}`} role="status">
      {children}
    </div>
  );
}
