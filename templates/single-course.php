<?php
/**
 * 講座詳細（/courses/{id}）.
 *
 * 予約カレンダーは実装順序 6 でここに追加する。
 *
 * @package skillshare
 */

defined( 'ABSPATH' ) || exit;

$ssb_course = ssb_get_published_course( (int) get_query_var( 'ssb_course_id' ) );

if ( ! $ssb_course ) {
	status_header( 404 );
	nocache_headers();

	get_header();
	?>
	<h1 class="ssb-page__title">講座が見つかりません</h1>
	<p>公開が終了したか、URL が正しくない可能性があります。</p>
	<p><a class="ssb-button" href="<?php echo esc_url( ssb_get_page_url( 'courses' ) ); ?>">講座一覧へ</a></p>
	<?php
	get_footer();
	return;
}

$ssb_image = ssb_course_image_url( $ssb_course, 'large' );

get_header();
?>

<article class="ssb-course">
	<h1 class="ssb-page__title"><?php echo esc_html( $ssb_course->title ); ?></h1>

	<?php if ( $ssb_image ) : ?>
		<p class="ssb-course__image">
			<img src="<?php echo esc_url( $ssb_image ); ?>" alt="<?php echo esc_attr( $ssb_course->title ); ?>">
		</p>
	<?php endif; ?>

	<div class="ssb-card ssb-course__summary">
		<p>
			<span class="ssb-price"><?php echo esc_html( number_format( (int) $ssb_course->price ) ); ?> 円</span>
			<span class="ssb-muted">（税込）／ <?php echo esc_html( (string) (int) $ssb_course->duration_min ); ?> 分</span>
		</p>
		<p class="ssb-muted">講師：<?php echo esc_html( $ssb_course->instructor_name ); ?></p>
	</div>

	<?php if ( '' !== (string) $ssb_course->description ) : ?>
		<section class="ssb-section">
			<h2 class="ssb-section__title">概要</h2>
			<p><?php echo nl2br( esc_html( (string) $ssb_course->description ) ); ?></p>
		</section>
	<?php endif; ?>

	<?php if ( '' !== (string) $ssb_course->content ) : ?>
		<section class="ssb-section">
			<h2 class="ssb-section__title">内容詳細</h2>
			<p><?php echo nl2br( esc_html( (string) $ssb_course->content ) ); ?></p>
		</section>
	<?php endif; ?>

	<?php if ( '' !== (string) $ssb_course->target ) : ?>
		<section class="ssb-section">
			<h2 class="ssb-section__title">こんな方におすすめ</h2>
			<p><?php echo nl2br( esc_html( (string) $ssb_course->target ) ); ?></p>
		</section>
	<?php endif; ?>

	<?php if ( '' !== (string) $ssb_course->instructor_profile ) : ?>
		<section class="ssb-section">
			<h2 class="ssb-section__title">講師について</h2>
			<p><?php echo nl2br( esc_html( (string) $ssb_course->instructor_profile ) ); ?></p>
		</section>
	<?php endif; ?>

	<section class="ssb-section">
		<h2 class="ssb-section__title">予約</h2>

		<div id="ssb-calendar-message" class="ssb-notice ssb-notice--error" role="alert" hidden></div>

		<div id="ssb-calendar" class="ssb-calendar">
			<p class="ssb-muted">カレンダーを読み込んでいます…</p>
		</div>

		<noscript>
			<p class="ssb-muted">予約カレンダーの表示には JavaScript が必要です。</p>
		</noscript>

		<div id="ssb-booking" class="ssb-booking" hidden>
			<h3 class="ssb-booking__title">お申し込み内容</h3>

			<p class="ssb-booking__slot">
				<strong id="ssb-booking-slot"></strong>
				<span class="ssb-muted">／ <?php echo esc_html( number_format( (int) $ssb_course->price ) ); ?> 円（税込）</span>
			</p>

			<p class="ssb-booking__timer ssb-muted">
				この枠を <strong id="ssb-booking-countdown">--:--</strong> 確保しています。時間内にお申し込みください。
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ssb_start_checkout', 'ssb_checkout_nonce' ); ?>
				<input type="hidden" name="action" value="ssb_start_checkout">
				<input type="hidden" name="course_id" value="<?php echo esc_attr( (string) $ssb_course->id ); ?>">
				<input type="hidden" name="slot_id" id="ssb-field-slot-id" value="">
				<input type="hidden" name="hold_token" id="ssb-field-hold-token" value="">

				<div class="ssb-field">
					<label class="ssb-field__label" for="ssb-booking-name">お名前<span class="ssb-field__required">必須</span></label>
					<input class="ssb-input" type="text" id="ssb-booking-name" name="name" maxlength="100" required autocomplete="name">
				</div>

				<div class="ssb-field">
					<label class="ssb-field__label" for="ssb-booking-email">メールアドレス<span class="ssb-field__required">必須</span></label>
					<input class="ssb-input" type="email" id="ssb-booking-email" name="email" maxlength="255" required autocomplete="email">
					<p class="ssb-field__hint">予約確認と当日のご案内をお送りします。</p>
				</div>

				<div class="ssb-field">
					<label class="ssb-field__label" for="ssb-booking-note">相談内容</label>
					<textarea class="ssb-textarea" id="ssb-booking-note" name="note"></textarea>
					<p class="ssb-field__hint">当日話したいことを書いておくと、講師が準備できます。（任意）</p>
				</div>

				<p class="ssb-booking__actions">
					<button type="submit" class="ssb-button" disabled>この内容で予約に進む</button>
					<button type="button" class="ssb-button ssb-button--secondary" id="ssb-booking-cancel">選択をやめる</button>
				</p>

				<p class="ssb-muted" style="font-size:0.85rem;">
					カード決済への接続は準備中です。次の実装で有効になります。
				</p>
			</form>
		</div>
	</section>

	<p><a href="<?php echo esc_url( ssb_get_page_url( 'courses' ) ); ?>">&laquo; 講座一覧へ戻る</a></p>
</article>

<?php
get_footer();
