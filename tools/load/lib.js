// E24 - مساعدات مشتركة لسكربتات k6. لا تحوي أي سرّ؛ كلمة مرور المستخدمين الاصطناعيين معروفة لأنها لبيانات performance:seed فقط.
import http from 'k6/http';
import { check } from 'k6';
import { Rate, Trend } from 'k6/metrics';

export const BASE = (__ENV.BASE_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');
export const PASSWORD = __ENV.PERF_PASSWORD || 'perf-seed-not-a-real-password';
export const USERS = parseInt(__ENV.PERF_USERS || '1000', 10); // يجب ألا يتجاوز عدد من زرعهم performance:seed

export const app5xx = new Rate('app_5xx');
export const loginOk = new Rate('login_ok');
export const pageMs = new Trend('page_ms', true);

/**
 * حارس أمان: لا يعمل ضد مضيف يبدو إنتاجيًا. المسموح تلقائيًا: localhost / 127.0.0.1 / *.test / *.local / *.localhost.
 * أي مضيف آخر (staging) يتطلب ALLOW_NON_LOCAL=yes صراحةً. لا تشغّل أبدًا على الإنتاج الحقيقي.
 */
export function assertSafeTarget() {
  const host = BASE.replace(/^https?:\/\//, '').split('/')[0].split(':')[0];
  const local = host === 'localhost' || host === '127.0.0.1' || /\.(test|local|localhost)$/.test(host);

  if (!local && __ENV.ALLOW_NON_LOCAL !== 'yes') {
    throw new Error(`Refusing to load-test ${host}: not a local/test host. Set ALLOW_NON_LOCAL=yes only for a dedicated staging environment (never production).`);
  }
}

export function userEmail(n) {
  return `perf${n}@perf.ahjiyat.test`;
}

/** تسجيل دخول عبر النموذج الحقيقي (CSRF حقيقي) - الكوكيز تُحفظ تلقائيًا لكل VU. */
export function login(n) {
  const page = http.get(`${BASE}/login`);
  const m = page.body && page.body.match(/name="_token"\s+value="([^"]+)"/);

  if (!m) {
    loginOk.add(false);
    return false;
  }

  const res = http.post(`${BASE}/login`, { _token: m[1], email: userEmail(n), password: PASSWORD }, { redirects: 5 });
  const ok = check(res, { 'login ends on an authenticated page': (r) => r.status === 200 && !/name="password"/.test(r.body || '') });
  loginOk.add(ok);

  return ok;
}

/** GET مع تسجيل زمن الصفحة وفحص 5xx (أي 5xx فشل حقيقي حتى لو سمحت عتبة الأخطاء العامة). */
export function visit(path, tag) {
  const res = http.get(`${BASE}${path}`, { tags: { page: tag || path } });
  pageMs.add(res.timings.duration, { page: tag || path });
  app5xx.add(res.status >= 500);
  check(res, { [`${tag || path} is 2xx/3xx`]: (r) => r.status < 400 });

  return res;
}
