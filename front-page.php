<?php
/**
 * トップページ.
 *
 * 公開中の講座を新着順に並べる。
 *
 * @package skillshare
 */

defined( 'ABSPATH' ) || exit;

$ssb_courses = ssb_get_published_courses( 6 );

get_header();
?>

<section class="ssb-hero">
	<h1 class="ssb-hero__title">聞きたいことを、その道の人に。</h1>
	<p class="ssb-hero__lead">単発の相談枠をカレンダーから選んで、そのまま予約できます。</p>
	<p>
		<a class="ssb-button" href="<?php echo esc_url( ssb_get_page_url( 'courses' ) ); ?>">講座を探す</a>
		<a class="ssb-button ssb-button--secondary" href="<?php echo esc_url( ssb_get_page_url( 'apply' ) ); ?>">講師として登録する</a>
	</p>
</section>

<section class="ssb-section">
	<h2 class="ssb-section__title">新着の講座</h2>

	<?php if ( ! $ssb_courses ) : ?>
		<p class="ssb-muted">公開中の講座はまだありません。</p>
	<?php else : ?>
		<div class="ssb-course-grid">
			<?php foreach ( $ssb_courses as $ssb_course ) : ?>
				<?php get_template_part( 'templates/parts/course-card', null, array( 'course' => $ssb_course ) ); ?>
			<?php endforeach; ?>
		</div>

		<p style="margin-top:24px;">
			<a class="ssb-button ssb-button--secondary" href="<?php echo esc_url( ssb_get_page_url( 'courses' ) ); ?>">すべての講座を見る</a>
		</p>
	<?php endif; ?>
</section>

<?php
get_footer();
