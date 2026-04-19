import { defineConfig } from 'vitepress'

// ビルドモード切替: VP_MODE=tenant | internal
// ・tenant    → 顧客向け (tenant/ のみ) → public/docs-tenant/
// ・internal  → 弊社向け (tenant/ + internal/) → public/docs-internal/ (Basic認証で保護)
// 未指定時は tenant（誤ビルドで internal が混入することを防ぐデフォルト）
var MODE = process.env.VP_MODE || 'tenant'

var MODE_CONF = {
  tenant: {
    base: '/public/docs-tenant/',
    outDir: '../../public/docs-tenant',
    title: 'POSLA 取扱説明書',
    srcExclude: [
      'internal/**',
      'MANUAL_STATUS.md',
      'README.md'
    ]
  },
  internal: {
    base: '/public/docs-internal/',
    outDir: '../../public/docs-internal',
    title: 'POSLA 取扱説明書 (POSLA社内向け)',
    srcExclude: [
      'MANUAL_STATUS.md',
      'README.md'
    ]
  }
}

var conf = MODE_CONF[MODE]
if (!conf) throw new Error('Unknown VP_MODE: ' + MODE + ' (expected tenant|internal)')

// ---------- 共通サイドバー定義 ----------

var tenantSidebar = [
  { text: '1. はじめに', collapsed: false, items: [
    { text: 'POSLAとは・動作環境・ロール', link: '/tenant/01-introduction' }
  ]},
  { text: '2. ログインとアカウント', collapsed: false, items: [
    { text: 'ログイン・パスワード・セッション', link: '/tenant/02-login' }
  ]},
  { text: '3. 管理ダッシュボード', collapsed: false, items: [
    { text: '画面構成・タブ操作・店舗切替', link: '/tenant/03-dashboard' }
  ]},
  { text: '4. メニュー管理', collapsed: false, items: [
    { text: 'カテゴリ・テンプレート・オプション', link: '/tenant/04-menu' }
  ]},
  { text: '5. テーブル・フロアマップ', collapsed: false, items: [
    { text: 'テーブル登録・フロアマップ・セッション', link: '/tenant/05-tables' }
  ]},
  { text: '6. 注文管理（ハンディPOS）', collapsed: false, items: [
    { text: '店内注文・テイクアウト・履歴', link: '/tenant/06-orders' }
  ]},
  { text: '7. KDS（キッチンディスプレイ）', collapsed: false, items: [
    { text: 'カンバン・ステーション・AI・音声', link: '/tenant/07-kds' }
  ]},
  { text: '8. 会計・POSレジ', collapsed: false, items: [
    { text: '決済・割引・個別会計・レジ開閉', link: '/tenant/08-cashier' }
  ]},
  { text: '9. テイクアウト管理', collapsed: false, items: [
    { text: '設定・注文管理・QRコード', link: '/tenant/09-takeout' }
  ]},
  { text: '10. プラン・コース管理', collapsed: false, items: [
    { text: '食べ放題プラン・コース料理', link: '/tenant/10-plans' }
  ]},
  { text: '11. 在庫・レシピ管理', collapsed: false, items: [
    { text: '原材料・レシピ・棚卸し・AI予測', link: '/tenant/11-inventory' }
  ]},
  { text: '12. シフト管理', collapsed: false, items: [
    { text: 'カレンダー・勤怠・AI提案・ヘルプ', link: '/tenant/12-shift' }
  ]},
  { text: '13. レポート', collapsed: false, items: [
    { text: '売上・レジ・回転率・スタッフ・併売', link: '/tenant/13-reports' }
  ]},
  { text: '14. AIアシスタント', collapsed: false, items: [
    { text: 'SNS・売上分析・競合・需要予測', link: '/tenant/14-ai' }
  ]},
  { text: '15. オーナーダッシュボード', collapsed: false, items: [
    { text: 'クロス店舗・ABC・ユーザー・決済', link: '/tenant/15-owner' }
  ]},
  { text: '16. 店舗設定', collapsed: false, items: [
    { text: '営業・レジ・レシート・外観', link: '/tenant/16-settings' }
  ]},
  { text: '17. セルフオーダー画面', collapsed: false, items: [
    { text: '注文・AIウェイター・呼び出し', link: '/tenant/17-customer' }
  ]},
  { text: '18. セキュリティ', collapsed: false, items: [
    { text: '認証・監査・ベストプラクティス', link: '/tenant/18-security' }
  ]},
  { text: '19. トラブルシューティング', collapsed: false, items: [
    { text: 'よくある問題と対処法', link: '/tenant/19-troubleshoot' }
  ]},
  { text: '20. Googleレビュー連携', collapsed: false, items: [
    { text: '満足度評価・レビュー誘導', link: '/tenant/20-google-review' }
  ]},
  { text: '21. Stripe決済の全設定', collapsed: false, items: [
    { text: 'Billing・Connect・カードリーダー', link: '/tenant/21-stripe-full' }
  ]},
  { text: '22. スマレジ連携の全設定', collapsed: false, items: [
    { text: 'OAuth接続・店舗/商品マッピング', link: '/tenant/22-smaregi-full' }
  ]},
  { text: '23. KDSデバイス端末ガイド', collapsed: false, items: [
    { text: 'タブレット・マイク・ハンディ', link: '/tenant/23-kds-devices' }
  ]},
  { text: '24. 予約管理 (L-9)', collapsed: false, items: [
    { text: 'ガント台帳・AI予約・予約金', link: '/tenant/24-reservations' }
  ]},
  { text: '25. テーブル開放認証', collapsed: false, items: [
    { text: 'QR開放・ホワイトリスト・自動着席', link: '/tenant/25-table-auth' }
  ]},
  { text: '26. プリンタ連携', collapsed: false, items: [
    { text: 'Star・Epson・ブラウザ印刷', link: '/tenant/26-printer' }
  ]},
  { text: '99. エラーカタログ', collapsed: false, items: [
    { text: 'エラー番号 (Exxxx) で AI に質問', link: '/tenant/99-error-catalog' }
  ]}
]

