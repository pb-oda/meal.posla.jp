/** @jsxRuntime automatic */
/** @jsxImportSource @oai/artifact-tool/presentation-jsx */

import fs from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const {
  Presentation,
  PresentationFile,
  FileBlob,
  row,
  column,
  grid,
  text,
  shape,
  rule,
  fill,
  hug,
  wrap,
  fixed,
  fr,
  auto,
} = await import("@oai/artifact-tool");

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const workspaceDir = path.resolve(__dirname, "..");
const scratchDir = path.join(workspaceDir, "scratch");
const outputDir = path.join(workspaceDir, "output");
const outputPptx = path.join(outputDir, "output.pptx");
const sourceDir = path.join(scratchDir, "source-renders");
const pptxDir = path.join(scratchDir, "pptx-renders");

const SLIDE_W = 1920;
const SLIDE_H = 1080;
const TOTAL_SLIDES = 11;

const COLORS = {
  cream: "#F7F0E8",
  warmWhite: "#FBF7F2",
  charcoal: "#151311",
  cocoa: "#3B241A",
  copper: "#C46A2E",
  amber: "#E2A65B",
  clay: "#B4573C",
  olive: "#5F6C4C",
  sand: "#D5C2AE",
  stone: "#6D6258",
  blush: "#EED6C4",
};

const FONT = "Hiragino Sans";
const SOURCE_NOTE = "Source: POSLA current implementation, pseudo-prod review, 2026-04-25";

function fullFrame() {
  return { left: 0, top: 0, width: SLIDE_W, height: SLIDE_H };
}

function compose(slide, node, frame) {
  slide.compose(node, {
    frame: frame || fullFrame(),
    baseUnit: 8,
  });
}

function makeText(value, options) {
  return text(value, {
    name: options.name,
    width: options.maxWidth ? wrap(options.maxWidth) : options.width || fill,
    height: options.height || hug,
    style: {
      fontFamily: options.fontFamily || FONT,
      fontSize: options.fontSize,
      color: options.color,
      bold: !!options.bold,
      italic: !!options.italic,
      alignment: options.alignment || "left",
    },
  });
}

function titleStack(opts) {
  const eyebrowColor = opts.dark ? COLORS.amber : COLORS.copper;
  const titleColor = opts.dark ? COLORS.warmWhite : COLORS.charcoal;
  const subtitleColor = opts.dark ? COLORS.sand : COLORS.stone;
  const nodes = [];

  if (opts.eyebrow) {
    nodes.push(
      makeText(opts.eyebrow, {
        name: opts.namePrefix + "-eyebrow",
        fontSize: 18,
        color: eyebrowColor,
        bold: true,
        maxWidth: 900,
      }),
    );
    nodes.push(
      rule({
        name: opts.namePrefix + "-rule",
        width: fixed(120),
        stroke: eyebrowColor,
        weight: 4,
      }),
    );
  }

  nodes.push(
    makeText(opts.title, {
      name: opts.namePrefix + "-title",
      fontSize: opts.titleSize || 64,
      color: titleColor,
      bold: true,
      maxWidth: opts.titleWidth || 1280,
    }),
  );

  if (opts.subtitle) {
    nodes.push(
      makeText(opts.subtitle, {
        name: opts.namePrefix + "-subtitle",
        fontSize: opts.subtitleSize || 27,
        color: subtitleColor,
        maxWidth: opts.subtitleWidth || 1150,
      }),
    );
  }

  return column(
    {
      name: opts.namePrefix + "-stack",
      width: fill,
      height: hug,
      gap: opts.gap || 14,
    },
    nodes,
  );
}

function footerRow(index, dark) {
  return row(
    {
      name: "footer-row-" + index,
      width: fill,
      height: hug,
      justify: "between",
      align: "center",
    },
    [
      makeText(SOURCE_NOTE, {
        name: "footer-source-" + index,
        fontSize: 11,
        color: dark ? COLORS.sand : COLORS.stone,
        maxWidth: 1100,
      }),
      makeText(String(index) + " / " + String(TOTAL_SLIDES), {
        name: "footer-page-" + index,
        fontSize: 12,
        color: dark ? COLORS.amber : COLORS.copper,
        bold: true,
        maxWidth: 120,
        alignment: "right",
      }),
    ],
  );
}

