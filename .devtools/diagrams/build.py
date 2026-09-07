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
    b.append(label(610, 156, "DOMAIN WRITES"))
    b.append(rel("M 700,144 V 344 Q 700,352 692,352 H 650", dashed=False, marker="arrow"))
    b.append(label(730, 232, "RAW SQL"))
    b.append(rel("M 500,272 V 328", dashed=False, marker="arrow"))
    b.append(label(548, 292, "QUERY CALLBACK"))
    b.append(rel("M 350,368 H 270", dashed=False, marker="arrow"))
    b.append(label(310, 348, "DIALECT"))
    b.append(rel("M 650,384 H 730", marker="arrow"))
    b.append(label(690, 364, "ON COMMIT"))
    b.append(rel("M 500,408 V 464", dashed=False, stroke=ACCENT, marker="arrow-accent"))
    b.append(label(532, 428, "PDO EXEC", fill=ACCENT))
    # --- nodes
    b.append(node(180, 72, 240, 72, "HTTP", "Controllers", "routes.php · 16 API controllers"))
    b.append(node(520, 72, 240, 72, "DOMAIN", "Services", "BaseService · 20 singletons"))
    b.append(node(350, 200, 300, 72, "ORM", "LessQL\\Database", "$this->DB->products()->where()"))
    b.append(node(350, 328, 300, 80, "CORE", "DatabaseService", "one PDO · transactions · change tracking", kind="focal"))
    b.append(node(70, 336, 200, 64, "DIALECT", "PostgresDialect", "quoting · locks · changed-time"))
    b.append(node(730, 336, 200, 64, "ASYNC", "Outbox · MQTT · Influx", "after outermost commit", kind="optional"))
    b.append(node(350, 464, 300, 80, "ENGINE", "PostgreSQL 16", "46 tables · 44 views · 55 triggers", kind="store"))
    b.append(legend(572, 1000, [
        ("swatch", (ACC_04, ACCENT, "Single point of access")),
        ("swatch", (WHITE, INK, "PHP class")),
        ("swatch", ("rgba(23,75,58,0.05)", MUTED, "Engine")),
        ("swatch-dashed", ("rgba(23,75,58,0.02)", "rgba(23,75,58,0.30)", "Deferred to commit")),
        ("line", ("dashed", "Asynchronous")),
    ]))
    write("orm-stack.html", page(
        "orm-stack", "ARCHITECTURE · VICTUAL", "Data access · from request to engine",
        "0 0 1000 640",
        "Victual data access stack",
        "Controllers and services both hold a LessQL connection; every statement, including raw SQL, "
        "passes through DatabaseService, which owns the single PDO connection, the PostgreSQL dialect, "
        "transaction nesting and change tracking, and defers outbox, MQTT and Influx publishing until "
        "after the outermost commit.",
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
    b.append(label(572, 428, "44 VIEWS"))
    b.append(node(80, 80, 280, 88, "5 TABLES", "Recipes & meal plan", "recipes · recipes_pos · meal_plan"))
    b.append(node(460, 80, 280, 88, "7 TABLES", "Household", "chores · tasks · batteries · equipment"))
    b.append(node(840, 80, 280, 88, "11 TABLES", "Identity & access", "users · roles · permissions · sessions"))
    b.append(node(80, 288, 200, 104, "4 TABLES", "Extensibility", "userfields · userentities", kind="optional"))
    b.append(node(340, 288, 400, 104, "12 TABLES", "Stock & products", "products · stock · stock_log · locations", kind="focal"))
    b.append(node(840, 288, 280, 104, "7 TABLES", "Caches & infrastructure", "cache__* · outbox · files · mqtt_*", kind="store"))
    b.append(node(340, 496, 400, 88, "DERIVED", "Read layer", "44 views · 3 of them materialised as cache__*", kind="store"))
    b.append(legend(624, 1200, [
        ("swatch", (ACC_04, ACCENT, "Hub domain")),
        ("swatch", (WHITE, INK, "Table cluster")),
        ("swatch", ("rgba(23,75,58,0.05)", MUTED, "Derived or infrastructural")),
        ("line", ("dashed", "Untyped reference (object_id is plain text)")),
        ("line", ("solid", "Column named for its target")),
    ]))
    write("schema-map.html", page(
        "schema-map", "ARCHITECTURE · VICTUAL", "Schema map · 46 tables in six clusters",
        "0 0 1200 700",
        "Victual schema map",
        "Six table clusters around a stock and products hub: recipes, household chores and tasks, "
        "identity and access, extensibility, and caches. Arrows are the columns that name another "
        "cluster's rows; a read layer of 44 views sits below the hub.",
        "\n".join(b), min_width=1100))


# ================================================================ 3. ERD stock

def erd_stock():
    b = []
    # relationships
    b.append(rel("M 300,152 H 352 Q 360,152 360,160 V 280 Q 360,288 368,288 H 420"))
    b.append(card(316, 132, "1"))
    b.append(card(404, 268, "N"))
    b.append(rel("M 300,336 H 420"))
    b.append(card(316, 316, "1"))
    b.append(card(404, 316, "N"))
    b.append(rel("M 300,520 H 352 Q 360,520 360,512 V 392 Q 360,384 368,384 H 420"))
    b.append(card(316, 500, "1"))
    b.append(card(404, 364, "N"))
    b.append(rel("M 740,288 H 776 Q 784,288 784,280 V 180 Q 784,172 792,172 H 860"))
    b.append(card(756, 268, "1"))
    b.append(card(844, 152, "N"))
    b.append(rel("M 740,352 H 792 Q 800,352 800,360 V 388 Q 800,396 808,396 H 860"))
    b.append(card(756, 332, "1"))
    b.append(card(844, 376, "N"))
    b.append(rel("M 620,472 V 592 Q 620,600 628,600 H 860"))
    b.append(card(636, 484, "1"))
    b.append(card(844, 580, "N"))
    b.append(rel("M 540,472 V 776 Q 540,784 548,784 H 860"))
    b.append(card(556, 484, "1"))
    b.append(card(844, 764, "N"))
    b.append(rel("M 560,248 V 232 Q 560,224 568,224 H 632 Q 640,224 640,232 V 248"))
    b.append(label(600, 204, "PARENT PRODUCT"))
    # entities
    b.append(entity(40, 80, 260, "product_groups", [
        ("# id", "int"), ("name", "text uq"), ("min_stock_amount", "double"), ("active", "0/1")]))
    b.append(entity(40, 264, 260, "quantity_units", [
        ("# id", "int"), ("name", "text uq"), ("name_plural", "text"), ("active", "0/1")]))
    b.append(entity(40, 448, 260, "locations", [
        ("# id", "int"), ("name", "text uq"), ("is_freezer", "0/1"), ("active", "0/1")]))
    b.append(entity(420, 248, 320, "products", [
        ("# id", "int"), ("name", "text uq"), ("→ product_group_id", "int"),
        ("→ location_id", "int"), ("→ qu_id_stock", "int"),
        ("→ qu_id_purchase", "int"), ("→ parent_product_id", "int"),
        ("min_stock_amount", "double")], tag="ENTITY · HUB", focal=True))
    b.append(entity(860, 80, 300, "stock", [
        ("# id", "int"), ("→ product_id", "int"), ("stock_id", "text"),
        ("amount", "double"), ("best_before_date", "date"), ("→ location_id", "int")]))
    b.append(entity(860, 304, 300, "stock_log", [
        ("# id", "int"), ("→ product_id", "int"), ("stock_id", "text"),
        ("transaction_type", "text"), ("→ user_id ↗", "users"), ("undone", "0/1")],
        tag="LEDGER"))
    b.append(entity(860, 528, 300, "product_barcodes", [
        ("# id", "int"), ("→ product_id", "int"), ("barcode", "text"), ("→ qu_id", "int")]))
    b.append(entity(860, 712, 300, "shopping_list", [
        ("# id", "int"), ("→ product_id", "int"), ("→ shopping_list_id", "int"),
        ("amount", "double")]))
    b.append(legend(896, 1200, [
        ("swatch", (ACC_04, ACCENT, "Hub entity")),
        ("glyph", ("#", "Primary key")),
        ("glyph", ("→", "Reference column")),
        ("glyph", ("↗", "Target lives in another diagram")),
        ("line", ("dashed", "Convention only — no FOREIGN KEY declared")),
    ]))
    write("erd-stock.html", page(
        "erd-stock", "ER · VICTUAL", "Stock & products · the hub cluster",
        "0 0 1200 980",
        "Victual stock and products data model",
        "Products sits at the centre of the stock cluster: it names a product group, a location and "
        "two quantity units, may name a parent product, and is referenced by stock, the stock_log "
        "ledger, barcodes and shopping list rows. None of these references is a declared foreign key.",
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
    b.append(rel("M 280,436 H 532 a 8,8 0 0,1 16,0 H 592 a 8,8 0 0,1 16,0 H 860"))
    b.append(card(296, 416, "N"))
    b.append(card(844, 416, "1"))
    b.append(rel("M 720,240 H 782 Q 790,240 790,232 V 180 Q 790,172 798,172 H 860"))
    b.append(card(736, 220, "1"))
    b.append(card(844, 152, "N"))
    b.append(rel("M 1010,264 V 352"))
    b.append(card(1026, 276, "N"))
    b.append(card(1026, 332, "1"))
    b.append(rel("M 720,320 H 798 Q 806,320 806,328 V 388 Q 806,396 814,396 H 860"))
    b.append(card(736, 300, "N"))
    b.append(card(844, 376, "1"))
    b.append(rel("M 540,404 V 560"))
    b.append(card(556, 416, "1"))
    b.append(card(556, 540, "N"))
    b.append(rel("M 600,560 V 404"))
    b.append(label(632, 472, "INCLUDES"))
    b.append(entity(40, 80, 240, "meal_plan_sections", [
        ("# id", "int"), ("name", "text uq"), ("sort_number", "int"), ("time_info", "text")]))
    b.append(entity(40, 304, 240, "meal_plan", [
        ("# id", "int"), ("day", "date"), ("type", "text"), ("→ recipe_id", "int"),
        ("→ product_id", "int"), ("→ section_id", "int"), ("done", "0/1")]))
    b.append(entity(420, 200, 300, "recipes", [
        ("# id", "int"), ("name", "text"), ("type", "text"), ("base_servings", "double"),
        ("desired_servings", "double"), ("→ product_id ↗", "products"),
        ("not_check_shoppinglist", "0/1")], tag="ENTITY · HUB", focal=True))
    b.append(entity(420, 560, 300, "recipes_nestings", [
        ("# id", "int"), ("→ recipe_id", "int"), ("→ includes_recipe_id", "int"),
        ("servings", "double")], tag="SELF-JOIN"))
    b.append(entity(860, 80, 300, "recipes_pos", [
        ("# id", "int"), ("→ recipe_id", "int"), ("→ product_id", "int"),
        ("amount", "double"), ("→ qu_id ↗", "quantity_units"),
        ("ingredient_group", "text")], tag="LINE ITEM"))
    b.append(entity(860, 352, 300, "products", [
        ("# id", "int"), ("name", "text uq"), ("…", "see erd-stock")],
        tag="REFERENCE", ref=True))
    b.append(legend(744, 1200, [
        ("swatch", (ACC_04, ACCENT, "Hub entity")),
        ("swatch-dashed", ("rgba(23,75,58,0.03)", "rgba(23,75,58,0.30)", "Entity owned by another diagram")),
        ("glyph", ("→", "Reference column")),
        ("line", ("dashed", "Convention only — no FOREIGN KEY declared")),
    ]))
    write("erd-recipes.html", page(
        "erd-recipes", "ER · VICTUAL", "Recipes & meal plan",
        "0 0 1200 820",
        "Victual recipes and meal plan data model",
        "Recipes carry line items in recipes_pos and nest inside each other through recipes_nestings, "
        "which names a recipe twice. Meal plan entries point at a recipe, a product or a note, and "
        "group under a meal plan section. Two triggers guard the nesting table against self-inclusion "
        "and cycles.",
        "\n".join(b), min_width=1100))


# ============================================================= 5. ERD identity

def erd_identity():
    b = []
    b.append(rel("M 440,162 H 320"))
    b.append(card(424, 142, "1"))
    b.append(card(336, 142, "N"))
    b.append(rel("M 440,200 H 388 Q 380,200 380,208 V 378 Q 380,386 372,386 H 320"))
    b.append(card(424, 180, "1"))
    b.append(card(336, 366, "N"))
    b.append(rel("M 600,244 V 344"))
    b.append(card(616, 256, "1"))
    b.append(card(616, 324, "N"))
    b.append(rel("M 600,468 V 548"))
    b.append(card(616, 480, "N"))
    b.append(card(616, 528, "1"))
    b.append(rel("M 760,132 H 880", dashed=False))
    b.append(card(776, 112, "1"))
    b.append(card(864, 112, "N"))
    b.append(rel("M 1020,184 V 264", dashed=False))
    b.append(card(1036, 196, "N"))
    b.append(card(1036, 244, "1"))
    b.append(rel("M 1020,408 V 488", dashed=False))
    b.append(card(1036, 420, "1"))
    b.append(card(1036, 468, "N"))
    b.append(rel("M 880,560 H 760", dashed=False))
    b.append(card(864, 540, "N"))
    b.append(card(776, 540, "1"))
    b.append(entity(40, 80, 280, "sessions", [
        ("# id", "int"), ("session_key", "text uq"), ("→ user_id", "int"),
        ("expires", "timestamp"), ("last_used", "timestamp")]))
    b.append(entity(40, 304, 280, "api_keys", [
        ("# id", "int"), ("api_key", "text uq"), ("→ user_id", "int"),
        ("key_type", "text"), ("expires", "timestamp")]))
    b.append(entity(440, 80, 320, "users", [
        ("# id", "int"), ("username", "text uq"), ("password", "text"),
        ("first_name", "text"), ("last_name", "text")], tag="ENTITY · HUB", focal=True))
    b.append(entity(440, 344, 320, "user_permissions", [
        ("# id", "int"), ("→ user_id", "int"), ("→ permission_id", "int")],
        tag="DIRECT GRANT"))
    b.append(entity(440, 548, 320, "permission_hierarchy", [
        ("# id", "int"), ("name", "text uq"), ("→ parent", "int")], tag="TREE"))
    b.append(entity(880, 80, 280, "user_roles", [
        ("→ user_id", "int pk"), ("→ role_id", "int pk")], tag="JOIN · FK"))
    b.append(entity(880, 264, 280, "roles", [
        ("# id", "int"), ("code", "text uq"), ("name", "text uq"), ("builtin", "0/1")], tag="ENTITY · FK"))
    b.append(entity(880, 488, 280, "role_permissions", [
        ("→ role_id", "int pk"), ("→ permission_id", "int pk")], tag="JOIN · FK"))
    b.append(legend(712, 1200, [
        ("swatch", (ACC_04, ACCENT, "Hub entity")),
        ("line", ("solid", "Declared FOREIGN KEY (the only four in the schema)")),
        ("line", ("dashed", "Convention only — no constraint")),
        ("glyph", ("#", "Primary key")),
    ]))
    write("erd-identity.html", page(
        "erd-identity", "ER · VICTUAL", "Identity & access",
        "0 0 1200 800",
        "Victual identity and access data model",
        "Users hold sessions, API keys and direct permission grants; permissions form a tree that "
        "user_permissions_resolved walks. The roles cluster added in wave 3a is the only part of the "
        "schema with declared foreign keys — four of them, drawn solid.",
        "\n".join(b), min_width=1100))


# ============================================================ 6. ERD household

def erd_household():
    b = []
    b.append(rel("M 740,172 H 860"))
    b.append(card(756, 152, "1"))
    b.append(card(844, 152, "N"))
    b.append(rel("M 740,416 H 860"))
    b.append(card(756, 396, "N"))
    b.append(card(844, 396, "1"))
    b.append(rel("M 740,660 H 860"))
    b.append(card(756, 640, "1"))
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
    b.append(entity(420, 80, 320, "chores", [
        ("# id", "int"), ("name", "text uq"), ("period_type", "text"),
        ("→ product_id ↗", "products"),
        ("→ next_exec_user_id ↗", "users"), ("active", "0/1")],
        tag="ENTITY · HUB", focal=True))
    b.append(entity(860, 80, 300, "chores_log", [
        ("# id", "int"), ("→ chore_id", "int"), ("tracked_time", "timestamp"),
        ("→ done_by_user_id ↗", "users"), ("undone", "0/1"), ("skipped", "0/1")],
        tag="LEDGER"))
    b.append(entity(420, 344, 320, "tasks", [
        ("# id", "int"), ("name", "text"), ("due_date", "date"),
        ("→ category_id", "int"), ("→ assigned_to_user_id ↗", "users")]))
    b.append(entity(860, 344, 300, "task_categories", [
        ("# id", "int"), ("name", "text uq"), ("description", "text"), ("active", "0/1")]))
    b.append(entity(420, 588, 320, "batteries", [
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


if __name__ == "__main__":
    OUT.mkdir(parents=True, exist_ok=True)
    orm()
    schema_map()
    erd_stock()
    erd_recipes()
    erd_identity()
    erd_household()
