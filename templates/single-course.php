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
		<p class="ssb-muted">予約カレンダーは準備中です。</p>
	</section>

	<p><a href="<?php echo esc_url( ssb_get_page_url( 'courses' ) ); ?>">&laquo; 講座一覧へ戻る</a></p>
</article>

<?php
get_footer();
