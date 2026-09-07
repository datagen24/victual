//! Candidate C — one engine measures and draws.
//!
//! The comparison that produced this found two disqualifying properties elsewhere: Pillow
//! cannot scale a glyph anisotropically, and an emitter in front of the resvg CLI measures
//! with a different shaper than the one that draws. This binary removes both. Layout is
//! usvg's own text conversion, measured by converting the very text node that will be
//! painted; anisotropy is a transform on outlines, so nothing is resampled.

use std::path::{Path, PathBuf};
use serde_json::Value;

const MM: f32 = 25.4;

#[derive(Debug)]
struct Fail { code: &'static str, element: Option<String>, detail: String }

fn fail(code: &'static str, element: Option<&str>, detail: impl Into<String>) -> Fail {
    Fail { code, element: element.map(|s| s.to_string()), detail: detail.into() }
}

fn f(v: &Value, k: &str) -> f32 { v.get(k).and_then(|x| x.as_f64()).unwrap_or(0.0) as f32 }

/// A text node measured by the engine that will paint it. `font_px` is in x-resolution
/// device pixels, which is the space line breaking happens in.
fn measure(text: &str, family: &str, font_px: f32, db: &fontdb::Database) -> f32 {
    if text.is_empty() { return 0.0; }
    let svg = format!(
        r##"<svg xmlns="http://www.w3.org/2000/svg" width="100000" height="1000"><text x="0" y="500" font-family="{}" font-size="{}">{}</text></svg>"##,
        family, font_px, escape(text));
    let mut opt = usvg::Options::default();
    opt.fontdb = std::sync::Arc::new(db.clone());
    match usvg::Tree::from_str(&svg, &opt) {
        Ok(tree) => tree.root().abs_bounding_box().width(),
        Err(_) => 0.0,
    }
}

fn escape(s: &str) -> String {
    s.replace('&', "&amp;").replace('<', "&lt;").replace('>', "&gt;")
}

fn main() {
    let mut args = std::env::args().skip(1);
    let (mut dir, mut case_id, mut fonts, mut out) =
        (PathBuf::new(), String::new(), PathBuf::new(), PathBuf::new());
    while let Some(a) = args.next() {
        let v = args.next().unwrap_or_default();
        match a.as_str() {
            "--dir" => dir = PathBuf::from(v),
            "--case" => case_id = v,
            "--fonts" => fonts = PathBuf::from(v),
            "--out" => out = PathBuf::from(v),
            _ => {}
        }
    }
    match render(&dir, &case_id, &fonts, &out) {
        Ok(report) => println!("{}", report),
        Err(e) => {
            println!("{}", serde_json::json!({
                "ok": false, "case": case_id, "code": e.code,
                "element": e.element, "detail": e.detail }));
            std::process::exit(3);
        }
    }
}

fn render(dir: &Path, case_id: &str, fonts: &Path, out: &Path) -> Result<String, Fail> {
    let read = |n: &str| -> Value {
        serde_json::from_str(&std::fs::read_to_string(dir.join(n)).expect(n)).expect(n)
    };
    let tpl = read("template.json");
    let prof = read("profile.json");
    let cases = read("cases.json");
    let case = cases.as_array().unwrap().iter()
        .find(|c| c["id"] == case_id)
        .ok_or_else(|| fail("UNKNOWN_CASE", None, "no such case"))?.clone();

    let dpi_x = f(&prof, "dpi_x");
    let dpi_y = f(&prof, "dpi_y");
    let width = f(&prof, "raster_width_px") as u32;
    let px_x = |mm: f32| (mm * dpi_x / MM).round();
    let px_y = |mm: f32| (mm * dpi_y / MM).round();

    let els = tpl["elements"].as_array().unwrap();
    let text_el = els.iter().find(|e| e["type"] == "text").unwrap();
    let qr_el = els.iter().find(|e| e["type"] == "qr").unwrap();
    let rect_el = els.iter().find(|e| e["type"] == "rect");

    // The pinned font, loaded once and used for both the cmap check and the layout.
    let asset_id = text_el["font"].as_str().unwrap();
    let asset = tpl["assets"].as_array().unwrap().iter()
        .find(|a| a["id"] == asset_id).unwrap();
    let font_file = fonts.join(asset["file"].as_str().unwrap());
    let font_bytes = std::fs::read(&font_file)
        .map_err(|e| fail("ASSET_UNAVAILABLE", None, format!("{}: {}", font_file.display(), e)))?;

    let name = case["location.name"].as_str().unwrap_or("");

    // A missing glyph is an error, and neither rasterizer reports one — so ask the font.
    let face = ttf_parser::Face::parse(&font_bytes, 0)
        .map_err(|e| fail("ASSET_UNAVAILABLE", None, format!("unparsable font: {e}")))?;
    let missing: String = name.chars()
        .filter(|c| !c.is_whitespace() && face.glyph_index(*c).is_none())
        .collect();
    if !missing.is_empty() {
        return Err(fail("MISSING_GLYPH", text_el["id"].as_str(),
                        format!("font has no glyph for {missing}")));
    }

    let mut db = fontdb::Database::new();
    db.load_font_data(font_bytes.clone());
    let family = db.faces().next()
        .and_then(|face| face.families.first().map(|(n, _)| n.clone()))
        .unwrap_or_else(|| "sans-serif".into());

    // Points are physical. The glyph is therefore size_pt/72 inch on both axes, which is a
    // different number of device pixels on each — the whole reason this candidate exists.
    let size_pt = case.get("size_pt").and_then(|v| v.as_f64())
        .unwrap_or(text_el["size_pt"].as_f64().unwrap()) as f32;
    let font_px_x = size_pt * dpi_x / 72.0;
    let aniso = dpi_y / dpi_x;

    let box_w = px_x(f(&text_el["box"], "width_mm"));
    let mut lines: Vec<String> = Vec::new();
    let mut cur = String::new();
    for word in name.split_whitespace() {
        let trial = if cur.is_empty() { word.to_string() } else { format!("{cur} {word}") };
        if measure(&trial, &family, font_px_x, &db) <= box_w || cur.is_empty() {
            cur = trial;
        } else {
            lines.push(std::mem::take(&mut cur));
            cur = word.to_string();
        }
    }
    if !cur.is_empty() { lines.push(cur); }
    for l in &lines {
        if measure(l, &family, font_px_x, &db) > box_w {
            return Err(fail("TEXT_OVERFLOW", text_el["id"].as_str(),
                            format!("line does not fit box: {l:?}")));
        }
    }

    // QR: whole device pixels per module on each axis.
    let payload = case.get("label.payload").and_then(|v| v.as_str())
        .unwrap_or("vctl:0123456789ABC");
    let qr = qrcodegen::QrCode::encode_text(payload, qrcodegen::QrCodeEcc::Low)
        .map_err(|e| fail("QR_TOO_SMALL", qr_el["id"].as_str(), format!("{e}")))?;
    let n = qr.size();
    let quiet = qr_el["quiet_zone_modules"].as_i64().unwrap_or(4) as i32;
    let min_mm = f(qr_el, "min_module_mm");
    let mod_x = (min_mm * dpi_x / MM).ceil().max(1.0);
    let mod_y = (min_mm * dpi_y / MM).ceil().max(1.0);
    let qr_total = n + 2 * quiet;

    let line_h_y = size_pt * dpi_y / 72.0 * f(text_el, "line_spacing");
    let text_top = px_y(f(&text_el["box"], "y_mm"));
    let content_bottom = (text_top + line_h_y * lines.len() as f32)
        .max(px_y(f(&qr_el["at"], "y_mm")) + qr_total as f32 * mod_y);
    let mut height = content_bottom + px_y(f(&tpl["size"], "bottom_spacing_mm"));

    let rules = &prof["length_rules"];
    let mut length_mm = height * MM / dpi_y;
    let max_mm = f(rules, "max_mm");
    if length_mm > max_mm {
        return Err(fail("MEDIA_INCOMPATIBLE", None,
            format!("automatic height {length_mm:.1}mm exceeds length_rules.max_mm {max_mm}")));
    }
    let inc = f(rules, "increment_mm");
    length_mm = (length_mm / inc).ceil() * inc;
    height = (length_mm * dpi_y / MM).round();

    // Assemble. Text sits inside an anisotropic scale so outlines — not pixels — are
    // stretched; every y inside that group is divided by the scale to stay in place.
    let mut svg = format!(
        r##"<svg xmlns="http://www.w3.org/2000/svg" width="{w}" height="{h}" viewBox="0 0 {w} {h}"><rect width="100%" height="100%" fill="#ffffff"/>"##,
        w = width, h = height as u32);

    if let Some(r) = rect_el {
        if case.get("accent").and_then(|v| v.as_bool()).unwrap_or(false) {
            svg.push_str(&format!(
                r##"<rect x="{}" y="{}" width="{}" height="{}" fill="#ff0000"/>"##,
                px_x(f(&r["at"], "x_mm")), px_y(f(&r["at"], "y_mm")),
                px_x(f(&r["size"], "width_mm")), px_y(f(&r["size"], "height_mm"))));
        }
    }

    let qx = px_x(f(&qr_el["at"], "x_mm"));
    let qy = px_y(f(&qr_el["at"], "y_mm"));
    for y in 0..qr_total {
        for x in 0..qr_total {
            if qr.get_module(x - quiet, y - quiet) {
                svg.push_str(&format!(
                    r##"<rect x="{}" y="{}" width="{}" height="{}" fill="#000000" shape-rendering="crispEdges"/>"##,
                    qx + x as f32 * mod_x, qy + y as f32 * mod_y, mod_x, mod_y));
            }
        }
    }

    let tx = px_x(f(&text_el["box"], "x_mm"));
    svg.push_str(&format!(r##"<g transform="scale(1,{aniso})" font-family="{family}" font-size="{font_px_x}" fill="#000000">"##));
    for (i, l) in lines.iter().enumerate() {
        let baseline_y = (text_top + line_h_y * (i as f32 + 1.0)) / aniso;
        svg.push_str(&format!(r##"<text x="{tx}" y="{baseline_y}">{}</text>"##, escape(l)));
    }
    svg.push_str("</g></svg>");
    std::fs::write(out.with_extension("svg"), &svg).ok();

    let mut opt = usvg::Options::default();
    opt.fontdb = std::sync::Arc::new(db.clone());
    let tree = usvg::Tree::from_str(&svg, &opt)
        .map_err(|e| fail("RENDER_FAILED", None, format!("{e}")))?;
    let mut pixmap = tiny_skia::Pixmap::new(width, height as u32)
        .ok_or_else(|| fail("RENDER_FAILED", None, "pixmap allocation failed"))?;
    pixmap.fill(tiny_skia::Color::WHITE);
    resvg::render(&tree, tiny_skia::Transform::identity(), &mut pixmap.as_mut());

    // Threshold to the profile palette. Neither candidate in the earlier comparison emitted
    // a palette-conformant artifact for free, and this is where that step belongs.
    let threshold = prof["pixel_policy"]["threshold"].as_f64().unwrap_or(128.0) as u8;
    let mut counts = (0u32, 0u32, 0u32);
    for px in pixmap.pixels_mut() {
        let (r, g, b) = (px.red(), px.green(), px.blue());
        let is_red = r > 128 && g < 128 && b < 128;
        let lum = (0.299 * r as f32 + 0.587 * g as f32 + 0.114 * b as f32) as u8;
        let c = if is_red { counts.1 += 1; tiny_skia::ColorU8::from_rgba(255, 0, 0, 255) }
                else if lum < threshold { counts.0 += 1; tiny_skia::ColorU8::from_rgba(0, 0, 0, 255) }
                else { counts.2 += 1; tiny_skia::ColorU8::from_rgba(255, 255, 255, 255) };
        *px = c.premultiply();
    }
    pixmap.save_png(out).map_err(|e| fail("RENDER_FAILED", None, format!("{e}")))?;

    Ok(serde_json::json!({
        "ok": true, "case": case_id, "width_px": width, "height_px": height as u32,
        "length_mm": (length_mm * 100.0).round() / 100.0, "lines": lines.len(),
        "qr_modules": qr_total, "module_px": [mod_x as u32, mod_y as u32],
        "aniso": aniso, "palette": {"black": counts.0, "red": counts.1, "white": counts.2},
        "has_red": counts.1 > 0
    }).to_string())
}
