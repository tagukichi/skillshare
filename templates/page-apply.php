<?php
/**
 * 講師申請フォーム（/apply）.
 *
 * 送信先は admin-post.php。処理は inc/booking/instructor.php の ssb_handle_apply()。
 *
 * @package skillshare
 */

defined( 'ABSPATH' ) || exit;

$ssb_feedback = ssb_get_apply_feedback();
$ssb_errors   = isset( $ssb_feedback['errors'] ) ? (array) $ssb_feedback['errors'] : array();
$ssb_input    = isset( $ssb_feedback['input'] ) ? (array) $ssb_feedback['input'] : array();

$ssb_done     = ! empty( $_GET['ssb_applied'] );
$ssb_logged_in = is_user_logged_in();
$ssb_existing = $ssb_logged_in ? ssb_get_instructor_by_user_id( get_current_user_id() ) : null;
$ssb_user     = wp_get_current_user();

// 入力値の初期値。エラー時は打ち直しにならないよう入力値を戻す。
$ssb_v_name  = isset( $ssb_input['display_name'] ) ? $ssb_input['display_name'] : ( $ssb_logged_in ? $ssb_user->display_name : '' );
$ssb_v_email = isset( $ssb_input['email'] ) ? $ssb_input['email'] : ( $ssb_logged_in ? $ssb_user->user_email : '' );
$ssb_v_prof  = isset( $ssb_input['profile'] ) ? $ssb_input['profile'] : '';
$ssb_v_plan  = isset( $ssb_input['course_plan'] ) ? $ssb_input['course_plan'] : '';

get_header();
?>

<h1 class="ssb-page__title">講師申請</h1>

<?php if ( $ssb_done ) : ?>

	<div class="ssb-notice ssb-notice--success">
		<p>申請を受け付けました。運営が内容を確認し、結果をメールでお知らせします。</p>
		<?php if ( ! $ssb_logged_in ) : ?>
			<p>あわせて、ログイン用のパスワード設定メールをお送りしました。届かない場合は迷惑メールフォルダをご確認ください。</p>
		<?php endif; ?>
	</div>

<?php elseif ( $ssb_existing ) : ?>

	<div class="ssb-card">
		<h2 class="ssb-card__title">申請の状態：<?php echo esc_html( ssb_instructor_status_label( $ssb_existing->status ) ); ?></h2>
		<?php if ( 'pending' === $ssb_existing->status ) : ?>
			<p>現在審査中です。結果が出ましたらメールでお知らせします。</p>
		<?php elseif ( 'approved' === $ssb_existing->status ) : ?>
			<p>承認済みです。マイページから講座の作成と予約枠の登録ができます。</p>
			<p><a class="ssb-button" href="<?php echo esc_url( ssb_get_page_url( 'mypage' ) ); ?>">マイページへ</a></p>
		<?php else : ?>
			<p>今回は見送らせていただきました。詳細は運営までお問い合わせください。</p>
		<?php endif; ?>
	</div>

<?php else : ?>

	<p class="ssb-muted">単発相談の講座を出品したい方はこちらからご申請ください。運営が内容を確認のうえ、結果をメールでお知らせします。</p>

	<?php if ( $ssb_errors ) : ?>
		<div class="ssb-notice ssb-notice--error">
			<p>入力内容をご確認ください。</p>
			<ul>
				<?php foreach ( $ssb_errors as $ssb_error ) : ?>
					<li><?php echo esc_html( $ssb_error ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php if ( ! $ssb_logged_in ) : ?>
		<p class="ssb-muted">
			申請と同時にアカウントを作成します。すでにアカウントをお持ちの方は
			<a href="<?php echo esc_url( wp_login_url( ssb_get_page_url( 'apply' ) ) ); ?>">ログイン</a>してから申請してください。
		</p>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'ssb_apply', 'ssb_apply_nonce' ); ?>
		<input type="hidden" name="action" value="ssb_apply">

		<div class="ssb-field">
			<label class="ssb-field__label" for="ssb-display-name">表示名<span class="ssb-field__required">必須</span></label>
			<input class="ssb-input" type="text" id="ssb-display-name" name="display_name" maxlength="100" required
				value="<?php echo esc_attr( $ssb_v_name ); ?>">
			<p class="ssb-field__hint">受講者に表示される名前です。</p>
		</div>

		<div class="ssb-field">
			<label class="ssb-field__label" for="ssb-email">メールアドレス<span class="ssb-field__required">必須</span></label>
			<input class="ssb-input" type="email" id="ssb-email" name="email" maxlength="255" required
				value="<?php echo esc_attr( $ssb_v_email ); ?>">
			<p class="ssb-field__hint">審査結果や予約通知をお送りします。</p>
		</div>

		<div class="ssb-field">
			<label class="ssb-field__label" for="ssb-profile">自己紹介<span class="ssb-field__required">必須</span></label>
			<textarea class="ssb-textarea" id="ssb-profile" name="profile" required><?php echo esc_textarea( $ssb_v_prof ); ?></textarea>
			<p class="ssb-field__hint">経歴や得意分野をご記入ください。</p>
		</div>

		<div class="ssb-field">
			<label class="ssb-field__label" for="ssb-course-plan">希望する講座内容<span class="ssb-field__required">必須</span></label>
			<textarea class="ssb-textarea" id="ssb-course-plan" name="course_plan" required><?php echo esc_textarea( $ssb_v_plan ); ?></textarea>
			<p class="ssb-field__hint">どんな相談に応じられるか、想定する所要時間や価格帯があればあわせてご記入ください。</p>
		</div>

		<p><button type="submit" class="ssb-button">この内容で申請する</button></p>
	</form>

<?php endif; ?>

<?php
get_footer();
