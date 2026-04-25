// Node-oriented editable pro deck builder.
// Run this after editing SLIDES, SOURCES, and layout functions.
// The init script installs a sibling node_modules/@oai/artifact-tool package link
// and package.json with type=module for shell-run eval builders. Run with the
// Node executable from Codex workspace dependencies or the platform-appropriate
// command emitted by the init script.
// Do not use pnpm exec from the repo root or any Node binary whose module
// lookup cannot resolve the builder's sibling node_modules/@oai/artifact-tool.

const fs = await import("node:fs/promises");
const path = await import("node:path");
const { Presentation, PresentationFile } = await import("@oai/artifact-tool");

const W = 1280;
const H = 720;

const DECK_ID = "codex-ops-platform-architecture";
const OUT_DIR = "/Users/odahiroki/Desktop/matsunoya-mt/meal.posla.jp(正)/outputs/codex-ops-platform-architecture";
const REF_DIR = "/Users/odahiroki/Desktop/matsunoya-mt/meal.posla.jp(正)/tmp/slides/pro-reference-images";
const SCRATCH_DIR = path.resolve(process.env.PPTX_SCRATCH_DIR || path.join("tmp", "slides", DECK_ID));
const PREVIEW_DIR = path.join(SCRATCH_DIR, "preview");
const VERIFICATION_DIR = path.join(SCRATCH_DIR, "verification");
const INSPECT_PATH = path.join(SCRATCH_DIR, "inspect.ndjson");
const MAX_RENDER_VERIFY_LOOPS = 3;

const INK = "#101214";
const GRAPHITE = "#30363A";
const MUTED = "#687076";
const PAPER = "#F7F4ED";
const PAPER_96 = "#F7F4EDF5";
const WHITE = "#FFFFFF";
const ACCENT = "#27C47D";
const ACCENT_DARK = "#116B49";
const GOLD = "#D7A83D";
const CORAL = "#E86F5B";
const TRANSPARENT = "#00000000";

const TITLE_FACE = "Caladea";
const BODY_FACE = "Lato";
const MONO_FACE = "Aptos Mono";

const FALLBACK_PLATE_DATA_URL =
  "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=";

const SOURCES = {
  primary: "docs/codex-ops-platform-architecture.md (2026-04-24)",
  context: "運用方針: product内 help は非AI、AI運用支援は別系統",
};

const SLIDES = [
  {
    kicker: "CODEX OPS PLATFORM",
    title: "Codex 運用支援基盤",
    subtitle: "POSLA を含む複数サービス向け\nprivate 接続型の運用支援アーキテクチャ案",
    moment: "APP の外に置く",
    notes: "表紙。プロダクト内 help ではなく、別系統の運用支援基盤であることを最初に強調する。",
    sources: ["primary", "context"],
    kind: "cover",
  },
  {
    kicker: "WHY NOW",
    title: "背景と設計前提",
    subtitle: "FAQ の代替ではなく、社内向けの運用支援基盤として切り出す。",
    cards: [
      [
        "背景",
        "POSLA 内の help は tenant / 管理側ともに非AIへ整理済み。AI は運用調査や生成など、価値が高い用途へ寄せる方針。",
      ],
      [
        "目的",
        "障害の一次調査、原因候補の特定、解決策の提示、承認後の実行補助までを一つの運用 UI から扱えるようにする。",
      ],
      [
        "前提",
        "POSLA 本体が不調でも使えることを優先し、運用支援は product の内部機能としては持たない。",
      ],
    ],
    notes: "なぜ別系統で作るかを説明する導入スライド。",
    sources: ["primary", "context"],
    kind: "cards",
  },
  {
    kicker: "TARGET ARCHITECTURE",
    title: "推奨構成",
    subtitle: "Codex は APP と DB の外に置き、private network 経由で接続する。",
    notes: "Codex UI/API、POSLA APP、POSLA DB、将来の他サービスを1枚で見せる。",
    sources: ["primary"],
    kind: "diagram",
  },
  {
    kicker: "BOUNDARY",
    title: "責務分離と権限モデル",
    subtitle: "product 内 help と、外部の運用支援を混ぜない。",
    notes: "責務分離と read_only / propose_only / approved_execute の段階制を説明する。",
    sources: ["primary", "context"],
    kind: "governance",
  },
  {
    kicker: "POSLA MVP",
    title: "POSLA への初期適用範囲",
    subtitle: "まずは read-only で始め、調査品質と安全性を先に固める。",
    cards: [
      [
        "最初にやること",
        "docs 検索、ログ参照、read-only SQL、health check、エラーコード参照、原因候補提示、手順提示。",
      ],
      [
        "接続対象",
        "APP、DB、internal docs、運用 runbook、監視 API、将来の deploy 先。まずは POSLA を 1 本目の connector として実装する。",
      ],
      [
        "成功条件",
        "障害報告を受けて、画面・tenant・store・時刻・エラーコードを整理し、一次切り分け案を短時間で返せること。",
      ],
    ],
    notes: "MVPは read-only と明確に区切る。",
    sources: ["primary"],
    kind: "cards",
  },
  {
    kicker: "ROADMAP",
    title: "将来拡張",
    subtitle: "承認後実行と複数サービス接続へ段階的に拡張する。",
    cards: [
      [
        "承認後実行",
        "次段階では cache clear、docs deploy、非破壊 SQL、アプリ deploy などを、明示承認付きで限定開放する。",
      ],
      [
        "複数サービス",
        "POSLA 以外の社内サービスも connector 単位で追加する。Codex 本体は共通化し、サービスごとの capability だけ増やす。",
      ],
      [
        "権限管理",
        "docs.read、logs.read、db.read、db.write、deploy.run など capability 単位で権限を管理し、用途に応じて分離する。",
      ],
    ],
    notes: "MVP後の伸ばし方を示すロードマップ。",
    sources: ["primary"],
    kind: "cards",
  },
  {
    kicker: "INFRA VIEW",
    title: "インフラ要件と推奨パターン",
    subtitle: "最小構成と本番推奨を分け、private 接続と監査ログを前提に設計する。",
    notes: "最小構成と本番推奨、必須インフラ要件をまとめる締め。",
    sources: ["primary"],
    kind: "infra",
  },
];

