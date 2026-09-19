#!/usr/bin/env python3
"""Emit the Victual ORM/ERD diagram set as self-contained HTML files.

Skin: the `victual` diagram-design profile (~/.diagram-design/profiles/victual.md),
extracted from branding/logo.svg. Values are duplicated here because the output
files must be self-contained.
"""
import math
import pathlib
import sys

OUT = pathlib.Path(sys.argv[1])

PAPER = "#f2e7d3"
INK = "#174b3a"
MUTED = "#4a6459"
SOFT = "#6b8579"
ACCENT = "#c85a3d"
LINK = "#1e6e7a"
WHITE = "#ffffff"
RULE = "rgba(23,75,58,0.12)"
INK_04 = "rgba(23,75,58,0.04)"
INK_22 = "rgba(23,75,58,0.22)"
ACC_04 = "rgba(200,90,61,0.04)"
ACC_10 = "rgba(200,90,61,0.10)"
ACC_40 = "rgba(200,90,61,0.40)"

FONTS = ("https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1"
         "&family=Geist:wght@400;500;600&family=Geist+Mono:wght@400;500;600&display=swap")

MONO = "'Geist Mono', monospace"
SANS = "'Geist', sans-serif"


def page(slug, eyebrow, h1, viewbox, title, desc, body, min_width=1000):
    return f"""<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{h1}</title>
  <link href="{FONTS}" rel="stylesheet">
  <style>
    *, *::before, *::after {{ box-sizing: border-box; margin: 0; padding: 0; }}
    :root {{
      --color-paper:  {PAPER};
      --color-ink:    {INK};
      --color-muted:  {MUTED};
      --color-accent: {ACCENT};
      --font-sans:    {SANS};
      --font-serif:   'Instrument Serif', serif;
      --font-mono:    {MONO};
    }}
    body {{
      font-family: var(--font-sans);
      background: var(--color-paper);
      color: var(--color-ink);
      min-height: 100vh;
      display: flex;
      justify-content: center;
      padding: 3rem 2rem;
    }}
    .frame {{ max-width: 1280px; width: 100%; }}
    .eyebrow {{
      font-family: var(--font-mono);
      font-size: 0.66rem;
      font-weight: 500;
      letter-spacing: 0.18em;
      text-transform: uppercase;
      color: var(--color-muted);
      margin-bottom: 0.5rem;
    }}
    h1 {{
      font-family: var(--font-serif);
      font-size: clamp(1.5rem, 2.4vw + 0.75rem, 2rem);
      font-weight: 400;
      letter-spacing: -0.02em;
      line-height: 1.15;
      color: var(--color-ink);
      margin-bottom: 1.5rem;
    }}
    .scroll {{ overflow-x: auto; }}
    svg {{ width: 100%; min-width: {min_width}px; display: block; }}
  </style>
</head>
<body>
  <div class="frame">
    <p class="eyebrow">{eyebrow}</p>
    <h1>{h1}</h1>
    <div class="scroll">
    <svg viewBox="{viewbox}" xmlns="http://www.w3.org/2000/svg" role="img" aria-labelledby="{slug}-title {slug}-desc">
      <title id="{slug}-title">{title}</title>
      <desc id="{slug}-desc">{desc}</desc>
      <rect width="100%" height="100%" fill="{PAPER}"/>
{body}
    </svg>
    </div>
  </div>
</body>
</html>
"""


# ---------------------------------------------------------------- primitives

def esc(s):
    return s.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


def mono_w(text, size=8, pad=8):
    """Mask width for a mono string, rounded up to a multiple of 4."""
    w = len(text) * 0.62 * size + pad
    return int(math.ceil(w / 4.0) * 4)


def label(cx, top, text, size=8, fill=None, track="0.10em"):
    """Arrow / relationship label with an opaque mask. `top` is the mask top."""
    fill = fill or MUTED
    w = mono_w(text, size)
    return (f'    <rect x="{cx - w // 2}" y="{top}" width="{w}" height="12" rx="2" fill="{PAPER}"/>\n'
            f'    <text x="{cx}" y="{top + 9}" fill="{fill}" font-size="{size}" font-family="{MONO}" '
            f'text-anchor="middle" letter-spacing="{track}">{esc(text)}</text>')


def card(cx, top, text):
    """Cardinality digit: 12px mono, weight 600, on its own mask."""
    w = 16 if len(text) <= 1 else mono_w(text, 12, 6)
    return (f'    <rect x="{cx - w // 2}" y="{top}" width="{w}" height="12" rx="2" fill="{PAPER}"/>\n'
            f'    <text x="{cx}" y="{top + 10}" fill="{MUTED}" font-size="12" font-family="{MONO}" '
            f'text-anchor="middle" font-weight="600">{esc(text)}</text>')


def rel(d, dashed=True, stroke=None, marker=None):
    stroke = stroke or MUTED
    dash = ' stroke-dasharray="4,3"' if dashed else ''
    mk = f' marker-end="url(#{marker})"' if marker else ''
    return (f'    <path d="{d}" fill="none" stroke="{stroke}" stroke-width="1"{dash}{mk}/>')


ENTITY_HEADER = 44
ROW = 20
ENTITY_PAD = 20


def entity_h(n):
    return ENTITY_HEADER + ROW * n + ENTITY_PAD


def entity(x, y, w, name, fields, tag="ENTITY", focal=False, ref=False):
    """Two-section ER entity box. `fields` is a list of (label, type) pairs."""
    h = entity_h(len(fields))
    if focal:
        fill, stroke, hdr, div, tagfill = ACC_04, ACCENT, ACC_10, ACC_40, ACCENT
        dash = ''
    elif ref:
        fill, stroke, hdr, div, tagfill = "rgba(23,75,58,0.03)", "rgba(23,75,58,0.30)", INK_04, INK_22, SOFT
        dash = ' stroke-dasharray="4,3"'
    else:
        fill, stroke, hdr, div, tagfill = WHITE, INK, INK_04, INK_22, MUTED
        dash = ''
    o = [f'    <rect x="{x}" y="{y}" width="{w}" height="{h}" rx="6" fill="{PAPER}"/>',
         f'    <rect x="{x}" y="{y}" width="{w}" height="{h}" rx="6" fill="{fill}" stroke="{stroke}" stroke-width="1"{dash}/>',
         f'    <rect x="{x}" y="{y}" width="{w}" height="{ENTITY_HEADER}" rx="6" fill="{hdr}" stroke="none"/>',
         f'    <rect x="{x}" y="{y + 36}" width="{w}" height="8" fill="{hdr}"/>',
         f'    <line x1="{x}" y1="{y + ENTITY_HEADER}" x2="{x + w}" y2="{y + ENTITY_HEADER}" stroke="{div}" stroke-width="1"/>',
         f'    <text x="{x + 16}" y="{y + 16}" fill="{tagfill}" font-size="8" font-family="{MONO}" letter-spacing="0.14em">{esc(tag)}</text>',
         f'    <text x="{x + 16}" y="{y + 34}" fill="{INK}" font-size="16" font-weight="600" font-family="{SANS}">{esc(name)}</text>']
    for i, (fname, ftype) in enumerate(fields):
        by = y + 64 + ROW * i
        o.append(f'    <text x="{x + 16}" y="{by}" fill="{INK}" font-size="12" font-family="{MONO}">{esc(fname)}</text>')
        o.append(f'    <text x="{x + w - 16}" y="{by}" fill="{SOFT}" font-size="12" font-family="{MONO}" text-anchor="end">{esc(ftype)}</text>')
    return "\n".join(o)


