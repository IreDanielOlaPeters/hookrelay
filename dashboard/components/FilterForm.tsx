import type { ReactNode } from 'react';
import Link from 'next/link';

/** GET filter form; works without JavaScript. */
export function FilterForm({
  action,
  children,
  resetHref,
}: {
  action: string;
  children: ReactNode;
  resetHref: string;
}) {
  return (
    <form method="get" action={action} className="flex flex-wrap items-end gap-3 border-b border-gray-200 p-4">
      {children}
      <div className="flex gap-2">
        <button type="submit" className="btn-primary">Apply</button>
        <Link href={resetHref} className="btn-secondary">Reset</Link>
      </div>
    </form>
  );
}