const inspectRecords = [];

async function pathExists(filePath) {
  try {
    await fs.access(filePath);
    return true;
  } catch {
    return false;
  }
}

async function readImageBlob(imagePath) {
  const bytes = await fs.readFile(imagePath);
  if (!bytes.byteLength) {
    throw new Error(`Image file is empty: ${imagePath}`);
  }
  return bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength);
}

async function normalizeImageConfig(config) {
  if (!config.path) {
    return config;
  }
  const { path: imagePath, ...rest } = config;
  return {
    ...rest,
    blob: await readImageBlob(imagePath),
  };
}

async function ensureDirs() {
  await fs.mkdir(OUT_DIR, { recursive: true });
  const obsoleteFinalArtifacts = [
    "preview",
    "verification",
    "inspect.ndjson",
    ["presentation", "proto.json"].join("_"),
    ["quality", "report.json"].join("_"),
  ];
  for (const obsolete of obsoleteFinalArtifacts) {
    await fs.rm(path.join(OUT_DIR, obsolete), { recursive: true, force: true });
  }
  await fs.mkdir(SCRATCH_DIR, { recursive: true });
  await fs.mkdir(PREVIEW_DIR, { recursive: true });
  await fs.mkdir(VERIFICATION_DIR, { recursive: true });
}

function lineConfig(fill = TRANSPARENT, width = 0) {
  return { style: "solid", fill, width };
}

function recordShape(slideNo, shape, role, shapeType, x, y, w, h) {
  if (!slideNo) return;
  inspectRecords.push({
    kind: "shape",
    slide: slideNo,
    id: shape?.id || `slide-${slideNo}-${role}-${inspectRecords.length + 1}`,
    role,
    shapeType,
    bbox: [x, y, w, h],
  });
}

function addShape(slide, geometry, x, y, w, h, fill = TRANSPARENT, line = TRANSPARENT, lineWidth = 0, meta = {}) {
  const shape = slide.shapes.add({
    geometry,
    position: { left: x, top: y, width: w, height: h },
    fill,
    line: lineConfig(line, lineWidth),
  });
  recordShape(meta.slideNo, shape, meta.role || geometry, geometry, x, y, w, h);
  return shape;
}

function normalizeText(text) {
  if (Array.isArray(text)) {
    return text.map((item) => String(item ?? "")).join("\n");
  }
  return String(text ?? "");
}

function textLineCount(text) {
  const value = normalizeText(text);
  if (!value.trim()) {
    return 0;
  }
  return Math.max(1, value.split(/\n/).length);
}

function requiredTextHeight(text, fontSize, lineHeight = 1.18, minHeight = 8) {
  const lines = textLineCount(text);
  if (lines === 0) {
    return minHeight;
  }
  return Math.max(minHeight, lines * fontSize * lineHeight);
}

