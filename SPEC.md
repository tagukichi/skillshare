# スキルシェア予約サイト PoC 仕様書

このドキュメントは Claude Code に渡す実装仕様書です。
実装前に必ず全体を読み、不明点があれば実装を始める前に質問してください。

---

## 1. プロジェクト概要

講師と受講者をつなぐスキルシェア型の予約・決済サイト。
講師が単発相談の枠を出品し、受講者がカレンダーから枠を選んでカード決済すると予約が確定する。

**このPoCの目的は需要の検証**であり、機能を最小限に絞ることを最優先とする。
迷ったら「作らない」「シンプルにする」を選ぶこと。

### 重要な前提

- **運営が販売主体**となる。受講料は運営が受領し、講師へは月末に業務委託報酬として銀行振込する（サイト外の作業）。
- したがって **Stripe Connect は使わない**。通常の Stripe Checkout のみ。
- 講師ごとの売上分配ロジックはサイトに実装しない（管理画面で集計表示のみ）。

---

## 2. 開発環境

### 2.1 構成

| 項目 | 内容 |
|---|---|
| ローカル環境 | Local（旧 Local by Flywheel） |
| PHP | 8.1 以上 |
| WordPress | 最新安定版 |
| DB | MySQL（Local 同梱） |
| 決済 | Stripe Checkout（テストモード） |

### 2.2 作業ディレクトリ

WordPress のテーマディレクトリ内で作業する。

```
wp-content/themes/skillshare/
```

Git のリポジトリルートはこの `skillshare` ディレクトリとする。
WordPress 本体は Git 管理しない。

### 2.3 ディレクトリ構成

予約機能のロジックは `inc/booking/` に閉じ込め、テーマの見た目と混ぜないこと。
（将来プラグイン化・Next.js 移行する際に切り出しやすくするため）

```
skillshare/
├── style.css                    # テーマ定義
├── functions.php                # 読み込みとフック登録のみ。ロジックは書かない
├── inc/
│   ├── booking/
│   │   ├── install.php          # テーブル作成
│   │   ├── instructor.php       # 講師申請・審査
│   │   ├── course.php           # 講座
│   │   ├── slot.php             # 予約枠・仮押さえ
│   │   ├── booking.php          # 予約
│   │   ├── stripe.php           # Checkout セッション作成
│   │   ├── webhook.php          # REST エンドポイント
│   │   ├── gcal.php             # Googleカレンダー（ICS取得・招待メール）
│   │   └── mail.php             # 各種メール送信
│   └── admin/
│       ├── instructors.php      # 講師審査画面
│       └── bookings.php         # 予約一覧画面
├── templates/
│   ├── page-apply.php           # 講師申請フォーム
│   ├── page-mypage.php          # 講師マイページ
│   ├── page-courses.php         # 講座一覧
│   ├── single-course.php        # 講座詳細＋予約カレンダー
│   └── page-checkout-done.php   # 予約完了
├── assets/
│   ├── js/calendar.js           # 予約カレンダーUI
│   └── css/
├── .gitignore
└── README.md
```

### 2.4 命名規則

- 関数・フックの接頭辞：`ssb_`
- テーブル接頭辞：`{$wpdb->prefix}ssb_`
- テキストドメイン：`skillshare`

### 2.5 .gitignore

```
node_modules/
vendor/
*.log
.env
.DS_Store
```

---

## 3. データベース設計

有効化時（`after_switch_theme`）に `dbDelta()` で作成する。

### 3.1 wp_ssb_instructors（講師）

| カラム | 型 | 説明 |
|---|---|---|
| id | BIGINT UNSIGNED AI PK | |
| user_id | BIGINT UNSIGNED | WordPress ユーザーID |
| status | VARCHAR(20) | `pending` / `approved` / `rejected` |
| display_name | VARCHAR(100) | 表示名 |
| profile | TEXT | 自己紹介 |
| course_plan | TEXT | 希望する講座内容（申請時） |
| email | VARCHAR(255) | 連絡先 |
| gcal_ics_url | TEXT | GoogleカレンダーのICS URL（未連携なら空） |
| gcal_cache | LONGTEXT | ICS解析結果のキャッシュ（JSON） |
| gcal_fetched_at | DATETIME | 最終取得日時 |
| applied_at | DATETIME | |
| approved_at | DATETIME | |
| interview_at | DATETIME | 面接日（運営のみ） |
| admin_note | TEXT | 管理メモ（運営のみ） |