def node(x, y, w, h, tag, name, sub, kind="backend"):
    """Architecture node box."""
    if kind == "focal":
        fill, stroke, tagcol = ACC_04, ACCENT, ACCENT
        dash = ''
    elif kind == "store":
        fill, stroke, tagcol = "rgba(23,75,58,0.05)", MUTED, MUTED
        dash = ''
    elif kind == "optional":
        fill, stroke, tagcol = "rgba(23,75,58,0.02)", "rgba(23,75,58,0.30)", SOFT
        dash = ' stroke-dasharray="4,3"'
    else:
        fill, stroke, tagcol = WHITE, INK, MUTED
        dash = ''
    cx = x + w // 2
    tw = mono_w(tag, 8, 12)
    o = [f'    <rect x="{x}" y="{y}" width="{w}" height="{h}" rx="6" fill="{PAPER}"/>',
         f'    <rect x="{x}" y="{y}" width="{w}" height="{h}" rx="6" fill="{fill}" stroke="{stroke}" stroke-width="1"{dash}/>',
         f'    <rect x="{x + 12}" y="{y + 10}" width="{tw}" height="12" rx="2" fill="none" stroke="{tagcol}" stroke-opacity="0.45" stroke-width="0.8"/>',
         f'    <text x="{x + 12 + tw // 2}" y="{y + 19}" fill="{tagcol}" font-size="8" font-family="{MONO}" text-anchor="middle" letter-spacing="0.10em">{esc(tag)}</text>',
         f'    <text x="{cx}" y="{y + h - 28}" fill="{INK}" font-size="16" font-weight="600" font-family="{SANS}" text-anchor="middle">{esc(name)}</text>',
         f'    <text x="{cx}" y="{y + h - 12}" fill="{MUTED}" font-size="8" font-family="{MONO}" text-anchor="middle">{esc(sub)}</text>']
    return "\n".join(o)


def legend(y, width, items):
    """Horizontal legend strip. items: list of (kind, text) where kind is a
    swatch spec dict or the literal string of a glyph."""
    o = [f'    <line x1="40" y1="{y}" x2="{width - 40}" y2="{y}" stroke="{RULE}" stroke-width="0.8"/>',
         f'    <text x="40" y="{y + 16}" fill="{MUTED}" font-size="8" font-family="{MONO}" letter-spacing="0.18em">LEGEND</text>']
    x = 40
    ly = y + 36
    for kind, text in items:
        if kind == "glyph":
            g, t = text
            o.append(f'    <text x="{x}" y="{ly + 8}" fill="{INK}" font-size="12" font-family="{MONO}" font-weight="600">{esc(g)}</text>')
            o.append(f'    <text x="{x + 20}" y="{ly + 8}" fill="{MUTED}" font-size="8" font-family="{SANS}">{esc(t)}</text>')
            x += 24 + int(len(t) * 4.6) + 32
        elif kind == "line":
            style, t = text
            dash = ' stroke-dasharray="4,3"' if style == "dashed" else ''
            o.append(f'    <line x1="{x}" y1="{ly + 4}" x2="{x + 24}" y2="{ly + 4}" stroke="{MUTED}" stroke-width="1"{dash}/>')
            o.append(f'    <text x="{x + 32}" y="{ly + 8}" fill="{MUTED}" font-size="8" font-family="{SANS}">{esc(t)}</text>')
            x += 36 + int(len(t) * 4.6) + 32
        else:
            fill, stroke, t = text
            dash = ' stroke-dasharray="3,2"' if kind == "swatch-dashed" else ''
            o.append(f'    <rect x="{x}" y="{ly - 2}" width="16" height="12" rx="2" fill="{fill}" stroke="{stroke}" stroke-width="1"{dash}/>')
            o.append(f'    <text x="{x + 24}" y="{ly + 8}" fill="{MUTED}" font-size="8" font-family="{SANS}">{esc(t)}</text>')
            x += 28 + int(len(t) * 4.6) + 32
    return "\n".join(o)


MARKERS = f"""      <defs>
        <marker id="arrow" markerWidth="8" markerHeight="6" refX="7" refY="3" orient="auto"><polygon points="0 0, 8 3, 0 6" fill="{MUTED}"/></marker>
        <marker id="arrow-accent" markerWidth="8" markerHeight="6" refX="7" refY="3" orient="auto"><polygon points="0 0, 8 3, 0 6" fill="{ACCENT}"/></marker>
        <marker id="arrow-link" markerWidth="8" markerHeight="6" refX="7" refY="3" orient="auto"><polygon points="0 0, 8 3, 0 6" fill="{LINK}"/></marker>
      </defs>"""


def lift(lines, dy):
    """Wrap a diagram body in a vertical translate, for layouts whose top rows are empty."""
    return f'    <g transform="translate(0,{dy})">\n' + "\n".join(lines) + '\n    </g>'


def write(name, html):
    p = OUT / name
    p.write_text(html)
    print("wrote", p)


# =============================================================== 1. ORM stack