function assertTextFits(text, boxHeight, fontSize, role = "text") {
  const required = requiredTextHeight(text, fontSize);
  const tolerance = Math.max(2, fontSize * 0.08);
  if (normalizeText(text).trim() && boxHeight + tolerance < required) {
    throw new Error(
      `${role} text box is too short: height=${boxHeight.toFixed(1)}, required>=${required.toFixed(1)}, ` +
        `lines=${textLineCount(text)}, fontSize=${fontSize}, text=${JSON.stringify(normalizeText(text).slice(0, 90))}`,
    );
  }
}

function wrapText(text, widthChars) {
  const words = normalizeText(text).split(/\s+/).filter(Boolean);
  const lines = [];
  let current = "";
  for (const word of words) {
    const next = current ? `${current} ${word}` : word;
    if (next.length > widthChars && current) {
      lines.push(current);
      current = word;
    } else {
      current = next;
    }
  }
  if (current) {
    lines.push(current);
  }
  return lines.join("\n");
}

function recordText(slideNo, shape, role, text, x, y, w, h) {
  const value = normalizeText(text);
  inspectRecords.push({
    kind: "textbox",
    slide: slideNo,
    id: shape?.id || `slide-${slideNo}-${role}-${inspectRecords.length + 1}`,
    role,
    text: value,
    textPreview: value.replace(/\n/g, " | ").slice(0, 180),
    textChars: value.length,
    textLines: textLineCount(value),
    bbox: [x, y, w, h],
  });
}

function recordImage(slideNo, image, role, imagePath, x, y, w, h) {
  inspectRecords.push({
    kind: "image",
    slide: slideNo,
    id: image?.id || `slide-${slideNo}-${role}-${inspectRecords.length + 1}`,
    role,
    path: imagePath,
    bbox: [x, y, w, h],
  });
}

function applyTextStyle(box, text, size, color, bold, face, align, valign, autoFit, listStyle) {
  box.text = text;
  box.text.fontSize = size;
  box.text.color = color;
  box.text.bold = Boolean(bold);
  box.text.alignment = align;
  box.text.verticalAlignment = valign;
  box.text.typeface = face;
  box.text.insets = { left: 0, right: 0, top: 0, bottom: 0 };
  if (autoFit) {
    box.text.autoFit = autoFit;
  }
  if (listStyle) {
    box.text.style = "list";
  }
}

function addText(
  slide,
  slideNo,
  text,
  x,
  y,
  w,
  h,
  {
    size = 22,
    color = INK,
    bold = false,
    face = BODY_FACE,
    align = "left",
    valign = "top",
    fill = TRANSPARENT,
    line = TRANSPARENT,
    lineWidth = 0,
    autoFit = null,
    listStyle = false,
    checkFit = true,
    role = "text",
  } = {},
) {
  if (!checkFit && textLineCount(text) > 1) {
    throw new Error("checkFit=false is only allowed for single-line headers, footers, and captions.");
  }
  if (checkFit) {
    assertTextFits(text, h, size, role);
  }
  const box = addShape(slide, "rect", x, y, w, h, fill, line, lineWidth);
  applyTextStyle(box, text, size, color, bold, face, align, valign, autoFit, listStyle);
  recordText(slideNo, box, role, text, x, y, w, h);
  return box;
}

async function addImage(slide, slideNo, config, position, role, sourcePath = null) {
  const image = slide.images.add(await normalizeImageConfig(config));
  image.position = position;
  recordImage(slideNo, image, role, sourcePath || config.path || config.uri || "inline-data-url", position.left, position.top, position.width, position.height);
  return image;
}

async function addPlate(slide, slideNo, opacityPanel = false) {
  slide.background.fill = PAPER;
  const platePath = path.join(REF_DIR, `slide-${String(slideNo).padStart(2, "0")}.png`);
  if (await pathExists(platePath)) {
    await addImage(
      slide,
      slideNo,
      { path: platePath, fit: "cover", alt: `Text-free art-direction plate for slide ${slideNo}` },
      { left: 0, top: 0, width: W, height: H },
      "art plate",
      platePath,
    );
  } else {
    await addImage(
      slide,
      slideNo,
      { dataUrl: FALLBACK_PLATE_DATA_URL, fit: "cover", alt: `Fallback blank art plate for slide ${slideNo}` },
      { left: 0, top: 0, width: W, height: H },
      "fallback art plate",
      "fallback-data-url",
    );
  }
  if (opacityPanel) {
    addShape(slide, "rect", 0, 0, W, H, "#FFFFFFB8", TRANSPARENT, 0, { slideNo, role: "plate readability overlay" });
  }
}

