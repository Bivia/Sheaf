#!/usr/bin/env python3
"""
scramble-docx.py — make a .docx unintelligible while preserving every bit of
its formatting, so it can be shared for structural testing without exposing the
real text.

Each letter becomes a random letter (same case) and each digit a random digit,
INDEPENDENTLY per character — so nothing can be reversed or frequency-analysed.
All punctuation, symbols (•, *, …), whitespace, blank paragraphs, page breaks,
section breaks, heading styles, and every other formatting construct are left
exactly as they were. Hidden metadata (docProps: title, author, chapter list)
is scrambled too.

Usage:
    python3 scramble-docx.py "My Manuscript.docx"
    # writes "My Manuscript.scrambled.docx" beside it

Stock Python 3, standard library only. It uploads nothing — run it on your own
machine and share only the .scrambled.docx it produces.
"""

import os
import re
import sys
import html
import random
import zipfile
from xml.sax.saxutils import escape


def _scramble_plain(text):
    """Randomise letters and digits per character; leave everything else."""
    out = []
    for ch in text:
        if 'a' <= ch <= 'z':
            out.append(chr(random.randint(ord('a'), ord('z'))))
        elif 'A' <= ch <= 'Z':
            out.append(chr(random.randint(ord('A'), ord('Z'))))
        elif '0' <= ch <= '9':
            out.append(chr(random.randint(ord('0'), ord('9'))))
        else:
            out.append(ch)
    return ''.join(out)


def _scramble_xml_text(raw):
    """raw is XML-escaped text; decode → scramble → re-escape so entities such
    as &amp; and numeric refs such as &#8226; (•••) are never corrupted."""
    plain = html.unescape(raw)
    return escape(_scramble_plain(plain))


# Only the inner text of <w:t> runs — keeps style names, breaks, etc. intact.
_WT = re.compile(r'(<w:t\b[^>]*>)(.*?)(</w:t>)', re.S)
# Any text node between tags — used for docProps metadata (title, author, …).
_TEXT = re.compile(r'>([^<]+)<')


def _process_wt(xml_bytes):
    s = xml_bytes.decode('utf-8')
    s = _WT.sub(lambda m: m.group(1) + _scramble_xml_text(m.group(2)) + m.group(3), s)
    return s.encode('utf-8')


def _process_textnodes(xml_bytes):
    s = xml_bytes.decode('utf-8')
    s = _TEXT.sub(lambda m: '>' + _scramble_xml_text(m.group(1)) + '<', s)
    return s.encode('utf-8')


def scramble(path):
    base, ext = os.path.splitext(path)
    out_path = base + '.scrambled' + ext
    with zipfile.ZipFile(path) as zin, \
            zipfile.ZipFile(out_path, 'w', zipfile.ZIP_DEFLATED) as zout:
        for item in zin.infolist():
            data = zin.read(item.filename)
            name = item.filename
            if re.match(r'word/.*\.xml$', name):
                data = _process_wt(data)          # scramble visible text runs
            elif re.match(r'docProps/.*\.xml$', name):
                data = _process_textnodes(data)   # scramble hidden metadata
            # everything else (styles, numbering, media, rels) is copied as-is
            zout.writestr(item, data)
    return out_path


if __name__ == '__main__':
    if len(sys.argv) != 2:
        sys.exit('Usage: python3 scramble-docx.py <file.docx>')
    src = sys.argv[1]
    if not src.lower().endswith('.docx'):
        sys.exit('Please pass a .docx file.')
    print('Wrote', scramble(src))