function lineItem(name, lead, detail, dark, opts) {
  const options = opts || {};
  return column(
    {
      name: name + "-item",
      width: fill,
      height: hug,
      gap: options.gap || 6,
    },
    [
      makeText(lead, {
        name: name + "-lead",
        fontSize: options.leadSize || 28,
        color: dark ? COLORS.warmWhite : COLORS.charcoal,
        bold: true,
        maxWidth: options.leadWidth || 560,
      }),
      makeText(detail, {
        name: name + "-detail",
        fontSize: options.detailSize || 20,
        color: dark ? COLORS.sand : COLORS.stone,
        maxWidth: options.detailWidth || 560,
      }),
    ],
  );
}

function bigWord(name, value, color) {
  return makeText(value, {
    name: name,
    fontSize: 44,
    color: color,
    bold: true,
    maxWidth: 420,
  });
}

function addBackground(slide, fillColor) {
  compose(
    slide,
    shape({
      name: "background",
      fill: fillColor,
      width: fill,
      height: fill,
    }),
  );
}

function addDecor(slide, nodes) {
  var i;
  for (i = 0; i < nodes.length; i += 1) {
    compose(slide, nodes[i].node, nodes[i].frame);
  }
}

function addSlideShell(slide, opts) {
  compose(
    slide,
    grid(
      {
        name: "shell-" + opts.index,
        width: fill,
        height: fill,
        columns: [fr(1)],
        rows: [auto, fr(1), auto],
        rowGap: opts.rowGap || 34,
        padding: { x: opts.padX || 92, y: opts.padY || 74 },
      },
      [
        titleStack({
          namePrefix: "slide-" + opts.index,
          eyebrow: opts.eyebrow,
          title: opts.title,
          subtitle: opts.subtitle,
          dark: !!opts.dark,
          titleSize: opts.titleSize,
          subtitleSize: opts.subtitleSize,
          titleWidth: opts.titleWidth,
          subtitleWidth: opts.subtitleWidth,
        }),
        opts.body,
        footerRow(opts.index, !!opts.dark),
      ],
    ),
  );
}

function addCoverSlide(presentation) {
  const slide = presentation.slides.add();
  addBackground(slide, COLORS.charcoal);
  addDecor(slide, [
    {
      node: shape({ name: "cover-left-band", fill: COLORS.copper, width: fill, height: fill }),
      frame: { left: 0, top: 0, width: 28, height: SLIDE_H },
    },
    {
      node: shape({ name: "cover-bottom-band", fill: COLORS.cocoa, width: fill, height: fill }),
      frame: { left: 0, top: 930, width: SLIDE_W, height: 150 },
    },
    {
      node: makeText("POSLA", {
        name: "cover-ghost",
        fontSize: 300,
        color: "#4B2D22",
        bold: true,
        maxWidth: 1200,
      }),
      frame: { left: 980, top: 90, width: 820, height: 320 },
    },
  ]);

  addSlideShell(slide, {
    index: 1,
    dark: true,
    eyebrow: "POSLA proposal draft",
    title: "売上増と\n現場効率化を\n同時に進める",
    subtitle:
      "POSLAは注文システムではなく、予約・厨房・会計・在庫・分析までつなぐ店舗運営基盤です。",
    titleSize: 78,
    subtitleWidth: 760,
    body: grid(
      {
        name: "cover-body",
        width: fill,
        height: fill,
        columns: [fr(1), fr(1)],
        columnGap: 24,
      },
      [
        column(
          {
            name: "cover-left-empty",
            width: fill,
            height: fill,
            gap: 14,
          },
          [
            makeText("単機能ではなく、店舗の1日をまとめて変える。", {
              name: "cover-proof",
              fontSize: 24,
              color: COLORS.sand,
              maxWidth: 560,
            }),
          ],
        ),
        column(
          {
            name: "cover-right-promises",
            width: fill,
            height: hug,
            gap: 20,
          },
          [
            bigWord("cover-word-1", "客単価アップ", COLORS.amber),
            makeText("おすすめ表示、AIウェイター、併売分析で売れる導線をつくる。", {
              name: "cover-word-1-detail",
              fontSize: 20,
              color: COLORS.sand,
              maxWidth: 460,
            }),
            bigWord("cover-word-2", "電話対応削減", COLORS.amber),
            makeText("予約変更や受取調整を店舗だけで抱え込まない。", {
              name: "cover-word-2-detail",
              fontSize: 20,
              color: COLORS.sand,
              maxWidth: 460,
            }),
            bigWord("cover-word-3", "発注精度向上", COLORS.amber),
            makeText("在庫と売上をつなげ、仕込みや欠品のムダを減らしやすくする。", {
              name: "cover-word-3-detail",
              fontSize: 20,
              color: COLORS.sand,
              maxWidth: 460,
            }),
          ],
        ),
      ],
    ),
  });
}