function addHeader(slide, slideNo, kicker, idx, total) {
  addText(slide, slideNo, String(kicker || "").toUpperCase(), 64, 34, 430, 24, {
    size: 13,
    color: ACCENT_DARK,
    bold: true,
    face: MONO_FACE,
    checkFit: false,
    role: "header",
  });
  addText(slide, slideNo, `${String(idx).padStart(2, "0")} / ${String(total).padStart(2, "0")}`, 1114, 34, 104, 24, {
    size: 13,
    color: ACCENT_DARK,
    bold: true,
    face: MONO_FACE,
    align: "right",
    checkFit: false,
    role: "header",
  });
  addShape(slide, "rect", 64, 64, 1152, 2, INK, TRANSPARENT, 0, { slideNo, role: "header rule" });
  addShape(slide, "ellipse", 57, 57, 16, 16, ACCENT, INK, 2, { slideNo, role: "header marker" });
}

function addTitleBlock(slide, slideNo, title, subtitle = null, x = 64, y = 86, w = 780, dark = false) {
  const titleColor = dark ? PAPER : INK;
  const bodyColor = dark ? PAPER : GRAPHITE;
  addText(slide, slideNo, title, x, y, w, 142, {
    size: 40,
    color: titleColor,
    bold: true,
    face: TITLE_FACE,
    role: "title",
  });
  if (subtitle) {
    addText(slide, slideNo, subtitle, x + 2, y + 148, Math.min(w, 720), 70, {
      size: 19,
      color: bodyColor,
      face: BODY_FACE,
      role: "subtitle",
    });
  }
}

function addIconBadge(slide, slideNo, x, y, accent = ACCENT, kind = "signal") {
  addShape(slide, "ellipse", x, y, 54, 54, PAPER_96, INK, 1.2, { slideNo, role: "icon badge" });
  if (kind === "flow") {
    addShape(slide, "ellipse", x + 13, y + 18, 10, 10, accent, INK, 1, { slideNo, role: "icon glyph" });
    addShape(slide, "ellipse", x + 31, y + 27, 10, 10, accent, INK, 1, { slideNo, role: "icon glyph" });
    addShape(slide, "rect", x + 22, y + 25, 19, 3, INK, TRANSPARENT, 0, { slideNo, role: "icon glyph" });
  } else if (kind === "layers") {
    addShape(slide, "roundRect", x + 13, y + 15, 26, 13, accent, INK, 1, { slideNo, role: "icon glyph" });
    addShape(slide, "roundRect", x + 18, y + 24, 26, 13, GOLD, INK, 1, { slideNo, role: "icon glyph" });
    addShape(slide, "roundRect", x + 23, y + 33, 20, 10, CORAL, INK, 1, { slideNo, role: "icon glyph" });
  } else {
    addShape(slide, "rect", x + 16, y + 29, 6, 12, accent, TRANSPARENT, 0, { slideNo, role: "icon glyph" });
    addShape(slide, "rect", x + 25, y + 21, 6, 20, accent, TRANSPARENT, 0, { slideNo, role: "icon glyph" });
    addShape(slide, "rect", x + 34, y + 14, 6, 27, accent, TRANSPARENT, 0, { slideNo, role: "icon glyph" });
  }
}

function addCard(slide, slideNo, x, y, w, h, label, body, { accent = ACCENT, fill = PAPER_96, line = INK, iconKind = "signal" } = {}) {
  if (h < 156) {
    throw new Error(`Card is too short for editable pro-deck copy: height=${h.toFixed(1)}, minimum=156.`);
  }
  addShape(slide, "roundRect", x, y, w, h, fill, line, 1.2, { slideNo, role: `card panel: ${label}` });
  addShape(slide, "rect", x, y, 8, h, accent, TRANSPARENT, 0, { slideNo, role: `card accent: ${label}` });
  addIconBadge(slide, slideNo, x + 22, y + 24, accent, iconKind);
  addText(slide, slideNo, label, x + 88, y + 22, w - 108, 28, {
    size: 15,
    color: ACCENT_DARK,
    bold: true,
    face: MONO_FACE,
    role: "card label",
  });
  const wrapped = wrapText(body, Math.max(28, Math.floor(w / 13)));
  const bodyY = y + 86;
  const bodyH = h - (bodyY - y) - 22;
  if (bodyH < 54) {
    throw new Error(`Card body area is too short: height=${bodyH.toFixed(1)}, cardHeight=${h.toFixed(1)}, label=${JSON.stringify(label)}.`);
  }
  addText(slide, slideNo, wrapped, x + 24, bodyY, w - 48, bodyH, {
    size: 17,
    color: INK,
    face: BODY_FACE,
    role: `card body: ${label}`,
  });
}

