import { describe, expect, it } from 'vitest';
import { keepValues } from '@/lib/form-state';

function formData(entries: Record<string, string>): FormData {
  const fd = new FormData();
  for (const [k, v] of Object.entries(entries)) fd.append(k, v);
  return fd;
}

describe('keepValues', () => {
  it('echoes submitted values on failure so React 19 form resets keep user input', () => {
    const state = keepValues(formData({ name: 'GitHub', slug: 'demo' }), { ok: false, message: 'taken' });
    expect(state.values).toEqual({ name: 'GitHub', slug: 'demo' });
    expect(state.message).toBe('taken');
  });

  it('never echoes secrets or React internals', () => {
    const state = keepValues(
      formData({ name: 'x', signing_secret: 'whsec', auth_header: 'Bearer y', password: 'p', $ACTION_ID_1: '' }),
      { ok: false },
    );
    expect(state.values).toEqual({ name: 'x' });
  });

  it('leaves successful states untouched', () => {
    const ok = { ok: true, message: 'Created', nonce: 1 };
    expect(keepValues(formData({ name: 'x' }), ok)).toBe(ok);
  });
});
