"""Turn the RESOK asset register spreadsheet into a seed file the migration runner can apply.

Reads RESOK_ASSET_DATA VIRTUAL.xlsx and writes resok-portal/server/seed-ict-assets.sql.

Decisions worth knowing, because they are judgement calls on somebody else's data:

  The barcode is kept as the asset tag. The register numbers assets 000001 upwards and those
  numbers are on physical stickers, so renumbering them to ICT-LAP-0001 would make the
  system disagree with the shelf. New assets added through the portal get the new format;
  the two coexist deliberately.

  CONDITION maps GOOD to good, BAD to poor and FAULTY to damaged. That middle one is a
  guess - "BAD" could mean broken or merely worn - so the original word is preserved in the
  notes of every row and can be re-read later.

  A custodian becomes an open assignment rather than a column, because that is how the rest
  of the module works. Assignments are dated to the acquisition date, which is the earliest
  defensible date; nobody recorded when each item was actually handed over.

  DONOR is the funder, not the supplier, so it goes to notes rather than the supplier column.
  Attributing equipment to the wrong grant is the kind of error an audit finds.

Usage:  python tools/dev/import_asset_register.py [path-to-xlsx]
"""
import io
import os
import re
import sys

import openpyxl

SOURCE = sys.argv[1] if len(sys.argv) > 1 else \
    os.path.join(os.path.expanduser('~'), 'Downloads', 'RESOK_ASSET_DATA VIRTUAL.xlsx')
TARGET = os.path.join('resok-portal', 'server', 'seed-ict-assets.sql')
SHEET = 'RESOK ASSETS DATA 2025'
LAST_DATA_ROW = 174          # rows beyond this are the sign-off block, not assets

# Matched in order, first hit wins - so "COMPUTER MONITOR" is a monitor, not a desktop.
CATEGORY_RULES = [
    (r'MONITOR',                                   'monitor'),
    (r'\bTAB\b|TABLET|IPAD|GALAXY TAB',            'tablet'),
    (r'LAPTOP|THINK ?PAD|IDEAPAD|FLEX 5|NOTEBOOK', 'laptop'),
    (r'DESKTOP|COMPUTER CPU|COMPUTOR|\bCPU\b',     'desktop'),
    (r'PHONE|HEADSET',                             'phone'),
    (r'PRINTER',                                   'printer'),
    (r'SCANNER',                                   'scanner'),
    (r'PROJECTOR',                                 'projector'),
    (r'SERVER',                                    'server'),
    (r'\bUPS\b',                                   'ups'),
    (r'ROUTER|SWITCH|ACCESS POINT',                'router'),
    (r'SPIROMETER|SPIROMETRY|SYRINGE|FILTER|PNEUMOTRAC', 'medical'),
    (r'CHAIR|DESK|CABINET|CREDENZA|WORK ?STATION|PEDESTAL|COUNTER|SAFE|BOARD|SHELF',
                                                   'furniture'),
    (r'MICROWAVE|HEATER|DISPENSER|URN|KETTLE|FRIDGE', 'appliance'),
    (r'BINDING|LIMINATOR|LAMINAT|CUTTER|SHREDDER', 'office'),
    (r'RECORDER|\bTV\b|TELEVISION',                'accessory'),
]

MANUFACTURERS = ['LENOVO', 'ASUS', 'HP', 'HEWLETT PACKARD', 'DELL', 'SAMSUNG', 'SUMSUNG',
                 'SONY', 'EPSON', 'KYOCERA', 'RICOH', 'PANASONIC', 'APC', 'MECER',
                 'TCL', 'REAL ME', 'COMPACT']

CONDITION_MAP = {'GOOD': 'good', 'BAD': 'poor', 'FAULTY': 'damaged'}


def q(value):
    """SQL string literal, or NULL."""
    if value is None:
        return 'NULL'
    text = str(value).strip()
    if text == '':
        return 'NULL'
    return "'" + text.replace('\\', '\\\\').replace("'", "''") + "'"


