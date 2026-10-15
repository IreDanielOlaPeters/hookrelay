// Peak ingestion: ITERATIONS webhooks as fast as VUS concurrent senders can push them.
import { send } from './lib.js';

export const options = {
  scenarios: {
    max: {
      executor: 'shared-iterations',
      vus: Number(__ENV.VUS || 64),
      iterations: Number(__ENV.ITERATIONS || 10000),
      maxDuration: '5m',
    },
  },
  thresholds: { checks: ['rate>0.999'] },
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export default send;