function addMetricCard(slide, slideNo, x, y, w, h, metric, label, note = null, accent = ACCENT) {
  const metricSize = String(metric).length > 14 ? 22 : (String(metric).length > 10 ? 26 : 34);
  if (h < 132) {
    throw new Error(`Metric card is too short for editable pro-deck copy: height=${h.toFixed(1)}, minimum=132.`);
  }
  addShape(slide, "roundRect", x, y, w, h, PAPER_96, INK, 1.2, { slideNo, role: `metric panel: ${label}` });
  addShape(slide, "rect", x, y, w, 7, accent, TRANSPARENT, 0, { slideNo, role: `metric accent: ${label}` });
  addText(slide, slideNo, metric, x + 22, y + 24, w - 44, 54, {
    size: metricSize,
    color: INK,
    bold: true,
    face: TITLE_FACE,
    role: "metric value",
  });
  addText(slide, slideNo, label, x + 24, y + 82, w - 48, 38, {
    size: 16,
    color: GRAPHITE,
    face: BODY_FACE,
    role: "metric label",
  });
  if (note) {
    addText(slide, slideNo, note, x + 24, y + h - 42, w - 48, 22, {
      size: 10,
      color: MUTED,
      face: BODY_FACE,
      role: "metric note",
    });
  }
}

function addNotes(slide, body, sourceKeys) {
  const sourceLines = (sourceKeys || []).map((key) => `- ${SOURCES[key] || key}`).join("\n");
  slide.speakerNotes.setText(`${body || ""}\n\n[Sources]\n${sourceLines}`);
}

function addReferenceCaption(slide, slideNo) {
  addText(
    slide,
    slideNo,
    "Source: docs/codex-ops-platform-architecture.md",
    64,
    674,
    420,
    22,
    {
      size: 10,
      color: MUTED,
      face: BODY_FACE,
      checkFit: false,
      role: "caption",
    },
  );
}

function addFooterTag(slide, slideNo, text) {
  addShape(slide, "roundRect", 1080, 666, 136, 28, WHITE, INK, 1, { slideNo, role: "footer tag" });
  addText(slide, slideNo, text, 1098, 672, 100, 14, {
    size: 10,
    color: INK,
    face: MONO_FACE,
    bold: true,
    align: "center",
    checkFit: false,
    role: "footer tag label",
  });
}

function addListPanel(slide, slideNo, x, y, w, h, title, items, accent) {
  addShape(slide, "roundRect", x, y, w, h, PAPER_96, INK, 1.2, { slideNo, role: "list panel" });
  addShape(slide, "rect", x, y, w, 8, accent || ACCENT, TRANSPARENT, 0, { slideNo, role: "list panel accent" });
  addText(slide, slideNo, title, x + 24, y + 24, w - 48, 28, {
    size: 16,
    color: ACCENT_DARK,
    bold: true,
    face: MONO_FACE,
    role: "list panel title",
  });
  addText(slide, slideNo, items.join("\n"), x + 24, y + 60, w - 48, h - 80, {
    size: 15,
    color: INK,
    face: BODY_FACE,
    role: "list panel body",
  });
}

function addNodePanel(slide, slideNo, x, y, w, h, title, subtitle, accent) {
  addShape(slide, "roundRect", x, y, w, h, WHITE, INK, 1.4, { slideNo, role: "node panel" });
  addShape(slide, "rect", x, y, w, 10, accent, TRANSPARENT, 0, { slideNo, role: "node accent" });
  addText(slide, slideNo, title, x + 20, y + 28, w - 40, 40, {
    size: 23,
    color: INK,
    bold: true,
    face: TITLE_FACE,
    role: "node title",
  });
  addText(slide, slideNo, subtitle, x + 20, y + 74, w - 40, h - 92, {
    size: 14,
    color: GRAPHITE,
    face: BODY_FACE,
    role: "node body",
  });
}

function addConnection(slide, slideNo, x, y, w, h, label) {
  addShape(slide, "roundRect", x, y, w, h, INK, TRANSPARENT, 0, { slideNo, role: "connection line" });
  if (label) {
    addShape(slide, "roundRect", x + (w / 2) - 108, y - 30, 216, 24, PAPER_96, INK, 1, { slideNo, role: "connection label panel" });
    addText(slide, slideNo, label, x + (w / 2) - 94, y - 24, 188, 14, {
      size: 10,
      color: INK,
      face: MONO_FACE,
      bold: true,
      align: "center",
      checkFit: false,
      role: "connection label",
    });
  }
}

