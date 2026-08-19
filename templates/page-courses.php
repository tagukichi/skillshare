<?php
/**
 * 講座一覧（/courses）.
 *
 * @package skillshare
 */

defined( 'ABSPATH' ) || exit;

$ssb_courses = ssb_get_published_courses();

get_header();
?>

<h1 class="ssb-page__title">講座一覧</h1>

<?php if ( ! $ssb_courses ) : ?>
	<p class="ssb-muted">公開中の講座はまだありません。</p>
<?php else : ?>
	<div class="ssb-course-grid">
		<?php foreach ( $ssb_courses as $ssb_course ) : ?>
			<?php get_template_part( 'templates/parts/course-card', null, array( 'course' => $ssb_course ) ); ?>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<?php
get_footer();
