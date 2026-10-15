// Slow destination: steady RATE/s into a source whose only destination takes 2 s to answer.
// Ingestion must stay fast; delivery throughput is bounded by workers / 2 s.
import { send } from './lib.js';

const RATE = Number(__ENV.RATE || 5);

export const options = {
  scenarios: {
    slow: {
      executor: 'constant-arrival-rate',
      rate: RATE,
      timeUnit: '1s',
      duration: __ENV.DURATION || '60s',
      preAllocatedVUs: 10,
      maxVUs: 100,
    },
  },
  thresholds: {
    'http_req_duration{name:ingest}': ['p(95)<100'],
    checks: ['rate>0.999'],
  },
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export default send;