async function slideCover(presentation) {
  const slideNo = 1;
  const data = SLIDES[0];
  const slide = presentation.slides.add();
  await addPlate(slide, slideNo);
  addShape(slide, "rect", 0, 0, W, H, "#FFFFFFCC", TRANSPARENT, 0, { slideNo, role: "cover contrast overlay" });
  addShape(slide, "ellipse", 874, 122, 260, 260, "#27C47D26", TRANSPARENT, 0, { slideNo, role: "cover decor" });
  addShape(slide, "ellipse", 968, 208, 170, 170, "#D7A83D30", TRANSPARENT, 0, { slideNo, role: "cover decor" });
  addShape(slide, "roundRect", 842, 424, 300, 152, WHITE, INK, 1.2, { slideNo, role: "cover side panel" });
  addText(slide, slideNo, "Audience", 868, 452, 110, 20, {
    size: 12,
    color: ACCENT_DARK,
    bold: true,
    face: MONO_FACE,
    role: "cover side label",
  });
  addText(slide, slideNo, "インフラエンジニア\n運用設計担当", 868, 480, 220, 56, {
    size: 20,
    color: INK,
    bold: true,
    face: TITLE_FACE,
    role: "cover side body",
  });
  addShape(slide, "rect", 64, 86, 7, 455, ACCENT, TRANSPARENT, 0, { slideNo, role: "cover accent rule" });
  addText(slide, slideNo, data.kicker, 86, 88, 520, 26, {
    size: 13,
    color: ACCENT_DARK,
    bold: true,
    face: MONO_FACE,
    role: "kicker",
  });
  addText(slide, slideNo, data.title, 82, 130, 785, 184, {
    size: 48,
    color: INK,
    bold: true,
    face: TITLE_FACE,
    role: "cover title",
  });
  addText(slide, slideNo, data.subtitle, 86, 326, 610, 86, {
    size: 20,
    color: GRAPHITE,
    face: BODY_FACE,
    role: "cover subtitle",
  });
  addShape(slide, "roundRect", 86, 456, 390, 92, PAPER_96, INK, 1.2, { slideNo, role: "cover moment panel" });
  addText(slide, slideNo, data.moment || "Replace with core idea", 112, 478, 336, 40, {
    size: 23,
    color: INK,
    bold: true,
    face: TITLE_FACE,
    role: "cover moment",
  });
  addReferenceCaption(slide, slideNo);
  addFooterTag(slide, slideNo, "INFRA v1");
  addNotes(slide, data.notes, data.sources);
}

async function slideCards(presentation, idx) {
  const data = SLIDES[idx - 1];
  const slide = presentation.slides.add();
  await addPlate(slide, idx);
  addShape(slide, "rect", 0, 0, W, H, "#FFFFFFB8", TRANSPARENT, 0, { slideNo: idx, role: "content contrast overlay" });
  addHeader(slide, idx, data.kicker, idx, SLIDES.length);
  addTitleBlock(slide, idx, data.title, data.subtitle, 64, 86, 760);
  const cards = data.cards?.length
    ? data.cards
    : [
        ["Replace", "Add a specific, sourced point for this slide."],
        ["Author", "Use native PowerPoint chart objects for charts; use deterministic geometry for cards and callouts."],
        ["Verify", "Render previews, inspect them at readable size, and fix actionable layout issues within 3 total render loops."],
      ];
  const cols = Math.min(3, cards.length);
  const cardW = (1114 - (cols - 1) * 24) / cols;
  const iconKinds = ["signal", "flow", "layers"];
  for (let cardIdx = 0; cardIdx < cols; cardIdx += 1) {
    const [label, body] = cards[cardIdx];
    const x = 84 + cardIdx * (cardW + 24);
    addCard(slide, idx, x, 410, cardW, 200, label, body, { iconKind: iconKinds[cardIdx % iconKinds.length] });
  }
  addReferenceCaption(slide, idx);
  addFooterTag(slide, idx, "READABLE");
  addNotes(slide, data.notes, data.sources);
}