def category_for(description):
    upper = (description or '').upper()
    for pattern, name in CATEGORY_RULES:
        if re.search(pattern, upper):
            return name
    return 'other'


def manufacturer_for(description):
    upper = (description or '').upper()
    for maker in MANUFACTURERS:
        if maker in upper:
            return 'Samsung' if maker in ('SAMSUNG', 'SUMSUNG') else maker.title()
    return None


def tidy(value):
    if value is None:
        return None
    text = re.sub(r'\s+', ' ', str(value)).strip()
    return text or None


def as_date(value):
    if value is None:
        return None
    if hasattr(value, 'strftime'):
        return value.strftime('%Y-%m-%d')
    text = str(value).strip()
    m = re.match(r'^(\d{1,2})[./-](\d{1,2})[./-](\d{4})$', text)
    if m:                                    # 18.03.2023 - day first, as written locally
        return '%s-%s-%s' % (m.group(3), m.group(2).zfill(2), m.group(1).zfill(2))
    m = re.match(r'^(\d{4})-(\d{2})-(\d{2})', text)
    return m.group(0) if m else None


def as_money(value):
    if value is None:
        return None
    try:
        amount = float(str(value).replace(',', '').strip())
    except ValueError:
        return None
    return None if amount < 0 else round(amount, 2)