def orm():
    b = [MARKERS]
    # --- connectors (drawn before boxes)
    b.append(rel("M 300,144 V 168 Q 300,176 308,176 H 412 Q 420,176 420,184 V 200",
                 dashed=False, marker="arrow"))
    b.append(label(360, 156, "ENTITY CRUD"))
    b.append(rel("M 640,144 V 168 Q 640,176 632,176 H 588 Q 580,176 580,184 V 200",
                 dashed=False, marker="arrow"))
    b.append(label(590, 156, "DOMAIN WRITES"))
    b.append(rel("M 700,144 V 344 Q 700,352 692,352 H 650", dashed=False, marker="arrow"))
    b.append(label(730, 232, "RAW SQL"))
    b.append(rel("M 500,272 V 328", dashed=False, marker="arrow"))
    b.append(label(548, 292, "QUERY CALLBACK"))
    b.append(rel("M 350,368 H 270", dashed=False, marker="arrow"))
    b.append(label(310, 348, "DIALECT"))
    b.append(rel("M 650,384 H 730", marker="arrow"))
    b.append(label(690, 364, "COMMIT HOOKS"))
    b.append(rel("M 500,408 V 464", dashed=False, stroke=ACCENT, marker="arrow-accent"))
    b.append(label(532, 428, "PDO EXEC", fill=ACCENT))
    # the shared PDO handed to the label services, which write hand-written SQL inside DatabaseService transactions
    b.append(rel("M 650,400 H 682 Q 690,400 690,408 V 496 Q 690,504 698,504 H 750",
                 dashed=False, marker="arrow"))
    b.append(label(720, 444, "RAW PDO"))
    b.append(rel("M 750,528 H 650", dashed=False, marker="arrow"))
    b.append(label(700, 536, "SQL IN TX"))
    # --- nodes
    b.append(node(180, 72, 240, 72, "HTTP", "Controllers", "routes.php · 18 API controllers"))
    b.append(node(520, 72, 240, 72, "DOMAIN", "Services", "BaseService · 23 singletons"))
    b.append(node(350, 200, 300, 72, "ORM", "LessQL\\Database", "$this->DB->products()->where()"))
    b.append(node(350, 328, 300, 80, "CORE", "DatabaseService", "one PDO · transactions · change tracking", kind="focal"))
    b.append(node(70, 336, 200, 64, "DIALECT", "PostgresDialect", "quoting · locks · changed-time"))
    b.append(node(730, 336, 220, 64, "ASYNC", "Outbox · MQTT · Influx", "outbox in tx · publish at request end", kind="optional"))
    b.append(node(750, 464, 200, 80, "LABELS", "Label services", "raw SQL · in transactions"))
    b.append(node(350, 464, 300, 80, "ENGINE", "PostgreSQL 16", "71 tables · 50 views · 65 triggers", kind="store"))
    b.append(legend(572, 1000, [
        ("swatch", (ACC_04, ACCENT, "Single point of access")),
        ("swatch", (WHITE, INK, "PHP class")),
        ("swatch", ("rgba(23,75,58,0.05)", MUTED, "Engine")),
        ("swatch-dashed", ("rgba(23,75,58,0.02)", "rgba(23,75,58,0.30)", "Written or published at commit")),
        ("line", ("dashed", "Asynchronous")),
    ]))
    write("orm-stack.html", page(
        "orm-stack", "ARCHITECTURE · VICTUAL", "Data access · from request to engine",
        "0 0 1000 640",
        "Victual data access stack",
        "Controllers and services hold a LessQL connection, and services also send raw SQL through "
        "DatabaseService, which owns the single PDO connection, the PostgreSQL dialect, transaction "
        "nesting and change tracking. Commit hooks write the outbox row inside the outermost transaction and MQTT and "
        "InfluxDB are published at the end of the request. The label services take the same PDO from "
        "DatabaseService and run hand-written SQL on it inside its transactions; their controllers "
        "advance the changed time themselves, because LessQL never sees those statements.",
        "\n".join(b)))


# ========================================================== 2. schema map

def schema_map():
    b = [MARKERS]
    b.append(rel("M 300,168 V 308 Q 300,316 308,316 H 340", dashed=False, marker="arrow"))
    b.append(label(338, 220, "PRODUCT_ID"))
    b.append(rel("M 600,168 V 288", dashed=False, marker="arrow"))
    b.append(label(638, 212, "PRODUCT_ID"))
    b.append(rel("M 740,124 H 840", dashed=False, marker="arrow"))
    b.append(label(790, 104, "USER_ID"))
    b.append(rel("M 680,288 V 216 Q 680,208 688,208 H 972 Q 980,208 980,200 V 168",
                 dashed=False, marker="arrow"))
    b.append(label(830, 188, "USER_ID"))
    b.append(rel("M 740,320 H 840", dashed=False, marker="arrow"))
    b.append(label(790, 300, "TRIGGERS"))
    b.append(rel("M 280,364 H 340", marker="arrow"))
    b.append(label(310, 344, "OBJECT_ID"))
    b.append(rel("M 540,392 V 496", marker="arrow"))
    b.append(label(572, 428, "50 VIEWS"))
    # labels name the rows they mark by (kind, target_id): untyped, the kind picks the table
    b.append(rel("M 840,536 H 778 Q 770,536 770,528 V 380 Q 770,372 762,372 H 740", marker="arrow"))
    b.append(label(806, 444, "TARGET_ID"))
    b.append(rel("M 980,496 V 392", dashed=False, marker="arrow"))
    b.append(label(1016, 436, "OUTBOX_ID"))
    b.append(node(80, 80, 280, 88, "5 TABLES", "Recipes & meal plan", "recipes · recipes_pos · meal_plan"))
    b.append(node(460, 80, 280, 88, "7 TABLES", "Household", "chores · tasks · batteries · equipment"))
    b.append(node(840, 80, 280, 88, "12 TABLES", "Identity & access", "users · roles · permissions · sessions"))
    b.append(node(80, 288, 200, 104, "4 TABLES", "Extensibility", "userfields · userentities", kind="optional"))
    b.append(node(340, 288, 400, 104, "15 TABLES", "Stock & products", "products · stock · stock_log · locations · storage_classes", kind="focal"))
    b.append(node(840, 288, 280, 104, "7 TABLES", "Caches & infrastructure", "cache__* · outbox · files · mqtt_*", kind="store"))
    b.append(node(340, 496, 400, 88, "DERIVED", "Read layer", "50 views · 3 of them materialised as cache__*", kind="store"))
    b.append(node(840, 496, 280, 104, "21 TABLES", "Labels & printing", "labels · label_templates · print_jobs"))
    b.append(legend(624, 1200, [
        ("swatch", (ACC_04, ACCENT, "Hub domain")),
        ("swatch", (WHITE, INK, "Table cluster")),
        ("swatch", ("rgba(23,75,58,0.05)", MUTED, "Derived or infrastructural")),
        ("line", ("dashed", "Untyped or derived (object_id is plain text; views read tables)")),
        ("line", ("solid", "Column named for its target")),
    ]))
    write("schema-map.html", page(
        "schema-map", "ARCHITECTURE · VICTUAL", "Schema map · 71 tables in seven clusters",
        "0 0 1200 700",
        "Victual schema map",
        "Seven table clusters around a stock and products hub: recipes, household chores and tasks, "
        "identity and access, extensibility, label printing, and caches. Arrows are the columns that "
        "name another cluster's rows; a read layer of 50 views sits below the hub.",
        "\n".join(b), min_width=1100))


