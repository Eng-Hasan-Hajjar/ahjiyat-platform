// E24 - رحلات مستخدمين مسجّلين (قراءة فقط): الرئيسية، الأحجيات، لوحة الصدارة، الإشعارات، المحفظة، الدردشة العامة (قراءة).
// لا تنفّذ أي كتابة تغيّر الاقتصاد (لا حل أحجيات، لا مكافآت، لا شراء) - الهدف قياس كلفة التصفح لا تضخيم البيانات.
// المتطلبات: php artisan performance:seed small  (يزرع perf1..perfN@perf.ahjiyat.test) ثم تشغيل التطبيق محليًا.
// تشغيل:  k6 run -e PROFILE=light -e BASE_URL=http://127.0.0.1:8000 tools/load/journeys.js
// الملفات: smoke(5) | light(25) | s50 | s100 | s500 | s1000.  ما فوق light يُشغَّل على staging مخصّص فقط (انظر README).
import { sleep } from 'k6';
import { assertSafeTarget, login, visit, USERS } from './lib.js';

const PROFILES = {
  smoke: [{ duration: '20s', target: 5 }, { duration: '40s', target: 5 }, { duration: '10s', target: 0 }],
  light: [{ duration: '30s', target: 25 }, { duration: '2m', target: 25 }, { duration: '20s', target: 0 }],
  s50: [{ duration: '1m', target: 50 }, { duration: '5m', target: 50 }, { duration: '30s', target: 0 }],
  s100: [{ duration: '2m', target: 100 }, { duration: '5m', target: 100 }, { duration: '1m', target: 0 }],
  s500: [{ duration: '5m', target: 500 }, { duration: '10m', target: 500 }, { duration: '2m', target: 0 }],
  s1000: [{ duration: '10m', target: 1000 }, { duration: '10m', target: 1000 }, { duration: '3m', target: 0 }],
};

const profile = __ENV.PROFILE || 'smoke';

export const options = {
  scenarios: { journeys: { executor: 'ramping-vus', startVUs: 0, stages: PROFILES[profile] || PROFILES.smoke, gracefulRampDown: '30s' } },
  thresholds: {
    http_req_failed: ['rate<0.02'],
    app_5xx: ['rate<0.005'],
    login_ok: ['rate>0.98'],
    'page_ms{page:home}': ['p(95)<1500'],
    'page_ms{page:puzzles}': ['p(95)<1500'],
    'page_ms{page:leaderboard}': ['p(95)<1500'],
  },
};

export function setup() {
  assertSafeTarget();
}

let loggedIn = false;

export default function () {
  if (!loggedIn) {
    // مستخدم ثابت لكل VU (يتوزّع على المستخدمين المزروعين)؛ كوكيز الجلسة تبقى طوال الاختبار.
    loggedIn = login(((__VU - 1) % USERS) + 1);
    if (!loggedIn) {
      sleep(5);
      return;
    }
  }

  const roll = Math.random();
  visit('/', 'home');
  sleep(1 + Math.random() * 3);

  if (roll < 0.35) {
    visit('/puzzles', 'puzzles');
  } else if (roll < 0.55) {
    visit('/leaderboard', 'leaderboard');
  } else if (roll < 0.70) {
    visit('/notifications', 'notifications');
  } else if (roll < 0.82) {
    visit('/wallet', 'wallet');
  } else {
    visit('/community/chat', 'chat');
  }

  sleep(2 + Math.random() * 4);
}
