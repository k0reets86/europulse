const { performance } = require('perf_hooks');

const BASE = process.env.BASE_URL || 'http://204.168.148.47';

function percentile(values, p) {
  if (!values.length) return 0;
  const sorted = [...values].sort((a, b) => a - b);
  const idx = Math.min(sorted.length - 1, Math.max(0, Math.ceil((p / 100) * sorted.length) - 1));
  return sorted[idx];
}

async function hit(url) {
  const start = performance.now();
  const res = await fetch(url, {
    headers: {
      'user-agent': 'EuroPulse-Stress/1.0',
    },
  });
  await res.arrayBuffer();
  const end = performance.now();
  return {
    url,
    status: res.status,
    ms: end - start,
    cache: res.headers.get('x-fastcgi-cache') || '',
  };
}

async function runScenario(name, buildUrl, total, concurrency) {
  const latencies = [];
  const statuses = {};
  const cacheStates = {};
  let completed = 0;
  let cursor = 0;
  const started = performance.now();

  async function worker() {
    while (true) {
      const index = cursor++;
      if (index >= total) return;
      const result = await hit(buildUrl(index));
      latencies.push(result.ms);
      statuses[result.status] = (statuses[result.status] || 0) + 1;
      cacheStates[result.cache || 'NONE'] = (cacheStates[result.cache || 'NONE'] || 0) + 1;
      completed++;
    }
  }

  await Promise.all(Array.from({ length: concurrency }, () => worker()));

  const durationMs = performance.now() - started;
  return {
    name,
    total,
    concurrency,
    duration_ms: Math.round(durationMs),
    req_per_sec: Number((total / (durationMs / 1000)).toFixed(2)),
    p50_ms: Number(percentile(latencies, 50).toFixed(2)),
    p95_ms: Number(percentile(latencies, 95).toFixed(2)),
    p99_ms: Number(percentile(latencies, 99).toFixed(2)),
    max_ms: Number(Math.max(...latencies).toFixed(2)),
    statuses,
    cache_states: cacheStates,
  };
}

(async () => {
  // Warm cache.
  await hit(`${BASE}/`);
  await hit(`${BASE}/`);

  const scenarios = [];

  scenarios.push(await runScenario(
    'cached_home',
    () => `${BASE}/`,
    1500,
    50
  ));

  scenarios.push(await runScenario(
    'mixed_dynamic_uncached',
    (i) => {
      const urls = [
        `${BASE}/?cb=${Date.now()}-${i}`,
        `${BASE}/?p=398&cb=${Date.now()}-${i}`,
        `${BASE}/?cat=14&cb=${Date.now()}-${i}`,
        `${BASE}/?lang=uk&cb=${Date.now()}-${i}`,
        `${BASE}/?lang=en&cb=${Date.now()}-${i}`,
        `${BASE}/?s=Integration&cb=${Date.now()}-${i}`,
        `${BASE}/?lang=uk&s=%D0%A3%D0%BA%D1%80%D0%B0%D1%97%D0%BD%D0%B0&cb=${Date.now()}-${i}`,
      ];
      return urls[i % urls.length];
    },
    500,
    20
  ));

  console.log(JSON.stringify({ generated_at: new Date().toISOString(), scenarios }, null, 2));
})();
