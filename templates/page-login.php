<?php
/**
 * ログイン（/login）.
 *
 * WordPress 標準の wp-login.php ではなく、サイトの見た目に合わせた自前の画面。
 * 処理は inc/booking/instructor.php の ssb_handle_login() / ssb_handle_lostpassword()。
 *
 * @package skillshare
 */

defined( 'ABSPATH' ) || exit;

$ssb_flash  = ssb_flash_take();
$ssb_errors = isset( $ssb_flash['errors'] ) ? (array) $ssb_flash['errors'] : array();
$ssb_input  = isset( $ssb_flash['input'] ) ? (array) $ssb_flash['input'] : array();

$ssb_action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
$ssb_sent   = ! empty( $_GET['sent'] );

$ssb_redirect_to = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';

get_header();
?>

<?php if ( is_user_logged_in() ) : ?>

	<h1 class="ssb-page__title">ログイン済みです</h1>
	<p><?php echo esc_html( wp_get_current_user()->display_name ); ?> さんとしてログインしています。</p>
	<p>
		<a class="ssb-button" href="<?php echo esc_url( ssb_get_page_url( 'mypage' ) ); ?>">マイページへ</a>
		<a class="ssb-button ssb-button--secondary" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">ログアウト</a>
	</p>

<?php elseif ( 'lostpassword' === $ssb_action ) : ?>

	<h1 class="ssb-page__title">パスワードの再設定</h1>

	<?php if ( $ssb_sent ) : ?>
		<div class="ssb-notice ssb-notice--success">
			<p>ご入力のアドレス宛に、パスワード再設定用のメールをお送りしました。</p>
			<p>届かない場合は、迷惑メールフォルダをご確認のうえ、入力内容をお確かめください。</p>
		</div>
		<p><a href="<?php echo esc_url( ssb_get_page_url( 'login' ) ); ?>">ログイン画面へ戻る</a></p>
	<?php else : ?>

		<?php if ( $ssb_errors ) : ?>
			<div class="ssb-notice ssb-notice--error">
				<ul>
					<?php foreach ( $ssb_errors as $ssb_error ) : ?>
						<li><?php echo esc_html( $ssb_error ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<p class="ssb-muted">ご登録のメールアドレス（またはユーザー名）を入力してください。再設定用のリンクをお送りします。</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ssb_lostpassword', 'ssb_lostpassword_nonce' ); ?>
			<input type="hidden" name="action" value="ssb_lostpassword">

			<div class="ssb-field">
				<label class="ssb-field__label" for="ssb-user-login">メールアドレス または ユーザー名</label>
				<input class="ssb-input" type="text" id="ssb-user-login" name="user_login" required autocomplete="username">
			</div>

			<p>
				<button type="submit" class="ssb-button">再設定メールを送る</button>
				<a class="ssb-button ssb-button--secondary" href="<?php echo esc_url( ssb_get_page_url( 'login' ) ); ?>">戻る</a>
			</p>
		</form>

	<?php endif; ?>

<?php else : ?>

	<h1 class="ssb-page__title">ログイン</h1>

	<?php if ( $ssb_errors ) : ?>
		<div class="ssb-notice ssb-notice--error">
			<ul>
				<?php foreach ( $ssb_errors as $ssb_error ) : ?>
					<li><?php echo esc_html( $ssb_error ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'ssb_login', 'ssb_login_nonce' ); ?>
		<input type="hidden" name="action" value="ssb_login">
		<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $ssb_redirect_to ); ?>">

		<div class="ssb-field">
			<label class="ssb-field__label" for="ssb-log">ユーザー名 または メールアドレス</label>
			<input class="ssb-input" type="text" id="ssb-log" name="log" required autocomplete="username"
				value="<?php echo esc_attr( isset( $ssb_input['log'] ) ? $ssb_input['log'] : '' ); ?>">
		</div>

		<div class="ssb-field">
			<label class="ssb-field__label" for="ssb-pwd">パスワード</label>
			<input class="ssb-input" type="password" id="ssb-pwd" name="pwd" required autocomplete="current-password">
		</div>

		<div class="ssb-field">
			<label>
				<input type="checkbox" name="rememberme" value="1"> ログイン状態を保存する
			</label>
		</div>

		<p><button type="submit" class="ssb-button">ログイン</button></p>
	</form>

	<p>
		<a href="<?php echo esc_url( add_query_arg( 'action', 'lostpassword', ssb_get_page_url( 'login' ) ) ); ?>">パスワードをお忘れの方はこちら</a>
	</p>
	<p class="ssb-muted">
		講師をご希望の方は<a href="<?php echo esc_url( ssb_get_page_url( 'apply' ) ); ?>">講師申請</a>からお手続きください。
	</p>

<?php endif; ?>

<?php
get_footer();
