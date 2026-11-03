#!/usr/bin/env python3
"""
TATAWWUR-FINAL - مدرّب المصنِّف التعلّمي المحلي للنوايا (يعمل **خارج** الإنتاج وبلا شبكة؛ لا يُشغَّل أثناء خدمة الطلبات).

المدخلات (ينتجها tests/Feature/TatawwurLocalEngine/TIFinalExportTest.php بـ TI_EVAL=1 TI_EVAL_OUT=<dir>):
  train-export.jsonl : سؤال بكلماته المطوية (Featurizer::tokens) وتسميته، من مجموعات الضبط فقط (لا مجموعة قبول).
  train-docs.json    : نصوص أقسام المقالات المنشورة المرتبطة بكل نية معرفية.

المخرج: app/Services/AiAssistant/Local/Learned/model/intent_model.php (ملف PHP يعيد مصفوفة أوزان).
الخوارزمية: TF-IDF (كلمات مفردة وثنائية + n-gram حرفية 2-5 بحدود كلمة، tf لوغاريتمي، تطبيع L2 لكل كتلة) + انحدار لوجستي متعدد الفئات + تقليم الخصائص.
لا تضمينات ولا نموذج لغوي. المكتبات: numpy, scipy, scikit-learn (للتدريب فقط). التكرار: بذور ثابتة، فيعيد التشغيل نفس الملف بنفس المدخلات.

الاستعمال: python3 -I tools/tatawwur/train_intent_model.py --export <dir> --out <path> [--aug 1] [--C 10] [--keep 24000]
"""
import argparse
import collections
import hashlib
import json
import math
import os
import random
import sys

import numpy as np
from scipy.sparse import csr_matrix, hstack
from sklearn.feature_extraction import DictVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.model_selection import StratifiedKFold

TRAIN_SOURCES = ('gold_dev', 'gold_dev_q1', 'gold_dev_final', 'gold_dev_final_extra', 'gold_dev_followups')  # مجموعات الضبط فقط؛ held-out وV2 وV3 لا تدخل التدريب إطلاقًا.
DIAL = {'ما': ['شو', 'ايش', 'وش', 'شنو'], 'ماذا': ['شو', 'ايش', 'وش', 'شنو', 'ايه'], 'كيف': ['كيفاش', 'ازاي', 'شلون', 'كيف'], 'لماذا': ['ليش', 'ليه', 'علاش'],
        'اين': ['وين', 'فين', 'فاين'], 'متي': ['امتي', 'وقتاش', 'متا'], 'اريد': ['بدي', 'ابغي', 'ابي', 'عايز', 'بغيت'], 'استطيع': ['بقدر', 'اقدر', 'نقدر'],
        'يمكنني': ['بقدر', 'اقدر', 'ينفع', 'نقدر'], 'احصل': ['اجيب', 'اخد', 'ناخد'], 'اكسب': ['اربح', 'بكسب', 'نكسب'], 'الان': ['هلق', 'دلوقتي', 'الحين', 'دابا', 'هسا'],
        'الذي': ['اللي'], 'التي': ['اللي'], 'هل': ['هل', 'ولا', 'واش'], 'كم': ['كم', 'شحال', 'قديش'], 'يوجد': ['فيه', 'في', 'كاين'], 'هناك': ['فيه', 'في', 'كاين'],
        'ايضا': ['كمان', 'برضو'], 'كثير': ['كتير', 'بزاف', 'واجد'], 'جدا': ['كتير', 'اوي', 'بزاف']}
CONF = {'ت': 'ط', 'ط': 'ت', 'س': 'ص', 'ص': 'س', 'ض': 'ظ', 'ظ': 'ض', 'ذ': 'ز', 'ز': 'ذ', 'ك': 'ق', 'غ': 'ق'}


def counts(tokens):
    word, char = collections.Counter(), collections.Counter()
    for i, t in enumerate(tokens):
        word['w:' + t] += 1
        if i + 1 < len(tokens):
            word['b:' + t + ' ' + tokens[i + 1]] += 1
        chars = list(' ' + t + ' ')
        size = len(chars)
        for n in range(2, 6):
            off = 0
            char['c:' + ''.join(chars[off:off + n])] += 1
            while off + n < size:
                off += 1
                char['c:' + ''.join(chars[off:off + n])] += 1
            if off == 0:
                break
    return dict(word), dict(char)


def dialect(tokens, rng):
    return [rng.choice(DIAL[t]) if t in DIAL and rng.random() < 0.7 else t for t in tokens]


