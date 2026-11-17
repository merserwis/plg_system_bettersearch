"""Joomla's changelog of Better Search (changelog.xml, the file <changelogurl> points to) made from CHANGELOG.md.

Joomla shows it behind the Changelog button of Extensions: Update (the version offered) and Extensions:
Manage (the version installed). The short list of a version comes from its section of CHANGELOG.md:

- a heading of a kind (### New, ### Fixes, ### Security, ### Under the hood…) gives one point for each of
  its bullets: the bold title of the bullet, or the start of its text;
- any other heading (### Themes, ### Fixed: currency choice…) is one point itself, as in MerTools.

A point is a new feature (addition) in a x.y.0 version and a fix in a x.y.z one, unless its heading says
otherwise; a comment at the end of a heading decides too: <!-- fix -->, <!-- addition -->, <!-- change -->,
<!-- security -->, <!-- remove -->. A x.y.z version (z > 0) also lists the points of the x.y versions before
it, each with its version, so an update from an older line shows all that the x.y line brings. Every
version ends with a note of two links: its GitHub release (the full list of its changes) and all releases.

Joomla's parser cuts a text into two points at a character beyond ASCII and at an entity: the points are
plain ASCII without "&", the links in CDATA.

Usage: tools/changelog_xml.py  (writes changelog.xml next to CHANGELOG.md)
"""
import re
import sys
import unicodedata
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
ELEMENT = 'pkg_bettersearch'
KINDS = ('security', 'fix', 'addition', 'change', 'remove')
RELEASES = 'https://github.com/merserwis/plg_system_bettersearch/releases'
# headings that name a kind: their bullets are the points
GROUPS = {'new': 'addition', 'fixes': 'fix', 'fixed': 'fix', 'security': 'security', 'security hardening': 'security',
          'under the hood': 'change', 'update': 'change', 'changes': 'change', 'performance': 'change'}
# points of one version at most (the rest is in the GitHub release the note links to)
MAX_POINTS = 12
PLAIN = {' — ': ': ', '—': '-', '–': '-', '→': '->', '“': '"', '”': '"', '‘': "'", '’': "'", '…': '...', '×': 'x',
         '&amp;': 'and', '&': 'and', '<': 'less than '}


def plain(text):
    text = re.sub(r'\[([^\]]+)\]\([^)]*\)', r'\1', text)          # [text](link) -> text
    text = text.replace('*', '').replace('`', '')                 # *x*, **x**, `x` (an _ is part of a name)
    for a, b in PLAIN.items():
        text = text.replace(a, b)
    text = unicodedata.normalize('NFKD', text).encode('ascii', 'ignore').decode()

    text = re.sub(r'\s+', ' ', text).strip()

    return text[:1].upper() + text[1:]


def heading(title):
    """(name without emoji and marker, kind it names or None, kind of its comment or None)"""
    mark = re.search(r'<!--\s*(\w+)\s*-->', title)
    name = re.sub(r'<!--.*?-->', '', title)
    name = re.sub(r'^[^\w"“(]+', '', name).strip()
    return name, GROUPS.get(name.lower()), (mark.group(1) if mark and mark.group(1) in KINDS else None)


def bullet(text):
    """The point of a bullet: its bold title, or the start of its text (one sentence, at most 90 characters)."""
    bold = re.match(r'\*\*(.+?)\*\*', text)
    if bold:
        title = bold.group(1)
    else:
        title = re.split(r'(?<=[a-z0-9)])\.\s', text, maxsplit=1)[0]
    title = plain(title).rstrip('.:; ')
    if len(title) > 90:
        title = title[:87].rsplit(' ', 1)[0].rstrip(',;:') + '...'
    return title


def versions(text):
    """[(version, [(kind, point)])], newest first, as in CHANGELOG.md."""
    out = []
    for m in re.finditer(r'^## (\d+\.\d+\.\d+)\b.*?$(.*?)(?=^## |\Z)', text, re.M | re.S):
        version, body = m.group(1), m.group(2)
        default = 'fix' if int(version.split('.')[2]) > 0 else 'addition'
        items = []
        # the text before the first heading belongs to no heading (a version written as bullets only)
        parts = re.split(r'^### (.+?)\s*$', body, flags=re.M)
        sections = [(None, parts[0])] + list(zip(parts[1::2], parts[2::2]))
        for title, content in sections:
            bullets = re.findall(r'^- (.+)$', content, re.M)
            if title is None:
                items += [(default, bullet(b)) for b in bullets]
                continue
            name, group, mark = heading(title)
            if group:
                items += [(mark or group, bullet(b)) for b in bullets]
            else:
                fixed = re.match(r'(?i)^fixed:\s*(.+)$', name)
                items.append((mark or ('fix' if fixed else default), plain(fixed.group(1) if fixed else name)))
        items = [(k, t) for k, t in items if t]
        out.append((version, items[:MAX_POINTS]))
    return out


def line(entries):
    """Each version with the points of the earlier versions of its x.y line (x.y.z, z > 0), marked by version."""
    out = []
    for i, (version, items) in enumerate(entries):
        major_minor = version.rsplit('.', 1)[0]
        earlier = [(k, '%s: %s' % (v, t)) for v, its in entries[i + 1:] if v.rsplit('.', 1)[0] == major_minor for k, t in its]
        out.append((version, items, earlier))
    return out


def links(version):
    """The note of a version: its release and all of them, as HTML (Joomla shows an item as it is)."""
    a = '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>'
    return ['<![CDATA[%s]]>' % (a % ('%s/tag/v%s' % (RELEASES, version), 'The full list of changes of %s (GitHub)' % version)),
            '<![CDATA[%s]]>' % (a % (RELEASES, 'All versions and their changes (GitHub releases)'))]


def xml(entries):
    lines = ['<?xml version="1.0" encoding="utf-8"?>', '<changelogs>']
    for version, items, earlier in line(entries):
        if not items:
            continue
        lines += ['    <changelog>', '        <element>%s</element>' % ELEMENT, '        <type>package</type>',
                  '        <version>%s</version>' % version]
        for kind in KINDS:
            mine = [t for k, t in items + earlier if k == kind]
            if mine:
                lines.append('        <%s>' % kind)
                lines += ['            <item>%s</item>' % t for t in mine]
                lines.append('        </%s>' % kind)
        lines.append('        <note>')
        lines += ['            <item>%s</item>' % t for t in links(version)]
        lines.append('        </note>')
        lines.append('    </changelog>')
    lines.append('</changelogs>')
    return '\n'.join(lines) + '\n'


if __name__ == '__main__':
    entries = versions((ROOT / 'CHANGELOG.md').read_text(encoding='utf-8'))
    if not entries or not entries[0][1]:
        sys.exit('no version with points in CHANGELOG.md')
    (ROOT / 'changelog.xml').write_text(xml(entries), encoding='utf-8')
    print('changelog.xml: %d versions, newest %s' % (len(entries), entries[0][0]))
