// CI smoke: short steady load; fails the build if ingestion p95 >= 100 ms or any request fails.
import { send } from './lib.js';

export const options = {
  scenarios: {
    smoke: {
      executor: 'constant-arrival-rate',
      rate: Number(__ENV.RATE || 25),
      timeUnit: '1s',
      duration: __ENV.DURATION || '30s',
      preAllocatedVUs: 10,
      maxVUs: 50,
    },
  },
  thresholds: {
    'http_req_duration{name:ingest}': ['p(95)<100'],
    checks: ['rate==1'],
  },
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export default send;
