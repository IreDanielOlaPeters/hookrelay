import Link from 'next/link';

export default function NotFound() {
  return (
    <div className="card p-6 text-sm text-gray-700">
      <p className="font-semibold text-gray-900">Not found</p>
      <p className="mt-1">That page or record does not exist.</p>
      <Link href="/" className="link mt-3 inline-block">Back to overview</Link>
    </div>
  );
}