# ================================================================ 3. ERD stock

def erd_stock():
    b = []
    # left column into the hub (products spans y 280..524)
    b.append(rel("M 300,200 H 352 Q 360,200 360,208 V 312 Q 360,320 368,320 H 420"))
    b.append(card(316, 180, "1"))
    b.append(card(404, 300, "N"))
    b.append(rel("M 300,400 H 420"))
    b.append(card(316, 380, "1"))
    b.append(card(404, 380, "N"))
    # hub out to the right column: nested channels so no two verticals cross a horizontal
    b.append(rel("M 740,300 H 792 Q 800,300 800,292 V 188 Q 800,180 808,180 H 860"))
    b.append(card(756, 280, "1"))
    b.append(card(844, 160, "N"))
    b.append(rel("M 740,340 H 822 Q 830,340 830,348 V 432 Q 830,440 838,440 H 860"))
    b.append(card(756, 320, "1"))
    b.append(card(844, 420, "N"))
    b.append(rel("M 740,400 H 802 Q 810,400 810,408 V 668 Q 810,676 818,676 H 860"))
    b.append(card(756, 380, "1"))
    b.append(card(844, 656, "N"))
    b.append(rel("M 740,460 H 782 Q 790,460 790,468 V 852 Q 790,860 798,860 H 860"))
    b.append(card(756, 440, "1"))
    b.append(card(844, 840, "N"))
    # self references
    b.append(rel("M 560,280 V 264 Q 560,256 568,256 H 632 Q 640,256 640,264 V 280"))
    b.append(label(600, 236, "PARENT PRODUCT"))
    b.append(rel("M 120,96 V 80 Q 120,72 128,72 H 212 Q 220,72 220,80 V 96"))
    b.append(label(170, 52, "PARENT GROUP"))
    # products <- product_substitutions, once per direction
    b.append(rel("M 500,604 V 524"))
    b.append(card(516, 532, "1"))
    b.append(label(522, 556, "FROM"))
    b.append(card(516, 580, "N"))
    b.append(rel("M 660,604 V 524"))
    b.append(card(676, 532, "1"))
    b.append(label(678, 556, "TO"))
    b.append(card(676, 580, "N"))
    # entities
    b.append(entity(40, 96, 260, "product_groups", [
        ("# id", "int"), ("name", "text uq/parent"), ("→ parent_product_group_id", "int"),
        ("min_stock_amount", "double"), ("active", "0/1")]))
    b.append(entity(40, 340, 260, "quantity_units", [
        ("# id", "int"), ("name", "text uq"), ("name_plural", "text"), ("active", "0/1")]))
    b.append(entity(420, 280, 320, "products", [
        ("# id", "int"), ("name", "text uq"), ("→ product_group_id", "int"),
        ("→ location_id ↗", "locations"), ("→ qu_id_stock", "int"),
        ("→ qu_id_purchase", "int"), ("→ parent_product_id", "int"),
        ("min_stock_amount", "double"), ("quick_refill_amount", "double")],
        tag="ENTITY · HUB", focal=True))
    b.append(entity(420, 604, 320, "product_substitutions", [
        ("# id", "int"), ("→ from_product_id", "int"), ("→ to_product_id", "int"),
        ("(from, to)", "unique")], tag="DIRECTED EDGE"))
    b.append(entity(860, 96, 300, "stock", [
        ("# id", "int"), ("→ product_id", "int"), ("stock_id", "text"),
        ("amount", "double"), ("best_before_date", "date"), ("→ location_id ↗", "locations"),
        ("opened_amount", "double"), ("→ opened_qu_id", "int")]))
    b.append(entity(860, 360, 300, "stock_log", [
        ("# id", "int"), ("→ product_id", "int"), ("stock_id", "text"),
        ("transaction_type", "text"), ("→ user_id ↗", "users"), ("undone", "0/1"),
        ("opened_amount", "double")], tag="LEDGER"))
    b.append(entity(860, 604, 300, "product_barcodes", [
        ("# id", "int"), ("→ product_id", "int"), ("barcode", "text"), ("→ qu_id", "int")]))
    b.append(entity(860, 788, 300, "shopping_list", [
        ("# id", "int"), ("→ product_id", "int"), ("→ shopping_list_id ↗", "shopping_lists"),
        ("amount", "double")]))
    b.append(legend(972, 1200, [
        ("swatch", (ACC_04, ACCENT, "Hub entity")),
        ("glyph", ("#", "Primary key")),
        ("glyph", ("→", "Reference column")),
        ("glyph", ("↗", "Target lives in another diagram")),
        ("line", ("dashed", "Convention only — no FOREIGN KEY declared")),
    ]))
    write("erd-stock.html", page(
        "erd-stock", "ER · VICTUAL", "Stock & products · the hub cluster",
        "0 0 1200 1050",
        "Victual stock and products data model",
        "Products sits at the centre of the stock cluster: it names a product group, two quantity "
        "units and a location, may name a parent product, and is referenced by stock, the stock_log "
        "ledger, barcodes, shopping list rows and, twice over, by directed substitution edges. "
        "Product groups form a tree of their own. None of these references is a declared foreign key.",
        "\n".join(b), min_width=1100))


# ============================================================== 4. ERD recipes