### 3.2 wp_ssb_courses（講座）

| カラム | 型 | 説明 |
|---|---|---|
| id | BIGINT UNSIGNED AI PK | |
| instructor_id | BIGINT UNSIGNED | |
| title | VARCHAR(255) | |
| description | TEXT | 概要（一覧・カードに表示） |
| content | TEXT | 内容詳細 |
| target | TEXT | こんな方におすすめ |
| image_id | BIGINT UNSIGNED | イメージ画像の添付ID（0なら未設定） |
| price | INT | 税込価格（円） |
| duration_min | INT | 所要時間（分） |
| status | VARCHAR(20) | `draft` / `published` |
| created_at | DATETIME | |

### 3.3 wp_ssb_slots（予約枠）

| カラム | 型 | 説明 |
|---|---|---|
| id | BIGINT UNSIGNED AI PK | |
| course_id | BIGINT UNSIGNED | |
| start_at | DATETIME | 開始日時 |
| end_at | DATETIME | 終了日時 |
| status | VARCHAR(20) | `open` / `held` / `booked` / `closed` |
| hold_token | VARCHAR(64) | 仮押さえ識別子 |
| hold_expires_at | DATETIME | 仮押さえ期限 |

インデックス：`(course_id, start_at)`, `(status, hold_expires_at)`

### 3.4 wp_ssb_bookings（予約）

| カラム | 型 | 説明 |
|---|---|---|
| id | BIGINT UNSIGNED AI PK | |
| slot_id | BIGINT UNSIGNED | |
| name | VARCHAR(100) | 受講者名 |
| email | VARCHAR(255) | 受講者メール |
| note | TEXT | 相談内容（任意） |
| amount | INT | 決済金額 |
| status | VARCHAR(20) | `pending` / `paid` / `cancelled` |
| stripe_session_id | VARCHAR(255) | |
| stripe_payment_intent | VARCHAR(255) | |
| stripe_refund_id | VARCHAR(255) | 返金ID |
| cancel_token | VARCHAR(64) | 受講者がキャンセルするための鍵 |
| cancelled_by | VARCHAR(20) | `customer` / `instructor` / `admin` |
| created_at | DATETIME | |
| paid_at | DATETIME | |
| cancelled_at | DATETIME | |

`stripe_session_id` にユニークインデックスを張ること（Webhook の重複処理防止）。

---

## 4. 機能仕様

### 4.1 講師申請・審査

**申請フォーム（`/apply`）**
- 入力項目：表示名、メールアドレス、自己紹介、希望する講座内容
- 未ログインの場合は WordPress ユーザーを新規作成し、`ssb_instructor` ロールを付与する
- `wp_ssb_instructors` に `status = pending` で登録
- 運営宛てに通知メールを送信

**審査（管理画面）**
- 管理画面に「講師申請」メニューを追加
- 一覧に申請内容を表示し、承認 / 却下ボタンを配置
- 承認時：`status = approved`、`approved_at` を記録、本人に通知メール
- 却下時：`status = rejected`、本人に通知メール
- 一覧の申請内容は折りたたみ表示（既定は閉じた状態）
- 表示名をクリックすると詳細画面を開き、運営用に面接日（`interview_at`）と
  メモ（`admin_note`）を登録できる。どちらも講師には通知しない
- 削除ボタンで申請を削除できる。却下された人が再申請できるようにするための操作で、
  WordPress ユーザーアカウントは残し `ssb_instructor` ロールのみ外す。
  講座が登録されている場合は予約データが孤立するため削除しない

**権限**
- `ssb_instructor` ロールを追加し、`read` と独自ケーパビリティ `ssb_manage_own_courses` を付与
- 管理画面（wp-admin）へのアクセスは不要。マイページはフロント側に作る

### 4.2 講師マイページ（`/mypage`）

`status = approved` の講師のみアクセス可。
未ログインなら `/login` へ、ログイン済みだが講師でない場合は `/apply` へリダイレクトする。