async function slideArchitecture(presentation, idx) {
  const data = SLIDES[idx - 1];
  const slide = presentation.slides.add();
  await addPlate(slide, idx);
  addShape(slide, "rect", 0, 0, W, H, "#FFFFFFC7", TRANSPARENT, 0, { slideNo: idx, role: "diagram contrast overlay" });
  addHeader(slide, idx, data.kicker, idx, SLIDES.length);
  addTitleBlock(slide, idx, data.title, data.subtitle, 64, 86, 780);
  addText(slide, idx, "future services", 72, 300, 120, 18, {
    size: 10,
    color: MUTED,
    face: MONO_FACE,
    bold: true,
    role: "diagram note",
  });
  addNodePanel(slide, idx, 92, 332, 228, 148, "他サービス", "Service A\nService B\n将来追加する接続先", CORAL);
  addNodePanel(slide, idx, 488, 280, 304, 180, "Codex UI / API", "運用担当が使う Web UI\n調査フロー制御\n権限制御 / 監査ログ", ACCENT);
  addNodePanel(slide, idx, 930, 236, 246, 140, "POSLA APP", "meal.posla.jp\nAPI / docs / logs の参照先", GOLD);
  addNodePanel(slide, idx, 930, 420, 246, 140, "POSLA DB", "MySQL\n原則 read-only 接続", ACCENT_DARK);
  addConnection(slide, idx, 320, 400, 146, 4, null);
  addConnection(slide, idx, 792, 304, 118, 4, null);
  addConnection(slide, idx, 1050, 376, 4, 44, "app-db");
  addShape(slide, "roundRect", 474, 202, 338, 32, PAPER_96, INK, 1, { slideNo: idx, role: "network label" });
  addText(slide, idx, "private network / VPN / bastion", 500, 210, 286, 14, {
    size: 11,
    color: INK,
    face: MONO_FACE,
    bold: true,
    align: "center",
    checkFit: false,
    role: "network label text",
  });
  addListPanel(slide, idx, 92, 500, 532, 150, "この構成の要点", [
    "• Codex は APP と同居させない",
    "• 調査は public API ではなく private 経路優先",
    "• APP 障害時でも Codex 側は生き残る前提",
  ], ACCENT);
  addListPanel(slide, idx, 656, 500, 520, 150, "インフラ担当が確認すべきこと", [
    "• private subnet / bastion / internal endpoint の設計",
    "• DB の read-only 経路と write 権限の分離",
    "• Codex の監査ログ保存先",
  ], GOLD);
  addReferenceCaption(slide, idx);
  addFooterTag(slide, idx, "PRIVATE");
  addNotes(slide, data.notes, data.sources);
}

async function slideGovernance(presentation, idx) {
  const data = SLIDES[idx - 1];
  const slide = presentation.slides.add();
  await addPlate(slide, idx);
  addShape(slide, "rect", 0, 0, W, H, "#FFFFFFC6", TRANSPARENT, 0, { slideNo: idx, role: "governance contrast overlay" });
  addHeader(slide, idx, data.kicker, idx, SLIDES.length);
  addTitleBlock(slide, idx, data.title, data.subtitle, 64, 86, 760);
  addCard(slide, idx, 84, 258, 348, 200, "product 内 help", "tenant / POSLA 管理画面の help は非AI。\nFAQ と検索で即答する導線に限定し、強い権限は持たせない。", {
    accent: ACCENT,
    iconKind: "signal",
  });
  addCard(slide, idx, 466, 258, 348, 200, "運用補助", "POSLA 管理画面の read-only 支援。\n運用状況、エラー検索、症状フロー、対応前チェックまでを扱う。", {
    accent: GOLD,
    iconKind: "layers",
  });
  addCard(slide, idx, 848, 258, 348, 200, "外部 Codex", "深い調査、修正提案、将来の承認後実行を担当。\nproduct とは別系統の運用基盤とする。", {
    accent: CORAL,
    iconKind: "flow",
  });
  addMetricCard(slide, idx, 124, 500, 300, 132, "read_only", "docs / logs / health / read-only SQL", null, ACCENT);
  addMetricCard(slide, idx, 490, 500, 300, 132, "propose_only", "修正案・SQL案・deploy案の提示", null, GOLD);
  addMetricCard(slide, idx, 856, 500, 300, 132, "approved_execute", "明示承認後のみ実行", null, CORAL);
  addReferenceCaption(slide, idx);
  addFooterTag(slide, idx, "BOUNDARY");
  addNotes(slide, data.notes, data.sources);
}

async function slideInfra(presentation, idx) {
  const data = SLIDES[idx - 1];
  const slide = presentation.slides.add();
  await addPlate(slide, idx);
  addShape(slide, "rect", 0, 0, W, H, "#FFFFFFCA", TRANSPARENT, 0, { slideNo: idx, role: "infra contrast overlay" });
  addHeader(slide, idx, data.kicker, idx, SLIDES.length);
  addTitleBlock(slide, idx, data.title, data.subtitle, 64, 86, 780);
  addListPanel(slide, idx, 84, 256, 526, 184, "パターンA: 最小構成", [
    "• APP: POSLA",
    "• DB: MySQL",
    "• Codex: 別コンテナ or 別プロセス",
    "• まずは read-only で立ち上げる",
    "• private 接続が無理なら bastion を必須化する",
  ], ACCENT);
  addListPanel(slide, idx, 670, 256, 526, 184, "パターンB: 本番推奨", [
    "• APP / DB / Codex を別ホスト or 別障害ドメイン",
    "• private subnet + bastion / VPN を前提化",
    "• DB は read-only / write を別資格情報で分離",
    "• 監査ログを永続化し、承認履歴を残す",
  ], CORAL);
  addListPanel(slide, idx, 84, 474, 1112, 144, "インフラ担当の最初のタスク", [
    "• Codex から APP / DB / docs / logs へどう private 接続するかを決める",
    "• 調査専用資格情報と、将来の実行用資格情報を分離して設計する",
    "• 監査ログの保存先、保持期間、運用フローを先に決める",
  ], GOLD);
  addReferenceCaption(slide, idx);
  addFooterTag(slide, idx, "NEXT");
  addNotes(slide, data.notes, data.sources);
}

