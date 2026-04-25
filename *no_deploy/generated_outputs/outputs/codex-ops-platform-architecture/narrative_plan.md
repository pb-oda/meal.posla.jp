Audience: インフラエンジニア / 運用設計担当
Objective: Codex 運用支援基盤を POSLA 本体の外に置く理由、接続方針、初期MVP、将来拡張、インフラ要件を短時間で共有する。

Narrative arc:
1. なぜ今この基盤が必要か
2. どのような分離構成を目指すか
3. product 内 help とどう役割を分けるか
4. まず何を実装し、何をまだやらないか
5. インフラ担当に何を決めてもらう必要があるか

Slide list:
1. 表紙: Codex 運用支援基盤
2. 背景と設計前提
3. 推奨構成
4. 責務分離と権限モデル
5. POSLA への初期適用範囲
6. 将来拡張
7. インフラ要件と推奨パターン

Source plan:
- primary: docs/codex-ops-platform-architecture.md
- context: 既存運用方針（product内 help は非AI、AI運用支援は別系統）

Visual system:
- 16:9
- 明るい背景 + 緑/金/赤のアクセント
- タイトルは Caladea、本文は Lato、ラベルは Aptos Mono
- 説明資料らしく、図形・カード・接続線を主体に構成

Imagegen plan:
- 今回は不要
- 図解は editable shapes で構成する

Asset needs:
- 追加画像なし
- 図解・カード・ラベルは全て PowerPoint 上の editable object

Editability plan:
- タイトル、本文、カード、図解ラベル、注記は全て editable text
- 接続図は shapes で構成
- speaker notes に説明意図と source を残す