def erd_recipes():
    b = []
    b.append(rel("M 160,224 V 304"))
    b.append(card(176, 236, "1"))
    b.append(card(176, 284, "N"))
    b.append(rel("M 280,380 H 342 Q 350,380 350,372 V 288 Q 350,280 358,280 H 420"))
    b.append(card(296, 360, "N"))
    b.append(card(404, 260, "1"))
    b.append(rel("M 280,456 H 532 a 8,8 0 0,1 16,0 H 592 a 8,8 0 0,1 16,0 H 860"))
    b.append(card(296, 436, "N"))
    b.append(card(844, 436, "1"))
    b.append(rel("M 780,240 H 808 Q 816,240 816,232 V 180 Q 816,172 824,172 H 860"))
    b.append(card(796, 220, "1"))
    b.append(card(844, 152, "N"))
    b.append(rel("M 1010,264 V 352"))
    b.append(card(1026, 276, "N"))
    b.append(card(1026, 332, "1"))
    b.append(rel("M 780,320 H 812 Q 820,320 820,328 V 388 Q 820,396 828,396 H 860"))
    b.append(card(796, 300, "N"))
    b.append(card(844, 376, "1"))
    b.append(rel("M 540,424 V 580"))
    b.append(card(556, 432, "1"))
    b.append(card(556, 560, "N"))
    b.append(rel("M 600,580 V 424"))
    b.append(label(632, 492, "INCLUDES"))
    b.append(entity(40, 80, 240, "meal_plan_sections", [
        ("# id", "int"), ("name", "text uq"), ("sort_number", "int"), ("time_info", "text")]))
    b.append(entity(40, 304, 240, "meal_plan", [
        ("# id", "int"), ("day", "date"), ("type", "text"), ("→ recipe_id", "int"),
        ("→ product_id", "int"), ("→ section_id", "int"), ("done", "0/1")]))
    b.append(entity(420, 200, 360, "recipes", [
        ("# id", "int"), ("name", "text"), ("type", "text"), ("base_servings", "double"),
        ("desired_servings", "double"), ("→ product_id ↗", "products"),
        ("→ default_shopping_list_id ↗", "shopping_lists"),
        ("not_check_shoppinglist", "0/1")], tag="ENTITY · HUB", focal=True))
    b.append(entity(420, 580, 300, "recipes_nestings", [
        ("# id", "int"), ("→ recipe_id", "int"), ("→ includes_recipe_id", "int"),
        ("servings", "double")], tag="SELF-JOIN"))
    b.append(entity(860, 80, 300, "recipes_pos", [
        ("# id", "int"), ("→ recipe_id", "int"), ("→ product_id", "int"),
        ("amount", "double"), ("→ qu_id ↗", "quantity_units"),
        ("ingredient_group", "text")], tag="LINE ITEM"))
    b.append(entity(860, 352, 300, "products", [
        ("# id", "int"), ("name", "text uq"), ("…", "see erd-stock")],
        tag="REFERENCE", ref=True))
    b.append(legend(776, 1200, [
        ("swatch", (ACC_04, ACCENT, "Hub entity")),
        ("swatch-dashed", ("rgba(23,75,58,0.03)", "rgba(23,75,58,0.30)", "Entity owned by another diagram")),
        ("glyph", ("→", "Reference column")),
        ("glyph", ("↗", "Target lives in another diagram")),
        ("line", ("dashed", "Convention only — no FOREIGN KEY declared")),
    ]))
    write("erd-recipes.html", page(
        "erd-recipes", "ER · VICTUAL", "Recipes & meal plan",
        "0 0 1200 850",
        "Victual recipes and meal plan data model",
        "Recipes carry line items in recipes_pos and nest inside each other through recipes_nestings, "
        "which names a recipe twice. A recipe may name a product it produces and a default shopping "
        "list. Meal plan entries point at a recipe, a product or a note, and group under a meal plan "
        "section. Insert and update triggers on the nesting table guard it against self-inclusion "
        "and cycles.",
        "\n".join(b), min_width=1100))


# ============================================================= 5. ERD identity

def erd_identity():
    b = []
    b.append(rel("M 440,150 H 320"))
    b.append(card(424, 130, "1"))
    b.append(card(336, 130, "N"))
    b.append(rel("M 440,196 H 388 Q 380,196 380,204 V 378 Q 380,386 372,386 H 320"))
    b.append(card(424, 176, "1"))
    b.append(card(336, 366, "N"))
    b.append(rel("M 760,150 H 880"))
    b.append(card(776, 130, "1"))
    b.append(card(864, 130, "N"))
    # api_keys.rotated_from_id is one of the declared foreign keys: solid
    b.append(rel("M 960,96 V 80 Q 960,72 968,72 H 1052 Q 1060,72 1060,80 V 96", dashed=False))
    b.append(label(1010, 52, "ROTATED FROM"))
    b.append(entity(40, 96, 280, "sessions", [
        ("# id", "int"), ("session_key", "text uq"), ("→ user_id", "int"),
        ("expires", "timestamp"), ("last_used", "timestamp")]))
    b.append(entity(40, 340, 280, "user_settings", [
        ("# id", "int"), ("→ user_id", "int"), ("key", "text"), ("value", "text")]))
    b.append(entity(440, 96, 320, "users", [
        ("# id", "int"), ("username", "text uq"), ("password", "text"),
        ("first_name", "text"), ("last_name", "text"), ("must_change_password", "0/1")],
        tag="ENTITY · HUB", focal=True))
    b.append(entity(880, 96, 280, "api_keys", [
        ("# id", "int"), ("api_key", "text uq"), ("→ user_id", "int"),
        ("key_type", "text"), ("→ rotated_from_id", "int"), ("read_only", "0/1"),
        ("expires", "timestamp")]))
    b.append(legend(560, 1200, [
        ("swatch", (ACC_04, ACCENT, "Hub entity")),
        ("line", ("solid", "Declared FOREIGN KEY (api_keys.rotated_from_id, ON DELETE SET NULL)")),
        ("line", ("dashed", "Convention only — no constraint")),
        ("glyph", ("#", "Primary key")),
    ]))
    write("erd-identity.html", page(
        "erd-identity", "ER · VICTUAL", "Identity · users, sessions, API keys",
        "0 0 1200 640",
        "Victual identity data model",
        "Users hold sessions, API keys and per-user settings by convention; an API key can record the "
        "key it was rotated from, through a declared self-referencing foreign key. Which permissions a "
        "user holds is a separate diagram.",
        "\n".join(b), min_width=1100))


# =============================================================== 5b. ERD access