function addPainSlide(presentation) {
  const slide = presentation.slides.add();
  addBackground(slide, COLORS.warmWhite);
  addDecor(slide, [
    {
      node: makeText("5", {
        name: "pain-big-number",
        fontSize: 240,
        color: COLORS.blush,
        bold: true,
        maxWidth: 240,
      }),
      frame: { left: 1450, top: 160, width: 220, height: 260 },
    },
  ]);

  addSlideShell(slide, {
    index: 2,
    eyebrow: "The operating problem",
    title: "飲食店には\n5つのムダが残る",
    subtitle: "断片化した作業を、ひとつの運営基盤で減らしていく提案です。",
    titleSize: 56,
    subtitleSize: 24,
    body: column(
      {
        name: "pain-list",
        width: fill,
        height: hug,
        gap: 12,
      },
      [
        lineItem("pain-1", "01 注文を取る → 伝える → 戻る", "ホールの往復が増え、ピーク時に接客品質まで落ちやすい。", false, {
          leadWidth: 1200,
          detailWidth: 1200,
          leadSize: 24,
          detailSize: 18,
        }),
        rule({ name: "pain-rule-1", width: fill, stroke: COLORS.sand, weight: 1 }),
        lineItem("pain-2", "02 予約変更とテイクアウトの電話対応", "売上機会をつかみたいのに、確認作業で現場が止まりやすい。", false, {
          leadWidth: 1200,
          detailWidth: 1200,
          leadSize: 24,
          detailSize: 18,
        }),
        rule({ name: "pain-rule-2", width: fill, stroke: COLORS.sand, weight: 1 }),
        lineItem("pain-3", "03 厨房とホールの情報ズレ", "注文状況や提供状況の見え方が分かれ、伝達ミスが起きやすい。", false, {
          leadWidth: 1200,
          detailWidth: 1200,
          leadSize: 24,
          detailSize: 18,
        }),
        rule({ name: "pain-rule-3", width: fill, stroke: COLORS.sand, weight: 1 }),
        lineItem("pain-4", "04 品切れと仕込みの勘頼り", "売れた後に気づく、仕込みすぎる、発注判断が属人化する。", false, {
          leadWidth: 1200,
          detailWidth: 1200,
          leadSize: 24,
          detailSize: 18,
        }),
        rule({ name: "pain-rule-4", width: fill, stroke: COLORS.sand, weight: 1 }),
        lineItem("pain-5", "05 データがあるのに改善に使えていない", "売上は見えても、次の打ち手までつながらない。", false, {
          leadWidth: 1200,
          detailWidth: 1200,
          leadSize: 24,
          detailSize: 18,
        }),
      ],
    ),
  });
}

