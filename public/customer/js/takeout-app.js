(function () {
  'use strict';

  var BASE_API = '../../api';
  var POLL_INTERVAL = 10000;

  var _storeId = null;
  var _storeName = '';
  var _settings = {};
  var _categories = [];
  var _popularity = {};
  var _recommendations = [];
  var _cart = [];
  var _currentSection = 1;
  var _orderId = null;
  var _orderPhone = '';
  var _pollTimer = null;
  var _selectedSlot = '';
  var _onlinePaymentAvailable = false;

  // ===== ユーティリティ =====
  function escapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  }

  function formatPrice(yen) {
    return '¥' + String(yen).replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1,');
  }

  function generateUUID() {
    var d = new Date().getTime();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = (d + Math.random() * 16) % 16 | 0;
      d = Math.floor(d / 16);
      return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
  }

  function apiGet(path) {
    return fetch(BASE_API + path, { credentials: 'same-origin' }).then(function (res) {
      return res.text().then(function (body) {
        var json;
        try { json = JSON.parse(body); } catch (e) { return Promise.reject(new Error('応答の解析に失敗しました')); }
        if (!res.ok || !json.ok) {
          var msg = (window.Utils && Utils.formatError) ? Utils.formatError(json) : ((json.error && json.error.message) || 'エラーが発生しました');
          return Promise.reject(new Error(msg));
        }
        return json.data;
      });
    });
  }

  function apiPost(path, data) {
    return fetch(BASE_API + path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
      credentials: 'same-origin'
    }).then(function (res) {
      return res.text().then(function (body) {
        var json;
        try { json = JSON.parse(body); } catch (e) { return Promise.reject(new Error('応答の解析に失敗しました')); }
        if (!res.ok || !json.ok) {
          var msg = (window.Utils && Utils.formatError) ? Utils.formatError(json) : ((json.error && json.error.message) || 'エラーが発生しました');
          return Promise.reject(new Error(msg));
        }
        return json.data;
      });
    });
  }

  // ===== 初期化 =====
  function init() {
    var params = new URLSearchParams(location.search);
    _storeId = params.get('store_id');
    if (!_storeId) {
      showError('store_idが指定されていません。');
      return;
    }

    showLoading();

    apiGet('/customer/takeout-orders.php?action=settings&store_id=' + encodeURIComponent(_storeId))
      .then(function (data) {
        _settings = data;
        _storeName = data.store_name || '';
        _onlinePaymentAvailable = !!data.online_payment_available;

        var headerName = document.getElementById('to-store-name');
        if (headerName) headerName.textContent = _storeName;
        document.title = _storeName + ' テイクアウト注文';

        // N-12: ブランディング適用
        var _brandColor = data.brand_color;
        if (_brandColor) {
          var bStyle = document.createElement('style');
          bStyle.textContent = ''
            + '.to-header{background:' + _brandColor + '}'
            + 'a{color:' + _brandColor + '}'
            + '.to-spinner{border-top-color:' + _brandColor + '}'
            + '.to-progress__step--active .to-progress__num{background:' + _brandColor + '}'
            + '.to-progress__step--active{color:' + _brandColor + '}'
            + '.to-cat-tab--active{background:' + _brandColor + '}'
            + '.to-menu-card__price{color:' + _brandColor + '}'
            + '.to-qty-btn--add{background:' + _brandColor + ';border-color:' + _brandColor + '}'
            + '.to-cart-bar__total{color:' + _brandColor + '}'
            + '.to-cart-bar__next{background:' + _brandColor + '}'
            + '.to-form__input:focus{border-color:' + _brandColor + '}'
            + '.to-slot--selected{background:' + _brandColor + ';border-color:' + _brandColor + '}'
            + '.to-radio-card--selected{border-color:' + _brandColor + '}'
            + '.to-btn--primary{background:' + _brandColor + '}'
            + '.to-complete__order-id{color:' + _brandColor + '}';
          document.head.appendChild(bStyle);
          var meta = document.querySelector('meta[name="theme-color"]');
          if (meta) meta.setAttribute('content', _brandColor);
        }
        if (data.brand_logo_url) {
          var nameEl = document.getElementById('to-store-name');
          if (nameEl) {
            var bImg = document.createElement('img');
            bImg.src = data.brand_logo_url;
            bImg.alt = '';
            bImg.style.cssText = 'height:24px;margin-right:6px;vertical-align:middle;';
            bImg.onerror = function () { this.style.display = 'none'; };
            nameEl.insertBefore(bImg, nameEl.firstChild);
          }
        }
        if (data.brand_display_name) {
          if (headerName) headerName.textContent = data.brand_display_name;
          document.title = data.brand_display_name + ' テイクアウト注文';
        }

        if (!data.takeout_enabled) {
          showError('現在テイクアウトは受け付けていません。');
          return;
        }

        return apiGet('/customer/menu.php?store_id=' + encodeURIComponent(_storeId));
      })
      .then(function (data) {
        if (!data) return;
        _categories = data.categories || [];
        _popularity = data.popularity || {};
        _recommendations = data.recommendations || [];
        renderApp();
        goToSection(1);
      })
      .catch(function (err) {
        showError(err.message || '読み込みに失敗しました。');
      });
  }

  function showLoading() {
    var app = document.getElementById('takeout-app');
    app.innerHTML = '<div style="text-align:center;padding:3rem;color:#888;"><div class="to-spinner"></div>読み込み中...</div>';
  }

  function showError(msg) {
    var app = document.getElementById('takeout-app');
    app.innerHTML = '<div style="text-align:center;padding:3rem;color:#c62828;">' + escapeHtml(msg) + '</div>';
  }

  // ===== アプリ描画 =====
  function renderApp() {
    var app = document.getElementById('takeout-app');
    app.innerHTML = ''
      + '<div class="to-progress" id="to-progress"></div>'
      + '<div class="to-section" id="to-section-1"></div>'
      + '<div class="to-section" id="to-section-2" style="display:none"></div>'
      + '<div class="to-section" id="to-section-3" style="display:none"></div>'
      + '<div class="to-section" id="to-section-4" style="display:none"></div>'
      + '<div class="to-section" id="to-section-5" style="display:none"></div>'
      + '<div class="to-cart-bar" id="to-cart-bar" style="display:none"></div>'
      + '<div class="to-cart-modal" id="to-cart-modal" style="display:none"></div>';
  }

  // ===== 進捗バー =====
  function renderProgress() {
    var steps = ['メニュー', '情報入力', '支払い', '確認', '完了'];
    var html = '';
    for (var i = 0; i < steps.length; i++) {
      var cls = 'to-progress__step';
      if (i + 1 < _currentSection) cls += ' to-progress__step--done';
      if (i + 1 === _currentSection) cls += ' to-progress__step--active';
      html += '<div class="' + cls + '"><span class="to-progress__num">' + (i + 1) + '</span><span class="to-progress__label">' + steps[i] + '</span></div>';
    }
    var el = document.getElementById('to-progress');
    if (el) el.innerHTML = html;
  }

  // ===== セクション遷移 =====
  function goToSection(num) {
    _currentSection = num;
    for (var i = 1; i <= 5; i++) {
      var sec = document.getElementById('to-section-' + i);
      if (sec) sec.style.display = (i === num) ? '' : 'none';
    }
    renderProgress();
    window.scrollTo(0, 0);

    if (num === 1) renderMenu();
    if (num === 2) renderInfoForm();
    if (num === 3) renderPaymentChoice();
    if (num === 4) renderConfirmation();
    if (num === 5) renderCompletion();

    var cartBar = document.getElementById('to-cart-bar');
    if (cartBar) cartBar.style.display = (num === 1) ? '' : 'none';
  }

  // ===== セクション1: メニュー =====
  function renderMenu() {
    var sec = document.getElementById('to-section-1');
    if (!_categories.length) {
      sec.innerHTML = '<div style="text-align:center;padding:2rem;color:#888;">メニューがありません。</div>';
      return;
    }

    var html = '<div class="to-cat-tabs" id="to-cat-tabs"></div>';
    html += '<div class="to-menu-items" id="to-menu-items"></div>';
    sec.innerHTML = html;

    // カテゴリタブ
    var tabHtml = '';
    for (var c = 0; c < _categories.length; c++) {
      tabHtml += '<button class="to-cat-tab' + (c === 0 ? ' to-cat-tab--active' : '') + '" data-cat-idx="' + c + '">'
        + escapeHtml(_categories[c].categoryName) + '</button>';
    }
    document.getElementById('to-cat-tabs').innerHTML = tabHtml;
    document.getElementById('to-cat-tabs').addEventListener('click', function (e) {
      var btn = e.target.closest('.to-cat-tab');
      if (!btn) return;
      document.querySelectorAll('.to-cat-tab').forEach(function (b) { b.classList.remove('to-cat-tab--active'); });
      btn.classList.add('to-cat-tab--active');
      renderCategoryItems(parseInt(btn.dataset.catIdx, 10));
    });

    renderCategoryItems(0);
    renderCartBar();
  }

  function renderCategoryItems(catIdx) {
    var cat = _categories[catIdx];
    if (!cat) return;
    var items = cat.items || [];
    var html = '';

    for (var i = 0; i < items.length; i++) {
      var it = items[i];
      var soldOut = !!it.soldOut;
      var inCart = getCartQty(it.menuItemId);
      var popRank = _popularity[it.menuItemId];
      var isRec = false;
      for (var r = 0; r < _recommendations.length; r++) {
        if (_recommendations[r].menu_item_id === it.menuItemId) { isRec = true; break; }
      }

      html += '<div class="to-menu-card' + (soldOut ? ' to-menu-card--sold-out' : '') + '">';
      if (it.imageUrl) {
        html += '<div class="to-menu-card__img" style="background-image:url(' + escapeHtml(it.imageUrl) + ')"></div>';
      }
      html += '<div class="to-menu-card__body">';
      html += '<div class="to-menu-card__name">' + escapeHtml(it.name);
      if (popRank && popRank <= 3) html += ' <span class="to-badge to-badge--pop">#' + popRank + '</span>';
      if (isRec) html += ' <span class="to-badge to-badge--rec">おすすめ</span>';
      html += '</div>';
      if (it.nameEn) html += '<div class="to-menu-card__name-en">' + escapeHtml(it.nameEn) + '</div>';
      if (it.description) html += '<div class="to-menu-card__desc">' + escapeHtml(it.description) + '</div>';
      html += '<div class="to-menu-card__price">' + formatPrice(it.price) + '</div>';
      if (soldOut) {
        html += '<div class="to-menu-card__soldout">品切れ</div>';
      } else {
        html += '<div class="to-menu-card__actions">';
        if (inCart > 0) {
          html += '<button class="to-qty-btn" data-item-id="' + escapeHtml(it.menuItemId) + '" data-delta="-1">-</button>';
          html += '<span class="to-qty-num">' + inCart + '</span>';
        }
        html += '<button class="to-qty-btn to-qty-btn--add" data-item-id="' + escapeHtml(it.menuItemId) + '" data-delta="1"'
          + ' data-name="' + escapeHtml(it.name) + '"'
          + ' data-price="' + (it.price || 0) + '"'
          + ' data-cat-idx="' + catIdx + '"'
          + ' data-item-idx="' + i + '"'
          + '>+</button>';
        html += '</div>';
      }
      html += '</div></div>';
    }

    var container = document.getElementById('to-menu-items');
    container.innerHTML = html;

    container.addEventListener('click', function (e) {
      var btn = e.target.closest('.to-qty-btn');
      if (!btn) return;
      var itemId = btn.dataset.itemId;
      var delta = parseInt(btn.dataset.delta, 10);
      if (delta > 0) {
        var cIdx = parseInt(btn.dataset.catIdx, 10);
        var iIdx = parseInt(btn.dataset.itemIdx, 10);
        var itemData = _categories[cIdx] && _categories[cIdx].items[iIdx];
        if (itemData) addToCart(itemData);
      } else {
        removeFromCartById(itemId, 1);
      }
      renderCategoryItems(parseInt(document.querySelector('.to-cat-tab--active').dataset.catIdx, 10));
      renderCartBar();
    });
  }

  // ===== カート管理 =====
  function addToCart(item) {
    for (var i = 0; i < _cart.length; i++) {
      if (_cart[i].id === item.menuItemId) {
        _cart[i].qty += 1;
        return;
      }
    }
    _cart.push({
      id: item.menuItemId,
      name: item.name,
      nameEn: item.nameEn || '',
      price: item.price || 0,
      qty: 1,
    });
  }

  function removeFromCartById(itemId, delta) {
    for (var i = 0; i < _cart.length; i++) {
      if (_cart[i].id === itemId) {
        _cart[i].qty -= delta;
        if (_cart[i].qty <= 0) _cart.splice(i, 1);
        return;
      }
    }
  }

  function getCartQty(itemId) {
    for (var i = 0; i < _cart.length; i++) {
      if (_cart[i].id === itemId) return _cart[i].qty;
    }
    return 0;
  }

  function getCartTotal() {
    var total = 0;
    for (var i = 0; i < _cart.length; i++) total += _cart[i].price * _cart[i].qty;
    return total;
  }

  function getCartCount() {
    var count = 0;
    for (var i = 0; i < _cart.length; i++) count += _cart[i].qty;
    return count;
  }

  function renderCartBar() {
    var bar = document.getElementById('to-cart-bar');
    if (!bar) return;
    var count = getCartCount();
    if (count === 0) {
      bar.style.display = 'none';
      return;
    }
    bar.style.display = '';
    bar.innerHTML = '<div class="to-cart-bar__info" id="to-cart-bar-detail">'
      + '<span class="to-cart-bar__count">カート (' + count + '品)</span>'
      + '<span class="to-cart-bar__total">' + formatPrice(getCartTotal()) + '</span>'
      + '</div>'
      + '<button class="to-cart-bar__next" id="to-cart-bar-next">次へ</button>';

    document.getElementById('to-cart-bar-next').addEventListener('click', function () {
      if (getCartCount() === 0) return;
      goToSection(2);
    });
    document.getElementById('to-cart-bar-detail').addEventListener('click', function () {
      showCartModal();
    });
  }

  function showCartModal() {
    var modal = document.getElementById('to-cart-modal');
    var html = '<div class="to-cart-modal__inner">';
    html += '<div class="to-cart-modal__header">カート内容 <button class="to-cart-modal__close" id="to-cart-close">&times;</button></div>';
    html += '<div class="to-cart-modal__list">';
    for (var i = 0; i < _cart.length; i++) {
      var c = _cart[i];
      html += '<div class="to-cart-item">'
        + '<div class="to-cart-item__name">' + escapeHtml(c.name) + '</div>'
        + '<div class="to-cart-item__price">' + formatPrice(c.price) + '</div>'
        + '<div class="to-cart-item__qty">'
        + '<button class="to-qty-btn" data-cart-idx="' + i + '" data-cdelta="-1">-</button>'
        + '<span>' + c.qty + '</span>'
        + '<button class="to-qty-btn" data-cart-idx="' + i + '" data-cdelta="1">+</button>'
        + '</div>'
        + '<div class="to-cart-item__subtotal">' + formatPrice(c.price * c.qty) + '</div>'
        + '</div>';
    }
    html += '</div>';
    html += '<div class="to-cart-modal__total">合計: ' + formatPrice(getCartTotal()) + '</div>';
    html += '</div>';
    modal.innerHTML = html;
    modal.style.display = '';

    document.getElementById('to-cart-close').addEventListener('click', function () {
      modal.style.display = 'none';
    });
    modal.addEventListener('click', function (e) {
      var btn = e.target.closest('.to-qty-btn');
      if (!btn) {
        if (e.target === modal) modal.style.display = 'none';
        return;
      }
      var idx = parseInt(btn.dataset.cartIdx, 10);
      var d = parseInt(btn.dataset.cdelta, 10);
      if (!isNaN(idx) && _cart[idx]) {
        _cart[idx].qty += d;
        if (_cart[idx].qty <= 0) _cart.splice(idx, 1);
        showCartModal();
        renderCartBar();
      }
    });
  }

  // ===== セクション2: お客様情報 =====
  function renderInfoForm() {
    var sec = document.getElementById('to-section-2');
    var html = '<h2 class="to-section__title">お客様情報</h2>';
    html += '<div class="to-form">';
    html += '<div class="to-form__group"><label class="to-form__label">お名前 <span class="to-required">*</span></label>';
    html += '<input class="to-form__input" id="to-name" type="text" placeholder="例: 山田太郎" value="' + escapeHtml(_orderPhone ? '' : '') + '"></div>';
    html += '<div class="to-form__group"><label class="to-form__label">電話番号 <span class="to-required">*</span></label>';
    html += '<input class="to-form__input" id="to-phone" type="tel" placeholder="例: 09012345678" pattern="[0-9]{10,11}"></div>';
    html += '<div class="to-form__group"><label class="to-form__label">メモ（アレルギー等）</label>';
    html += '<textarea class="to-form__input to-form__textarea" id="to-memo" placeholder="ご要望があればご記入ください"></textarea></div>';
    html += '<div class="to-form__group"><label class="to-form__label">受取時間 <span class="to-required">*</span></label>';
    html += '<div class="to-slots" id="to-slots"><div style="text-align:center;padding:1rem;color:#888;">時間枠を読み込み中...</div></div></div>';
    html += '</div>';
    html += '<div class="to-nav-btns">';
    html += '<button class="to-btn to-btn--secondary" id="to-back-1">戻る</button>';
    html += '<button class="to-btn to-btn--primary" id="to-next-3">次へ</button>';
    html += '</div>';
    sec.innerHTML = html;

    document.getElementById('to-back-1').addEventListener('click', function () { goToSection(1); });
    document.getElementById('to-next-3').addEventListener('click', function () {
      var name = document.getElementById('to-name').value.trim();
      var phone = document.getElementById('to-phone').value.trim();
      if (!name) { showToast('お名前を入力してください'); return; }
      if (!/^[0-9]{10,11}$/.test(phone)) { showToast('電話番号をハイフンなし10〜11桁で入力してください'); return; }
      if (!_selectedSlot) { showToast('受取時間を選択してください'); return; }
      goToSection(3);
    });

    loadTimeSlots();
  }

  function loadTimeSlots() {
    var today = new Date();
    var dateStr = today.getFullYear() + '-' + ('0' + (today.getMonth() + 1)).slice(-2) + '-' + ('0' + today.getDate()).slice(-2);

    apiGet('/customer/takeout-orders.php?action=slots&store_id=' + encodeURIComponent(_storeId) + '&date=' + dateStr)
      .then(function (data) {
        var slots = data.slots || [];
        var container = document.getElementById('to-slots');
        if (!slots.length) {
          container.innerHTML = '<div style="color:#888;padding:0.5rem;">本日の受付時間枠がありません。</div>';
          return;
        }
        var html = '';
        for (var i = 0; i < slots.length; i++) {
          var s = slots[i];
          var full = s.available <= 0;
          var selected = (_selectedSlot === s.time);
          html += '<button class="to-slot' + (full ? ' to-slot--full' : '') + (selected ? ' to-slot--selected' : '') + '"'
            + ' data-time="' + s.time + '"' + (full ? ' disabled' : '') + '>'
            + s.time + (full ? ' <span class="to-slot__full">満</span>' : ' <span class="to-slot__avail">残' + s.available + '</span>')
            + '</button>';
        }
        container.innerHTML = html;

        container.addEventListener('click', function (e) {
          var btn = e.target.closest('.to-slot');
          if (!btn || btn.disabled) return;
          _selectedSlot = btn.dataset.time;
          container.querySelectorAll('.to-slot').forEach(function (b) { b.classList.remove('to-slot--selected'); });
          btn.classList.add('to-slot--selected');
        });
      })
      .catch(function () {
        var container = document.getElementById('to-slots');
        if (container) container.innerHTML = '<div style="color:#c62828;">時間枠の取得に失敗しました。</div>';
      });
  }

  // ===== セクション3: 支払い方法 =====
  function renderPaymentChoice() {
    var sec = document.getElementById('to-section-3');
    var html = '<h2 class="to-section__title">お支払い方法</h2>';
    html += '<div class="to-form">';
    if (_onlinePaymentAvailable) {
      html += '<label class="to-radio-card to-radio-card--selected"><input type="radio" name="to-payment" value="online" checked> オンライン決済<span class="to-radio-desc">決済ページに移動します（事前決済）</span></label>';
    } else {
      html += '<div class="to-notice" style="padding:16px;background:#fff3cd;border-radius:8px;color:#856404;">現在テイクアウトのオンライン決済が設定されていないため、ご注文いただけません。店舗にお問い合わせください。</div>';
    }
    html += '</div>';
    html += '<div class="to-nav-btns">';
    html += '<button class="to-btn to-btn--secondary" id="to-back-2">戻る</button>';
    if (_onlinePaymentAvailable) {
      html += '<button class="to-btn to-btn--primary" id="to-next-4">次へ</button>';
    }
    html += '</div>';
    sec.innerHTML = html;

    document.getElementById('to-back-2').addEventListener('click', function () { goToSection(2); });
    var nextBtn = document.getElementById('to-next-4');
    if (nextBtn) {
      nextBtn.addEventListener('click', function () { goToSection(4); });
    }
  }

  // ===== セクション4: 確認 =====
  function renderConfirmation() {
    var sec = document.getElementById('to-section-4');
    var name = document.getElementById('to-name') ? document.getElementById('to-name').value.trim() : '';
    var phone = document.getElementById('to-phone') ? document.getElementById('to-phone').value.trim() : '';
    var memo = document.getElementById('to-memo') ? document.getElementById('to-memo').value.trim() : '';
    var payEl = document.querySelector('input[name="to-payment"]:checked');
    var payMethod = payEl ? payEl.value : 'online';

    var html = '<h2 class="to-section__title">ご注文確認</h2>';

    // 注文内容
    html += '<div class="to-confirm-card"><h3 class="to-confirm-card__title">注文内容</h3>';
    for (var i = 0; i < _cart.length; i++) {
      var c = _cart[i];
      html += '<div class="to-confirm-item"><span>' + escapeHtml(c.name) + ' x' + c.qty + '</span><span>' + formatPrice(c.price * c.qty) + '</span></div>';
    }
    html += '<div class="to-confirm-total">合計: ' + formatPrice(getCartTotal()) + '</div>';
    html += '</div>';

    // お客様情報
    html += '<div class="to-confirm-card"><h3 class="to-confirm-card__title">お客様情報</h3>';
    html += '<div class="to-confirm-row"><span>お名前</span><span>' + escapeHtml(name) + '</span></div>';
    html += '<div class="to-confirm-row"><span>電話番号</span><span>' + escapeHtml(phone) + '</span></div>';
    if (memo) html += '<div class="to-confirm-row"><span>メモ</span><span>' + escapeHtml(memo) + '</span></div>';
    html += '<div class="to-confirm-row"><span>受取時間</span><span>' + escapeHtml(_selectedSlot) + '</span></div>';
    html += '<div class="to-confirm-row"><span>支払方法</span><span>オンライン決済</span></div>';
    html += '</div>';

    html += '<div class="to-nav-btns">';
    html += '<button class="to-btn to-btn--secondary" id="to-back-3">戻る</button>';
    html += '<button class="to-btn to-btn--primary to-btn--submit" id="to-submit">注文を確定する</button>';
    html += '</div>';
    sec.innerHTML = html;

    document.getElementById('to-back-3').addEventListener('click', function () { goToSection(3); });
    document.getElementById('to-submit').addEventListener('click', function () {
      this.disabled = true;
      this.textContent = '送信中...';
      submitOrder(name, phone, memo, payMethod);
    });
  }

  // ===== 注文送信 =====
  function submitOrder(name, phone, memo, payMethod) {
    var today = new Date();
    var dateStr = today.getFullYear() + '-' + ('0' + (today.getMonth() + 1)).slice(-2) + '-' + ('0' + today.getDate()).slice(-2);
    var pickupAt = dateStr + ' ' + _selectedSlot + ':00';

    var orderItems = [];
    for (var i = 0; i < _cart.length; i++) {
      orderItems.push({
        id: _cart[i].id,
        name: _cart[i].name,
        price: _cart[i].price,
        qty: _cart[i].qty,
      });
    }

    apiPost('/customer/takeout-orders.php', {
      store_id: _storeId,
      items: orderItems,
      customer_name: name,
      customer_phone: phone,
      pickup_at: pickupAt,
      memo: memo,
      payment_method: payMethod,
      idempotency_key: generateUUID(),
    }).then(function (data) {
      _orderId = data.order_id;
      _orderPhone = phone;

      if (data.checkout_url) {
        window.location.href = data.checkout_url;
        return;
      }

      goToSection(5);
    }).catch(function (err) {
      showToast(err.message || '注文に失敗しました');
      var btn = document.getElementById('to-submit');
      if (btn) { btn.disabled = false; btn.textContent = '注文を確定する'; }
    });
  }

  // ===== 決済リターンハンドリング =====
  function handlePaymentReturn() {
    var params = new URLSearchParams(location.search);
    _storeId = params.get('store_id');
    _orderId = params.get('order_id');
    _orderPhone = params.get('phone') || '';
    var status = params.get('payment_status');

    var app = document.getElementById('takeout-app');

    if (status === 'success') {
      app.innerHTML = '<div style="text-align:center;padding:3rem;"><div class="to-spinner"></div>決済を確認中...</div>';

      // セッションIDがURLにある場合
      var sessionId = params.get('session_id') || '';
      var transactionId = params.get('transaction_id') || '';
      var qs = 'order_id=' + encodeURIComponent(_orderId);
      if (sessionId) qs += '&session_id=' + encodeURIComponent(sessionId);
      if (transactionId) qs += '&transaction_id=' + encodeURIComponent(transactionId);

      apiGet('/customer/takeout-payment.php?' + qs)
        .then(function () {
          renderApp();
          goToSection(5);
        })
        .catch(function () {
          // 確認失敗でも完了画面を表示（注文自体は作成済み）
          renderApp();
          goToSection(5);
        });
    } else if (status === 'cancel') {
      app.innerHTML = '<div style="text-align:center;padding:3rem;">'
        + '<div style="font-size:1.2rem;color:#FF9800;margin-bottom:1rem;">決済がキャンセルされました</div>'
        + '<p style="color:#666;margin-bottom:2rem;">注文は保持されています。もう一度お試しいただくか、店舗にお問い合わせください。</p>'
        + '<a href="takeout.html?store_id=' + encodeURIComponent(_storeId) + '" class="to-btn to-btn--primary" style="text-decoration:none;display:inline-block;">新しい注文を作成</a>'
        + '</div>';
    }
  }

  // ===== セクション5: 完了 =====
  function renderCompletion() {
    var sec = document.getElementById('to-section-5');
    var shortId = _orderId ? _orderId.substring(0, 8).toUpperCase() : '---';

    var html = '<div class="to-complete">';
    html += '<div class="to-complete__icon">&#10003;</div>';
    html += '<h2 class="to-complete__title">ご注文を受け付けました</h2>';
    html += '<div class="to-complete__order-id">注文番号: ' + shortId + '</div>';
    html += '<div class="to-complete__pickup">受取時間: ' + escapeHtml(_selectedSlot || '---') + '</div>';
    html += '<div class="to-complete__status" id="to-status-display">ステータス: 受付済み</div>';
    html += '<button class="to-btn to-btn--secondary" id="to-check-status" style="margin-top:1rem;">ステータスを更新</button>';
    html += '</div>';
    sec.innerHTML = html;

    document.getElementById('to-check-status').addEventListener('click', function () {
      checkStatus();
    });

    // ポーリング開始
    startStatusPolling();
  }

  function startStatusPolling() {
    if (_pollTimer) clearInterval(_pollTimer);
    _pollTimer = setInterval(function () {
      checkStatus();
    }, POLL_INTERVAL);
  }

  function checkStatus() {
    if (!_orderId || !_orderPhone) return;

    apiGet('/customer/takeout-orders.php?action=status&order_id=' + encodeURIComponent(_orderId)
      + '&phone=' + encodeURIComponent(_orderPhone))
      .then(function (data) {
        var display = document.getElementById('to-status-display');
        if (!display) return;

        var statusMap = {
          'pending': '受付済み - 調理をお待ちください',
          'pending_payment': '決済待ち',
          'preparing': '調理中',
          'ready': '準備完了 - お受け取りいただけます',
          'served': 'お受け取り済み',
          'paid': 'お受け取り済み',
        };
        var label = statusMap[data.status] || data.status;

        display.textContent = 'ステータス: ' + label;
        display.className = 'to-complete__status';
        if (data.status === 'ready') {
          display.classList.add('to-complete__status--ready');
          if (_pollTimer) { clearInterval(_pollTimer); _pollTimer = null; }
        } else if (data.status === 'served' || data.status === 'paid') {
          if (_pollTimer) { clearInterval(_pollTimer); _pollTimer = null; }
        }

        if (data.pickup_at) {
          var pickupEl = document.querySelector('.to-complete__pickup');
          if (pickupEl) {
            var t = data.pickup_at.substring(11, 16);
            pickupEl.textContent = '受取時間: ' + t;
            if (!_selectedSlot) _selectedSlot = t;
          }
        }
      })
      .catch(function () {});
  }

  // ===== トースト =====
  function showToast(msg) {
    var existing = document.getElementById('to-toast');
    if (existing) existing.remove();
    var toast = document.createElement('div');
    toast.id = 'to-toast';
    toast.className = 'to-toast';
    toast.textContent = msg;
    document.body.appendChild(toast);
    setTimeout(function () { toast.classList.add('to-toast--show'); }, 10);
    setTimeout(function () {
      toast.classList.remove('to-toast--show');
      setTimeout(function () { toast.remove(); }, 300);
    }, 3000);
  }

  // ===== 起動 =====
  document.addEventListener('DOMContentLoaded', function () {
    var params = new URLSearchParams(location.search);
    if (params.get('order_id') && params.get('payment_status')) {
      handlePaymentReturn();
    } else {
      init();
    }
  });
})();
