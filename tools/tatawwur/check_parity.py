#!/usr/bin/env python3
"""
TATAWWUR-FINAL - فحص تكافؤ الخصائص بين PHP (Featurizer::counts) وPython (train_intent_model.counts) على عيّنة مثبّتة داخل المستودع:
tests/Feature/TatawwurLocalEngine/Datasets/learned_parity_fixture.json. أي اختلاف ⇒ رمز خروج 1 (وإلا فالنموذج المدرَّب لا يطابق ما يحسبه PHP عند الخدمة).
الاستعمال: python3 -I tools/tatawwur/check_parity.py [المسار-إلى-fixture]
"""
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import train_intent_model as T  # noqa: E402


def main():
    path = sys.argv[1] if len(sys.argv) > 1 else os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'tests', 'Feature', 'TatawwurLocalEngine', 'Datasets', 'learned_parity_fixture.json')
    rows = json.load(open(path, encoding='utf-8'))
    bad = 0
    for i, r in enumerate(rows):
        word, char = T.counts(r['tokens'])
        php_word, php_char = r['counts']['word'], r['counts']['char']
        if dict(word) != {k: int(v) for k, v in php_word.items()} or dict(char) != {k: int(v) for k, v in php_char.items()}:
            bad += 1
            print('MISMATCH row', i)
    print('rows=%d mismatches=%d' % (len(rows), bad))
    return 1 if bad else 0


if __name__ == '__main__':
    sys.exit(main())
