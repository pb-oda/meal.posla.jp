/**
 * 共通ユーティリティ
 */
var Utils = (function () {
  'use strict';

  function formatYen(amount) {
    return '\u00a5' + (amount || 0).toLocaleString();
  }

  function escapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  }

  function toDateStr(date) {
    var y = date.getFullYear();
    var m = ('0' + (date.getMonth() + 1)).slice(-2);
    var d = ('0' + date.getDate()).slice(-2);
    return y + '-' + m + '-' + d;
  }

  function formatDateTime(dtStr) {
    if (!dtStr) return '-';
    var d = new Date(dtStr);
    var m = ('0' + (d.getMonth() + 1)).slice(-2);
    var day = ('0' + d.getDate()).slice(-2);
    var h = ('0' + d.getHours()).slice(-2);
    var min = ('0' + d.getMinutes()).slice(-2);
    return m + '/' + day + ' ' + h + ':' + min;
  }

  function formatShortDate(dateStr) {
    var parts = dateStr.split('-');
    return parseInt(parts[1], 10) + '/' + parseInt(parts[2], 10);
  }

  function formatDuration(seconds) {
    if (seconds === null || seconds === undefined || seconds < 0) return '-';
    var m = Math.floor(seconds / 60);
    var s = seconds % 60;
    if (m === 0) return s + '秒';
    return m + '分' + (s > 0 ? s + '秒' : '');
  }

  function getPresetRange(preset) {
    var today = new Date();
    today.setHours(0, 0, 0, 0);
    var from, to;
    switch (preset) {
      case 'today':
        from = to = toDateStr(today); break;
      case 'yesterday':
        var yd = new Date(today); yd.setDate(yd.getDate() - 1);
        from = to = toDateStr(yd); break;
      case 'week':
        var w = new Date(today); w.setDate(w.getDate() - w.getDay());
        from = toDateStr(w); to = toDateStr(today); break;
      case 'month':
        var mo = new Date(today.getFullYear(), today.getMonth(), 1);
        from = toDateStr(mo); to = toDateStr(today); break;
      default:
        from = to = toDateStr(today);
    }
    return { from: from, to: to };
  }

  return {
    formatYen: formatYen,
    escapeHtml: escapeHtml,
    toDateStr: toDateStr,
    formatDateTime: formatDateTime,
    formatShortDate: formatShortDate,
    formatDuration: formatDuration,
    getPresetRange: getPresetRange,
  };
})();