function addConnectionSlide(presentation) {
  const slide = presentation.slides.add();
  addBackground(slide, COLORS.charcoal);

  addSlideShell(slide, {
    index: 3,
    dark: true,
    eyebrow: "What POSLA connects",
    title: "POSLAは\n業務を分断しない",
    subtitle: "顧客接点だけ強くしても、店舗運営は変わらない。注文から改善までを一つにつなぐ。",
    body: grid(
      {
        name: "connection-grid",
        width: fill,
        height: fill,
        columns: [fr(1), fr(1), fr(1), fr(1), fr(1)],
        columnGap: 18,
      },
      [
        column(
          { name: "connection-customer", width: fill, height: hug, gap: 10 },
          [
            makeText("顧客", { name: "connection-customer-title", fontSize: 34, color: COLORS.amber, bold: true }),
            makeText("セルフオーダー\n予約\nテイクアウト", {
              name: "connection-customer-body",
              fontSize: 20,
              color: COLORS.sand,
              maxWidth: 240,
            }),
          ],
        ),
        column(
          { name: "connection-floor", width: fill, height: hug, gap: 10 },
          [
            makeText("ホール", { name: "connection-floor-title", fontSize: 34, color: COLORS.amber, bold: true }),
            makeText("ハンディ\nテーブル運用\n接客補助", {
              name: "connection-floor-body",
              fontSize: 20,
              color: COLORS.sand,
              maxWidth: 240,
            }),
          ],
        ),
        column(
          { name: "connection-kitchen", width: fill, height: hug, gap: 10 },
          [
            makeText("厨房", { name: "connection-kitchen-title", fontSize: 34, color: COLORS.amber, bold: true }),
            makeText("KDS\n優先順位\n提供状況", {
              name: "connection-kitchen-body",
              fontSize: 20,
              color: COLORS.sand,
              maxWidth: 240,
            }),
          ],
        ),
        column(
          { name: "connection-payment", width: fill, height: hug, gap: 10 },
          [
            makeText("会計", { name: "connection-payment-title", fontSize: 34, color: COLORS.amber, bold: true }),
            makeText("レジ\nセルフ会計\n分割合流会計", {
              name: "connection-payment-body",
              fontSize: 20,
              color: COLORS.sand,
              maxWidth: 240,
            }),
          ],
        ),
        column(
          { name: "connection-insight", width: fill, height: hug, gap: 10 },
          [
            makeText("改善", { name: "connection-insight-title", fontSize: 34, color: COLORS.amber, bold: true }),
            makeText("在庫\n分析\n本部管理", {
              name: "connection-insight-body",
              fontSize: 20,
              color: COLORS.sand,
              maxWidth: 240,
            }),
          ],
        ),
      ],
    ),
  });

  addDecor(slide, [
    {
      node: rule({ name: "connection-line", width: fill, stroke: COLORS.clay, weight: 3 }),
      frame: { left: 164, top: 638, width: 1592, height: 3 },
    },
    {
      node: shape({ name: "connection-dot-1", geometry: "ellipse", fill: COLORS.amber, width: fill, height: fill }),
      frame: { left: 205, top: 626, width: 20, height: 20 },
    },
    {
      node: shape({ name: "connection-dot-2", geometry: "ellipse", fill: COLORS.amber, width: fill, height: fill }),
      frame: { left: 534, top: 626, width: 20, height: 20 },
    },
    {
      node: shape({ name: "connection-dot-3", geometry: "ellipse", fill: COLORS.amber, width: fill, height: fill }),
      frame: { left: 865, top: 626, width: 20, height: 20 },
    },
    {
      node: shape({ name: "connection-dot-4", geometry: "ellipse", fill: COLORS.amber, width: fill, height: fill }),
      frame: { left: 1194, top: 626, width: 20, height: 20 },
    },
    {
      node: shape({ name: "connection-dot-5", geometry: "ellipse", fill: COLORS.amber, width: fill, height: fill }),
      frame: { left: 1525, top: 626, width: 20, height: 20 },
    },
  ]);
}

function addDayFlowSlide(presentation) {
  const slide = presentation.slides.add();
  addBackground(slide, COLORS.cream);

  addSlideShell(slide, {
    index: 4,
    eyebrow: "One day in the store",
    title: "店舗の1日で\nどう効くか",
    subtitle: "導入効果が出るのは、営業中だけでなく、開店前と営業後までデータがつながるから。",
    body: grid(
      {
        name: "dayflow-grid",
        width: fill,
        height: fill,
        columns: [fr(1), fixed(2), fr(1), fixed(2), fr(1)],
        columnGap: 18,
      },
      [
        column(
          { name: "dayflow-open", width: fill, height: hug, gap: 16 },
          [
            makeText("開店前", { name: "dayflow-open-title", fontSize: 34, color: COLORS.copper, bold: true }),
            makeText("予約確認", { name: "dayflow-open-1", fontSize: 24, color: COLORS.charcoal, bold: true }),
            makeText("シフト確認", { name: "dayflow-open-2", fontSize: 24, color: COLORS.charcoal, bold: true }),
            makeText("今日のおすすめ設定", { name: "dayflow-open-3", fontSize: 24, color: COLORS.charcoal, bold: true }),
            makeText("需要予測を見て仕込み判断", { name: "dayflow-open-4", fontSize: 24, color: COLORS.charcoal, bold: true }),
          ],
        ),
        rule({ name: "dayflow-vrule-1", width: fixed(2), height: fill, stroke: COLORS.sand, weight: 2 }),
        column(
          { name: "dayflow-service", width: fill, height: hug, gap: 16 },
          [
            makeText("営業中", { name: "dayflow-service-title", fontSize: 34, color: COLORS.copper, bold: true }),
            makeText("顧客はスマホで注文", { name: "dayflow-service-1", fontSize: 24, color: COLORS.charcoal, bold: true }),
            makeText("スタッフはハンディで補助", { name: "dayflow-service-2", fontSize: 24, color: COLORS.charcoal, bold: true }),
            makeText("厨房はKDSで進行確認", { name: "dayflow-service-3", fontSize: 24, color: COLORS.charcoal, bold: true }),
            makeText("会計はレジまたはセルフ会計", { name: "dayflow-service-4", fontSize: 24, color: COLORS.charcoal, bold: true }),
          ],
        ),
        rule({ name: "dayflow-vrule-2", width: fixed(2), height: fill, stroke: COLORS.sand, weight: 2 }),
        column(
          { name: "dayflow-close", width: fill, height: hug, gap: 16 },
          [
            makeText("営業後", { name: "dayflow-close-title", fontSize: 34, color: COLORS.copper, bold: true }),
            makeText("売上と客数を確認", { name: "dayflow-close-1", fontSize: 24, color: COLORS.charcoal, bold: true }),
            makeText("回転率と満足度を振り返る", { name: "dayflow-close-2", fontSize: 24, color: COLORS.charcoal, bold: true }),
            makeText("併売傾向を確認する", { name: "dayflow-close-3", fontSize: 24, color: COLORS.charcoal, bold: true }),
            makeText("明日の仕込みと配置を見直す", { name: "dayflow-close-4", fontSize: 24, color: COLORS.charcoal, bold: true }),
          ],
        ),
      ],
    ),
  });
}