def typo(tokens, rng, p=0.3):
    out = []
    for t in tokens:
        if len(t) >= 4 and rng.random() < p:
            op = rng.choice(['drop', 'swap', 'conf', 'dbl'])
            i = rng.randrange(1, len(t) - 1)
            if op == 'drop':
                t = t[:i] + t[i + 1:]
            elif op == 'swap' and i + 1 < len(t):
                t = t[:i] + t[i + 1] + t[i] + t[i + 2:]
            elif op == 'conf' and t[i] in CONF:
                t = t[:i] + CONF[t[i]] + t[i + 1:]
            elif op == 'dbl':
                t = t[:i] + t[i] + t[i:]
        out.append(t)
    return out


class Tfidf:
    def __init__(self, keep=None):
        self.keep = keep

    def fit(self, docs):
        self.dv = [DictVectorizer(sparse=True), DictVectorizer(sparse=True)]
        self.idf = []
        for b in (0, 1):
            X = self.dv[b].fit_transform([d[b] for d in docs])
            df = np.asarray((X > 0).sum(axis=0)).ravel()
            self.idf.append(np.log((1 + X.shape[0]) / (1 + df)) + 1.0)
        return self

    def restrict(self, keep_features):
        """يحصر المفردات في مجموعة مُبقاة (يُعاد التدريب بعدها كي يتسق التطبيع مع وقت التشغيل)."""
        for b in (0, 1):
            names = self.dv[b].feature_names_
            idx = [i for i, n in enumerate(names) if n in keep_features]
            self.idf[b] = self.idf[b][idx]
            self.dv[b].restrict([i in set(idx) for i in range(len(names))])
        return self

    def transform(self, docs):
        mats = []
        for b in (0, 1):
            X = self.dv[b].transform([d[b] for d in docs]).tocsr().astype(float)
            X.data = 1.0 + np.log(X.data)
            X = X.multiply(self.idf[b]).tocsr()
            nrm = np.sqrt(np.asarray(X.multiply(X).sum(axis=1)).ravel())
            nrm[nrm == 0] = 1
            mats.append(csr_matrix(X.multiply(1.0 / nrm[:, None])))
        return hstack(mats).tocsr()

    def names(self):
        return list(self.dv[0].feature_names_) + list(self.dv[1].feature_names_)

    def idfs(self):
        return list(self.idf[0]) + list(self.idf[1])


def build_training(args):
    rows = [json.loads(l) for l in open(args.export + '/train-export.jsonl', encoding='utf-8')]
    docs = json.load(open(args.export + '/train-docs.json', encoding='utf-8'))
    seen, train = set(), []
    for r in rows:
        if r['src'] not in TRAIN_SOURCES:
            continue
        key = (r['label'], tuple(r['tokens']))
        if key in seen:
            continue
        seen.add(key)
        train.append({'label': r['label'], 'tokens': r['tokens']})
    rng = random.Random(11)
    out = []
    for r in train:
        out.append(r)
        if args.aug:
            for toks in (dialect(r['tokens'], rng), typo(r['tokens'], rng), typo(dialect(r['tokens'], rng), rng)):
                out.append({'label': r['label'], 'tokens': toks})
    out += [{'label': d['label'], 'tokens': d['tokens']} for d in docs if d['tokens']]
    return out, rows, docs


def fit(train, C, keep=None):
    tf = Tfidf().fit([counts(r['tokens']) for r in train])
    X = tf.transform([counts(r['tokens']) for r in train])
    y = [r['label'] for r in train]
    m = LogisticRegression(C=C, max_iter=3000)
    m.fit(X, y)
    if keep:
        W = np.abs(m.coef_).max(axis=0)
        order = np.argsort(-W)[:keep]
        names = tf.names()
        keep_set = {names[i] for i in order}
        tf = Tfidf().fit([counts(r['tokens']) for r in train])
        tf.restrict(keep_set)
        X = tf.transform([counts(r['tokens']) for r in train])
        m = LogisticRegression(C=C, max_iter=3000)
        m.fit(X, y)
    return tf, m


def calibrate(train, C, keep):
    """حرارة softmax من تحقق متقاطع 4 طيّات (تقلل log-loss)."""
    y = [r['label'] for r in train]
    cnt = collections.Counter(y)
    strata = [l if cnt[l] >= 4 else 'rare' for l in y]
    zs, ys, mapping = [], [], None
    for tr, te in StratifiedKFold(4, shuffle=True, random_state=5).split(train, strata):
        tf, m = fit([train[i] for i in tr], C, keep)
        Z = m.decision_function(tf.transform([counts(train[i]['tokens']) for i in te]))
        cls = list(m.classes_)
        for z, i in zip(Z, te):
            if y[i] in cls:
                zs.append(z)
                ys.append(cls.index(y[i]))
    best, bt = 1e9, 1.0
    for t in np.arange(0.5, 3.01, 0.25):
        nll = 0.0
        for z, k in zip(zs, ys):
            s = z * t
            s = s - s.max()
            nll -= s[k] - math.log(np.exp(s).sum())
        if nll < best:
            best, bt = nll, float(t)
    return bt


