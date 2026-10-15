// HookRelay mock receiver: a tiny webhook destination for testing failure modes.
//
//   POST /ok                 -> 200
//   POST /fail               -> 500
//   POST /flaky              -> 500 half of the time, otherwise 200
//   POST /timeout            -> never answers within the relay timeout (sleeps 60 s)
//   POST /any?mode=ok|fail|flaky|timeout&status=503&delay_ms=250
//   GET  /_received          -> last 100 requests received (JSON), for debugging
//   GET  /health             -> 200
const http = require('node:http');

const PORT = Number(process.env.PORT || 4000);
const FLAKY_FAILURE_RATE = Number(process.env.FLAKY_FAILURE_RATE || 0.5);
const received = [];

function reply(res, status, body) {
  res.writeHead(status, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify(body));
}

const server = http.createServer((req, res) => {
  const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
  const path = url.pathname.replace(/\/+$/, '') || '/';

  if (req.method === 'GET' && path === '/health') return reply(res, 200, { ok: true });
  if (req.method === 'GET' && path === '/_received') return reply(res, 200, received);

  const chunks = [];
  req.on('data', (c) => chunks.push(c));
  req.on('end', () => {
    const mode = url.searchParams.get('mode') || path.slice(1) || 'ok';
    const failStatus = Number(url.searchParams.get('status') || 500);
    const delayMs = Number(url.searchParams.get('delay_ms') || 0);

    let status = 200;
    if (mode === 'fail') status = failStatus;
    if (mode === 'flaky') status = Math.random() < FLAKY_FAILURE_RATE ? failStatus : 200;

    received.unshift({
      at: new Date().toISOString(),
      method: req.method,
      path: url.pathname + url.search,
      mode,
      status: mode === 'timeout' ? 'timeout' : status,
      headers: req.headers,
      body: Buffer.concat(chunks).toString('utf8'),
    });
    received.length = Math.min(received.length, 100);
    console.log(`${req.method} ${url.pathname}${url.search} -> ${mode === 'timeout' ? 'timeout' : status}`);

    if (mode === 'timeout') {
      setTimeout(() => reply(res, 200, { ok: true, late: true }), 60_000);
      return;
    }
    setTimeout(() => reply(res, status, { ok: status < 300, mode }), delayMs);
  });
});

server.listen(PORT, () => console.log(`mock-receiver listening on :${PORT}`));
process.on('SIGTERM', () => server.close(() => process.exit(0)));
