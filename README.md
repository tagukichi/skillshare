# Skillshare（スキルシェア予約サイト PoC）

講師が単発相談の枠を出品し、受講者がカレンダーから枠を選んでカード決済すると予約が確定する
WordPress テーマ。仕様は [SPEC.md](SPEC.md) が唯一の正とする。

運営が販売主体となるため **Stripe Connect は使わない**（通常の Stripe Checkout のみ）。
講師への報酬は月末に銀行振込するサイト外の作業とし、管理画面では集計表示のみ行う。

## 動作環境

| 項目 | 内容 |
|---|---|
| WordPress | 6.9 以上 |
| PHP | 8.1 以上（ローカルは Local 同梱の 8.2） |
| DB | MySQL / MariaDB |
| 決済 | Stripe Checkout（テストモード） |

## セットアップ

1. このディレクトリを `wp-content/themes/skillshare/` に配置する。
2. 管理画面 → 外観 → テーマ から「Skillshare」を有効化する。
   有効化時（`after_switch_theme`）に `wp_ssb_*` テーブルを自動作成する。
   スキーマを変更したときは `SSB_DB_VERSION` を上げれば、次に wp-admin を開いた時点で
   `dbDelta()` が再実行される（テーマを切り替え直す必要はない）。
3. **設定 → 一般 → タイムゾーンを「東京」にする。**
   日時はすべてサイト設定のタイムゾーンのローカル時刻で保存するため、
   UTC のままだと予約枠の時刻が 9 時間ずれる。
4. 依存ライブラリを入れる（実装順序 8 以降で必要）。

   ```bash
   composer install
   ```

5. `wp-config.php` に Stripe のキーを定数で定義する。**DB に保存しない。Git に入れない。**

   ```php
   define('SSB_STRIPE_SECRET', 'sk_test_xxx');
   define('SSB_STRIPE_PUBLIC', 'pk_test_xxx');
   define('SSB_STRIPE_WEBHOOK_SECRET', 'whsec_xxx');
   ```

6. パーマリンク設定を「投稿名」にし、一度保存してリライトルールを反映する。

## ディレクトリ構成

```
skillshare/
├── style.css              テーマ定義のみ（スタイルは assets/css/main.css）
├── functions.php          読み込みとフック登録のみ。ロジックは書かない
├── inc/
│   ├── booking/           予約機能のロジック。テーマの見た目と混ぜない
│   └── admin/             管理画面
├── templates/             各画面のテンプレート
└── assets/                css / js
```

`inc/booking/` は将来プラグインとして切り出す前提のため、各モジュールは
**自分のフックを自分のファイル末尾で登録する**。`functions.php` は require と
テーマ自体の設定（textdomain・アセット読み込み）だけを持つ。

## 画面

| URL | 内容 | アクセス |
|---|---|---|
| `/` | トップ | 全員 |
| `/courses` | 講座一覧 | 全員 |
| `/courses/{id}` | 講座詳細＋予約カレンダー | 全員 |
| `/apply` | 講師申請フォーム | 全員 |
| `/mypage` | 講師マイページ | 承認済み講師 |
| `/booking/done` | 予約完了 | 全員 |
| `/terms` | 利用規約 | 全員 |
| `/tokushoho` | 特定商取引法に基づく表記 | 全員 |
| `/cancel-policy` | キャンセルポリシー | 全員 |

## 命名規則

- 関数・フックの接頭辞：`ssb_`
- テーブル：`{$wpdb->prefix}ssb_`
- テキストドメイン：`skillshare`

## Stripe のテスト

```bash
stripe listen --forward-to https://skillshare.local/wp-json/ssb/v1/stripe-webhook
```

テストカード：`4242 4242 4242 4242` / 任意の将来日 / 任意の CVC

決済の確定は **必ず Webhook で行う**。`success_url` の画面は表示のみで、確定処理はしない。

## メール確認

Local に同梱の Mailpit で送信内容を確認できる（Local の管理画面 → Tools → Mailpit）。

## 実装状況

SPEC.md「7. 実装順序」に従って上から進める。

- [x] 1. テーマの雛形作成、`functions.php` の読み込み構成
- [x] 2. DBテーブル作成（`install.php`）
- [x] 3. 講師申請フォーム → 管理画面での承認
- [x] 4. 講師マイページ（プロフィール・講座管理）
- [x] 5. 予約枠の一括登録
- [x] 6. 講座詳細ページのカレンダー表示
- [x] 7. 仮押さえ＋入力フォーム
- [x] 8. Stripe Checkout 連携
- [x] 9. Webhook による予約確定
- [x] 10. メール送信（`.ics` 添付含む）
- [ ] 11. Googleカレンダー ICS 読み取り・除外表示
- [ ] 12. 管理画面の予約一覧・売上集計
- [ ] 13. 規約類ページ

## 仕様の補足（SPEC.md の確認事項として合意済み）

- **仮押さえ期限**：枠クリック直後は 15 分。Stripe Checkout セッション作成時に 30 分へ延長し、
  セッションの `expires_at` も 30 分に揃える（Stripe の最短有効期限が 30 分のため）。
  Webhook では `metadata.hold_token` と枠の `hold_token` の一致を検証してから確定する。
- **講師アカウント**：未ログイン申請時は WP ユーザーを新規作成し、標準のパスワード設定メールを送る。
  未ログインで既存メールアドレスの場合はエラーとし、ログイン後に申請してもらう（なりすまし防止）。
- **タイムゾーン**：DATETIME はすべてサイト設定のタイムゾーン（Asia/Tokyo）のローカル時刻で保存する。
  表示するときは `mysql2date()` を使うこと。`wp_date( $fmt, strtotime( $value ) )` は
  strtotime が UTC として解釈するため 9 時間ずれる。
- **申請フォームの「希望する講座内容」**：`wp_ssb_instructors.course_plan` に保存する。
- **ログイン画面**：`wp-login.php` は使わず `/login` の独自画面で受ける。
- **講座のイメージ画像**：`wp_ssb_courses.image_id` に添付IDで持つ。講師には `upload_files` 権限を
  与えず、`inc/booking/course.php` 側で MIME とサイズを検証してから `media_handle_upload()` に渡す。
- **Stripe ライブラリ**：この開発機に Composer が入っていないため、公式 SDK は導入せず
  `wp_remote_post()` で API を呼び、Webhook の署名検証も自前で実装している
  （SPEC 4.6 が Composer 不可の場合の代替として認めている方式）。
  署名検証は `hash_hmac('sha256', "{timestamp}.{payload}")` を `hash_equals()` で比較し、
  タイムスタンプの許容ずれを 300 秒としている。Composer を入れる場合は
  `inc/booking/stripe.php` の `ssb_stripe_post()` と
  `inc/booking/webhook.php` の `ssb_verify_stripe_signature()` を差し替えれば移行できる。
- **仮押さえの延長**：Checkout セッションは 32 分、仮押さえは 35 分にしている。
  Stripe の最短有効期限が 30 分であることと、決済がぎりぎりで完了しても
  枠が空いていない状態を避けるため、仮押さえをセッションより長く保つ。
