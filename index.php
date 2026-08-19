<?php
/**
 * 汎用テンプレート（フォールバック）.
 *
 * 専用テンプレートを持たない画面はすべてここに落ちる。
 *
 * @package skillshare
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<?php if ( have_posts() ) : ?>

	<?php if ( is_home() && ! is_front_page() ) : ?>
		<h1 class="ssb-page__title"><?php single_post_title(); ?></h1>
	<?php elseif ( is_archive() || is_search() ) : ?>
		<h1 class="ssb-page__title"><?php the_archive_title(); ?></h1>
	<?php endif; ?>

	<?php
	while ( have_posts() ) :
		the_post();
		?>
		<article <?php post_class( 'ssb-entry' ); ?>>
			<?php if ( is_singular() ) : ?>
				<h1 class="ssb-page__title"><?php the_title(); ?></h1>
			<?php else : ?>
				<h2 class="ssb-entry__title">
					<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
				</h2>
			<?php endif; ?>

			<div class="ssb-entry__body">
				<?php
				if ( is_singular() ) {
					the_content();
				} else {
					the_excerpt();
				}
				?>
			</div>
		</article>
		<?php
	endwhile;
	?>

	<?php the_posts_pagination(); ?>

<?php else : ?>

	<h1 class="ssb-page__title">ページが見つかりません</h1>
	<p>お探しのページは存在しないか、移動した可能性があります。</p>
	<p><a class="ssb-button" href="<?php echo esc_url( home_url( '/' ) ); ?>">トップへ戻る</a></p>

<?php endif; ?>

<?php
get_footer();
