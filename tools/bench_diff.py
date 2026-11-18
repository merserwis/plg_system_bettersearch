"""Summary of a run of tools/bench.php, or the differences between two runs (before and after a change).

  python3 tools/bench_diff.py run.jsonl               summary: queries at the candidate limit, times
  python3 tools/bench_diff.py before.jsonl after.jsonl  what changed: totals, the first results, times

A change of the first results is shown by query and sort: the positions of the first 5 results of the
run before in the run after (- = not among the first 30 any more).
"""
import json
import sys


def load(path):
    rows = {}
    for line in open(path, encoding='utf-8'):
        line = line.strip()
        if line.startswith('{'):
            r = json.loads(line)
            rows[(r['q'], r['sort'])] = r
    return rows


def times(rows):
    ms = sorted(r['ms'] for r in rows.values())
    return 'p50 %.1f ms, p95 %.1f ms, max %.1f ms' % (ms[len(ms) // 2], ms[min(len(ms) - 1, int(len(ms) * .95))], ms[-1])


def summary(rows):
    print('%d searches; time %s' % (len(rows), times(rows)))
    limit = [(k, r['total']) for k, r in rows.items() if r['truncated']]
    print('at the candidate limit: %d' % len(limit))
    for (q, sort), total in limit:
        print('  %-28s %-10s total %d' % (q, sort, total))
    slow = sorted(rows.items(), key=lambda kv: -kv[1]['ms'])[:5]
    print('slowest: ' + '; '.join('%s/%s %.0f ms (%d)' % (q, s, r['ms'], r['total']) for (q, s), r in slow))


def diff(a, b):
    print('before: %s\nafter:  %s' % (times(a), times(b)))
    same = 0
    for key in a:
        if key not in b:
            print('MISSING after: %s' % (key,))
            continue
        x, y = a[key], b[key]
        notes = []
        if x['total'] != y['total']:
            notes.append('total %d -> %d' % (x['total'], y['total']))
        if x['mode'] != y['mode']:
            notes.append('mode %s -> %s' % (x['mode'], y['mode']))
        if x['truncated'] != y['truncated']:
            notes.append('limit %s -> %s' % (x['truncated'], y['truncated']))
        if x['ids'][:10] != y['ids'][:10]:
            moved = []
            for i, pid in enumerate(x['ids'][:5]):
                j = y['ids'].index(pid) + 1 if pid in y['ids'] else '-'
                if j != i + 1:
                    moved.append('#%d->%s' % (i + 1, j))
            new = [pid for pid in y['ids'][:5] if pid not in x['ids'][:10]]
            notes.append('first 10 differ' + (' (' + ', '.join(moved) + ')' if moved else '') + (' new in top 5: %s' % new if new else ''))
        if notes:
            print('%-30s %-10s %s' % (key[0][:30], key[1], '; '.join(notes)))
        else:
            same += 1
    print('unchanged: %d of %d' % (same, len(a)))


if __name__ == '__main__':
    if len(sys.argv) == 2:
        summary(load(sys.argv[1]))
    else:
        diff(load(sys.argv[1]), load(sys.argv[2]))
