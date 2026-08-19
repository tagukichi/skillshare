<?php
/**
 * Skillshare テーマ ブートストラップ.
 *
 * このファイルはモジュールの読み込みとテーマ自体のフック登録のみを行う。
 * 予約機能のロジックは inc/booking/ に、管理画面は inc/admin/ に置くこと。
 * 将来のプラグイン化・Next.js 移行に備え、各モジュールは自分のフックを
 * 自分のファイル末尾で登録し、テーマの表示処理と混在させない。
 *
 * @package skillshare
 */

defined( 'ABSPATH' ) || exit;

define( 'SSB_VERSION', '0.1.0' );
define( 'SSB_PATH', get_stylesheet_directory() );
define( 'SSB_URL', get_stylesheet_directory_uri() );

/**
 * 予約機能モジュール.
 *
 * 依存順に読み込む。install.php はテーブル定義を持つため最初に置く。
 */
require_once SSB_PATH . '/inc/booking/install.php';
require_once SSB_PATH . '/inc/booking/instructor.php';
require_once SSB_PATH . '/inc/booking/course.php';
require_once SSB_PATH . '/inc/booking/slot.php';
require_once SSB_PATH . '/inc/booking/booking.php';
require_once SSB_PATH . '/inc/booking/stripe.php';
require_once SSB_PATH . '/inc/booking/webhook.php';
require_once SSB_PATH . '/inc/booking/gcal.php';
require_once SSB_PATH . '/inc/booking/mail.php';

/**
 * 管理画面モジュール.
 */
require_once SSB_PATH . '/inc/admin/instructors.php';
require_once SSB_PATH . '/inc/admin/bookings.php';

/**
 * テーマの基本設定.
 *
 * @return void
 */
function ssb_theme_setup() {
	load_theme_textdomain( 'skillshare', SSB_PATH . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support(
		'html5',
		array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' )
	);
}
add_action( 'after_setup_theme', 'ssb_theme_setup' );

/**
 * フロント側のアセット読み込み.
 *
 * @return void
 */
function ssb_enqueue_assets() {
	wp_enqueue_style( 'skillshare-theme', get_stylesheet_uri(), array(), SSB_VERSION );
	wp_enqueue_style( 'skillshare-main', SSB_URL . '/assets/css/main.css', array( 'skillshare-theme' ), SSB_VERSION );
}
add_action( 'wp_enqueue_scripts', 'ssb_enqueue_assets' );

/**
 * templates/ 配下のテンプレートを固定ページに割り当てる.
 *
 * WordPress は page-{slug}.php をテーマ直下からしか探さないため、
 * ssb_get_managed_pages()（inc/booking/install.php）の定義を見て
 * templates/ 配下から読み込む。
 *
 * @param string $template 既定のテンプレートパス。
 * @return string
 */
function ssb_template_include( $template ) {
	// /courses/{id} は固定ページではなくリライトルール経由で来る。
	if ( (int) get_query_var( 'ssb_course_id' ) > 0 ) {
		$single = SSB_PATH . '/templates/single-course.php';

		if ( file_exists( $single ) ) {
			return $single;
		}
	}

	if ( ! is_page() ) {
		return $template;
	}

	$pages = ssb_get_managed_pages();
	$slug  = (string) get_post_field( 'post_name', get_queried_object_id() );

	if ( empty( $pages[ $slug ]['template'] ) ) {
		return $template;
	}

	$path = SSB_PATH . '/templates/' . $pages[ $slug ]['template'];

	return file_exists( $path ) ? $path : $template;
}
add_filter( 'template_include', 'ssb_template_include' );
