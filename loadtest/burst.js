// Burst: RATE/s for 60 s, then 10x RATE for 60 s, then back to RATE for 60 s.
import { send } from './lib.js';

const RATE = Number(__ENV.RATE || 100);

export const options = {
  scenarios: {
    burst: {
      executor: 'ramping-arrival-rate',
      startRate: RATE,
      timeUnit: '1s',
      preAllocatedVUs: Math.max(50, Math.ceil(RATE)),
      maxVUs: RATE * 20,
      stages: [
        { target: RATE, duration: '60s' },
        { target: RATE * 10, duration: '1s' },
        { target: RATE * 10, duration: '60s' },
        { target: RATE, duration: '1s' },
        { target: RATE, duration: '60s' },
      ],
    },
  },
  thresholds: {
    checks: ['rate>0.999'],
  },
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export default send;