ログインは WordPress 標準の `wp-login.php` ではなく `/login` で受ける。
ID・パスワード・ログイン状態の保存・パスワード再設定への導線を持つ独自画面とし、
認証失敗時はアカウントの有無を区別しない文言を返す。

以下を1ページ内のタブで構成する。

1. **プロフィール編集**
2. **講座管理**：講座の作成・編集・公開/非公開・削除
   　項目はタイトル・イメージ画像・概要・内容詳細・こんな方におすすめ・価格・所要時間
   　削除は、予約済み・仮押さえ中の枠がある場合と決済済みの予約がある場合はできない。
   　削除できる場合は、その講座の枠・未完了の予約・イメージ画像もまとめて片付ける
3. **予約枠登録**：日付と時間帯を指定して枠を一括生成（例：3/1〜3/31 の平日 14:00-18:00、60分刻み）
4. **Googleカレンダー連携**：ICS URL の登録・解除
5. **予約一覧**：自分の講座に入った予約を表示

### 4.3 予約枠の生成ロジック

受講者に表示される「予約可能枠」は次の引き算で決まる。

```
講師が登録した枠（status = open）
　－ Googleカレンダーに予定がある時間帯（連携時のみ）
　－ 仮押さえ中の枠（held かつ期限内）
　＝ 表示される空き枠
```

**重要**：Googleカレンダーは除外フィルタとしてのみ機能する。
ICS URL が未登録の講師は、登録した枠がそのまま表示される（手動運用が成立する）。

### 4.4 Googleカレンダー連携

**読み取り（ICS方式）**
- 講師がマイページに Googleカレンダーの「限定公開URL（iCal形式）」を貼り付ける
- サーバー側で `wp_remote_get()` により ICS を取得し、パースして予定の開始・終了時刻を抽出
- 結果を `gcal_cache` に JSON で保存、`gcal_fetched_at` を更新
- **キャッシュは1時間**。講座ページ表示時にキャッシュが古ければ再取得する（wp-cron は使わない）
- 取得失敗時は前回のキャッシュを使い、キャッシュもなければ除外なしで表示する（**連携失敗で予約を止めない**）
- 繰り返し予定（RRULE）は簡易対応。`FREQ=WEEKLY` と `FREQ=DAILY` のみ展開し、それ以外は無視してよい
- 終日予定（VALUE=DATE）はその日全体を除外扱いとする

**書き込み（招待メール方式）**
- 予約確定時に、講師宛てに `.ics` を添付したメールを送信
- Content-Type は `text/calendar; method=REQUEST` とする
- ICSの内容：SUMMARY（講座名＋受講者名）、DTSTART / DTEND、DESCRIPTION（相談内容）、ORGANIZER（運営）、ATTENDEE（講師）
- OAuth・Google API は一切使わない

**セキュリティ**
- ICS URL は第三者に知られると閲覧できてしまうため、フロントに出力しない。ログにも残さない
- URL のバリデーション：`https://calendar.google.com/` で始まることを確認する

### 4.5 予約フロー（受講者側）

```
講座詳細ページ
  └ カレンダー表示（空き枠のみ）
      └ 枠をクリック
          └ 仮押さえ（15分）＋ 入力フォーム表示
              └ 氏名・メール・相談内容を入力
                  └ Stripe Checkout へリダイレクト
                      └ カード決済
                          └ Webhook で予約確定
                              └ 完了ページ＋確認メール
```

**仮押さえの実装**
- 枠クリック時に Ajax で `status = held`、`hold_token` を発行、`hold_expires_at` を現在時刻 +15分に設定
- 期限切れの解放は **cron を使わず**、枠一覧を取得するクエリの直前に以下を実行する

```sql
UPDATE wp_ssb_slots
SET status = 'open', hold_token = NULL, hold_expires_at = NULL
WHERE status = 'held' AND hold_expires_at < NOW();
```

- 仮押さえ時は `SELECT ... FOR UPDATE` を含むトランザクションで排他制御し、二重取得を防ぐこと

### 4.6 Stripe 決済

**Checkout セッション作成**
- サーバー側で `checkout.sessions.create` を実行
- `mode` は `payment`（サブスクリプションではない）
- `metadata` に `booking_id`、`slot_id`、`hold_token` を含める
- `success_url` / `cancel_url` を設定
- この時点で `wp_ssb_bookings` に `status = pending` で1件作成しておく

