/* L-9 予約管理 — 客側予約LP IIFE (ES5)
   ステップ式: 1.日付 → 2.人数 → 3.時間 → 4.情報 → 5.確認 → 完了
   AI チャットあり、多言語対応 */

(function () {
  'use strict';

  var API = '/api/customer';

  // S3-#17: ES5 互換 (String.prototype.padStart は古い WebView 非対応)
  function _pad2RA(n) { return ('0' + n).slice(-2); }

  var _info = null;            // 店舗予約設定
  var _storeId = null;
  var _state = {
    step: 1,
    date: null,                // YYYY-MM-DD
    partySize: null,
    time: null,                // HH:MM
    name: '',
    phone: '',
    email: '',
    memo: '',
    courseId: null,
    language: 'ja',
    monthOffset: 0,            // カレンダー月送り
    waitlistMode: false,       // 満席枠のキャンセル待ち登録
    waitlistId: null,
  };
  var _heatmapCache = {};
  var _slotsCache = {};
  var _completed = null;

  // ---------- i18n ----------
  var I18N = {
    ja: {
      'step.date': '日付', 'step.party': '人数', 'step.time': '時間', 'step.info': '情報', 'step.confirm': '確認',
      'loading.init': '読み込み中…',
      'sec.date.title': '来店日を選んでください', 'sec.date.sub': '緑＝空き / 黄＝混雑予想 / 赤＝残りわずか',
      'sec.party.title': '人数を選んでください', 'sec.party.sub': '大人数の場合は店舗へお電話ください',
      'sec.time.title': '時間を選んでください', 'sec.time.sub': 'お選びの時間に着席いただけます',
      'sec.info.title': 'お客様情報',
      'sec.confirm.title': 'ご予約内容の確認',
      'label.name': 'お名前', 'label.phone': '電話番号', 'label.email': 'メールアドレス',
      'label.course': 'コース', 'label.memo': 'ご要望・アレルギー',
      'btn.next': '次へ', 'btn.back': '戻る', 'btn.confirm': '予約を確定する', 'btn.proceed_payment': '予約金を支払う', 'btn.join_waitlist': 'キャンセル待ちに登録',
      'placeholder.name': '田中 太郎', 'placeholder.phone': '090-1234-5678', 'placeholder.email': 'taro@example.com',
      'placeholder.memo': 'アレルギー、ベビーカー、お祝い等',
      'no.slots': 'この日は空きがありません。別の日をお試しください',
      'no.tables': 'お選びの人数では満席です',
      'completion.title': 'ご予約ありがとうございます！',
      'completion.id': '予約番号',
      'completion.email_sent': '確認メールをお送りしました',
      'completion.show_at_visit': '来店時にスタッフへ予約番号をお伝えください',
      'completion.deposit_redirect': '予約金のお支払いに進みます…',
      'completion.no_deposit': '当日のご来店をお待ちしております',
      'completion.manage': '予約を確認・変更する',
      'completion.new': 'もう一度予約する',
      'completion.close': '閉じる',
      'completion.closed_thanks': 'ご予約ありがとうございました。このタブは閉じて大丈夫です。',
      'tag.confirmed': '確定', 'tag.pending': '決済待ち',
      'remaining_seats': '残り{n}席',
      'closed': '定休',
      'past_date': '過去日',
      'optional': '(任意)',
      'required': '*',
      'confirm.notice': '内容に誤りがないかご確認ください',
      'waitlist.notice': 'この時間は満席です。空席が出た場合の通知候補として登録します',
      'waitlist.confirm_title': 'キャンセル待ち内容の確認',
      'waitlist.confirm_notice': '空席が出た場合に店舗から連絡します。予約はまだ確定していません',
      'waitlist.registered_title': 'キャンセル待ちに登録しました',
      'waitlist.registered_body': '空席が出た場合、店舗からご連絡します',
      'waitlist.id': '受付番号',
      'slot.phone_only': '電話受付のみ',
      'deposit.amount': '予約金',
      'deposit.notice': '予約確定には予約金のお支払いが必要です。次の画面で決済を行います。',
      'deposit.captured_on_no_show': 'no-show 時には予約金が没収されます',
      'cancel.deadline': 'キャンセル無料: 来店{n}時間前まで',
      'ai.fab': 'AIで予約', 'ai.title': 'AIアシスタント', 'ai.help': '「明日19時、2名、田中」のように日付・時刻・人数を含めて入力してください', 'ai.placeholder': '例: 4月19日 19時 4名で予約', 'ai.submit': '解析する',
      'ai.parsed': '解析結果', 'ai.apply': 'この内容で予約フォームに反映する',
      'ai.confidence_low': '解釈の確信度が低いです。フォームで内容を再確認してください',
      'ai.missing': '不足項目',
      'error.generic': 'エラーが発生しました',
      'error.disabled': 'この店舗ではオンライン予約を受け付けていません',
      'error.slot_unavailable': 'お選びの時間は満席です',
      'error.lead_time': '予約締切時刻を過ぎています',
      'error.phone_only': 'この人数は店舗へお電話ください',
    },
    en: {
      'step.date': 'Date', 'step.party': 'Guests', 'step.time': 'Time', 'step.info': 'Info', 'step.confirm': 'Confirm',
      'loading.init': 'Loading...',
      'sec.date.title': 'Choose a date', 'sec.date.sub': 'Green = available / Yellow = busy / Red = nearly full',
      'sec.party.title': 'How many people?', 'sec.party.sub': 'For larger parties, please call the restaurant',
      'sec.time.title': 'Choose a time', 'sec.time.sub': 'You will be seated at the selected time',
      'sec.info.title': 'Your details',
      'sec.confirm.title': 'Review your reservation',
      'label.name': 'Name', 'label.phone': 'Phone', 'label.email': 'Email',
      'label.course': 'Course', 'label.memo': 'Notes / Allergies',
      'btn.next': 'Next', 'btn.back': 'Back', 'btn.confirm': 'Confirm reservation', 'btn.proceed_payment': 'Pay deposit', 'btn.join_waitlist': 'Join waitlist',
      'placeholder.name': 'John Smith', 'placeholder.phone': '+81 90 1234 5678', 'placeholder.email': 'john@example.com',
      'placeholder.memo': 'Allergies, stroller, special occasion etc.',
      'no.slots': 'No availability on this day. Please try another date.',
      'no.tables': 'Sorry, fully booked for this party size',
      'completion.title': 'Reservation confirmed!',
      'completion.id': 'Reservation ID',
      'completion.email_sent': 'A confirmation email has been sent',
      'completion.show_at_visit': 'Please show this ID to staff on arrival',
      'completion.deposit_redirect': 'Redirecting to deposit payment...',
      'completion.no_deposit': 'See you soon!',
      'completion.manage': 'View / change reservation',
      'completion.new': 'Book another',
      'completion.close': 'Close',
      'completion.closed_thanks': 'Thank you! You may close this tab.',
      'tag.confirmed': 'Confirmed', 'tag.pending': 'Awaiting payment',
      'remaining_seats': '{n} seats left',
      'closed': 'Closed',
      'past_date': 'Past',
      'optional': '(optional)',
      'required': '*',
      'confirm.notice': 'Please review your reservation details',
      'waitlist.notice': 'This time is full. Join the waitlist to be contacted if a seat opens.',
      'waitlist.confirm_title': 'Review waitlist request',
      'waitlist.confirm_notice': 'The restaurant will contact you if a seat opens. This is not a confirmed reservation yet.',
      'waitlist.registered_title': 'Waitlist request received',
      'waitlist.registered_body': 'The restaurant will contact you if a seat opens.',
      'waitlist.id': 'Request ID',
      'slot.phone_only': 'Call only',
      'deposit.amount': 'Deposit',
      'deposit.notice': 'A deposit payment is required to confirm this reservation.',
      'deposit.captured_on_no_show': 'Deposit will be captured if you do not show up',
      'cancel.deadline': 'Free cancellation up to {n} hours before',
      'ai.fab': 'AI booking', 'ai.title': 'AI assistant', 'ai.help': 'Just type your request in plain language', 'ai.placeholder': 'e.g. Tomorrow 7pm, 4 people', 'ai.submit': 'Parse',
      'ai.parsed': 'Parsed result', 'ai.apply': 'Apply to reservation form',
      'ai.confidence_low': 'Low confidence. Please double-check the form.',
      'ai.missing': 'Missing',
      'error.generic': 'An error occurred',
      'error.disabled': 'Online reservations are not available at this store',
      'error.slot_unavailable': 'Selected time is full',
      'error.lead_time': 'Lead time exceeded',
      'error.phone_only': 'Please call the restaurant for this party size',
    },
    'zh-Hans': {
      'step.date': '日期', 'step.party': '人数', 'step.time': '时间', 'step.info': '信息', 'step.confirm': '确认',
      'loading.init': '加载中…',
      'sec.date.title': '请选择到店日期', 'sec.date.sub': '绿=有空/黄=忙碌/红=近满',
      'sec.party.title': '请选择人数', 'sec.party.sub': '大人数请直接致电店铺',
      'sec.time.title': '请选择时间', 'sec.time.sub': '您将在选择时间入座',
      'sec.info.title': '客人信息',
      'sec.confirm.title': '请确认预订内容',
      'label.name': '姓名', 'label.phone': '电话', 'label.email': '邮箱',
      'label.course': '套餐', 'label.memo': '备注/过敏',
      'btn.next': '下一步', 'btn.back': '返回', 'btn.confirm': '确认预订', 'btn.proceed_payment': '支付订金', 'btn.join_waitlist': '登记候补',
      'placeholder.name': '张三', 'placeholder.phone': '+81 90 1234 5678', 'placeholder.email': 'example@example.com',
      'placeholder.memo': '过敏/婴儿车/纪念日等',
      'no.slots': '此日无空位,请尝试其他日期',
      'no.tables': '该人数已满',
      'completion.title': '预订成功!',
      'completion.id': '预订编号',
      'completion.email_sent': '确认邮件已发送',
      'completion.show_at_visit': '到店时请向店员出示预订编号',
      'completion.deposit_redirect': '即将跳转至订金支付…',
      'completion.no_deposit': '期待您的光临',
      'completion.manage': '查看/变更预订',
      'completion.new': '再次预订',
      'completion.close': '关闭',
      'completion.closed_thanks': '感谢您的预订,可以关闭此页面。',
      'tag.confirmed': '已确认', 'tag.pending': '等待支付',
      'remaining_seats': '剩{n}席',
      'closed': '休息日',
      'past_date': '过去',
      'optional': '(可选)',
      'required': '*',
      'confirm.notice': '请确认内容无误',
      'waitlist.notice': '该时间已满。可登记候补,有空位时店铺会联系您。',
      'waitlist.confirm_title': '请确认候补内容',
      'waitlist.confirm_notice': '有空位时店铺会联系您。此时预订尚未确定。',
      'waitlist.registered_title': '已登记候补',
      'waitlist.registered_body': '有空位时店铺会联系您。',
      'waitlist.id': '受理编号',
      'slot.phone_only': '电话受理',
      'deposit.amount': '订金',
      'deposit.notice': '需支付订金以确认预订',
      'deposit.captured_on_no_show': '若未到店,订金将被扣除',
      'cancel.deadline': '到店前{n}小时可免费取消',
      'ai.fab': 'AI预订', 'ai.title': 'AI助手', 'ai.help': '请用自然语言描述您的需求', 'ai.placeholder': '例: 明天19点 4人', 'ai.submit': '解析',
      'ai.parsed': '解析结果', 'ai.apply': '应用至表单',
      'ai.confidence_low': '解析置信度较低,请确认表单',
      'ai.missing': '缺失字段',
      'error.generic': '发生错误',
      'error.disabled': '此店铺暂未开放线上预订',
      'error.slot_unavailable': '所选时间已满',
      'error.lead_time': '预订截止时间已过',
      'error.phone_only': '该人数请致电店铺',
    },
    ko: {
      'step.date': '날짜', 'step.party': '인원', 'step.time': '시간', 'step.info': '정보', 'step.confirm': '확인',
      'loading.init': '로딩 중…',
      'sec.date.title': '방문 날짜를 선택하세요', 'sec.date.sub': '녹색=여유 / 노랑=혼잡 / 빨강=거의 만석',
      'sec.party.title': '인원수를 선택하세요', 'sec.party.sub': '대인원은 매장으로 전화 주세요',
      'sec.time.title': '시간을 선택하세요', 'sec.time.sub': '선택한 시간에 착석합니다',
      'sec.info.title': '고객 정보',
      'sec.confirm.title': '예약 내용을 확인하세요',
      'label.name': '이름', 'label.phone': '전화번호', 'label.email': '이메일',
      'label.course': '코스', 'label.memo': '요청사항/알레르기',
      'btn.next': '다음', 'btn.back': '뒤로', 'btn.confirm': '예약 확정', 'btn.proceed_payment': '예약금 결제', 'btn.join_waitlist': '대기 등록',
      'placeholder.name': '김철수', 'placeholder.phone': '+82 10 1234 5678', 'placeholder.email': 'kim@example.com',
      'placeholder.memo': '알레르기, 유모차, 기념일 등',
      'no.slots': '이 날은 공석이 없습니다. 다른 날을 시도해 주세요',
      'no.tables': '이 인원수로는 만석입니다',
      'completion.title': '예약이 완료되었습니다!',
      'completion.id': '예약 번호',
      'completion.email_sent': '확인 이메일을 발송했습니다',
      'completion.show_at_visit': '방문 시 직원에게 예약 번호를 알려주세요',
      'completion.deposit_redirect': '예약금 결제로 이동합니다…',
      'completion.no_deposit': '방문을 기다리겠습니다',
      'completion.manage': '예약 확인 / 변경',
      'completion.new': '다시 예약',
      'completion.close': '닫기',
      'completion.closed_thanks': '예약 감사합니다. 이 탭을 닫으셔도 됩니다.',
      'tag.confirmed': '확정', 'tag.pending': '결제 대기',
      'remaining_seats': '{n}석 남음',
      'closed': '휴무',
      'past_date': '과거',
      'optional': '(선택)',
      'required': '*',
      'confirm.notice': '내용에 오류가 없는지 확인하세요',
      'waitlist.notice': '이 시간은 만석입니다. 자리가 나면 연락드릴 수 있도록 대기 등록합니다.',
      'waitlist.confirm_title': '대기 등록 내용 확인',
      'waitlist.confirm_notice': '자리가 나면 매장에서 연락드립니다. 아직 예약 확정은 아닙니다.',
      'waitlist.registered_title': '대기 등록이 완료되었습니다',
      'waitlist.registered_body': '자리가 나면 매장에서 연락드립니다.',
      'waitlist.id': '접수 번호',
      'slot.phone_only': '전화 접수',
      'deposit.amount': '예약금',
      'deposit.notice': '예약 확정에는 예약금 결제가 필요합니다',
      'deposit.captured_on_no_show': 'no-show 시 예약금이 결제됩니다',
      'cancel.deadline': '방문 {n}시간 전까지 무료 취소',
      'ai.fab': 'AI 예약', 'ai.title': 'AI 어시스턴트', 'ai.help': '자유롭게 입력하세요', 'ai.placeholder': '예: 내일 저녁 7시 4명', 'ai.submit': '해석',
      'ai.parsed': '해석 결과', 'ai.apply': '예약 폼에 반영',
      'ai.confidence_low': '해석 신뢰도가 낮습니다. 폼을 다시 확인하세요',
      'ai.missing': '누락 항목',
      'error.generic': '오류가 발생했습니다',
      'error.disabled': '이 매장은 온라인 예약을 받지 않습니다',
      'error.slot_unavailable': '선택한 시간은 만석입니다',
      'error.lead_time': '예약 마감 시간이 지났습니다',
      'error.phone_only': '이 인원은 매장으로 전화해 주세요',
    },
  };

  function t(key, vars) {
    var lang = _state.language || 'ja';
    var bag = I18N[lang] || I18N.ja;
    var s = bag[key] || I18N.ja[key] || key;
    if (vars) {
      for (var k in vars) {
        if (vars.hasOwnProperty(k)) s = s.replace('{' + k + '}', vars[k]);
      }
    }
    return s;
  }

  function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function applyI18nStatic() {
    var els = document.querySelectorAll('[data-i18n]');
    for (var i = 0; i < els.length; i++) {
      els[i].textContent = t(els[i].getAttribute('data-i18n'));
    }
    var elsP = document.querySelectorAll('[data-i18n-placeholder]');
    for (var j = 0; j < elsP.length; j++) {
      elsP[j].setAttribute('placeholder', t(elsP[j].getAttribute('data-i18n-placeholder')));
    }
  }

  function setLang(lang) {
    _state.language = lang;
    var btns = document.querySelectorAll('.rs-lang-btn');
    for (var i = 0; i < btns.length; i++) {
      var on = btns[i].getAttribute('data-lang') === lang;
      btns[i].className = 'rs-lang-btn' + (on ? ' rs-lang-btn--active' : '');
    }
    applyI18nStatic();
    render();
  }

  // ---------- API helpers ----------
  function makeApiError(data, fallback) {
    if (window.Utils && Utils.createApiError) {
      return Utils.createApiError(data, fallback || t('error.generic'));
    }
    return (data && data.error) || data || { message: fallback || t('error.generic') };
  }

  function formatDisplayError(err, fallback) {
    if (window.Utils && Utils.formatError) {
      if (err && (err.errorNo || err.code || err.serverTime) && fallback && fallback !== err.message) {
        return Utils.formatError({
          ok: false,
          serverTime: err.serverTime,
          error: { code: err.code, errorNo: err.errorNo, message: fallback }
        });
      }
      return Utils.formatError(err || { message: fallback || t('error.generic') });
    }
    return (err && err.message) || fallback || t('error.generic');
  }

  function apiGet(path, callback) {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', API + path);
    xhr.onload = function () {
      try {
        var data = JSON.parse(xhr.responseText);
        if (data.ok) callback(null, data.data);
        else callback(makeApiError(data));
      } catch (e) {
        callback({ message: 'invalid_response' });
      }
    };
    xhr.onerror = function () { callback({ message: 'network_error' }); };
    xhr.send();
  }
  function apiPost(path, body, callback) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', API + path);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.onload = function () {
      try {
        var data = JSON.parse(xhr.responseText);
        if (data.ok) callback(null, data.data);
        else callback(makeApiError(data));
      } catch (e) {
        callback({ message: 'invalid_response' });
      }
    };
    xhr.onerror = function () { callback({ message: 'network_error' }); };
    xhr.send(JSON.stringify(body));
  }

  function showToast(msg, ms) {
    var el = document.getElementById('rs-toast');
    if (!el) return;
    el.textContent = msg;
    el.hidden = false;
    setTimeout(function () { el.hidden = true; }, ms || 2400);
  }

  // ---------- Initialize ----------
  function init() {
    var qs = new URLSearchParams(location.search);
    _storeId = qs.get('store_id');
    _state.waitlistId = qs.get('waitlist_id');
    if (!_storeId) {
      document.getElementById('reserve-app').innerHTML = '<div class="rs-error">店舗IDが指定されていません (store_id)</div>';
      return;
    }
    var langParam = qs.get('lang');
    if (langParam && I18N[langParam]) _state.language = langParam;

    // 言語切替
    var lbtns = document.querySelectorAll('.rs-lang-btn');
    for (var i = 0; i < lbtns.length; i++) {
      lbtns[i].addEventListener('click', function (e) { setLang(e.target.getAttribute('data-lang')); });
    }

    apiGet('/reservation-availability.php?action=info&store_id=' + encodeURIComponent(_storeId), function (err, info) {
      if (err) {
        var msg = (err && err.code === 'RESERVATION_DISABLED') ? formatDisplayError(err, t('error.disabled')) : formatDisplayError(err, t('error.generic'));
        document.getElementById('reserve-app').innerHTML = '<div class="rs-error">' + escapeHtml(msg) + '</div>';
        return;
      }
      _info = info;
      document.getElementById('rs-store-name').textContent = info.store.name + ' — ' + t('step.date');
      // AI FAB
      if (info.ai_chat_enabled) {
        document.getElementById('rs-ai-fab').hidden = false;
        document.getElementById('rs-ai-open').addEventListener('click', openAiModal);
        var modal = document.getElementById('rs-ai-modal');
        var closes = modal.querySelectorAll('[data-modal-close]');
        for (var j = 0; j < closes.length; j++) closes[j].addEventListener('click', closeAiModal);
      }
      applyI18nStatic();
      render();
    });
  }

  // ---------- Render entry ----------
  function render() {
    updateStepper();
    var app = document.getElementById('reserve-app');
    var step = _state.step;
    if (step === 1) renderDate(app);
    else if (step === 2) renderParty(app);
    else if (step === 3) renderTime(app);
    else if (step === 4) renderInfo(app);
    else if (step === 5) renderConfirm(app);
    else if (step === 6) renderCompletion(app);
  }

  function updateStepper() {
    var steps = document.querySelectorAll('.rs-step');
    for (var i = 0; i < steps.length; i++) {
      var n = parseInt(steps[i].getAttribute('data-step'), 10);
      var cls = 'rs-step';
      if (n === _state.step) cls += ' rs-step--active';
      else if (n < _state.step) cls += ' rs-step--done';
      steps[i].className = cls;
    }
  }

  // ---------- Step 1: Date ----------
  function renderDate(app) {
    var html = '<section class="rs-section">';
    html += '<h2 class="rs-section__title">' + escapeHtml(t('sec.date.title')) + '</h2>';
    html += '<p class="rs-section__sub">' + escapeHtml(t('sec.date.sub')) + '</p>';
    html += renderCalendar();
    html += '</section>';
    app.innerHTML = html;
    bindCalendar();
  }

  function renderCalendar() {
    var today = new Date();
    today.setHours(0, 0, 0, 0);
    var base = new Date(today.getFullYear(), today.getMonth() + _state.monthOffset, 1);
    var year = base.getFullYear();
    var month = base.getMonth();
    var firstDayWeek = new Date(year, month, 1).getDay();
    var lastDate = new Date(year, month + 1, 0).getDate();
    var maxAdvanceDays = (_info && _info.max_advance_days) || 60;
    var maxDate = new Date(today);
    maxDate.setDate(today.getDate() + maxAdvanceDays);

    var monthLabels = ['1月','2月','3月','4月','5月','6月','7月','8月','9月','10月','11月','12月'];
    if (_state.language === 'en') monthLabels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var weekHeads = (_state.language === 'en') ? ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] :
                    (_state.language === 'zh-Hans') ? ['日','一','二','三','四','五','六'] :
                    (_state.language === 'ko') ? ['일','월','화','수','목','금','토'] :
                    ['日','月','火','水','木','金','土'];

    var html = '<div class="rs-cal-month">';
    html += '<button class="rs-cal-nav" id="rs-cal-prev" ' + (_state.monthOffset <= 0 ? 'disabled' : '') + '>‹</button>';
    html += '<span>' + year + ' ' + monthLabels[month] + '</span>';
    html += '<button class="rs-cal-nav" id="rs-cal-next">›</button>';
    html += '</div><div class="rs-calendar">';
    for (var w = 0; w < 7; w++) html += '<div class="rs-cal-head">' + weekHeads[w] + '</div>';
    for (var pad = 0; pad < firstDayWeek; pad++) html += '<div></div>';

    // ヒート取得 (キャッシュキー = 月)
    var monthKey = year + '-' + _pad2RA(month + 1);
    var heatMap = _heatmapCache[monthKey] || {};

    for (var d = 1; d <= lastDate; d++) {
      var dt = new Date(year, month, d);
      var iso = year + '-' + _pad2RA(month + 1) + '-' + _pad2RA(d);
      var cls = 'rs-cal-day';
      var disabled = false;
      if (dt < today) { cls += ' rs-cal-day--past'; disabled = true; }
      else if (dt > maxDate) { cls += ' rs-cal-day--past'; disabled = true; }
      else {
        var heat = heatMap[iso];
        if (heat) {
          if (heat.level === 'closed') { cls += ' rs-cal-day--closed'; disabled = true; }
          else if (heat.level === 'low') cls += ' rs-cal-day--low';
          else if (heat.level === 'mid') cls += ' rs-cal-day--mid';
          else if (heat.level === 'high') cls += ' rs-cal-day--high';
        }
      }
      var selected = (_state.date === iso);
      if (selected) cls += ' rs-cal-day--selected';
      var dayAttrs = 'type="button" class="' + cls + '" data-date="' + iso + '" data-disabled="' + (disabled ? '1' : '0') + '"';
      if (disabled) dayAttrs += ' disabled aria-disabled="true"';
      if (selected) dayAttrs += ' aria-pressed="true"';
      html += '<button ' + dayAttrs + '>';
      html += '<span class="rs-cal-day__num">' + d + '</span>';
      if (heatMap[iso] && heatMap[iso].level === 'closed') {
        html += '<span class="rs-cal-day__label">' + t('closed') + '</span>';
      }
      html += '</button>';
    }
    html += '</div>';
    return html;
  }

  function bindCalendar() {
    var prev = document.getElementById('rs-cal-prev');
    var next = document.getElementById('rs-cal-next');
    if (prev) prev.addEventListener('click', function () { _state.monthOffset--; render(); fetchHeatmap(); });
    if (next) next.addEventListener('click', function () { _state.monthOffset++; render(); fetchHeatmap(); });
    var days = document.querySelectorAll('.rs-cal-day[data-date]');
    for (var i = 0; i < days.length; i++) {
      days[i].addEventListener('click', function (e) {
        var el = e.currentTarget;
        if (el.getAttribute('data-disabled') === '1') return;
        _state.date = el.getAttribute('data-date');
        _state.time = null;
        _state.waitlistMode = false;
        _state.step = 2;
        render();
      });
    }
    fetchHeatmap();
  }

  function fetchHeatmap() {
    var today = new Date();
    var base = new Date(today.getFullYear(), today.getMonth() + _state.monthOffset, 1);
    var year = base.getFullYear();
    var month = base.getMonth();
    var monthKey = year + '-' + _pad2RA(month + 1);
    if (_heatmapCache[monthKey]) return;
    var fromDt = monthKey + '-01';
    var endDate = new Date(year, month + 1, 0).getDate();
    var ps = _state.partySize || 2;
    apiGet('/reservation-availability.php?action=heatmap&store_id=' + encodeURIComponent(_storeId) + '&from=' + fromDt + '&days=' + endDate + '&party_size=' + ps, function (err, data) {
      if (err) return;
      var map = {};
      for (var i = 0; i < data.heatmap.length; i++) map[data.heatmap[i].date] = data.heatmap[i];
      _heatmapCache[monthKey] = map;
      // 当該月を表示中なら再描画
      var current = (new Date()).getMonth() + _state.monthOffset;
      if (current === month) render();
    });
  }

  // ---------- Step 2: Party ----------
  function renderParty(app) {
    var maxP = (_info && _info.max_party_size) || 10;
    var minP = (_info && _info.min_party_size) || 1;
    var html = '<section class="rs-section">';
    html += '<h2 class="rs-section__title">' + escapeHtml(t('sec.party.title')) + '</h2>';
    html += '<p class="rs-section__sub">' + escapeHtml(t('sec.party.sub')) + '</p>';
    html += '<div class="rs-party-grid">';
    for (var n = minP; n <= maxP; n++) {
      var sel = (_state.partySize === n) ? ' rs-party-btn--selected' : '';
      html += '<button class="rs-party-btn' + sel + '" data-party="' + n + '">' + n + '</button>';
    }
    html += '</div>';
    html += '<div class="rs-nav">';
    html += '<button class="rs-btn rs-btn--secondary" id="rs-back">' + t('btn.back') + '</button>';
    html += '<button class="rs-btn rs-btn--primary" id="rs-next" ' + (_state.partySize ? '' : 'disabled') + '>' + t('btn.next') + '</button>';
    html += '</div></section>';
    app.innerHTML = html;
    var btns = document.querySelectorAll('.rs-party-btn');
    for (var i = 0; i < btns.length; i++) {
      btns[i].addEventListener('click', function (e) {
        _state.partySize = parseInt(e.currentTarget.getAttribute('data-party'), 10);
        _state.time = null;
        _state.waitlistMode = false;
        // ヒートマップ再取得 (人数依存)
        _heatmapCache = {};
        render();
      });
    }
    document.getElementById('rs-back').addEventListener('click', function () { _state.step = 1; render(); });
    document.getElementById('rs-next').addEventListener('click', function () {
      if (!_state.partySize) return;
      _state.step = 3;
      render();
    });
  }

  // ---------- Step 3: Time ----------
  function renderTime(app) {
    var html = '<section class="rs-section">';
    html += '<h2 class="rs-section__title">' + escapeHtml(t('sec.time.title')) + '</h2>';
    html += '<p class="rs-section__sub">' + escapeHtml(_state.date + ' / ' + _state.partySize + (_state.language === 'en' ? ' guests' : '名')) + '</p>';
    if (_state.waitlistMode && _state.time) {
      html += '<div class="rs-waitlist-banner">' + escapeHtml(t('waitlist.notice')) + '</div>';
    }
    html += '<div id="rs-slot-wrap"><div class="rs-loading"><div class="rs-spinner"></div></div></div>';
    html += '<div class="rs-nav">';
    html += '<button class="rs-btn rs-btn--secondary" id="rs-back">' + t('btn.back') + '</button>';
    html += '<button class="rs-btn rs-btn--primary" id="rs-next" ' + (_state.time ? '' : 'disabled') + '>' + t('btn.next') + '</button>';
    html += '</div></section>';
    app.innerHTML = html;
    document.getElementById('rs-back').addEventListener('click', function () { _state.step = 2; render(); });
    document.getElementById('rs-next').addEventListener('click', function () { if (!_state.time) return; _state.step = 4; render(); });

    var cacheKey = _state.date + '_' + _state.partySize;
    if (_slotsCache[cacheKey]) {
      drawSlots(_slotsCache[cacheKey]);
      return;
    }
    var waitQ = _state.waitlistId ? '&waitlist_id=' + encodeURIComponent(_state.waitlistId) : '';
    apiGet('/reservation-availability.php?store_id=' + encodeURIComponent(_storeId) + '&date=' + _state.date + '&party_size=' + _state.partySize + waitQ, function (err, data) {
      if (err) {
        document.getElementById('rs-slot-wrap').innerHTML = '<div class="rs-error">' + escapeHtml(formatDisplayError(err, t('error.generic'))) + '</div>';
        return;
      }
      _slotsCache[cacheKey] = data.slots || [];
      drawSlots(_slotsCache[cacheKey]);
    });
  }

  function drawSlots(slots) {
    var wrap = document.getElementById('rs-slot-wrap');
    if (!slots || !slots.length) {
      wrap.innerHTML = '<div class="rs-empty">' + escapeHtml(t('no.slots')) + '</div>';
      return;
    }
    var html = '<div class="rs-slots">';
    for (var i = 0; i < slots.length; i++) {
      var s = slots[i];
      var cls = 'rs-slot';
      var waitlistable = (!s.available && s.reason !== 'lead_time' && s.reason !== 'phone_only');
      if (!s.available) cls += ' rs-slot--unavailable rs-slot--full';
      if (waitlistable) cls += ' rs-slot--waitlist';
      var slotSelected = (_state.time === s.time);
      if (slotSelected) cls += ' rs-slot--selected';
      var slotAttrs = 'type="button" class="' + cls + '" data-time="' + s.time + '" data-disabled="' + ((s.available || waitlistable) ? '0' : '1') + '" data-waitlist="' + (waitlistable ? '1' : '0') + '"';
      if (!s.available && !waitlistable) slotAttrs += ' disabled aria-disabled="true"';
      if (slotSelected) slotAttrs += ' aria-pressed="true"';
      html += '<button ' + slotAttrs + '>';
      html += '<span class="rs-slot__time">' + s.time + '</span>';
      if (s.available && s.remaining_capacity) {
        html += '<span class="rs-slot__bag">' + t('remaining_seats', { n: s.remaining_capacity }) + '</span>';
      } else if (waitlistable) {
        html += '<span class="rs-slot__bag">' + t('btn.join_waitlist') + '</span>';
      } else if (s.reason === 'phone_only') {
        html += '<span class="rs-slot__bag">' + t('slot.phone_only') + '</span>';
      } else if (!s.available) {
        html += '<span class="rs-slot__bag">×</span>';
      }
      html += '</button>';
    }
    html += '</div>';
    wrap.innerHTML = html;

    var slotEls = wrap.querySelectorAll('.rs-slot');
    for (var j = 0; j < slotEls.length; j++) {
      slotEls[j].addEventListener('click', function (e) {
        var el = e.currentTarget;
        if (el.getAttribute('data-disabled') === '1') return;
        _state.time = el.getAttribute('data-time');
        _state.waitlistMode = (el.getAttribute('data-waitlist') === '1');
        render();
      });
    }
  }

  // ---------- Step 4: Info ----------
  function renderInfo(app) {
    var html = '<section class="rs-section">';
    html += '<h2 class="rs-section__title">' + escapeHtml(t('sec.info.title')) + '</h2>';
    html += '<div class="rs-form-group"><label>' + t('label.name') + '<span class="rs-required">' + t('required') + '</span></label>';
    html += '<input type="text" id="rs-name" placeholder="' + escapeHtml(t('placeholder.name')) + '" value="' + escapeHtml(_state.name) + '" maxlength="120"></div>';
    var phoneReq = (_info && _info.require_phone) ? t('required') : t('optional');
    html += '<div class="rs-form-group"><label>' + t('label.phone') + ' <span class="rs-required">' + phoneReq + '</span></label>';
    html += '<input type="tel" id="rs-phone" placeholder="' + escapeHtml(t('placeholder.phone')) + '" value="' + escapeHtml(_state.phone) + '" maxlength="40"></div>';
    var emailReq = (_info && _info.require_email) ? t('required') : t('optional');
    html += '<div class="rs-form-group"><label>' + t('label.email') + ' <span class="rs-required">' + emailReq + '</span></label>';
    html += '<input type="email" id="rs-email" placeholder="' + escapeHtml(t('placeholder.email')) + '" value="' + escapeHtml(_state.email) + '" maxlength="190"></div>';
    html += '<div class="rs-form-group"><label>' + t('label.memo') + ' ' + t('optional') + '</label>';
    html += '<textarea id="rs-memo" rows="3" placeholder="' + escapeHtml(t('placeholder.memo')) + '" maxlength="500">' + escapeHtml(_state.memo) + '</textarea></div>';
    html += '<div class="rs-nav">';
    html += '<button class="rs-btn rs-btn--secondary" id="rs-back">' + t('btn.back') + '</button>';
    html += '<button class="rs-btn rs-btn--primary" id="rs-next">' + t('btn.next') + '</button>';
    html += '</div></section>';
    app.innerHTML = html;

    document.getElementById('rs-back').addEventListener('click', function () { _state.step = 3; render(); });
    document.getElementById('rs-next').addEventListener('click', function () {
      var name = document.getElementById('rs-name').value.trim();
      var phone = document.getElementById('rs-phone').value.trim();
      var email = document.getElementById('rs-email').value.trim();
      var memo = document.getElementById('rs-memo').value.trim();
      if (!name) { showToast(t('label.name')); return; }
      if (_info.require_phone && !phone) { showToast(t('label.phone')); return; }
      if (_info.require_email && !email) { showToast(t('label.email')); return; }
      if (email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) { showToast(t('label.email')); return; }
      _state.name = name; _state.phone = phone; _state.email = email; _state.memo = memo;
      _state.step = 5;
      render();
    });
  }

  // ---------- Step 5: Confirm ----------
  function renderConfirm(app) {
    // 必須項目ガード: 未入力があれば情報入力ステップに戻す
    if (!_state.name || (_info.require_phone && !_state.phone) || (_info.require_email && !_state.email)) {
      _state.step = 4;
      showToast(t('confirm.notice'), 1800);
      render();
      return;
    }
    var depositRequired = !_state.waitlistMode && _info && _info.deposit_available && _info.deposit_enabled && _state.partySize >= _info.deposit_min_party_size;
    var depositAmount = depositRequired ? (_info.deposit_per_person * _state.partySize) : 0;

    var html = '<section class="rs-section">';
    html += '<h2 class="rs-section__title">' + escapeHtml(t(_state.waitlistMode ? 'waitlist.confirm_title' : 'sec.confirm.title')) + '</h2>';
    html += '<p class="rs-section__sub">' + escapeHtml(t(_state.waitlistMode ? 'waitlist.confirm_notice' : 'confirm.notice')) + '</p>';
    html += '<div class="rs-confirm-row"><span>' + t('step.date') + '</span><span>' + escapeHtml(_state.date) + '</span></div>';
    html += '<div class="rs-confirm-row"><span>' + t('step.time') + '</span><span>' + escapeHtml(_state.time) + '</span></div>';
    html += '<div class="rs-confirm-row"><span>' + t('step.party') + '</span><span>' + _state.partySize + '</span></div>';
    html += '<div class="rs-confirm-row"><span>' + t('label.name') + '</span><span>' + escapeHtml(_state.name) + '</span></div>';
    if (_state.phone) html += '<div class="rs-confirm-row"><span>' + t('label.phone') + '</span><span>' + escapeHtml(_state.phone) + '</span></div>';
    if (_state.email) html += '<div class="rs-confirm-row"><span>' + t('label.email') + '</span><span>' + escapeHtml(_state.email) + '</span></div>';
    if (_state.memo) html += '<div class="rs-confirm-row"><span>' + t('label.memo') + '</span><span>' + escapeHtml(_state.memo) + '</span></div>';
    if (_state.waitlistMode) {
      html += '<div class="rs-waitlist-banner">' + escapeHtml(t('waitlist.notice')) + '</div>';
    }

    if (depositRequired) {
      html += '<div class="rs-deposit-banner"><strong>' + t('deposit.amount') + ': ¥' + depositAmount.toLocaleString() + '</strong><br>' + t('deposit.notice') + '</div>';
    }
    if (_info.cancel_deadline_hours) {
      html += '<p class="rs-section__sub rs-section__sub--cancel-note">' + t('cancel.deadline', { n: _info.cancel_deadline_hours }) + '</p>';
    }

    html += '<div class="rs-nav">';
    html += '<button class="rs-btn rs-btn--secondary" id="rs-back">' + t('btn.back') + '</button>';
    html += '<button class="rs-btn rs-btn--primary" id="rs-submit">' + t(_state.waitlistMode ? 'btn.join_waitlist' : (depositRequired ? 'btn.proceed_payment' : 'btn.confirm')) + '</button>';
    html += '</div></section>';
    app.innerHTML = html;

    document.getElementById('rs-back').addEventListener('click', function () { _state.step = 4; render(); });
    document.getElementById('rs-submit').addEventListener('click', function () {
      var btn = this; btn.disabled = true; btn.textContent = '…';
      apiPost('/reservation-create.php', {
        store_id: _storeId,
        customer_name: _state.name,
        customer_phone: _state.phone,
        customer_email: _state.email,
        party_size: _state.partySize,
        reserved_at: _state.date + 'T' + _state.time + ':00',
        memo: _state.memo,
        language: _state.language,
        source: 'web',
        join_waitlist: _state.waitlistMode ? 1 : 0,
        waitlist_id: _state.waitlistId || ''
      }, function (err, data) {
        if (err) {
          btn.disabled = false; btn.textContent = t(_state.waitlistMode ? 'btn.join_waitlist' : (depositRequired ? 'btn.proceed_payment' : 'btn.confirm'));
          var msg = formatDisplayError(err, t('error.generic'));
          if (err.code === 'SLOT_UNAVAILABLE') msg = formatDisplayError(err, t('error.slot_unavailable'));
          if (err.code === 'LEAD_TIME_VIOLATION') msg = formatDisplayError(err, t('error.lead_time'));
          if (err.code === 'PHONE_ONLY_SLOT') msg = formatDisplayError(err, t('error.phone_only'));
          // 必須項目欠如系: 情報入力ステップに戻して入力させる
          if (err.code === 'PHONE_REQUIRED' || err.code === 'EMAIL_REQUIRED' || err.code === 'INVALID_PHONE' || err.code === 'INVALID_EMAIL' || err.code === 'INVALID_NAME') {
            _state.step = 4;
            render();
          }
          showToast(msg, 4000);
          return;
        }
        if (data.deposit_required && data.deposit_checkout_url) {
          // Stripe へリダイレクト
          window.location.href = data.deposit_checkout_url;
          return;
        }
        _completed = data;
        _state.step = 6;
        render();
      });
    });
  }

  // ---------- Step 6: Completion ----------
  function renderCompletion(app) {
    if (_completed && _completed.waitlist_id) {
      var wh = '<section class="rs-section rs-completion">';
      wh += '<div class="rs-completion__icon rs-completion__icon--waitlist">!</div>';
      wh += '<div class="rs-completion__title">' + escapeHtml(t('waitlist.registered_title')) + '</div>';
      wh += '<div class="rs-section__sub">' + escapeHtml(t('waitlist.registered_body')) + '</div>';
      wh += '<div class="rs-completion__id">' + t('waitlist.id') + ': ' + escapeHtml(_completed.waitlist_id) + '</div>';
      wh += '<div class="rs-completion__actions">';
      wh += '<button class="rs-btn rs-btn--primary" id="rs-complete-new">' + escapeHtml(t('completion.new')) + '</button>';
      wh += '<button class="rs-btn rs-btn--secondary" id="rs-complete-close">' + escapeHtml(t('completion.close')) + '</button>';
      wh += '</div>';
      wh += '</section>';
      app.innerHTML = wh;
      bindCompletionActions();
      return;
    }
    var html = '<section class="rs-section rs-completion">';
    html += '<div class="rs-completion__icon">✓</div>';
    html += '<div class="rs-completion__title">' + escapeHtml(t('completion.title')) + '</div>';
    html += '<div class="rs-section__sub">' + escapeHtml(t('completion.no_deposit')) + '</div>';
    html += '<div class="rs-completion__id">' + t('completion.id') + ': ' + escapeHtml(_completed.reservation_id) + '</div>';
    if (_state.email) {
      html += '<div class="rs-success-banner">' + escapeHtml(t('completion.email_sent')) + '</div>';
    }
    html += '<p class="rs-completion__visit-note">' + escapeHtml(t('completion.show_at_visit')) + '</p>';
    html += '<div class="rs-completion__actions">';
    html += '<a class="rs-btn rs-btn--primary rs-btn--link" href="' + escapeHtml(_completed.edit_url) + '">' + t('completion.manage') + '</a>';
    html += '<button class="rs-btn rs-btn--secondary" id="rs-complete-new">' + escapeHtml(t('completion.new')) + '</button>';
    html += '<button class="rs-btn rs-btn--secondary" id="rs-complete-close">' + escapeHtml(t('completion.close')) + '</button>';
    html += '</div>';
    html += '</section>';
    app.innerHTML = html;

    bindCompletionActions();
  }

  function bindCompletionActions() {
    document.getElementById('rs-complete-new').addEventListener('click', function () {
      // 状態をリセットして最初の画面へ
      _completed = null;
      _state.step = 1;
      _state.date = null;
      _state.partySize = null;
      _state.time = null;
      _state.memo = '';
      _state.courseId = null;
      _state.waitlistMode = false;
      _state.waitlistId = null;
      _heatmapCache = {};
      _slotsCache = {};
      render();
    });
    document.getElementById('rs-complete-close').addEventListener('click', function () {
      // ブラウザタブを閉じようとする (拒否されたらホームへリダイレクト)
      window.close();
      setTimeout(function () {
        // window.close() が失敗した場合のフォールバック
        document.getElementById('reserve-app').innerHTML = '<div class="rs-completion__goodbye">' + escapeHtml(t('completion.closed_thanks')) + '</div>';
      }, 200);
    });
  }

  // ---------- AI ----------
  function _aiModalKeydown(e) {
    if (e.key === 'Escape' || e.keyCode === 27) {
      closeAiModal();
    }
  }
  function openAiModal() {
    renderAiModalForm();
    document.getElementById('rs-ai-modal').hidden = false;
    document.addEventListener('keydown', _aiModalKeydown);
    var inp = document.getElementById('rs-ai-input');
    if (inp) inp.focus();
  }
  function closeAiModal() {
    document.getElementById('rs-ai-modal').hidden = true;
    document.removeEventListener('keydown', _aiModalKeydown);
  }

  function renderAiModalForm() {
    var body = document.getElementById('rs-ai-modal-body');
    if (!body) return;
    var phoneReq = (_info && _info.require_phone) ? t('required') : t('optional');
    var emailReq = (_info && _info.require_email) ? t('required') : t('optional');
    var html = '';
    html += '<h2>' + escapeHtml(t('ai.title')) + '</h2>';
    html += '<p class="rs-ai-help">' + escapeHtml(t('ai.help')) + '</p>';
    html += '<textarea id="rs-ai-input" class="rs-ai-input" rows="3" placeholder="' + escapeHtml(t('ai.placeholder')) + '"></textarea>';
    html += '<div class="rs-form-group rs-form-group--top-gap"><label>' + t('label.name') + '<span class="rs-required">' + t('required') + '</span></label>';
    html += '<input type="text" id="rs-ai-name" placeholder="' + escapeHtml(t('placeholder.name')) + '" maxlength="120" value="' + escapeHtml(_state.name || '') + '"></div>';
    html += '<div class="rs-form-group"><label>' + t('label.phone') + ' <span class="rs-required">' + phoneReq + '</span></label>';
    html += '<input type="tel" id="rs-ai-phone" placeholder="' + escapeHtml(t('placeholder.phone')) + '" maxlength="40" value="' + escapeHtml(_state.phone || '') + '"></div>';
    if (_info && _info.require_email) {
      html += '<div class="rs-form-group"><label>' + t('label.email') + ' <span class="rs-required">' + emailReq + '</span></label>';
      html += '<input type="email" id="rs-ai-email" placeholder="' + escapeHtml(t('placeholder.email')) + '" maxlength="190" value="' + escapeHtml(_state.email || '') + '"></div>';
    }
    html += '<button type="button" class="rs-btn rs-btn--primary rs-btn--full" id="rs-ai-submit">' + t('btn.confirm') + '</button>';
    html += '<div class="rs-ai-result" id="rs-ai-result" hidden></div>';
    body.innerHTML = html;
    document.getElementById('rs-ai-submit').addEventListener('click', submitAi);
  }

  function submitAi() {
    var text = (document.getElementById('rs-ai-input').value || '').trim();
    var name = (document.getElementById('rs-ai-name').value || '').trim();
    var phone = (document.getElementById('rs-ai-phone').value || '').trim();
    var emailEl = document.getElementById('rs-ai-email');
    var email = emailEl ? (emailEl.value || '').trim() : '';
    if (!text) { showToast(t('ai.help'), 2500); return; }
    if (!name) { showToast(t('label.name'), 2500); return; }
    if (_info.require_phone && !phone) { showToast(t('label.phone'), 2500); return; }
    if (_info.require_email && !email) { showToast(t('label.email'), 2500); return; }
    if (email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) { showToast(t('label.email'), 2500); return; }

    var btn = document.getElementById('rs-ai-submit');
    btn.disabled = true; btn.textContent = '…';
    var resultEl = document.getElementById('rs-ai-result');
    resultEl.hidden = true;
    apiPost('/reservation-ai-parse.php', { store_id: _storeId, text: text }, function (err, data) {
      if (err) { btn.disabled = false; btn.textContent = t('btn.confirm'); showToast(formatDisplayError(err, t('error.generic')), 3000); return; }
      // AI 解析結果が必須情報を満たしているかチェック
      if (!data.reserved_at || !data.party_size) {
        btn.disabled = false; btn.textContent = t('btn.confirm');
        var fieldLabel = {
          reserved_at: t('step.date') + '/' + t('step.time'),
          party_size: t('step.party'),
          customer_name: t('label.name'),
          customer_phone: t('label.phone'),
          customer_email: t('label.email'),
          memo: t('label.memo'),
          party_size_range: t('step.party')
        };
        var missing = [];
        if (data.missing_fields) {
          for (var mi = 0; mi < data.missing_fields.length; mi++) {
            missing.push(fieldLabel[data.missing_fields[mi]] || data.missing_fields[mi]);
          }
        }
        var errHtml = '<div class="rs-ai-confidence-low">⚠ ' + t('ai.confidence_low') + '</div>';
        if (missing.length) errHtml += '<div class="rs-ai-confidence-low rs-ai-result__missing">' + t('ai.missing') + ': ' + missing.join(', ') + '</div>';
        errHtml += '<button type="button" class="rs-btn rs-btn--secondary rs-btn--full" id="rs-ai-fallback">' + t('ai.apply') + '</button>';
        resultEl.innerHTML = errHtml;
        resultEl.hidden = false;
        document.getElementById('rs-ai-fallback').addEventListener('click', function () {
          applyAiToState(data);
          _state.name = name; _state.phone = phone; _state.email = email;
          _state.step = (_state.date && _state.partySize && _state.time) ? 4 : 1;
          closeAiModal(); render();
        });
        return;
      }

      // 時刻が営業時間外 (= AI が時刻不明で勝手な値を入れたケースを検知)
      var resDt = new Date(data.reserved_at);
      var resHour = resDt.getHours();
      var openHour = parseInt((_info.open_time || '11:00').split(':')[0], 10);
      var closeHour = parseInt((_info.close_time || '23:00').split(':')[0], 10);
      if (closeHour === 0) closeHour = 24;
      if (resHour < openHour || resHour >= closeHour) {
        btn.disabled = false; btn.textContent = t('btn.confirm');
        var noTimeHtml = '<div class="rs-ai-confidence-low">⚠ 時刻が指定されていません。「19時」など時刻を含めて入力してください</div>';
        noTimeHtml += '<button type="button" class="rs-btn rs-btn--secondary rs-btn--full" id="rs-ai-fallback">' + t('ai.apply') + '</button>';
        resultEl.innerHTML = noTimeHtml;
        resultEl.hidden = false;
        document.getElementById('rs-ai-fallback').addEventListener('click', function () {
          applyAiToState(data);
          _state.name = name; _state.phone = phone; _state.email = email;
          _state.step = 3; // 時刻ステップへ
          closeAiModal(); render();
        });
        return;
      }

      // 解析成功 → 即予約 API 呼び出し
      applyAiToState(data);
      _state.name = name; _state.phone = phone; _state.email = email;
      apiPost('/reservation-create.php', {
        store_id: _storeId,
        customer_name: name,
        customer_phone: phone,
        customer_email: email,
        party_size: _state.partySize,
        reserved_at: _state.date + 'T' + _state.time + ':00',
        memo: _state.memo,
        language: _state.language,
        source: 'ai_chat',
      }, function (err2, res) {
        btn.disabled = false; btn.textContent = t('btn.confirm');
        if (err2) {
          var msg2 = err2.message || t('error.generic');
          if (err2.code === 'SLOT_UNAVAILABLE') msg2 = t('error.slot_unavailable');
          if (err2.code === 'LEAD_TIME_VIOLATION') msg2 = t('error.lead_time');
          if (err2.code === 'PHONE_ONLY_SLOT') msg2 = t('error.phone_only');
          // 失敗時は手動フォームでリカバリー
          var recoverHtml = '<div class="rs-ai-confidence-low">' + escapeHtml(msg2) + '</div>';
          // SLOT_UNAVAILABLE の場合、別時間帯候補を提案
          if (err2.code === 'SLOT_UNAVAILABLE' && _state.date && _state.partySize) {
            recoverHtml += '<div class="rs-ai-result__label rs-ai-result__label--alt-top">' + escapeHtml(_state.date) + ' の他の空き時間:</div>';
            recoverHtml += '<div id="rs-ai-alt-slots" class="rs-ai-alt-slots">読み込み中…</div>';
          }
          recoverHtml += '<button type="button" class="rs-btn rs-btn--secondary rs-btn--full" id="rs-ai-fallback">' + t('ai.apply') + '</button>';
          resultEl.innerHTML = recoverHtml;
          resultEl.hidden = false;
          document.getElementById('rs-ai-fallback').addEventListener('click', function () {
            _state.step = 4;
            closeAiModal(); render();
          });
          // 別時間帯フェッチ
          var altWrap = document.getElementById('rs-ai-alt-slots');
          if (altWrap) {
            apiGet('/reservation-availability.php?store_id=' + encodeURIComponent(_storeId) + '&date=' + encodeURIComponent(_state.date) + '&party_size=' + _state.partySize, function (e3, d3) {
              if (e3 || !d3 || !d3.slots) { altWrap.textContent = '取得失敗'; return; }
              var avail = [];
              for (var si = 0; si < d3.slots.length; si++) if (d3.slots[si].available) avail.push(d3.slots[si].time);
              if (!avail.length) { altWrap.textContent = '空きなし'; return; }
              var ah = '';
              for (var ai = 0; ai < avail.length; ai++) ah += '<button type="button" class="rs-slot rs-slot--compact" data-alt-time="' + avail[ai] + '">' + avail[ai] + '</button>';
              altWrap.innerHTML = ah;
              var altBtns = altWrap.querySelectorAll('[data-alt-time]');
              for (var bi = 0; bi < altBtns.length; bi++) {
                altBtns[bi].addEventListener('click', function (ev) {
                  _state.time = ev.currentTarget.getAttribute('data-alt-time');
                  // 同じ submit を再実行
                  document.getElementById('rs-ai-submit').click();
                });
              }
            });
          }
          return;
        }
        if (res.deposit_required && res.deposit_checkout_url) {
          window.location.href = res.deposit_checkout_url;
          return;
        }
        _completed = res;
        _state.step = 6;
        closeAiModal();
        render();
      });
    });
  }

  function applyAiToState(data) {
    if (data.reserved_at) {
      var dt2 = new Date(data.reserved_at);
      _state.date = dt2.getFullYear() + '-' + _pad2RA(dt2.getMonth()+1) + '-' + _pad2RA(dt2.getDate());
      _state.time = _pad2RA(dt2.getHours()) + ':' + _pad2RA(dt2.getMinutes());
    }
    if (data.party_size) _state.partySize = data.party_size;
    if (data.customer_name) _state.name = data.customer_name;
    if (data.memo) _state.memo = data.memo;
  }

  // ---------- Boot ----------
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
