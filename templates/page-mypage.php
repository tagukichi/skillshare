<?php
/**
 * 講師マイページ（/mypage）.
 *
 * アクセス制限は inc/booking/instructor.php の ssb_restrict_mypage() が
 * template_redirect で行う。ここに来る時点で承認済み講師であることが保証される。
 *
 * @package skillshare
 */

defined( 'ABSPATH' ) || exit;

$ssb_me = ssb_current_instructor();

if ( ! $ssb_me ) {
	// 通常ここには来ないが、念のため。
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

$ssb_tabs = array(
	'profile' => 'プロフィール',
	'courses' => '講座管理',
);

$ssb_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'profile';
if ( ! isset( $ssb_tabs[ $ssb_tab ] ) ) {
	$ssb_tab = 'profile';
}

$ssb_feedback = ssb_get_mypage_feedback();
$ssb_errors   = isset( $ssb_feedback['errors'] ) ? (array) $ssb_feedback['errors'] : array();
$ssb_input    = isset( $ssb_feedback['input'] ) ? (array) $ssb_feedback['input'] : array();

$ssb_msg = isset( $_GET['ssb_msg'] ) ? sanitize_key( wp_unslash( $_GET['ssb_msg'] ) ) : '';

$ssb_notices = array(
	'profile_saved'      => array( 'success', 'プロフィールを保存しました。' ),
	'course_created'     => array( 'success', '講座を作成しました。' ),
	'course_updated'     => array( 'success', '講座を保存しました。' ),
	'course_published'   => array( 'success', '講座を公開しました。' ),
	'course_unpublished' => array( 'success', '講座を非公開にしました。' ),
	'course_forbidden'   => array( 'error', '対象の講座が見つかりません。' ),
	'error'              => array( 'error', '処理できませんでした。' ),
);

get_header();
?>

<h1 class="ssb-page__title">マイページ</h1>

<nav class="ssb-tabs">
	<?php foreach ( $ssb_tabs as $ssb_key => $ssb_label ) : ?>
		<a class="ssb-tabs__item <?php echo $ssb_tab === $ssb_key ? 'is-active' : ''; ?>"
			href="<?php echo esc_url( ssb_mypage_url( array( 'tab' => $ssb_key ) ) ); ?>">
			<?php echo esc_html( $ssb_label ); ?>
		</a>
	<?php endforeach; ?>
</nav>

<?php if ( isset( $ssb_notices[ $ssb_msg ] ) ) : ?>
	<div class="ssb-notice ssb-notice--<?php echo esc_attr( $ssb_notices[ $ssb_msg ][0] ); ?>">
		<p><?php echo esc_html( $ssb_notices[ $ssb_msg ][1] ); ?></p>
	</div>
<?php endif; ?>

<?php if ( $ssb_errors ) : ?>
	<div class="ssb-notice ssb-notice--error">
		<p>入力内容をご確認ください。</p>
		<ul>
			<?php foreach ( $ssb_errors as $ssb_error ) : ?>
				<li><?php echo esc_html( $ssb_error ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
<?php endif; ?>

<?php if ( 'profile' === $ssb_tab ) : ?>

	<?php
	$ssb_v_name  = isset( $ssb_input['display_name'] ) ? $ssb_input['display_name'] : $ssb_me->display_name;
	$ssb_v_email = isset( $ssb_input['email'] ) ? $ssb_input['email'] : $ssb_me->email;
	$ssb_v_prof  = isset( $ssb_input['profile'] ) ? $ssb_input['profile'] : (string) $ssb_me->profile;
	?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'ssb_save_profile', 'ssb_profile_nonce' ); ?>
		<input type="hidden" name="action" value="ssb_save_profile">

		<div class="ssb-field">
			<label class="ssb-field__label" for="ssb-display-name">表示名<span class="ssb-field__required">必須</span></label>
			<input class="ssb-input" type="text" id="ssb-display-name" name="display_name" maxlength="100" required
				value="<?php echo esc_attr( $ssb_v_name ); ?>">
			<p class="ssb-field__hint">受講者に表示される名前です。</p>
		</div>

		<div class="ssb-field">
			<label class="ssb-field__label" for="ssb-email">メールアドレス<span class="ssb-field__required">必須</span></label>
			<input class="ssb-input" type="email" id="ssb-email" name="email" maxlength="255" required
				value="<?php echo esc_attr( $ssb_v_email ); ?>">
			<p class="ssb-field__hint">予約通知の宛先です。ログイン用のアドレスとは別に管理されます。</p>
		</div>

		<div class="ssb-field">
			<label class="ssb-field__label" for="ssb-profile">自己紹介<span class="ssb-field__required">必須</span></label>
			<textarea class="ssb-textarea" id="ssb-profile" name="profile" required><?php echo esc_textarea( $ssb_v_prof ); ?></textarea>
		</div>

		<p><button type="submit" class="ssb-button">保存する</button></p>
	</form>

<?php endif; ?>

<?php if ( 'courses' === $ssb_tab ) : ?>

	<?php
	$ssb_course_param = isset( $_GET['course'] ) ? sanitize_text_field( wp_unslash( $_GET['course'] ) ) : '';
	$ssb_is_new       = ( 'new' === $ssb_course_param );
	$ssb_editing      = null;

	if ( '' !== $ssb_course_param && ! $ssb_is_new ) {
		// 他人の講座IDを渡されても掴めない。
		$ssb_editing = ssb_get_own_course( absint( $ssb_course_param ), $ssb_me->id );
	}
	?>

	<?php if ( $ssb_is_new || $ssb_editing ) : ?>

		<?php
		$ssb_c_id       = $ssb_editing ? (int) $ssb_editing->id : 0;
		$ssb_c_title    = isset( $ssb_input['title'] ) ? $ssb_input['title'] : ( $ssb_editing ? $ssb_editing->title : '' );
		$ssb_c_desc     = isset( $ssb_input['description'] ) ? $ssb_input['description'] : ( $ssb_editing ? (string) $ssb_editing->description : '' );
		$ssb_c_content  = isset( $ssb_input['content'] ) ? $ssb_input['content'] : ( $ssb_editing ? (string) $ssb_editing->content : '' );
		$ssb_c_target   = isset( $ssb_input['target'] ) ? $ssb_input['target'] : ( $ssb_editing ? (string) $ssb_editing->target : '' );
		$ssb_c_price    = isset( $ssb_input['price'] ) ? $ssb_input['price'] : ( $ssb_editing ? (string) $ssb_editing->price : '' );
		$ssb_c_duration = isset( $ssb_input['duration_min'] ) ? $ssb_input['duration_min'] : ( $ssb_editing ? (string) $ssb_editing->duration_min : '60' );
		$ssb_c_status   = isset( $ssb_input['status'] ) ? $ssb_input['status'] : ( $ssb_editing ? $ssb_editing->status : 'draft' );
		$ssb_c_image    = $ssb_editing ? ssb_course_image_url( $ssb_editing, 'medium' ) : '';
		?>

		<h2 class="ssb-section__title"><?php echo $ssb_editing ? '講座を編集' : '講座を作成'; ?></h2>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<?php wp_nonce_field( 'ssb_save_course', 'ssb_course_nonce' ); ?>
			<input type="hidden" name="action" value="ssb_save_course">
			<input type="hidden" name="course_id" value="<?php echo esc_attr( (string) $ssb_c_id ); ?>">

			<div class="ssb-field">
				<label class="ssb-field__label" for="ssb-course-title">講座タイトル<span class="ssb-field__required">必須</span></label>
				<input class="ssb-input" type="text" id="ssb-course-title" name="title" maxlength="255" required
					value="<?php echo esc_attr( $ssb_c_title ); ?>">
			</div>

			<div class="ssb-field">
				<label class="ssb-field__label" for="ssb-course-image">イメージ画像</label>
				<?php if ( $ssb_c_image ) : ?>
					<p class="ssb-course-form__preview">
						<img src="<?php echo esc_url( $ssb_c_image ); ?>" alt="現在のイメージ画像">
					</p>
					<p>
						<label>
							<input type="checkbox" name="remove_image" value="1"> この画像を削除する
						</label>
					</p>
				<?php endif; ?>
				<input class="ssb-input" type="file" id="ssb-course-image" name="image" accept="image/jpeg,image/png,image/webp,image/gif">
				<p class="ssb-field__hint">
					JPEG / PNG / WebP / GIF、<?php echo esc_html( (string) (int) ( SSB_COURSE_MAX_IMAGE_SIZE / MB_IN_BYTES ) ); ?> MB まで。
					新しい画像を選ぶと差し替わります。横長（16:9 程度）がおすすめです。
				</p>
			</div>

			<div class="ssb-field">
				<label class="ssb-field__label" for="ssb-course-desc">概要</label>
				<textarea class="ssb-textarea" id="ssb-course-desc" name="description"><?php echo esc_textarea( $ssb_c_desc ); ?></textarea>
				<p class="ssb-field__hint">一覧に表示される短い紹介文です。</p>
			</div>

			<div class="ssb-field">
				<label class="ssb-field__label" for="ssb-course-content">内容詳細</label>
				<textarea class="ssb-textarea" id="ssb-course-content" name="content"><?php echo esc_textarea( $ssb_c_content ); ?></textarea>
				<p class="ssb-field__hint">当日の進め方、話せるテーマ、事前に準備いただきたいことなど。</p>
			</div>

			<div class="ssb-field">
				<label class="ssb-field__label" for="ssb-course-target">こんな方におすすめ</label>
				<textarea class="ssb-textarea" id="ssb-course-target" name="target"><?php echo esc_textarea( $ssb_c_target ); ?></textarea>
				<p class="ssb-field__hint">想定している受講者像をご記入ください。</p>
			</div>

			<div class="ssb-field">
				<label class="ssb-field__label" for="ssb-course-price">価格（税込・円）<span class="ssb-field__required">必須</span></label>
				<input class="ssb-input" type="number" id="ssb-course-price" name="price" required
					min="<?php echo esc_attr( (string) SSB_COURSE_MIN_PRICE ); ?>"
					max="<?php echo esc_attr( (string) SSB_COURSE_MAX_PRICE ); ?>" step="1"
					value="<?php echo esc_attr( $ssb_c_price ); ?>">
				<p class="ssb-field__hint">カード決済の下限があるため <?php echo esc_html( (string) SSB_COURSE_MIN_PRICE ); ?> 円以上で設定してください。</p>
			</div>

			<div class="ssb-field">
				<label class="ssb-field__label" for="ssb-course-duration">所要時間（分）<span class="ssb-field__required">必須</span></label>
				<input class="ssb-input" type="number" id="ssb-course-duration" name="duration_min" required
					min="1" max="<?php echo esc_attr( (string) SSB_COURSE_MAX_DURATION ); ?>" step="1"
					value="<?php echo esc_attr( $ssb_c_duration ); ?>">
				<p class="ssb-field__hint">予約枠を作るときの1コマの長さになります。</p>
			</div>

			<div class="ssb-field">
				<label class="ssb-field__label" for="ssb-course-status">公開状態</label>
				<select class="ssb-select" id="ssb-course-status" name="status">
					<?php foreach ( ssb_course_statuses() as $ssb_key => $ssb_label ) : ?>
						<option value="<?php echo esc_attr( $ssb_key ); ?>" <?php selected( $ssb_c_status, $ssb_key ); ?>>
							<?php echo esc_html( $ssb_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="ssb-field__hint">公開中にすると講座一覧とトップページに表示されます。</p>
			</div>

			<p>
				<button type="submit" class="ssb-button">保存する</button>
				<a class="ssb-button ssb-button--secondary" href="<?php echo esc_url( ssb_mypage_url( array( 'tab' => 'courses' ) ) ); ?>">キャンセル</a>
			</p>
		</form>

	<?php else : ?>

		<?php $ssb_courses = ssb_get_courses_by_instructor( $ssb_me->id ); ?>

		<p>
			<a class="ssb-button" href="<?php echo esc_url( ssb_mypage_url( array( 'tab' => 'courses', 'course' => 'new' ) ) ); ?>">
				新しい講座を作る
			</a>
		</p>

		<?php if ( ! $ssb_courses ) : ?>
			<p class="ssb-muted">まだ講座がありません。「新しい講座を作る」から登録してください。</p>
		<?php else : ?>
			<table class="ssb-table">
				<thead>
					<tr>
						<th scope="col" style="width:80px;">画像</th>
						<th scope="col">講座</th>
						<th scope="col">価格</th>
						<th scope="col">所要時間</th>
						<th scope="col">状態</th>
						<th scope="col">操作</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ssb_courses as $ssb_course ) : ?>
						<?php $ssb_thumb = ssb_course_image_url( $ssb_course, 'thumbnail' ); ?>
						<tr>
							<td>
								<?php if ( $ssb_thumb ) : ?>
									<img class="ssb-thumb" src="<?php echo esc_url( $ssb_thumb ); ?>" alt="">
								<?php else : ?>
									<span class="ssb-muted">—</span>
								<?php endif; ?>
							</td>
							<td>
								<strong><?php echo esc_html( $ssb_course->title ); ?></strong>
								<?php if ( '' !== (string) $ssb_course->description ) : ?>
									<br><span class="ssb-muted"><?php echo esc_html( wp_trim_words( (string) $ssb_course->description, 30, '…' ) ); ?></span>
								<?php endif; ?>
								<?php if ( 'published' === $ssb_course->status ) : ?>
									<br><a href="<?php echo esc_url( ssb_course_url( $ssb_course->id ) ); ?>" target="_blank" rel="noopener">公開ページを見る</a>
								<?php endif; ?>
							</td>
							<td class="ssb-price"><?php echo esc_html( number_format( (int) $ssb_course->price ) ); ?> 円</td>
							<td><?php echo esc_html( (string) (int) $ssb_course->duration_min ); ?> 分</td>
							<td><?php echo esc_html( ssb_course_status_label( $ssb_course->status ) ); ?></td>
							<td>
								<a class="ssb-button ssb-button--secondary" href="<?php echo esc_url( ssb_mypage_url( array( 'tab' => 'courses', 'course' => (string) $ssb_course->id ) ) ); ?>">編集</a>

								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
									<?php wp_nonce_field( 'ssb_toggle_course', 'ssb_toggle_nonce' ); ?>
									<input type="hidden" name="action" value="ssb_toggle_course">
									<input type="hidden" name="course_id" value="<?php echo esc_attr( (string) $ssb_course->id ); ?>">
									<button type="submit" class="ssb-button ssb-button--secondary">
										<?php echo 'published' === $ssb_course->status ? '非公開にする' : '公開する'; ?>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

	<?php endif; ?>

<?php endif; ?>

<?php
get_footer();
