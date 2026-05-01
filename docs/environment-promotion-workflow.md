# POSLA 環境運用メモ

## 基本方針

POSLA の通常作業は `【テスト環境】meal.posla.jp` で行います。
`【本番環境】meal.posla.jp` は Cloud Run 本番デプロイ用の作業コピーで、直接編集しません。

本番への反映はフォルダコピーではなく Git で行います。

```text
テスト環境で実装・確認
  ↓
test ブランチへ commit / push
  ↓
OK になった commit を main へ昇格
  ↓
本番環境フォルダで git pull または release タグ checkout
  ↓
Cloud Run 本番デプロイ
```

## 普段の作業場所

```bash
cd "【テスト環境】meal.posla.jp"
git status
```

`test` ブランチにいることを確認してから作業します。

```bash
git branch --show-current
```

期待値:

```text
test
```

## テスト環境で作業後に push する

```bash
git status
git add -A
git commit -m "変更内容を短く書く"
git push
```

push 後に Docker Compose / スモークテスト / 画面確認を実施します。

## 本番へ昇格する

テスト環境で問題ないことを確認してから、`main` を進めます。

```bash
git push meal.posla.jp test:main
```

本番リリース地点を残す場合はタグを作成します。

```bash
git tag -a release-YYYYMMDD -m "Release YYYY-MM-DD"
git push meal.posla.jp release-YYYYMMDD
```

## 本番環境フォルダで取り込む

本番環境フォルダでは直接編集せず、GitHub から取り込みます。

```bash
cd "【本番環境】meal.posla.jp"
git status
git pull
```

タグで固定する場合:

```bash
git fetch --tags
git checkout release-YYYYMMDD
```

## 禁止事項

- テスト環境フォルダを本番環境フォルダへ手動コピーしない
- 本番環境フォルダで直接実装しない
- 【デモ環境】meal.posla.jp へ自動同期しない
- `rsync --delete` で環境間同期しない

## 本番デプロイ前チェック

- `git status` が clean
- 本番環境フォルダが `main` または releaseタグを指している
- Gemini API / 本番 Stripe API などのSecretがCloud Run側に設定済み
- 予約金・オンライン決済・AI機能の本番Secret込みスモークが通過済み
- ロールバック対象の releaseタグがある