**Webhook**
- エンドポイント：`/wp-json/ssb/v1/stripe-webhook`
- `register_rest_route()` で登録し、`permission_callback` は `__return_true`（署名で検証するため）
- **必ず署名検証を行う**（`Stripe\Webhook::constructEvent`）
- 処理するイベント：`checkout.session.completed`
- 処理内容：
  1. `metadata.booking_id` から予約を特定
  2. すでに `paid` なら何もせず 200 を返す（冪等性）
  3. `bookings.status = paid`、`paid_at`、`stripe_payment_intent` を記録
  4. `slots.status = booked`
  5. 受講者・講師・運営へメール送信、講師へは `.ics` を添付
- 例外が発生しても 200 以外を返す前に必ずログを残すこと

**リダイレクト先での確定はしない**
決済完了は必ず Webhook で確定する。`success_url` の画面はあくまで表示のみ。

**APIキー**
`wp-config.php` に定数で定義する。**DBに保存しない。Gitに入れない。**

```php
define('SSB_STRIPE_SECRET', 'sk_test_xxx');
define('SSB_STRIPE_PUBLIC', 'pk_test_xxx');
define('SSB_STRIPE_WEBHOOK_SECRET', 'whsec_xxx');
```

**Stripeライブラリ**
Composer が使えない環境も想定し、`wp_remote_post()` による直接呼び出しでも可。
ただし Webhook の署名検証だけは自前実装せず、公式ライブラリの利用を推奨する。

### 4.7 キャンセルと返金

確定済みの予約をキャンセルすると、**Stripe へ自動で全額返金**する。

**キャンセルできる人**

| 誰が | どこから | 制限 |
|---|---|---|
| 受講者 | 予約確認メールのリンク（`/booking/cancel`） | 開始時刻を過ぎたら不可 |
| 講師 | マイページ「予約一覧」 | これからの予約のみ |
| 運営 | 管理画面「予約一覧」 | 制限なし |

受講者の本人確認は、予約ごとに発行するトークン（`cancel_token`）で行う。ログインは不要。
トークンは確認メール以外に出力せず、比較は `hash_equals()` で行う。

**処理の順序**

1. Stripe へ全額返金する（`amount` を渡さなければ全額になる）
2. 成功したら `bookings.status = cancelled`、`cancelled_at`、`cancelled_by`、`stripe_refund_id` を記録
3. 枠を `open` に戻し、他の人が予約できるようにする
4. 受講者・講師・運営へ通知メールを送る。講師宛てには `METHOD:CANCEL` の `.ics` を添付し、
   カレンダーの予定を取り消せるようにする

**返金を先に通すこと。** DB を先に書くと、返金に失敗したときに「キャンセル済みなのに
返金されていない」状態が残る。返金は成立したのに DB の更新に失敗した場合は必ずログを残す。

返金リクエストには予約IDから作る冪等キーを付け、再送しても二重に返金されないようにする。

### 4.8 メール送信

`wp_mail()` を使用。以下を送信する。

| タイミング | 宛先 | 内容 |
|---|---|---|
| 講師申請時 | 運営 | 新規申請の通知 |
| 承認/却下時 | 講師 | 審査結果 |
| 予約確定時 | 受講者 | 予約内容の確認 |
| 予約確定時 | 講師 | 予約通知（`.ics` 添付） |
| 予約確定時 | 運営 | 予約通知 |
| キャンセル時 | 受講者 | キャンセルと返金のお知らせ |
| キャンセル時 | 講師 | キャンセル通知（取り消し用 `.ics` 添付） |
| キャンセル時 | 運営 | キャンセルと返金の通知 |

### 4.9 管理画面

- **講師申請**：一覧・承認・却下
- **予約一覧**：予約の一覧、講座・講師・金額・ステータスで絞り込み、CSVエクスポート
- 講師別の月次売上集計（振込作業用）を予約一覧の下部に表示する

---

## 5. 画面一覧