function addRevenueSlide(presentation) {
  const slide = presentation.slides.add();
  addBackground(slide, COLORS.warmWhite);
  addDecor(slide, [
    {
      node: shape({ name: "revenue-orb", geometry: "ellipse", fill: "#F2D7BE", width: fill, height: fill }),
      frame: { left: 1380, top: 540, width: 700, height: 700 },
    },
  ]);

  addSlideShell(slide, {
    index: 5,
    eyebrow: "Revenue upside",
    title: "売上増に効くのは\n売れる導線をつくること",
    subtitle: "POSLAは注文を受けるだけでなく、見せ方と提案の精度を上げる基盤として使える。",
    body: grid(
      {
        name: "revenue-grid",
        width: fill,
        height: fill,
        columns: [fr(1), fr(1)],
        columnGap: 36,
      },
      [
        column(
          { name: "revenue-words", width: fill, height: hug, gap: 24 },
          [
            bigWord("revenue-word-1", "おすすめ表示", COLORS.copper),
            bigWord("revenue-word-2", "AIウェイター", COLORS.clay),
            bigWord("revenue-word-3", "併売分析", COLORS.olive),
            bigWord("revenue-word-4", "レビュー導線", COLORS.copper),
          ],
        ),
        column(
          { name: "revenue-explain", width: fill, height: hug, gap: 18 },
          [
            lineItem("revenue-1", "今日売りたい商品を目立たせる", "おすすめ / 人気 / 限定のバッジで、見せたい商品に視線を集めやすい。"),
            rule({ name: "revenue-rule-1", width: fill, stroke: COLORS.sand, weight: 1 }),
            lineItem("revenue-2", "迷いを減らし、提案を増やす", "AIウェイターがメニュー案内やおすすめ提案を支援する。"),
            rule({ name: "revenue-rule-2", width: fill, stroke: COLORS.sand, weight: 1 }),
            lineItem("revenue-3", "一緒に売れる組み合わせを見つける", "バスケット分析で、セット提案や客単価アップのヒントを得られる。"),
            rule({ name: "revenue-rule-3", width: fill, stroke: COLORS.sand, weight: 1 }),
            lineItem("revenue-4", "高満足のお客様を次の集客へ", "満足度評価とGoogleレビュー導線で、自然な口コミ獲得につなげやすい。"),
          ],
        ),
      ],
    ),
  });
}

function addLaborSlide(presentation) {
  const slide = presentation.slides.add();
  addBackground(slide, COLORS.charcoal);
  addDecor(slide, [
    {
      node: makeText("接客に戻す", {
        name: "labor-ghost",
        fontSize: 86,
        color: "#4A2C22",
        bold: true,
        maxWidth: 640,
      }),
      frame: { left: 1180, top: 720, width: 520, height: 120 },
    },
  ]);

  addSlideShell(slide, {
    index: 6,
    dark: true,
    eyebrow: "Cost and labor",
    title: "人手不足でも\n回しやすい店へ",
    subtitle: "ホールの仕事を減らすのではなく、作業を減らして接客に戻す。",
    body: column(
      {
        name: "labor-body",
        width: fill,
        height: hug,
        gap: 28,
      },
      [
        grid(
          {
            name: "labor-grid",
            width: fill,
            height: hug,
            columns: [fr(1), fr(1)],
            columnGap: 32,
            rowGap: 28,
          },
          [
            lineItem("labor-1", "セルフオーダー", "注文取りの往復を減らし、ピーク時でも回しやすくする。", true),
            lineItem("labor-2", "KDS", "厨房とホールの伝達ミスを減らし、進行共有をそろえやすくする。", true),
            lineItem("labor-3", "セルフ会計", "レジ待ちを分散し、会計対応の偏りを抑えやすくする。", true),
            lineItem("labor-4", "予約変更のセルフ化", "電話確認を減らし、営業中の割り込みを減らしやすくする。", true),
          ],
        ),
        rule({ name: "labor-divider", width: fill, stroke: "#5A463B", weight: 1 }),
        makeText("少人数でも回しやすい導線をつくることが、採用コスト増への現実的な対策になる。", {
          name: "labor-takeaway",
          fontSize: 24,
          color: COLORS.sand,
          maxWidth: 1320,
        }),
      ],
    ),
  });
}