def erd_access():
    b = []
    # users -< user_roles >- roles (declared)
    b.append(rel("M 300,150 H 420", dashed=False))
    b.append(card(316, 130, "1"))
    b.append(card(404, 130, "N"))
    b.append(rel("M 860,150 H 740", dashed=False))
    b.append(card(844, 130, "1"))
    b.append(card(756, 130, "N"))
    # roles -< role_permissions >- permission_hierarchy (declared)
    b.append(rel("M 1010,240 V 360", dashed=False))
    b.append(card(1026, 252, "1"))
    b.append(card(1026, 340, "N"))
    b.append(rel("M 860,420 H 812 Q 804,420 804,428 V 604 Q 804,612 796,612 H 740", dashed=False))
    b.append(card(844, 400, "N"))
    b.append(card(756, 592, "1"))
    # direct grants (convention)
    b.append(rel("M 170,220 V 360"))
    b.append(card(186, 232, "1"))
    b.append(card(186, 340, "N"))
    b.append(rel("M 170,484 V 602 Q 170,610 178,610 H 420"))
    b.append(card(186, 496, "N"))
    b.append(card(404, 590, "1"))
    # the permission tree names its own parent
    b.append(rel("M 500,560 V 544 Q 500,536 508,536 H 652 Q 660,536 660,544 V 560"))
    b.append(label(580, 516, "PARENT"))
    # field policy (declared, on permission_hierarchy.name)
    b.append(rel("M 860,650 H 740", dashed=False))
    b.append(card(844, 630, "N"))
    b.append(card(756, 630, "1"))
    b.append(entity(40, 96, 260, "users", [
        ("# id", "int"), ("username", "text uq"), ("…", "see erd-identity")],
        tag="REFERENCE", ref=True))
    b.append(entity(40, 360, 260, "user_permissions", [
        ("# id", "int"), ("→ user_id", "int"), ("→ permission_id", "int")],
        tag="DIRECT GRANT"))
    b.append(entity(420, 96, 320, "user_roles", [
        ("→ user_id", "int pk"), ("→ role_id", "int pk")], tag="JOIN · FK"))
    b.append(entity(860, 96, 300, "roles", [
        ("# id", "int"), ("code", "text uq"), ("name", "text uq"), ("builtin", "0/1")],
        tag="ENTITY · FK"))
    b.append(entity(860, 360, 300, "role_permissions", [
        ("→ role_id", "int pk"), ("→ permission_id", "int pk")], tag="JOIN · FK"))
    b.append(entity(420, 560, 320, "permission_hierarchy", [
        ("# id", "int"), ("name", "text uq"), ("→ parent", "int")],
        tag="ENTITY · HUB · TREE", focal=True))
    b.append(entity(860, 560, 300, "permission_fields", [
        ("# id", "smallint"), ("→ permission_name", "text"), ("entity", "text"),
        ("field", "text")], tag="FIELD POLICY"))
    b.append(legend(752, 1200, [
        ("swatch", (ACC_04, ACCENT, "Hub entity")),
        ("swatch-dashed", ("rgba(23,75,58,0.03)", "rgba(23,75,58,0.30)", "Entity owned by another diagram")),
        ("line", ("solid", "Declared FOREIGN KEY (five, all in this diagram)")),
        ("line", ("dashed", "Convention only — no constraint")),
        ("glyph", ("#", "Primary key")),
    ]))
    write("erd-access.html", page(
        "erd-access", "ER · VICTUAL", "Access · roles, permissions, field policy",
        "0 0 1200 830",
        "Victual roles and permissions data model",
        "A user holds permissions directly through user_permissions or through roles, and both routes "
        "end at the permission_hierarchy tree that user_permissions_resolved walks. Five foreign "
        "keys are declared here, on the two role join tables and on permission_fields, which "
        "names the columns a permission unlocks.",
        "\n".join(b), min_width=1100))


# ============================================================ 6. ERD household

def erd_household():
    b = []
    b.append(rel("M 780,172 H 860"))
    b.append(card(796, 152, "1"))
    b.append(card(844, 152, "N"))
    b.append(rel("M 780,416 H 860"))
    b.append(card(796, 396, "N"))
    b.append(card(844, 396, "1"))
    b.append(rel("M 780,660 H 860"))
    b.append(card(796, 640, "1"))
    b.append(card(844, 640, "N"))
    b.append(rel("M 190,364 V 444"))
    b.append(card(206, 376, "1"))
    b.append(card(206, 424, "N"))
    b.append(rel("M 340,486 H 372 Q 380,486 380,478 V 216 Q 380,208 388,208 H 420"))
    b.append(label(418, 300, "ANY ENTITY"))
    b.append(entity(40, 200, 300, "userfields", [
        ("# id", "int"), ("entity", "text"), ("name", "text"), ("type", "text"),
        ("input_required", "0/1")], tag="SCHEMA"))
    b.append(entity(40, 444, 300, "userfield_values", [
        ("# id", "int"), ("→ field_id", "int"), ("object_id", "text"),
        ("value", "text")], tag="VALUE · UNTYPED"))
    b.append(entity(420, 80, 360, "chores", [
        ("# id", "int"), ("name", "text uq"), ("period_type", "text"),
        ("→ product_id ↗", "products"),
        ("→ next_execution_assigned_to_user_id ↗", "users"), ("active", "0/1")],
        tag="ENTITY · HUB", focal=True))
    b.append(entity(860, 80, 300, "chores_log", [
        ("# id", "int"), ("→ chore_id", "int"), ("tracked_time", "timestamp"),
        ("→ done_by_user_id ↗", "users"), ("undone", "0/1"), ("skipped", "0/1")],
        tag="LEDGER"))
    b.append(entity(420, 344, 360, "tasks", [
        ("# id", "int"), ("name", "text"), ("due_date", "date"),
        ("→ category_id", "int"), ("→ assigned_to_user_id ↗", "users")]))
    b.append(entity(860, 344, 300, "task_categories", [
        ("# id", "int"), ("name", "text uq"), ("description", "text"), ("active", "0/1")]))
    b.append(entity(420, 588, 360, "batteries", [
        ("# id", "int"), ("name", "text uq"), ("charge_interval_days", "int"), ("active", "0/1")]))
    b.append(entity(860, 588, 300, "battery_charge_cycles", [
        ("# id", "int"), ("→ battery_id", "int"), ("tracked_time", "timestamp"),
        ("undone", "0/1")], tag="LEDGER"))
    b.append(legend(772, 1200, [
        ("swatch", (ACC_04, ACCENT, "Hub entity")),
        ("glyph", ("→", "Reference column")),
        ("glyph", ("↗", "Target lives in another diagram")),
        ("line", ("dashed", "Convention only — no FOREIGN KEY declared")),
    ]))
    write("erd-household.html", page(
        "erd-household", "ER · VICTUAL", "Household · chores, tasks, batteries, userfields",
        "0 0 1200 860",
        "Victual household and userfield data model",
        "Three parallel pairs — chores, tasks and batteries, each with a log or category table — "
        "plus the userfields pair, whose object_id is plain text and so can address a row in any of "
        "them without naming which.",
        "\n".join(b), min_width=1100))


# ============================================================== 7. ERD locations