def main():
    if not os.path.exists(SOURCE):
        print('Cannot find %s' % SOURCE)
        return 1

    ws = openpyxl.load_workbook(SOURCE, data_only=True)[SHEET]
    headers = {str(ws.cell(row=1, column=c).value).strip(): c
               for c in range(1, 20) if ws.cell(row=1, column=c).value}

    def cell(row, name):
        col = headers.get(name)
        return ws.cell(row=row, column=col).value if col else None

    assets, assignments = [], []
    counts, skipped = {}, 0
    seen_tags, duplicates = {}, []

    for row in range(2, LAST_DATA_ROW + 1):
        barcode = tidy(cell(row, 'BARCODE'))
        description = tidy(cell(row, 'ASSET DESCRIPTION'))
        if not barcode or not description:
            skipped += 1
            continue

        # A barcode used twice is two physical stickers reading the same number, which no
        # audit can resolve. The spreadsheet does not complain; the database would silently
        # let the second row overwrite the first. Reported instead, because losing an asset
        # to a duplicate tag is worse than an import that stops and says why.
        if barcode in seen_tags:
            duplicates.append((barcode, seen_tags[barcode], description))
            continue
        seen_tags[barcode] = description

        category = category_for(description)
        counts[category] = counts.get(category, 0) + 1

        raw_condition = (tidy(cell(row, 'CONDITION')) or 'GOOD').upper()
        condition = CONDITION_MAP.get(raw_condition, 'good')
        custodian = tidy(cell(row, 'CUSTODIAN'))
        acquired = as_date(cell(row, 'DATE OF ACQUISITION'))

        note_parts = ['Imported from the 2025 asset register.']
        donor = tidy(cell(row, 'DONOR'))
        if donor:
            note_parts.append('Funded by: %s.' % donor)
        note_parts.append('Register condition: %s.' % raw_condition)
        available = (tidy(cell(row, 'AVAILABILITY')) or '').upper()
        if available == 'NO':
            note_parts.append('Register marked this as not available.')

        assets.append({
            'tag': barcode,
            'category': category,
            'name': description,
            'manufacturer': manufacturer_for(description),
            'model': tidy(cell(row, 'MODEL NO.')),
            'serial': tidy(cell(row, 'SERIAL NO.')),
            'purchased': acquired,
            'cost': as_money(cell(row, 'OPENING COST')),
            'condition': condition,
            # A custodian means somebody is holding it; that is what assigned means here.
            'status': 'assigned' if custodian else 'available',
            'location': tidy(cell(row, 'LOCATION')),
            'department': tidy(cell(row, 'DEPARTMENT')),
            'notes': ' '.join(note_parts),
        })
        if custodian:
            assignments.append({'tag': barcode, 'holder': custodian,
                                'department': tidy(cell(row, 'DEPARTMENT')),
                                'when': acquired, 'condition': condition})

    lines = [
        '-- ICT asset register, imported from RESOK_ASSET_DATA VIRTUAL.xlsx.',
        '--',
        '-- %d assets and %d custodian assignments, generated by' % (len(assets), len(assignments)),
        '-- tools/dev/import_asset_register.py. Do not edit by hand - re-run the script.',
        '--',
        '-- The barcode is kept as the asset tag. Those numbers are on physical stickers, so',
        '-- renumbering them would make the system disagree with the shelf. Assets added',
        '-- through the portal get the ICT-LAP-0001 format; the two coexist deliberately.',
        '--',
        '-- CONDITION maps GOOD to good, BAD to poor and FAULTY to damaged. The middle one is',
        '-- a guess, so the original word is preserved in every row\'s notes.',
        '--',
        '-- A custodian becomes an open assignment, dated to the acquisition date - the',
        '-- earliest defensible date, since nobody recorded the actual handover.',
        '--',
        '-- Safe to run more than once: assets match on their tag and assignments are only',
        '-- created where none is open, so a second run changes nothing.',
        '',
        'INSERT INTO ict_assets',
        '  (asset_tag, category, name, manufacturer, model, serial_number, purchase_date,',
        '   purchase_cost, `condition`, status, location, department, notes)',
        'VALUES',
    ]
    rows = []
    for a in assets:
        rows.append('  (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)' % (
            q(a['tag']), q(a['category']), q(a['name']), q(a['manufacturer']), q(a['model']),
            q(a['serial']), q(a['purchased']),
            'NULL' if a['cost'] is None else ('%.2f' % a['cost']),
            q(a['condition']), q(a['status']), q(a['location']), q(a['department']), q(a['notes'])))
    lines.append(',\n'.join(rows))
    lines += [
        'ON DUPLICATE KEY UPDATE',
        '  name = VALUES(name), category = VALUES(category), model = VALUES(model),',
        '  serial_number = VALUES(serial_number), purchase_date = VALUES(purchase_date),',
        '  purchase_cost = VALUES(purchase_cost), location = VALUES(location),',
        '  department = VALUES(department);',
        '',
    ]

    for a in assignments:
        when = a['when'] or '2025-01-01'
        lines += [
            'INSERT INTO ict_assignments (asset_id, holder_name, department, assigned_at, condition_out, handover_notes)',
            'SELECT id, %s, %s, %s, %s, %s FROM ict_assets WHERE asset_tag = %s'
            % (q(a['holder']), q(a['department']), q(when + ' 09:00:00'), q(a['condition']),
               q('Custodian recorded in the 2025 register.'), q(a['tag'])),
            '  AND NOT EXISTS (SELECT 1 FROM (SELECT * FROM ict_assignments) x',
            '                   WHERE x.asset_id = ict_assets.id AND x.returned_at IS NULL);',
        ]

    io.open(TARGET, 'w', encoding='utf-8', newline='\n').write('\n'.join(lines) + '\n')

    print('  wrote %s' % TARGET)
    print('  %d assets, %d assignments, %d rows skipped' % (len(assets), len(assignments), skipped))

    if duplicates:
        print()
        print('  %d DUPLICATE BARCODE(S) - these assets were NOT imported:' % len(duplicates))
        for tag, first, second in duplicates:
            print('    %s is on both "%s" and "%s"' % (tag, first[:38], second[:38]))
        print('    Two stickers with the same number cannot be told apart in an audit.')
        print('    Give one of each pair a new barcode, then re-run this.')
    print()
    print('  categories:')
    for name, n in sorted(counts.items(), key=lambda kv: -kv[1]):
        print('    %-12s %d' % (name, n))
    return 0


if __name__ == '__main__':
    sys.exit(main())
