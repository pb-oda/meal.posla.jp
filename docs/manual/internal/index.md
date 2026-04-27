---
layout: home
title: POSLA 運営マニュアル
hero:
  name: POSLA 運営マニュアル
  text: プラスビリーフ社内向け
  tagline: システム全体像・API・DB・運用・障害対応を確認できる社内用ドキュメント。本番化はデプロイ手順書から着手してください。
  actions:
    - theme: brand
      text: 本番デプロイ手順書を見る →
      link: /internal/12-production-deploy-runbook
    - theme: alt
      text: Cell配備運用を見る →
      link: /internal/11-cell-deployment
    - theme: alt
      text: 新サーバ移行ハンドオフを見る →
      link: /internal/10-server-migration
    - theme: alt
      text: 全体リファレンスから読む →
      link: /internal/00-complete-reference
    - theme: alt
      text: 運営編を見る →
      link: /internal/01-posla-admin
features:
  - title: 本番デプロイ手順書
    details: 本番host準備、env実値化、Docker起動、DB restore、決済provider、on-demand provisioner、cell deploy、smoke、rollbackまでの実行順 runbook
    link: /internal/12-production-deploy-runbook
  - title: 🚀 新サーバ移行ハンドオフ
    details: サーバ移行担当者が最初に読む専用ページ。着手前に必要な情報、web+cron の env、永続化、SQL、smoke test、ロールバックまで 1 枚で確認できます。
    link: /internal/10-server-migration
  - title: Cell配備運用
    details: 1 tenant / 1 cell の配備単位、build artifact、deploy、migration、rollback、外部運用基盤への接続方針を確認
    link: /internal/11-cell-deployment
  - title: POSLA全体リファレンス
    details: アーキテクチャ、API、DB、テナント境界、決済、AI、PWA、監視、スモークテストを横断整理
    link: /internal/00-complete-reference
  - title: 運営編(POSLA社内)
    details: POSLA管理画面・テナントオンボーディング・Stripe Billing/Connect・システム運用・トラブル対応・運用監視
    link: /internal/01-posla-admin
  - title: 顧客向け機能ガイド(全26章)
    details: 顧客へ説明するための操作ガイド。APIやDBを含まない表向きの説明を確認
    link: /tenant/01-introduction
  - title: 現場向け詳細マニュアル(Part 0-8)
    details: スタッフ教育や導入支援で使う、開店から閉店までの細かい操作手順
    link: /operations/00-table-of-contents
  - title: Stripe・スマレジ連携
    details: テナント側・POSLA側の両方の設定手順を完全収録
    link: /internal/03-billing
---