function addInventorySlide(presentation) {
  const slide = presentation.slides.add();
  addBackground(slide, COLORS.cream);

  addSlideShell(slide, {
    index: 7,
    eyebrow: "Inventory and prep",
    title: "在庫と仕込みを\n勘だけにしない",
    subtitle: "売れた後にまとめて在庫を見るのではなく、売上と在庫を同じ流れで扱う。",
    body: grid(
      {
        name: "inventory-grid",
        width: fill,
        height: fill,
        columns: [fr(1)],
        rows: [auto, auto, auto],
        rowGap: 28,
      },
      [
        row(
          { name: "inventory-flow", width: fill, height: hug, justify: "between", align: "center" },
          [
            bigWord("inventory-flow-1", "会計完了", COLORS.copper),
            makeText("→", { name: "inventory-arrow-1", fontSize: 42, color: COLORS.stone, bold: true, maxWidth: 40 }),
            bigWord("inventory-flow-2", "在庫自動減算", COLORS.clay),
            makeText("→", { name: "inventory-arrow-2", fontSize: 42, color: COLORS.stone, bold: true, maxWidth: 40 }),
            bigWord("inventory-flow-3", "品切れ連動", COLORS.olive),
            makeText("→", { name: "inventory-arrow-3", fontSize: 42, color: COLORS.stone, bold: true, maxWidth: 40 }),
            bigWord("inventory-flow-4", "需要予測 / 発注提案", COLORS.copper),
          ],
        ),
        rule({ name: "inventory-divider", width: fill, stroke: COLORS.sand, weight: 1 }),
        row(
          { name: "inventory-outcomes", width: fill, height: hug, justify: "between", align: "start" },
          [
            lineItem("inventory-outcome-1", "欠品を減らしやすい", "品切れ判断を売上データとつなげることで、気づきが早くなる。"),
            lineItem("inventory-outcome-2", "仕込み過多を抑えやすい", "需要予測を仕込み量の見直しに使いやすい。"),
            lineItem("inventory-outcome-3", "発注判断が早くなる", "レシピBOMと在庫を前提に、必要量を考えやすい。"),
          ],
        ),
      ],
    ),
  });
}

function addReservationSlide(presentation) {
  const slide = presentation.slides.add();
  addBackground(slide, COLORS.warmWhite);

  addSlideShell(slide, {
    index: 8,
    eyebrow: "Reservation and takeout",
    title: "予約も\nテイクアウトも取りこぼさない",
    subtitle: "売上機会を増やしたいのに、確認作業で現場が止まる。その矛盾を減らす。",
    titleSize: 52,
    titleWidth: 1500,
    subtitleWidth: 1220,
    body: grid(
      {
        name: "rt-grid",
        width: fill,
        height: fill,
        columns: [fr(1), fixed(2), fr(1)],
        columnGap: 28,
      },
      [
        column(
          { name: "reservation-lane", width: fill, height: hug, gap: 16 },
          [
            makeText("予約", { name: "reservation-lane-title", fontSize: 34, color: COLORS.copper, bold: true }),
            makeText("受付", { name: "reservation-lane-1", fontSize: 24, color: COLORS.charcoal, bold: true }),
            makeText("自己変更 / キャンセル", { name: "reservation-lane-2", fontSize: 24, color: COLORS.charcoal, bold: true }),
            makeText("予約金", { name: "reservation-lane-3", fontSize: 24, color: COLORS.charcoal, bold: true }),
            makeText("予約台帳", { name: "reservation-lane-4", fontSize: 24, color: COLORS.charcoal, bold: true }),
            makeText("LINE連携", { name: "reservation-lane-5", fontSize: 24, color: COLORS.charcoal, bold: true }),
            makeText("来店前後の問い合わせを、店舗だけで抱え込みにくくする。", {
              name: "reservation-lane-detail",
              fontSize: 20,
              color: COLORS.stone,
              maxWidth: 460,
            }),
          ],
        ),
        rule({ name: "rt-vrule", width: fixed(2), height: fill, stroke: COLORS.sand, weight: 2 }),
        column(
          { name: "takeout-lane", width: fill, height: hug, gap: 16 },
          [
            makeText("テイクアウト", { name: "takeout-lane-title", fontSize: 34, color: COLORS.copper, bold: true }),
            makeText("受取枠管理", { name: "takeout-lane-1", fontSize: 24, color: COLORS.charcoal, bold: true }),
            makeText("事前注文", { name: "takeout-lane-2", fontSize: 24, color: COLORS.charcoal, bold: true }),
            makeText("注文ステータス", { name: "takeout-lane-3", fontSize: 24, color: COLORS.charcoal, bold: true }),
            makeText("ピーク分散", { name: "takeout-lane-4", fontSize: 24, color: COLORS.charcoal, bold: true }),
            makeText("店内運用と同じ基盤", { name: "takeout-lane-5", fontSize: 24, color: COLORS.charcoal, bold: true }),
            makeText("売上機会を増やしながら、受取タイミングを整えやすくする。", {
              name: "takeout-lane-detail",
              fontSize: 20,
              color: COLORS.stone,
              maxWidth: 460,
            }),
          ],
        ),
      ],
    ),
  });
}

