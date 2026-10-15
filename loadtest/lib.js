// Shared helpers for the HookRelay k6 scenarios.
import http from 'k6/http';
import { check } from 'k6';
import exec from 'k6/execution';

export const BASE_URL = __ENV.BASE_URL || 'http://api:8080';
export const SOURCE = __ENV.SOURCE || 'bench';

// A realistic ~600-byte JSON webhook. Each request is unique, so none are deduped.
export function payload() {
  const id = `evt_${exec.scenario.name}_${exec.vu.idInTest}_${exec.vu.iterationInScenario}_${Date.now()}`;
  return JSON.stringify({
    id,
    type: 'order.created',
    created: Math.floor(Date.now() / 1000),
    data: {
      object: {
        id: `ord_${exec.vu.iterationInScenario}`,
        amount: 4200,
        currency: 'cad',
        customer: { email: 'buyer@example.com', name: 'Load Test' },
        items: [
          { sku: 'SKU-1', qty: 2, price: 1500 },
          { sku: 'SKU-2', qty: 1, price: 1200 },
        ],
        metadata: { source: 'k6', scenario: exec.scenario.name },
      },
    },
  });
}

export function send() {
  const res = http.post(`${BASE_URL}/in/${SOURCE}`, payload(), {
    headers: { 'Content-Type': 'application/json' },
    tags: { name: 'ingest' },
  });
  check(res, { 'accepted (202)': (r) => r.status === 202 });
  return res;
}
