// E24 - اختبار دخان للضيوف: 5 مستخدمين افتراضيين لدقيقة. يتحقق من أن الصفحات العامة وفحصَي الحياة/الجاهزية تعمل بلا 5xx.
// تشغيل:  k6 run -e BASE_URL=http://127.0.0.1:8000 tools/load/smoke.js
import { sleep } from 'k6';
import { assertSafeTarget, visit } from './lib.js';

export const options = {
  scenarios: { smoke: { executor: 'constant-vus', vus: Number(__ENV.VUS || 5), duration: __ENV.DURATION || '1m' } },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    app_5xx: ['rate==0'],
    http_req_duration: ['p(95)<2000'], // عتبة تقريبية للنسخة المحلية؛ ليست التزامًا للإنتاج
  },
};

export function setup() {
  assertSafeTarget();
}

export default function () {
  visit('/', 'home');
  visit('/puzzles', 'puzzles');
  visit('/leaderboard', 'leaderboard');
  visit('/up', 'up');
  visit('/ready', 'ready');
  sleep(1 + Math.random() * 2); // زمن تفكير واقعي
}
