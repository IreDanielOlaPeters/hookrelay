// Steady load: a constant arrival rate (RATE webhooks/s for DURATION).
import { send } from './lib.js';

const RATE = Number(__ENV.RATE || 200);

export const options = {
  scenarios: {
    steady: {
      executor: 'constant-arrival-rate',
      rate: RATE,
      timeUnit: '1s',
      duration: __ENV.DURATION || '2m',
      preAllocatedVUs: Math.max(20, Math.ceil(RATE / 4)),
      maxVUs: RATE * 2,
    },
  },
  thresholds: {
    'http_req_duration{name:ingest}': ['p(95)<100'],
    checks: ['rate>0.999'],
  },
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export default send;
