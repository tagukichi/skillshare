<?php
/**
 * 講座カード（トップ・講座一覧で使う）.
 *
 * @package skillshare
 *
 * @var array<string,mixed> $args course キーに講座レコードを渡すこと。
 */

defined( 'ABSPATH' ) || exit;

$ssb_course = isset( $args['course'] ) ? $args['course'] : null;

if ( ! $ssb_course ) {
	return;
}

$ssb_url   = ssb_course_url( $ssb_course->id );
$ssb_image = ssb_course_image_url( $ssb_course, 'medium_large' );
?>
<article class="ssb-course-card">
	<a class="ssb-course-card__thumb" href="<?php echo esc_url( $ssb_url ); ?>">
		<?php if ( $ssb_image ) : ?>
			<img src="<?php echo esc_url( $ssb_image ); ?>" alt="<?php echo esc_attr( $ssb_course->title ); ?>" loading="lazy">
		<?php else : ?>
			<span class="ssb-course-card__noimage">No Image</span>
		<?php endif; ?>
	</a>

	<div class="ssb-course-card__body">
		<h3 class="ssb-course-card__title">
			<a href="<?php echo esc_url( $ssb_url ); ?>"><?php echo esc_html( $ssb_course->title ); ?></a>
		</h3>

		<?php if ( ! empty( $ssb_course->instructor_name ) ) : ?>
			<p class="ssb-course-card__meta ssb-muted">講師：<?php echo esc_html( $ssb_course->instructor_name ); ?></p>
		<?php endif; ?>

		<?php if ( '' !== (string) $ssb_course->description ) : ?>
			<p class="ssb-course-card__desc">
				<?php echo esc_html( wp_trim_words( (string) $ssb_course->description, 45, '…' ) ); ?>
			</p>
		<?php endif; ?>

		<p class="ssb-course-card__foot">
			<span class="ssb-price"><?php echo esc_html( number_format( (int) $ssb_course->price ) ); ?> 円</span>
			<span class="ssb-muted">／ <?php echo esc_html( (string) (int) $ssb_course->duration_min ); ?> 分</span>
		</p>
	</div>
</article>
