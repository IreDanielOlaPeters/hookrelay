import type { ReactNode } from 'react';

export function EmptyRow({ colSpan, children }: { colSpan: number; children: ReactNode }) {
  return (
    <tr>
      <td colSpan={colSpan} className="!py-8 text-center !text-gray-400">
        {children}
      </td>
    </tr>
  );
}