async function createDeck() {
  await ensureDirs();
  if (!SLIDES.length) {
    throw new Error("SLIDES must contain at least one slide.");
  }
  const presentation = Presentation.create({ slideSize: { width: W, height: H } });
  for (let idx = 1; idx <= SLIDES.length; idx += 1) {
    const data = SLIDES[idx - 1];
    if (data.kind === "cover") {
      await slideCover(presentation);
    } else if (data.kind === "diagram") {
      await slideArchitecture(presentation, idx);
    } else if (data.kind === "governance") {
      await slideGovernance(presentation, idx);
    } else if (data.kind === "infra") {
      await slideInfra(presentation, idx);
    } else {
      await slideCards(presentation, idx);
    }
  }
  return presentation;
}

async function saveBlobToFile(blob, filePath) {
  const bytes = new Uint8Array(await blob.arrayBuffer());
  await fs.writeFile(filePath, bytes);
}

async function writeInspectArtifact(presentation) {
  inspectRecords.unshift({
    kind: "deck",
    id: DECK_ID,
    slideCount: presentation.slides.count,
    slideSize: { width: W, height: H },
  });
  presentation.slides.items.forEach((slide, index) => {
    inspectRecords.splice(index + 1, 0, {
      kind: "slide",
      slide: index + 1,
      id: slide?.id || `slide-${index + 1}`,
    });
  });
  const lines = inspectRecords.map((record) => JSON.stringify(record)).join("\n") + "\n";
  await fs.writeFile(INSPECT_PATH, lines, "utf8");
}

async function currentRenderLoopCount() {
  const logPath = path.join(VERIFICATION_DIR, "render_verify_loops.ndjson");
  if (!(await pathExists(logPath))) return 0;
  const previous = await fs.readFile(logPath, "utf8");
  return previous.split(/\r?\n/).filter((line) => line.trim()).length;
}

async function nextRenderLoopNumber() {
  return (await currentRenderLoopCount()) + 1;
}

async function appendRenderVerifyLoop(presentation, previewPaths, pptxPath) {
  const logPath = path.join(VERIFICATION_DIR, "render_verify_loops.ndjson");
  const priorCount = await currentRenderLoopCount();
  const record = {
    kind: "render_verify_loop",
    deckId: DECK_ID,
    loop: priorCount + 1,
    maxLoops: MAX_RENDER_VERIFY_LOOPS,
    capReached: priorCount + 1 >= MAX_RENDER_VERIFY_LOOPS,
    timestamp: new Date().toISOString(),
    slideCount: presentation.slides.count,
    previewCount: previewPaths.length,
    previewDir: PREVIEW_DIR,
    inspectPath: INSPECT_PATH,
    pptxPath,
  };
  await fs.appendFile(logPath, JSON.stringify(record) + "\n", "utf8");
  return record;
}

async function verifyAndExport(presentation) {
  await ensureDirs();
  const nextLoop = await nextRenderLoopNumber();
  if (nextLoop > MAX_RENDER_VERIFY_LOOPS) {
    throw new Error(
      `Render/verify/fix loop cap reached: ${MAX_RENDER_VERIFY_LOOPS} total renders are allowed. ` +
        "Do not rerender; note any remaining visual issues in the final response.",
    );
  }
  await writeInspectArtifact(presentation);
  const previewPaths = [];
  for (let idx = 0; idx < presentation.slides.items.length; idx += 1) {
    const slide = presentation.slides.items[idx];
    const preview = await presentation.export({ slide, format: "png", scale: 1 });
    const previewPath = path.join(PREVIEW_DIR, `slide-${String(idx + 1).padStart(2, "0")}.png`);
    await saveBlobToFile(preview, previewPath);
    previewPaths.push(previewPath);
  }
  const pptxBlob = await PresentationFile.exportPptx(presentation);
  const pptxPath = path.join(OUT_DIR, "output.pptx");
  await pptxBlob.save(pptxPath);
  const loopRecord = await appendRenderVerifyLoop(presentation, previewPaths, pptxPath);
  return { pptxPath, loopRecord };
}

const presentation = await createDeck();
const result = await verifyAndExport(presentation);
console.log(result.pptxPath);