function addAnalyticsSlide(presentation) {
  const slide = presentation.slides.add();
  addBackground(slide, COLORS.cream);
  addDecor(slide, [
    {
      node: makeText("改善", {
        name: "analytics-ghost",
        fontSize: 250,
        color: "#F0E1D3",
        bold: true,
        maxWidth: 900,
      }),
      frame: { left: 1040, top: 310, width: 700, height: 250 },
    },
  ]);

  addSlideShell(slide, {
    index: 9,
    eyebrow: "Management insight",
    title: "勘ではなく\n改善に使える数字が残る",
    subtitle: "売上を見て終わりではなく、次の打ち手までつなげるためのレポート群。",
    body: grid(
      {
        name: "analytics-grid",
        width: fill,
        height: fill,
        columns: [fr(1), fr(1)],
        columnGap: 36,
        rowGap: 22,
      },
      [
        lineItem("analytics-1", "ABC分析", "何が売上や粗利を支えているかを見極める。"),
        lineItem("analytics-2", "回転率 / 客層", "どの時間帯、どの席、どの客層で効率が落ちているかをつかむ。"),
        lineItem("analytics-3", "満足度分析", "どの商品、どの時間帯で不満が出ているかを改善に使う。"),
        lineItem("analytics-4", "スタッフ評価", "教育や運用改善のヒントを、印象ではなく実績から見る。"),
        lineItem("analytics-5", "クロス店舗レポート", "多店舗でも、店舗差を横断で把握しやすい。"),
        lineItem("analytics-6", "需要予測", "売上見込みと仕込み、発注、人員配置の判断を早くする。"),
      ],
    ),
  });
}

function addFitSlide(presentation) {
  const slide = presentation.slides.add();
  addBackground(slide, COLORS.warmWhite);

  addSlideShell(slide, {
    index: 10,
    eyebrow: "Best fit",
    title: "こんな店舗に\n向いている",
    subtitle: "1店舗の省力化から、多店舗運営の標準化まで。業態ごとの悩みに合わせて見せ方を変えられる。",
    body: grid(
      {
        name: "fit-grid",
        width: fill,
        height: fill,
        columns: [fr(1), fr(1)],
        columnGap: 36,
        rowGap: 24,
      },
      [
        lineItem("fit-1", "1店舗の個店", "少人数でも回しやすい店をつくりたい。"),
        lineItem("fit-2", "予約やテイクアウトが多い店", "電話や確認作業を減らしたい。"),
        lineItem("fit-3", "多店舗運営", "本部と店舗のズレを減らし、横断で見たい。"),
        lineItem("fit-4", "訪日客対応が必要な店", "多言語で注文障壁を下げたい。"),
      ],
    ),
  });
}