SPECIAL = ('oos', 'unknown', 'ambiguous')


def domain_vocabulary(train):
    """كلمات المجال: كلمات (≥3 أحرف) ترد في أسئلة نوايا المنصة ونصوص مقالاتها، وتُستبعد كلمات ترد في أسئلة خارج المجال أكثر مما ترد في أسئلة المجال."""
    inside, outside = collections.Counter(), collections.Counter()
    n_in = n_out = 0
    for r in train:
        lab = r['label']
        special = lab in SPECIAL or lab.startswith('blocked.')
        words = {t for t in r['tokens'] if len(t) >= 3}
        if special:
            if lab == 'oos':
                outside.update(words)
                n_out += 1
        else:
            inside.update(words)
            n_in += 1
    dom = []
    for w, c in inside.items():
        if outside[w] * 3 > c and outside[w] >= 2:
            continue
        dom.append(w)
    return sorted(dom)


def php_value(v):
    return repr(round(float(v), 4)) if isinstance(v, (float, np.floating)) else str(v)


def write_php(path, tf, m, temperature, meta, dom):
    classes = list(m.classes_)
    names, idf = tf.names(), tf.idfs()
    W = m.coef_
    lines = ['<?php', '', '// TATAWWUR-FINAL - أوزان المصنِّف التعلّمي المحلي. ملف مولَّد آليًا بـ tools/tatawwur/train_intent_model.py؛ لا يُعدَّل يدويًا.', 'return [']
    lines.append("    'meta' => " + json.dumps(meta, ensure_ascii=False, sort_keys=True).replace('{', '[').replace('}', ']').replace('":', '" =>') + ',')
    lines.append("    'temperature' => " + php_value(temperature) + ',')
    lines.append("    'classes' => [" + ','.join("'" + c.replace("'", "\\'") + "'" for c in classes) + '],')
    lines.append("    'bias' => [" + ','.join(php_value(b) for b in m.intercept_) + '],')
    lines.append("    'dom' => [" + ','.join("'" + w.replace('\\', '\\\\').replace("'", "\\'") + "'=>1" for w in dom) + '],')
    lines.append("    'f' => [")
    kept = 0
    for j, name in enumerate(names):
        col = W[:, j]
        nz = [(i, w) for i, w in enumerate(col) if abs(w) >= 0.15]
        if not nz:
            continue
        kept += 1
        nz.sort(key=lambda x: -abs(x[1]))
        body = ','.join('%d=>%s' % (i, php_value(w)) for i, w in nz[:10])
        lines.append("        '%s' => [%s,[%s]]," % (name.replace('\\', '\\\\').replace("'", "\\'"), php_value(idf[j]), body))
    lines.append('    ],')
    lines.append('];')
    tmp = path + '.tmp'
    open(tmp, 'w', encoding='utf-8').write('\n'.join(lines) + '\n')
    os.replace(tmp, path)   # كتابة ذرّية: لا يرى قارئ ملفًا نصف مكتوب
    return kept


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--export', required=True)
    ap.add_argument('--out', required=True)
    ap.add_argument('--aug', type=int, default=1)
    ap.add_argument('--C', type=float, default=10.0)
    ap.add_argument('--keep', type=int, default=24000)
    args = ap.parse_args()
    train, rows, docs = build_training(args)
    sources = sorted({r['src'] for r in rows if r['src'] in TRAIN_SOURCES})
    meta = {
        'version': 1, 'algorithm': 'tfidf(word1-2+char_wb2-5)+multinomial-logistic-regression', 'sources': sources, 'aug': args.aug, 'C': args.C,
        'train_rows': len(train), 'classes': len({r['label'] for r in train}),
        'export_sha256': hashlib.sha256(open(args.export + '/train-export.jsonl', 'rb').read()).hexdigest(),
        'docs_sha256': hashlib.sha256(open(args.export + '/train-docs.json', 'rb').read()).hexdigest(),
    }
    temperature = calibrate(train, args.C, args.keep)
    tf, m = fit(train, args.C, args.keep)
    kept = write_php(args.out, tf, m, temperature, meta, domain_vocabulary(train))
    print(json.dumps({'features_written': kept, 'temperature': temperature, **meta}, ensure_ascii=False))


if __name__ == '__main__':
    sys.exit(main())