| URL | 内容 | アクセス |
|---|---|---|
| `/` | トップ（公開中の講座を新着順に表示） | 全員 |
| `/courses` | 講座一覧 | 全員 |
| `/courses/{id}` | 講座詳細＋予約カレンダー | 全員 |
| `/apply` | 講師申請フォーム | 全員 |
| `/login` | ログイン・パスワード再設定 | 全員 |
| `/mypage` | 講師マイページ | 承認済み講師 |
| `/booking/done` | 予約完了 | 全員 |
| `/booking/cancel` | 予約のキャンセル | 予約確認メールのリンクから |
| `/terms` | 利用規約 | 全員 |
| `/tokushoho` | 特定商取引法に基づく表記 | 全員 |
| `/cancel-policy` | キャンセルポリシー | 全員 |

---

## 6. セキュリティ要件

- すべてのフォーム送信に `wp_nonce_field()` / `check_admin_referer()` を使う
- Ajax にも nonce を必須とする
- SQL は必ず `$wpdb->prepare()` を通す
- 出力は `esc_html()` / `esc_attr()` / `esc_url()` でエスケープする
- 講師マイページでは、操作対象の講座・枠が**そのログインユーザーのものか**を毎回検証する
- Stripe の金額はフロントから受け取らず、必ずサーバー側で `courses.price` を参照する

---

## 7. 実装順序

上から順に進め、各段階でブラウザ動作確認を行うこと。

1. テーマの雛形作成、`functions.php` の読み込み構成
2. DBテーブル作成（`install.php`）
3. 講師申請フォーム → 管理画面での承認
4. 講師マイページ（プロフィール・講座管理）
5. 予約枠の一括登録
6. 講座詳細ページのカレンダー表示（**この時点では予約不可でよい**）
7. 仮押さえ＋入力フォーム
8. Stripe Checkout 連携
9. Webhook による予約確定
10. メール送信（`.ics` 添付含む）
11. Googleカレンダー ICS 読み取り・除外表示
12. 管理画面の予約一覧・売上集計
13. 規約類ページ

**8〜9 は必ずセットで完成させること。** 決済だけ通って予約が確定しない状態を残さない。

---

## 8. テスト方法

### Stripe

```bash
stripe login
stripe listen --forward-to https://skillshare.local/wp-json/ssb/v1/stripe-webhook
```

テストカード：`4242 4242 4242 4242` / 任意の将来日 / 任意のCVC

**確認すべきケース**
- 決済成功 → 予約確定、枠が `booked` になる
- 決済途中でブラウザを閉じる → 予約は `pending` のまま、15分後に枠が解放される
- 同じ Webhook が2回届く → 予約が二重に確定しない
- 決済失敗 → 枠が解放される

### 予約枠

- 2つのブラウザで同じ枠を同時にクリック → 片方だけが仮押さえできる
- 仮押さえから15分放置 → 枠が再び表示される

### Googleカレンダー

- ICS URL 未登録の講師 → 登録した枠がそのまま表示される
- ICS URL 登録済みで予定がある時間 → その枠が非表示になる
- ICS URL が不正・取得失敗 → エラーにならず、除外なしで表示される

---

## 9. 今回のスコープ外

以下は**実装しない**。仕様を勝手に拡張しないこと。

- 月額プラン・サブスクリプション決済
- Stripe Connect による講師への自動分配
- 講師と受講者のメッセージ機能
- レビュー・評価機能
- 講師の売上出金申請機能
- スマホアプリ
- 多言語対応
- Google OAuth によるカレンダー連携（ICS方式のみ）

---

## 10. 備考（将来の展開）

PoC で需要が確認できた場合、以下を Next.js で再構築する想定。
**今回は実装しないが、データを引き継げる形で保持しておくこと。**

- 月額プラン（サブスクリプション）対応
- Stripe Connect の導入。受講料が運営口座を経由せず講師へ直接入金される形に切り替え
- インボイス制度対応（講師の登録番号収集、領収書の出し分け）
- Google OAuth 連携によるリアルタイムなカレンダー同期（要 Google 審査）
- メッセージ・レビュー機能

移行を見据え、講師・講座・予約・決済履歴は CSV でエクスポートできる状態を維持する。
予約機能のロジックは `inc/booking/` に閉じ込め、テーマの表示処理と混在させないこと。
