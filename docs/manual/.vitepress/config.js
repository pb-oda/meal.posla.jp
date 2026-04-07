import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'POSLA 取扱説明書',
  description: 'POSLAの詳細取扱説明書',
  base: '/docs/',
  lang: 'ja-JP',
  themeConfig: {
    nav: [
      { text: 'テナント向け', link: '/tenant/' }
    ],
    sidebar: {
      '/tenant/': [
        {
          text: '1. はじめに',
          collapsed: false,
          items: [
            { text: 'POSLAとは・動作環境・ロール', link: '/tenant/01-introduction' }
          ]
        },
        {
          text: '2. ログインとアカウント',
          collapsed: false,
          items: [
            { text: 'ログイン・パスワード・セッション', link: '/tenant/02-login' }
          ]
        },
        {
          text: '3. 管理ダッシュボード',
          collapsed: false,
          items: [
            { text: '画面構成・タブ操作・店舗切替', link: '/tenant/03-dashboard' }
          ]
        },
        {
          text: '4. メニュー管理',
          collapsed: false,
          items: [
            { text: 'カテゴリ・テンプレート・オプション', link: '/tenant/04-menu' }
          ]
        },
        {
          text: '5. テーブル・フロアマップ',
          collapsed: false,
          items: [
            { text: 'テーブル登録・フロアマップ・セッション', link: '/tenant/05-tables' }
          ]
        },
        {
          text: '6. 注文管理（ハンディPOS）',
          collapsed: false,
          items: [
            { text: '店内注文・テイクアウト・履歴', link: '/tenant/06-orders' }
          ]
        },
        {
          text: '7. KDS（キッチンディスプレイ）',
          collapsed: false,
          items: [
            { text: 'カンバン・ステーション・AI・音声', link: '/tenant/07-kds' }
          ]
        },
        {
          text: '8. 会計・POSレジ',
          collapsed: false,
          items: [
            { text: '決済・割引・個別会計・レジ開閉', link: '/tenant/08-cashier' }
          ]
        },
        {
          text: '9. テイクアウト管理',
          collapsed: false,
          items: [
            { text: '設定・注文管理・QRコード', link: '/tenant/09-takeout' }
          ]
        },
        {
          text: '10. プラン・コース管理',
          collapsed: false,
          items: [
            { text: '食べ放題プラン・コース料理', link: '/tenant/10-plans' }
          ]
        },
        {
          text: '11. 在庫・レシピ管理',
          collapsed: false,
          items: [
            { text: '原材料・レシピ・棚卸し・AI予測', link: '/tenant/11-inventory' }
          ]
        },
        {
          text: '12. シフト管理',
          collapsed: false,
          items: [
            { text: 'カレンダー・勤怠・AI提案・ヘルプ', link: '/tenant/12-shift' }
          ]
        },
        {
          text: '13. レポート',
          collapsed: false,
          items: [
            { text: '売上・レジ・回転率・スタッフ・併売', link: '/tenant/13-reports' }
          ]
        },
        {
          text: '14. AIアシスタント',
          collapsed: false,
          items: [
            { text: 'SNS・売上分析・競合・需要予測', link: '/tenant/14-ai' }
          ]
        },
        {
          text: '15. オーナーダッシュボード',
          collapsed: false,
          items: [
            { text: 'クロス店舗・ABC・ユーザー・決済', link: '/tenant/15-owner' }
          ]
        },
        {
          text: '16. 店舗設定',
          collapsed: false,
          items: [
            { text: '営業・レジ・レシート・外観', link: '/tenant/16-settings' }
          ]
        },
        {
          text: '17. セルフオーダー画面',
          collapsed: false,
          items: [
            { text: '注文・AIウェイター・呼び出し', link: '/tenant/17-customer' }
          ]
        },
        {
          text: '18. セキュリティ',
          collapsed: false,
          items: [
            { text: '認証・監査・ベストプラクティス', link: '/tenant/18-security' }
          ]
        },
        {
          text: '19. トラブルシューティング',
          collapsed: false,
          items: [
            { text: 'よくある問題と対処法', link: '/tenant/19-troubleshoot' }
          ]
        },
        {
          text: '20. Googleレビュー連携',
          collapsed: false,
          items: [
            { text: '満足度評価・レビュー誘導', link: '/tenant/20-google-review' }
          ]
        },
        {
          text: '21. Stripe決済の全設定',
          collapsed: false,
          items: [
            { text: 'Billing・Connect・カードリーダー', link: '/tenant/21-stripe-full' }
          ]
        },
        {
          text: '22. スマレジ連携の全設定',
          collapsed: false,
          items: [
            { text: 'OAuth接続・店舗/商品マッピング', link: '/tenant/22-smaregi-full' }
          ]
        }
      ],
      '/internal/': [
        {
          text: '■ 運営編（POSLA社内）',
          collapsed: false,
          items: [
            { text: '1. POSLA管理画面', link: '/internal/01-posla-admin' },
            { text: '2. テナントオンボーディング', link: '/internal/02-onboarding' },
            { text: '3. 課金・サブスクリプション', link: '/internal/03-billing' },
            { text: '4. 決済設定', link: '/internal/04-payment' },
            { text: '5. システム運用', link: '/internal/05-operations' },
            { text: '6. 運用トラブルシューティング', link: '/internal/06-troubleshoot' }
          ]
        },
        {
          text: '■ 機能詳細（全機能）',
          collapsed: false,
          items: [
            { text: '1. はじめに', link: '/internal/features/01-introduction' },
            { text: '2. ログインとアカウント', link: '/internal/features/02-login' },
            { text: '3. 管理ダッシュボード', link: '/internal/features/03-dashboard' },
            { text: '4. メニュー管理', link: '/internal/features/04-menu' },
            { text: '5. テーブル・フロアマップ', link: '/internal/features/05-tables' },
            { text: '6. 注文管理（ハンディPOS）', link: '/internal/features/06-orders' },
            { text: '7. KDS（キッチンディスプレイ）', link: '/internal/features/07-kds' },
            { text: '8. 会計・POSレジ', link: '/internal/features/08-cashier' },
            { text: '9. テイクアウト管理', link: '/internal/features/09-takeout' },
            { text: '10. プラン・コース管理', link: '/internal/features/10-plans' },
            { text: '11. 在庫・レシピ管理', link: '/internal/features/11-inventory' },
            { text: '12. シフト管理', link: '/internal/features/12-shift' },
            { text: '13. レポート', link: '/internal/features/13-reports' },
            { text: '14. AIアシスタント', link: '/internal/features/14-ai' },
            { text: '15. オーナーダッシュボード', link: '/internal/features/15-owner' },
            { text: '16. 店舗設定', link: '/internal/features/16-settings' },
            { text: '17. セルフオーダー画面', link: '/internal/features/17-customer' },
            { text: '18. セキュリティ', link: '/internal/features/18-security' },
            { text: '19. トラブルシューティング', link: '/internal/features/19-troubleshoot' },
            { text: '20. Googleレビュー連携', link: '/internal/features/20-google-review' },
            { text: '21. Stripe決済の全設定', link: '/internal/features/21-stripe-full' },
            { text: '22. スマレジ連携の全設定', link: '/internal/features/22-smaregi-full' }
          ]
        }
      ]
    },
    search: {
      provider: 'local'
    },
    outline: {
      level: [2, 3],
      label: '目次'
    },
    docFooter: {
      prev: '前のページ',
      next: '次のページ'
    }
  }
})
