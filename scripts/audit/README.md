# scripts/audit — セキュリティ監査スクリプト

本ディレクトリは `docs/manual/internal/98-security-audit.md` の Critical / High 項目を**自動回帰検証**するための実行可能スクリプト群。

**重要**: 本ディレクトリは `scripts/.htaccess` で deny 予定。ローカル / CI 実行のみを想定し、本番 web 経由での呼び出しは遮断する。

## 実行前提

- POSIX shell (`bash` 3.2+)
- `curl` / `jq` / `php` / `mysql` CLI
- 本番サーバーには書き込まない（全て read-only / 観測系）
- 本番 DB を叩くスクリプトは SELECT のみ

## スクリプト一覧

| スクリプト | 検証対象（98-security-audit.md） | 実行コスト |
|---|---|---|
| `01-auth-boundary.sh` | tenant 越境 / `require_store_access` / device role | 低（curl 20 回程度）|
| `02-money-path.sh` | void / refund idempotency / race / external_payment_id UNIQUE | 中（本番 DB SELECT あり） |
| `03-customer-surface.sh` | QR / PIN / session token rotation / HSTS | 低 |
| `04-rate-limit.sh` | 各 endpoint の rate limit 発動確認 | 高（意図的に burst、本番注意） |
| `05-static-scan.sh` | C-01/C-02/C-03 / php -l / 禁則 grep / hardcoded secret | 低（静的のみ） |

## 使い方

### 基本（全件）
```bash
cd /Users/odahiroki/Desktop/matsunoya-mt/meal.posla.jp\(正\)/scripts/audit
./05-static-scan.sh         # 先にこれ。本番に触れない
./01-auth-boundary.sh       # 認証系（read-only）
./03-customer-surface.sh    # 顧客系（read-only）
./02-money-path.sh          # 金銭（DB SELECT 含む）
./04-rate-limit.sh          # rate-limit（本番に burst 注意）
```

### CI 用
```bash
# CI で非 interactive 実行する場合
export PROD_HOST=eat.posla.jp
export AUDIT_MODE=ci
./05-static-scan.sh  # C-01/C-02/C-03 が検出されたら CI 赤
```

### Critical / High 検出の最小コマンド

**C-01 / C-02 / C-03** を 1 分以内に確認:
```bash
./05-static-scan.sh | grep -E '^(CRITICAL|HIGH)'
```

## 安全宣言

- 全スクリプトは**本番 DB を書き換えない**
- 02-money-path.sh は `--dry-run` がデフォルト。実際の POST を打つには `--execute` 明示必要
- rate-limit.sh は `--execute` を付けないと `curl -o /dev/null` で空打ち（本番に burst しない）
- auth-boundary.sh は認証情報を求めない（失敗 = 期待通りの 401/403）

## 発見項目の報告

検出項目が出た場合は:
1. `scripts/audit/output/<date>/` に結果 JSON を保存
2. `docs/manual/internal/98-security-audit.md` を更新
3. Critical / High は即座に `docs/manual/internal/06-troubleshoot.md` にもリンク

## 更新履歴

- **2026-04-21**: 初版。5 カテゴリ × 5 スクリプト。agent 並列監査に基づく検証経路の自動化。