function addCloseSlide(presentation) {
  const slide = presentation.slides.add();
  addBackground(slide, COLORS.cocoa);
  addDecor(slide, [
    {
      node: shape({ name: "close-top-line", fill: COLORS.amber, width: fill, height: fill }),
      frame: { left: 0, top: 0, width: SLIDE_W, height: 16 },
    },
  ]);

  addSlideShell(slide, {
    index: 11,
    dark: true,
    eyebrow: "Closing message",
    title: "POSLAは\nセルフオーダーではなく\n店舗運営基盤です",
    subtitle: "売上増、コスト削減、改善速度の3つを同時に前進させるための基盤として提案する。",
    titleSize: 72,
    body: row(
      {
        name: "close-body",
        width: fill,
        height: hug,
        justify: "between",
        align: "start",
      },
      [
        column(
          { name: "close-claims", width: fill, height: hug, gap: 18 },
          [
            bigWord("close-word-1", "売上増", COLORS.amber),
            makeText("おすすめ表示、AIウェイター、レビュー導線で売れる導線をつくる。", {
              name: "close-word-1-detail",
              fontSize: 22,
              color: COLORS.sand,
              maxWidth: 430,
            }),
            bigWord("close-word-2", "コスト削減", COLORS.amber),
            makeText("注文、伝達、会計、電話対応のムダを減らしやすくする。", {
              name: "close-word-2-detail",
              fontSize: 22,
              color: COLORS.sand,
              maxWidth: 430,
            }),
            bigWord("close-word-3", "改善速度", COLORS.amber),
            makeText("在庫、回転率、満足度、併売傾向を次の打ち手につなげる。", {
              name: "close-word-3-detail",
              fontSize: 22,
              color: COLORS.sand,
              maxWidth: 430,
            }),
          ],
        ),
        column(
          { name: "close-right", width: fill, height: hug, gap: 20 },
          [
            makeText("次に詰めるべきこと", {
              name: "close-next-title",
              fontSize: 30,
              color: COLORS.warmWhite,
              bold: true,
            }),
            makeText("1. 業態別の見せ方に分ける", {
              name: "close-next-1",
              fontSize: 24,
              color: COLORS.sand,
              bold: true,
            }),
            makeText("2. 導入後の1日を図解する", {
              name: "close-next-2",
              fontSize: 24,
              color: COLORS.sand,
              bold: true,
            }),
            makeText("3. 価格や導入ステップを別紙で補う", {
              name: "close-next-3",
              fontSize: 24,
              color: COLORS.sand,
              bold: true,
            }),
          ],
        ),
      ],
    ),
  });
}

async function ensureDirs() {
  await fs.mkdir(scratchDir, { recursive: true });
  await fs.mkdir(outputDir, { recursive: true });
  await fs.mkdir(sourceDir, { recursive: true });
  await fs.mkdir(pptxDir, { recursive: true });
}

async function writeBlob(filePath, blob) {
  await fs.writeFile(filePath, Buffer.from(await blob.arrayBuffer()));
}

async function renderSlides(presentation, baseDir, prefix) {
  var i;
  const summary = [];
  for (i = 0; i < presentation.slides.count; i += 1) {
    const slide = presentation.slides.getItem(i);
    const png = await slide.export({ format: "png" });
    const layout = await slide.export({ format: "layout" });
    const slideNo = String(i + 1).padStart(2, "0");
    const pngPath = path.join(baseDir, prefix + "-slide-" + slideNo + ".png");
    const layoutPath = path.join(baseDir, prefix + "-slide-" + slideNo + ".layout.json");
    await writeBlob(pngPath, png);
    await writeBlob(layoutPath, layout);
    summary.push({
      slide: i + 1,
      png: pngPath,
      layout: layoutPath,
    });
  }
  return summary;
}

async function main() {
  await ensureDirs();

  const presentation = Presentation.create({
    slideSize: { width: SLIDE_W, height: SLIDE_H },
  });

  addCoverSlide(presentation);
  addPainSlide(presentation);
  addConnectionSlide(presentation);
  addDayFlowSlide(presentation);
  addRevenueSlide(presentation);
  addLaborSlide(presentation);
  addInventorySlide(presentation);
  addReservationSlide(presentation);
  addAnalyticsSlide(presentation);
  addFitSlide(presentation);
  addCloseSlide(presentation);

  const pptxBlob = await PresentationFile.exportPptx(presentation);
  await pptxBlob.save(outputPptx);

  const sourceSummary = await renderSlides(presentation, sourceDir, "source");
  const reloaded = await PresentationFile.importPptx(await FileBlob.load(outputPptx));
  const pptxSummary = await renderSlides(reloaded, pptxDir, "pptx");

  await fs.writeFile(
    path.join(scratchDir, "qa-summary.json"),
    JSON.stringify(
      {
        slideCount: presentation.slides.count,
        outputPptx: outputPptx,
        sourceSummary: sourceSummary,
        pptxSummary: pptxSummary,
      },
      null,
      2,
    ),
    "utf8",
  );

  console.log(
    JSON.stringify(
      {
        ok: true,
        slideCount: presentation.slides.count,
        outputPptx: outputPptx,
        sourcePreviewDir: sourceDir,
        pptxPreviewDir: pptxDir,
      },
      null,
      2,
    ),
  );
}

await main();