def erd_locations():
    b = []
    # locations <- storage_classes (declared)
    b.append(rel("M 180,560 V 504", dashed=False))
    b.append(card(196, 512, "N"))
    b.append(card(196, 536, "1"))
    # locations -< product_location_min_stock >- products (both declared)
    b.append(rel("M 360,340 H 440", dashed=False))
    b.append(card(376, 320, "1"))
    b.append(card(424, 320, "N"))
    b.append(rel("M 760,340 H 840", dashed=False))
    b.append(card(776, 320, "N"))
    b.append(card(824, 320, "1"))
    # products -> locations, by convention, over the top
    b.append(rel("M 960,280 V 248 Q 960,240 952,240 H 328 Q 320,240 320,248 V 280"))
    b.append(label(640, 220, "LOCATION_ID"))
    b.append(card(976, 256, "N"))
    b.append(card(336, 256, "1"))
    # locations nest under a parent, by convention
    b.append(rel("M 100,280 V 264 Q 100,256 108,256 H 192 Q 200,256 200,264 V 280"))
    b.append(label(150, 236, "PARENT"))
    # stores
    b.append(rel("M 1040,404 V 520"))
    b.append(card(1056, 412, "N"))
    b.append(label(1084, 452, "DEFAULT LIST"))
    b.append(card(1056, 496, "1"))
    b.append(rel("M 760,550 H 792 Q 800,550 800,542 V 448 Q 800,440 808,440 H 892 Q 900,440 900,432 V 404"))
    b.append(label(850, 420, "DEFAULT STORE"))
    b.append(card(776, 530, "1"))
    b.append(card(916, 412, "N"))
    b.append(rel("M 760,600 H 840"))
    b.append(card(776, 580, "1"))
    b.append(card(824, 580, "N"))
    b.append(entity(40, 280, 320, "locations", [
        ("# id", "int"), ("name", "text uq/parent"), ("→ parent_location_id", "int"),
        ("→ storage_class_id", "int"), ("is_freezer", "0/1"), ("tare_weight", "double"),
        ("→ tare_qu_id ↗", "quantity_units fk"), ("active", "0/1")],
        tag="ENTITY · HUB", focal=True))
    b.append(entity(40, 560, 280, "storage_classes", [
        ("# id", "int"), ("name", "text uq"), ("min_temp_c", "numeric"), ("max_temp_c", "numeric"),
        ("treats_as_freezer", "0/1"), ("sort_order", "int")], tag="VOCABULARY"))
    b.append(entity(440, 280, 320, "product_location_min_stock", [
        ("# id", "int"), ("→ product_id", "int"), ("→ location_id", "int"),
        ("min_stock_amount", "double"), ("(product, location)", "unique")],
        tag="PER-LOCATION MINIMUM"))
    b.append(entity(840, 280, 320, "products", [
        ("# id", "int"), ("name", "text uq"), ("…", "see erd-stock")],
        tag="REFERENCE", ref=True))
    b.append(entity(440, 520, 320, "shopping_locations", [
        ("# id", "int"), ("name", "text uq"), ("description", "text"), ("active", "0/1")],
        tag="STORE"))
    b.append(entity(840, 520, 320, "shopping_lists", [
        ("# id", "int"), ("name", "text uq"), ("→ shopping_location_id", "int")],
        tag="LIST"))
    b.append(legend(808, 1200, [
        ("swatch", (ACC_04, ACCENT, "Hub entity")),
        ("swatch-dashed", ("rgba(23,75,58,0.03)", "rgba(23,75,58,0.30)", "Entity owned by another diagram")),
        ("glyph", ("→", "Reference column")),
        ("glyph", ("↗", "Target lives in another diagram")),
        ("line", ("solid", "Declared FOREIGN KEY")),
        ("line", ("dashed", "Convention only")),
    ]))
    write("erd-locations.html", page(
        "erd-locations", "ER · VICTUAL", "Places · locations, storage classes, stores",
        "0 0 1200 730",
        "Victual locations, storage classes and stores data model",
        "Locations nest under a parent by convention and may name a storage class, and a tare unit, "
        "through declared foreign keys. A per-location minimum joins products to locations with two "
        "more declared keys. Products name a default store and a default shopping list, and a shopping "
        "list may name the store it is for, all by convention.",
        lift(b, -160), min_width=1100))


# ================================================================ 8. ERD labels

def erd_labels():
    b = []
    # templates -< versions, and the default-version pointer back the other way (both declared)
    b.append(rel("M 340,150 H 460", dashed=False))
    b.append(card(356, 130, "1"))
    b.append(card(444, 130, "N"))
    b.append(rel("M 340,210 H 460", dashed=False))
    b.append(label(400, 190, "DEFAULT"))
    b.append(card(356, 190, "N"))
    b.append(card(444, 190, "1"))
    # templates - drafts, one to one
    b.append(rel("M 190,260 V 340", dashed=False))
    b.append(card(206, 272, "1"))
    b.append(card(206, 320, "1"))
    # into label_render_requests
    b.append(rel("M 620,260 V 340", dashed=False))
    b.append(card(636, 272, "1"))
    b.append(card(636, 320, "N"))
    b.append(rel("M 880,190 H 832 Q 824,190 824,198 V 362 Q 824,370 816,370 H 780", dashed=False))
    b.append(card(864, 170, "1"))
    b.append(card(796, 350, "N"))
    b.append(rel("M 540,600 V 544", dashed=False))
    b.append(card(556, 552, "N"))
    b.append(card(556, 576, "1"))
    b.append(rel("M 780,450 H 880", dashed=False))
    b.append(card(796, 430, "1"))
    b.append(card(864, 430, "N"))
    # labels.uid <- label_captures.label_uid, by convention
    b.append(rel("M 340,660 H 460"))
    b.append(card(356, 640, "1"))
    b.append(card(444, 640, "N"))
    b.append(label(400, 668, "LABEL_UID"))
    b.append(entity(40, 96, 300, "label_templates", [
        ("# id", "int"), ("name", "text uq"), ("entity_kind", "text"),
        ("→ default_version_id", "int"), ("archived_at", "timestamp")]))
    b.append(entity(40, 340, 300, "label_template_drafts", [
        ("→ template_id", "int pk"), ("document", "jsonb"), ("revision_token", "text"),
        ("updated_at", "timestamp")], tag="ONE PER TEMPLATE"))
    b.append(entity(40, 600, 300, "labels", [
        ("# uid", "text"), ("kind", "text"), ("target_id", "bigint"),
        ("retired_at", "timestamp"), ("retirement_snapshot", "jsonb")], tag="IDENTITY"))
    b.append(entity(460, 96, 320, "label_template_versions", [
        ("# id", "int"), ("→ template_id", "int"), ("version", "int"),
        ("document", "jsonb"), ("published_at", "timestamp")], tag="PUBLISHED"))
    b.append(entity(460, 340, 320, "label_render_requests", [
        ("# id", "int"), ("purpose", "text"), ("state", "text"),
        ("→ template_version_id", "int"), ("→ capture_id", "int"),
        ("→ profile_id", "int"), ("→ artifact_id", "int")],
        tag="ENTITY · HUB", focal=True))
    b.append(entity(460, 600, 320, "label_captures", [
        ("# id", "int"), ("entity_kind", "text"), ("target_id", "bigint"),
        ("→ label_uid", "text"), ("captured_fields", "jsonb"), ("is_sample", "0/1")],
        tag="FIELD SNAPSHOT"))
    b.append(entity(880, 96, 280, "label_media_profiles", [
        ("# id", "int"), ("profile_key", "text"), ("version", "int"),
        ("→ driver_id ↗", "label_drivers"), ("model", "text"), ("media", "text")]))
    b.append(entity(880, 340, 280, "label_artifacts", [
        ("# id", "int"), ("→ render_request_id", "int"), ("form", "text"),
        ("byte_digest", "text"), ("mime_type", "text"), ("manifest", "jsonb")],
        tag="RENDERED BYTES"))
    b.append(legend(840, 1200, [
        ("swatch", (ACC_04, ACCENT, "Hub entity")),
        ("glyph", ("#", "Primary key")),
        ("glyph", ("→", "Reference column")),
        ("glyph", ("↗", "Target lives in another diagram")),
        ("line", ("solid", "Declared FOREIGN KEY")),
        ("line", ("dashed", "Convention only")),
    ]))
    write("erd-labels.html", page(
        "erd-labels", "ER · VICTUAL", "Labels · identity, templates and rendering",
        "0 0 1200 920",
        "Victual label templates and rendering data model",
        "A label template has immutable published versions and one working draft, and points at its "
        "default version. A render request combines a template version, a field capture and a media "
        "profile and yields an artifact; artifacts, requests and captures are linked by declared foreign "
        "keys, while captures name the label they were taken for by uid alone. Artifacts also copy the "
        "three ids they were rendered from, and requests may point at a draft template instead of a "
        "version; neither is drawn.",
        "\n".join(b), min_width=1100))