var operationsSidebar = [
  { text: '📖 超詳細マニュアル (現場向け)', collapsed: false, items: [
    { text: '全体目次', link: '/operations/00-table-of-contents' },
    { text: 'Part 0: はじめに (準備・ログイン)', link: '/operations/part0-getting-started' },
    { text: 'Part 1: 毎日の営業 (開店〜閉店)', link: '/operations/part1-daily-operations' },
    { text: 'Part 2: スタッフ管理', link: '/operations/part2-staff' },
    { text: 'Part 3: メニュー管理', link: '/operations/part3-menu' },
    { text: 'Part 4: 売上とレポート', link: '/operations/part4-reports' },
    { text: 'Part 5: 予約管理', link: '/operations/part5-reservations' },
    { text: 'Part 6: 店舗の設定', link: '/operations/part6-settings' },
    { text: 'Part 7: 契約・お支払い', link: '/operations/part7-billing' },
    { text: 'Part 8: トラブルシューティング', link: '/operations/part8-troubleshooting' }
  ]}
]

var internalSidebar = [
  { text: '■ 運営編 (POSLA社内)', collapsed: false, items: [
    { text: '1. POSLA管理画面', link: '/internal/01-posla-admin' },
    { text: '2. テナントオンボーディング', link: '/internal/02-onboarding' },
    { text: '3. 課金・サブスクリプション', link: '/internal/03-billing' },
    { text: '4. 決済設定', link: '/internal/04-payment' },
    { text: '5. システム運用', link: '/internal/05-operations' },
    { text: '6. 運用トラブルシューティング', link: '/internal/06-troubleshoot' },
    { text: '7. 監視・アラート', link: '/internal/07-monitor' },
    { text: '8. サインアップフロー', link: '/internal/08-signup-flow' },
    { text: '9. スタッフ画面 PWA 化', link: '/internal/09-pwa' }
  ]}
]

// ---------- mode 別 themeConfig ----------

var themeConfig
if (MODE === 'tenant') {
  themeConfig = {
    nav: [
      { text: '機能マニュアル', link: '/tenant/01-introduction' },
      { text: '現場向け超詳細', link: '/operations/00-table-of-contents' }
    ],
    sidebar: {
      '/tenant/': tenantSidebar,
      '/operations/': operationsSidebar
    }
  }
} else {
  // internal: テナント機能詳細 + 運営編 + 現場向け超詳細 全部
  themeConfig = {
    nav: [
      { text: 'テナント機能', link: '/tenant/01-introduction' },
      { text: '現場向け超詳細', link: '/operations/00-table-of-contents' },
      { text: '運営編', link: '/internal/01-posla-admin' }
    ],
    sidebar: {
      '/tenant/': tenantSidebar,
      '/operations/': operationsSidebar,
      '/internal/': internalSidebar
    }
  }
}

// ---------- 共通 ----------

themeConfig.search = { provider: 'local' }
themeConfig.outline = { level: [2, 3], label: '目次' }
themeConfig.docFooter = { prev: '前のページ', next: '次のページ' }

// ---------- tenant mode 専用: 認証ゲート ----------
// 未ログイン時は /public/admin/index.html に強制リダイレクト。
// POSLA の契約テナントのみが閲覧可。認証チェックが完了するまで body を hidden にして
// 未認証ユーザーに一瞬でも中身を見せないようにする。
var AUTH_GATE_SCRIPT = [
  '(function(){',
  'document.documentElement.style.visibility="hidden";',
  'var show=function(){document.documentElement.style.visibility="";};',
  'var login=function(){',
  'var ret=encodeURIComponent(location.pathname+location.search);',
  'location.replace("/public/admin/index.html?return="+ret);',
  '};',
  'fetch("/api/auth/me.php",{credentials:"same-origin"})',
  '.then(function(r){return r.text().then(function(t){return {status:r.status,body:t};});})',
  '.then(function(r){',
  'if(r.status===200){show();return;}',
  'login();',
  '})',
  '.catch(function(){show();});', // ネットワーク障害時はフェイルオープン
  '})();'
].join('')

var head = []
if (MODE === 'tenant') {
  head.push(['script', {}, AUTH_GATE_SCRIPT])
}

export default defineConfig({
  title: conf.title,
  description: 'POSLAの詳細取扱説明書',
  base: conf.base,
  outDir: conf.outDir,
  srcExclude: conf.srcExclude,
  lang: 'ja-JP',
  cleanUrls: false,
  ignoreDeadLinks: true,
  head: head,
  themeConfig: themeConfig
})
