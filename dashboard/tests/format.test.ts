import { describe, expect, it } from 'vitest';
import { dateBound } from '@/lib/format';

describe('dateBound', () => {
  it('turns a date input into whole-day UTC bounds', () => {
    expect(dateBound('2026-10-01', 'start')).toBe('2026-10-01T00:00:00Z');
    expect(dateBound('2026-10-01', 'end')).toBe('2026-10-01T23:59:59Z');
  });

  it('ignores empty or malformed input', () => {
    expect(dateBound(undefined, 'start')).toBeUndefined();
    expect(dateBound('', 'end')).toBeUndefined();
    expect(dateBound('10/01/2026', 'start')).toBeUndefined();
  });
});
