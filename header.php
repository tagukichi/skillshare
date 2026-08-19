<?php
/**
 * ヘッダー.
 *
 * @package skillshare
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<header class="ssb-header">
	<div class="ssb-container ssb-header__inner">
		<p class="ssb-header__brand">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a>
		</p>
		<nav class="ssb-header__nav">
			<a href="<?php echo esc_url( home_url( '/courses/' ) ); ?>">講座を探す</a>
			<a href="<?php echo esc_url( home_url( '/apply/' ) ); ?>">講師になる</a>
			<?php if ( is_user_logged_in() ) : ?>
				<a href="<?php echo esc_url( home_url( '/mypage/' ) ); ?>">マイページ</a>
			<?php endif; ?>
		</nav>
	</div>
</header>

<main class="ssb-main">
	<div class="ssb-container">
