/**
 * 併売分析（バスケット分析）レポート
 * M-4: バスケット分析
 */
var BasketAnalysis = (function () {
  'use strict';

  var _container = null;

  function init(container) {
    _container = container;
  }

  function load() {
    if (!AdminApi.getCurrentStore()) {
      _container.innerHTML = '<div class="empty-state"><p class="empty-state__text">店舗を選択してください</p></div>';
      return;
    }
    _container.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span> 読み込み中...</div>';

    var range = Utils.getPresetRange('month');
    renderControls(range.from, range.to);
    fetchData(range.from, range.to, 3);
  }

  function renderControls(from, to) {
    _container.innerHTML =
      '<div class="report-presets" id="basket-presets">'
      + '<button class="btn btn-outline" data-preset="today">今日</button>'
      + '<button class="btn btn-outline" data-preset="yesterday">昨日</button>'
      + '<button class="btn btn-outline" data-preset="week">今週</button>'
      + '<button class="btn btn-outline active" data-preset="month">今月</button></div>'
      + '<div class="report-date-range">'
      + '<input type="date" id="basket-from" value="' + from + '">'
      + '<span class="report-date-range__sep">〜</span>'
      + '<input type="date" id="basket-to" value="' + to + '">'
      + '<label style="margin-left:8px;font-size:0.85rem;">最小出現: </label>'
      + '<select id="basket-min-support" style="padding:4px 8px;border:1px solid #ccc;border-radius:4px;">'
      + '<option value="2">2回</option>'
      + '<option value="3" selected>3回</option>'
      + '<option value="5">5回</option>'
      + '<option value="10">10回</option>'
      + '</select>'
      + '<button class="btn btn-sm btn-primary" id="btn-basket-load" style="margin-left:8px;">表示</button></div>'
      + '<div id="basket-data"></div>';

    document.getElementById('basket-presets').addEventListener('click', function (e) {
      var btn = e.target.closest('[data-preset]');
      if (!btn) return;
      var r = Utils.getPresetRange(btn.dataset.preset);
      document.getElementById('basket-from').value = r.from;
      document.getElementById('basket-to').value = r.to;
      this.querySelectorAll('.btn').forEach(function (b) { b.classList.remove('active'); });
      btn.classList.add('active');
      fetchData(r.from, r.to, document.getElementById('basket-min-support').value);
    });

    document.getElementById('btn-basket-load').addEventListener('click', function () {
      fetchData(
        document.getElementById('basket-from').value,
        document.getElementById('basket-to').value,
        document.getElementById('basket-min-support').value
      );
    });
  }

  function fetchData(from, to, minSupport) {
    var dataEl = document.getElementById('basket-data');
    if (!dataEl) return;
    dataEl.innerHTML = '<div class="loading-overlay"><span class="loading-spinner"></span></div>';

    AdminApi.getBasketAnalysis(from, to, minSupport).then(function (res) {
      renderReport(dataEl, res);
    }).catch(function (err) {
      dataEl.innerHTML = '<div class="empty-state"><p>' + Utils.escapeHtml(err.message) + '</p></div>';
    });
  }

  /* ========== レンダリング ========== */

  function renderReport(el, data) {
    var html = '';
    html += renderSummary(data);
    html += renderPairRanking(data.pairs);
    html += renderTopItems(data.topItems);
    html += renderHints();
    el.innerHTML = html;
  }

  // --- 1. サマリーカード ---
  function renderSummary(data) {
    return '<div class="report-section"><h3 class="report-section__title">サマリー</h3>'
      + '<div class="report-summary">'
      + '<div class="summary-card"><div class="summary-card__label">分析対象注文数</div><div class="summary-card__value">' + (data.totalOrders || 0) + '件</div></div>'
      + '<div class="summary-card"><div class="summary-card__label">検出ペア数</div><div class="summary-card__value">' + (data.pairs ? data.pairs.length : 0) + '組</div></div>'
      + '<div class="summary-card"><div class="summary-card__label">品目数</div><div class="summary-card__value">' + (data.totalItems || 0) + '品</div></div>'
      + '</div></div>';
  }

  // --- 2. 併売ペアランキング ---
  function renderPairRanking(pairs) {
    if (!pairs || pairs.length === 0) {
      return '<div class="report-section"><h3 class="report-section__title">よく一緒に注文される組み合わせ</h3>'
        + '<div class="empty-state"><p class="empty-state__text">条件に合うペアが見つかりませんでした</p></div></div>';
    }

    var html = '<div class="report-section"><h3 class="report-section__title">よく一緒に注文される組み合わせ TOP' + pairs.length + '</h3>'
      + '<div class="card"><table class="report-table"><thead><tr>'
      + '<th>#</th><th>品目A</th><th>品目B</th><th>出現回数</th><th>確信度(A→B)</th><th>確信度(B→A)</th><th>リフト値</th>'
      + '</tr></thead><tbody>';

    pairs.forEach(function (p, idx) {
      var liftStyle = '';
      if (p.lift >= 1.5) liftStyle = ' style="color:#43a047;font-weight:bold;"';
      else if (p.lift < 1.0) liftStyle = ' style="color:#e53935;font-weight:bold;"';

      html += '<tr>'
        + '<td>' + (idx + 1) + '</td>'
        + '<td>' + Utils.escapeHtml(p.itemA) + '</td>'
        + '<td>' + Utils.escapeHtml(p.itemB) + '</td>'
        + '<td>' + p.support + '回</td>'
        + '<td>' + p.confidenceAB + '%</td>'
        + '<td>' + p.confidenceBA + '%</td>'
        + '<td' + liftStyle + '>' + p.lift + '</td>'
        + '</tr>';
    });

    html += '</tbody></table></div></div>';
    return html;
  }

  // --- 3. 品目別出現頻度 ---
  function renderTopItems(topItems) {
    if (!topItems || topItems.length === 0) return '';

    var maxCount = Math.max.apply(null, topItems.map(function (i) { return i.count; }));
    var html = '<div class="report-section"><h3 class="report-section__title">品目別出現頻度 TOP' + topItems.length + '</h3>'
      + '<div class="card"><ul class="bar-chart">';

    topItems.forEach(function (item) {
      var pct = maxCount > 0 ? Math.round(item.count / maxCount * 100) : 0;
      html += '<li class="bar-row">'
        + '<span class="bar-row__label">' + Utils.escapeHtml(item.name) + '</span>'
        + '<span class="bar-row__track"><span class="bar-row__fill" style="width:' + pct + '%"></span></span>'
        + '<span class="bar-row__value">' + item.count + '回</span>'
        + '</li>';
    });

    html += '</ul></div></div>';
    return html;
  }

  // --- 4. 活用ヒント ---
  function renderHints() {
    return '<div class="report-section">'
      + '<div style="background:#e3f2fd;border-left:4px solid #1565c0;padding:12px 16px;border-radius:4px;">'
      + '<strong style="color:#1565c0;">活用ヒント</strong>'
      + '<ul style="margin:8px 0 0;padding-left:20px;font-size:0.85rem;color:#333;">'
      + '<li>リフト値が1.5以上のペアは強い併売傾向があります。セットメニューの企画やAIウェイターのおすすめ精度向上に活用できます。</li>'
      + '<li>確信度(A→B)が高い場合、品目Aを注文したお客様に品目Bを提案すると効果的です。</li>'
      + '<li>リフト値が1.0未満のペアは負の相関（一緒に注文されにくい）を示します。</li>'
      + '</ul></div></div>';
  }

  return { init: init, load: load };
})();
