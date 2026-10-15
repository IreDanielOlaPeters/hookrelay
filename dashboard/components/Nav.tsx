'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';

const LINKS = [
  { href: '/', label: 'Overview' },
  { href: '/events', label: 'Events' },
  { href: '/deliveries', label: 'Deliveries' },
  { href: '/dead-letter', label: 'Dead letter' },
  { href: '/sources', label: 'Sources' },
  { href: '/destinations', label: 'Destinations' },
];

export function Nav() {
  const pathname = usePathname();
  return (
    <nav className="flex gap-1 overflow-x-auto md:flex-col md:overflow-visible">
      {LINKS.map(({ href, label }) => {
        const active = href === '/' ? pathname === '/' : pathname.startsWith(href);
        return (
          <Link
            key={href}
            href={href}
            aria-current={active ? 'page' : undefined}
            className={`whitespace-nowrap rounded-md px-3 py-1.5 text-sm ${
              active
                ? 'bg-gray-900 font-medium text-white'
                : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'
            }`}
          >
            {label}
          </Link>
        );
      })}
    </nav>
  );
}
