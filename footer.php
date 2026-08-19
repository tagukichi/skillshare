<?php
/**
 * フッター.
 *
 * @package skillshare
 */

defined( 'ABSPATH' ) || exit;
?>
	</div><!-- /.ssb-container -->
</main>

<footer class="ssb-footer">
	<div class="ssb-container">
		<nav class="ssb-footer__nav">
			<a href="<?php echo esc_url( home_url( '/terms/' ) ); ?>">利用規約</a>
			<a href="<?php echo esc_url( home_url( '/tokushoho/' ) ); ?>">特定商取引法に基づく表記</a>
			<a href="<?php echo esc_url( home_url( '/cancel-policy/' ) ); ?>">キャンセルポリシー</a>
		</nav>
		<p class="ssb-footer__copy">
			&copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?>
		</p>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
