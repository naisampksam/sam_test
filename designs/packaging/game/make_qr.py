#!/usr/bin/env python3
"""Writes qr-dojo-run.json: the QR module matrix for the Dojo Run game link (pip install segno)."""
import json
import os

import segno

URL = 'https://haikufit.in/pages/dojo-run'
qr = segno.make(URL, error='h')  # high error correction survives scuffs and small print
rows = [''.join('1' if v else '0' for v in row) for row in qr.matrix]
json.dump({'url': URL, 'rows': rows}, open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'qr-dojo-run.json'), 'w'))
print(URL, f'{len(rows)}x{len(rows)} modules')
