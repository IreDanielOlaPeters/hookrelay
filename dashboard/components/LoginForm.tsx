'use client';

import { useActionState } from 'react';
import { loginAction } from '@/app/actions';
import type { DemoInfo } from '@/lib/api';
import { FormMessage } from './FieldError';
import { SubmitButton } from './SubmitButton';

export function LoginForm({ next, demo }: { next: string; demo: DemoInfo }) {
  const [state, action] = useActionState(loginAction, {});
  // In demo mode the public demo credentials are pre-filled and shown as hints.
  const email = state?.values?.email ?? (demo.demo_mode ? demo.email : '');
  const password = demo.demo_mode ? demo.password : undefined;

  return (
    <form action={action} className="space-y-4">
      <FormMessage state={state} />
      <input type="hidden" name="next" value={next} />
      <div>
        <label className="label" htmlFor="login-email">Email</label>
        <input
          id="login-email"
          name="email"
          type="email"
          autoComplete="username"
          required
          className="input"
          defaultValue={email}
          aria-describedby={demo.demo_mode ? 'login-email-hint' : undefined}
        />
        {demo.demo_mode && (
          <p id="login-email-hint" className="mt-1 text-xs text-gray-500">
            Demo email: <span className="mono">{demo.email}</span>
          </p>
        )}
      </div>
      <div>
        <label className="label" htmlFor="login-password">Password</label>
        <input
          id="login-password"
          name="password"
          type="password"
          autoComplete="current-password"
          required
          className="input"
          defaultValue={password}
          aria-describedby={demo.demo_mode ? 'login-password-hint' : undefined}
        />
        {demo.demo_mode && (
          <p id="login-password-hint" className="mt-1 text-xs text-gray-500">
            Demo password: <span className="mono">{demo.password}</span>
          </p>
        )}
      </div>
      <SubmitButton className="btn-primary w-full" pendingLabel="Signing in…">Sign in</SubmitButton>
    </form>
  );
}