# ============================================================= 9. ERD printing

def erd_printing():
    b = []
    b.append(rel("M 340,150 H 460", dashed=False))
    b.append(card(356, 130, "1"))
    b.append(card(444, 130, "N"))
    b.append(rel("M 620,300 V 420", dashed=False))
    b.append(card(636, 312, "N"))
    b.append(card(636, 400, "1"))
    b.append(rel("M 780,150 H 880"))
    b.append(card(796, 130, "1"))
    b.append(card(864, 130, "N"))
    b.append(label(830, 158, "PRINTER_ID"))
    b.append(rel("M 1020,300 V 420", dashed=False))
    b.append(card(1036, 312, "1"))
    b.append(card(1036, 400, "N"))
    b.append(rel("M 780,512 H 880", dashed=False))
    b.append(card(796, 492, "1"))
    b.append(card(864, 492, "N"))
    b.append(rel("M 1020,604 V 700", dashed=False))
    b.append(card(1036, 616, "1"))
    b.append(card(1036, 680, "N"))
    b.append(rel("M 620,604 V 700", dashed=False))
    b.append(card(636, 616, "1"))
    b.append(card(636, 680, "N"))
    b.append(rel("M 340,760 H 460", dashed=False))
    b.append(card(356, 740, "1"))
    b.append(card(444, 740, "1"))
    b.append(entity(40, 96, 300, "label_drivers", [
        ("# id", "int"), ("driver_id", "text"), ("schema_version", "text"),
        ("contract_version", "int"), ("capability_document", "jsonb")],
        tag="(DRIVER, VERSION) UNIQUE"))
    b.append(entity(460, 96, 320, "label_printers", [
        ("# id", "int"), ("name", "text"), ("→ worker_id", "int"),
        ("→ driver_id + schema_version", "text"), ("connection_type", "text"),
        ("is_default", "0/1"), ("settings", "jsonb")]))
    b.append(entity(880, 96, 280, "print_jobs", [
        ("# id", "int"), ("→ printer_id", "int"), ("label_uid", "text"),
        ("operation", "text"), ("→ artifact_id ↗", "label_artifacts"),
        ("→ current_attempt_id", "int"), ("outcome", "text")], tag="JOB"))
    b.append(entity(460, 420, 320, "label_workers", [
        ("# id", "int"), ("name", "text"), ("configuration_mode", "text"),
        ("active", "0/1"), ("last_registered_at", "timestamp"), ("requires_repairing", "0/1")],
        tag="ENTITY · HUB", focal=True))
    b.append(entity(880, 420, 280, "print_attempts", [
        ("# id", "int"), ("→ job_id", "int"), ("→ worker_id", "int"),
        ("attempt_number", "int"), ("outcome", "text"), ("ended_at", "timestamp")],
        tag="LEASE"))
    b.append(entity(880, 700, 280, "print_evidence", [
        ("# id", "int"), ("→ attempt_id", "int"), ("evidence_type", "text"),
        ("decoded_uid", "text"), ("observed_at", "timestamp")], tag="EVIDENCE"))
    b.append(entity(460, 700, 320, "label_worker_credentials", [
        ("→ api_key_id", "int pk"), ("→ worker_id", "int"), ("→ session_id", "int"),
        ("issued_at", "timestamp"), ("consumed_at", "timestamp"),
        ("→ successor_api_key_id", "int")], tag="PAIRING"))
    b.append(entity(40, 700, 300, "api_keys", [
        ("# id", "int"), ("key_type", "text"), ("…", "see erd-identity")],
        tag="REFERENCE", ref=True))
    b.append(legend(936, 1200, [
        ("swatch", (ACC_04, ACCENT, "Hub entity")),
        ("swatch-dashed", ("rgba(23,75,58,0.03)", "rgba(23,75,58,0.30)", "Entity owned by another diagram")),
        ("glyph", ("→", "Reference column")),
        ("glyph", ("↗", "Target lives in another diagram")),
        ("line", ("solid", "Declared FOREIGN KEY")),
        ("line", ("dashed", "Convention only")),
    ]))
    write("erd-printing.html", page(
        "erd-printing", "ER · VICTUAL", "Printing · workers, printers, jobs",
        "0 0 1200 1010",
        "Victual label printing data model",
        "A label worker owns printers, which bind to a driver by name and schema version, claims print "
        "jobs as leased attempts and records evidence against each attempt. Its credentials link it to "
        "API keys. Every reference here is a declared foreign key except the printer a job names. "
        "Outbox links, worker sessions, capabilities and printer status are not drawn.",
        "\n".join(b), min_width=1100))


if __name__ == "__main__":
    OUT.mkdir(parents=True, exist_ok=True)
    orm()
    schema_map()
    erd_stock()
    erd_locations()
    erd_recipes()
    erd_identity()
    erd_access()
    erd_household()
    erd_labels()
    erd_printing()
