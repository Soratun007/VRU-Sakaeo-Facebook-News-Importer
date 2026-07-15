<?php
/**
 * Plugin Name: VRU Sakaeo Facebook News Importer
 * Description: Production image hotfix for importing selected VRU Sakaeo Facebook Page posts into WordPress news.
 * Version: 2.2.1
 * Author: VRU Sakaeo
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: vru-sakaeo-fb-news
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VRU_Sakaeo_Facebook_News_Importer {
	private const OPTION_SETTINGS            = 'vru_sakaeo_fb_news_settings';
	private const OPTION_LOGS                = 'vru_sakaeo_fb_news_logs';
	private const NONCE_ACTION               = 'vru_sakaeo_fb_news_import';
	private const REPAIR_NONCE_ACTION        = 'vru_sakaeo_fb_news_repair';
	private const CAPABILITY                 = 'vru_import_facebook_news';
	private const GRAPH_VERSION              = 'v25.0';
	private const MAX_LOGS                   = 100;
	private const MAX_IMPORT_URLS            = 20;
	private const MAX_IMAGE_BYTES            = 10485760;
	private const PAGE_POST_SEARCH_PAGE_SIZE = 25;
	private const PAGE_POST_SEARCH_PAGES     = 8;
	private const MONTHLY_POST_PAGE_SIZE     = 100;
	private const MONTHLY_POST_MAX_PAGES     = 12;
	private const MAX_MONTHLY_IMPORT_POSTS   = 50;
	private const TITLE_MAX_CHARS            = 150;
	private const FACEBOOK_POST_FIELDS       = 'id,from{id,name},message,created_time,permalink_url,full_picture,attachments{media,target{id},type,url,title,description,subattachments{media,target{id},type,url,title,description}}';
	private const ALLOWED_GALLERY_SIZES      = array( 'medium_large', 'large', 'full' );

	private const FACEBOOK_HOST_SUFFIXES = array(
		'facebook.com',
	);

	private const FACEBOOK_IMAGE_HOST_SUFFIXES = array(
		'facebook.com',
		'fbcdn.net',
		'fbsbx.com',
	);

	private array $last_results               = array();
	private string $runtime_page_access_token = '';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_pages' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_administrator_capability' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'purge_legacy_access_token' ), 2 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_import_request' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
		add_action( 'wp_ajax_vru_fb_repair_post', array( $this, 'handle_repair_ajax' ) );
		add_filter( 'option_page_capability_vru_sakaeo_fb_news_settings_group', array( $this, 'settings_capability' ) );
	}

	public static function activate(): void {
		self::ensure_administrator_capability();
		self::purge_legacy_access_token();
	}

	public static function deactivate(): void {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->remove_cap( self::CAPABILITY );
		}
	}

	public static function ensure_administrator_capability(): void {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( self::CAPABILITY ) ) {
			$role->add_cap( self::CAPABILITY );
		}
	}

	public static function purge_legacy_access_token(): void {
		$settings = get_option( self::OPTION_SETTINGS, array() );
		if ( is_array( $settings ) && array_key_exists( 'access_token', $settings ) ) {
			unset( $settings['access_token'] );
			update_option( self::OPTION_SETTINGS, $settings, false );
		}
	}

	public function settings_capability(): string {
		return self::CAPABILITY;
	}

	public function register_admin_pages(): void {
		add_menu_page(
			'นำเข้าข่าวจาก Facebook',
			'นำเข้าข่าว Facebook',
			self::CAPABILITY,
			'vru-sakaeo-fb-news-importer',
			array( $this, 'render_import_page' ),
			'dashicons-facebook-alt',
			26
		);

		add_submenu_page(
			'vru-sakaeo-fb-news-importer',
			'ซ่อมรูปข่าวเดิม',
			'ซ่อมรูปข่าวเดิม',
			self::CAPABILITY,
			'vru-sakaeo-fb-news-repair',
			array( $this, 'render_repair_page' )
		);

		add_submenu_page(
			'vru-sakaeo-fb-news-importer',
			'ตั้งค่าการนำเข้า',
			'ตั้งค่า',
			self::CAPABILITY,
			'vru-sakaeo-fb-news-settings',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings(): void {
		register_setting(
			'vru_sakaeo_fb_news_settings_group',
			self::OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->default_settings(),
			)
		);
	}

	public function sanitize_settings( $settings ): array {
		$defaults = $this->default_settings();
		$settings = is_array( $settings ) ? $settings : array();

		$gallery_size = isset( $settings['gallery_size'] ) ? sanitize_key( (string) $settings['gallery_size'] ) : $defaults['gallery_size'];
		if ( ! in_array( $gallery_size, self::ALLOWED_GALLERY_SIZES, true ) ) {
			$gallery_size = $defaults['gallery_size'];
		}

		return array(
			'category_id'                 => isset( $settings['category_id'] ) ? absint( $settings['category_id'] ) : $defaults['category_id'],
			'author_id'                   => isset( $settings['author_id'] ) ? absint( $settings['author_id'] ) : $defaults['author_id'],
			'max_images'                  => isset( $settings['max_images'] ) ? max( 1, min( 10, absint( $settings['max_images'] ) ) ) : $defaults['max_images'],
			'gallery_size'                => $gallery_size,
			'include_featured_in_gallery' => ! empty( $settings['include_featured_in_gallery'] ) ? 1 : 0,
			'show_source'                 => ! empty( $settings['show_source'] ) ? 1 : 0,
		);
	}

	public function handle_import_request(): void {
		$is_link_import    = ! empty( $_POST['vru_sakaeo_fb_news_import_submit'] );
		$is_monthly_import = ! empty( $_POST['vru_sakaeo_fb_news_monthly_import_submit'] );

		if ( ! $is_link_import && ! $is_monthly_import ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to import news.', 'vru-sakaeo-fb-news' ) );
		}

		check_admin_referer( self::NONCE_ACTION, 'vru_sakaeo_fb_news_nonce' );

		if ( $is_monthly_import ) {
			$month           = isset( $_POST['facebook_month'] ) ? sanitize_text_field( wp_unslash( $_POST['facebook_month'] ) ) : '';
			$raw_ids         = isset( $_POST['facebook_post_ids'] ) && is_array( $_POST['facebook_post_ids'] ) ? wp_unslash( $_POST['facebook_post_ids'] ) : array();
			$raw_categories  = isset( $_POST['facebook_post_categories'] ) && is_array( $_POST['facebook_post_categories'] ) ? wp_unslash( $_POST['facebook_post_categories'] ) : array();
			$ids             = array();
			$post_categories = array();

			foreach ( $raw_ids as $raw_id ) {
				$id = sanitize_text_field( (string) $raw_id );
				if ( '' !== $id ) {
					$ids[] = $id;
				}
			}

			foreach ( $raw_categories as $raw_post_id => $raw_category_id ) {
				$post_id     = sanitize_text_field( (string) $raw_post_id );
				$category_id = absint( $raw_category_id );
				if ( '' !== $post_id && $this->is_valid_category_id( $category_id ) ) {
					$post_categories[ $post_id ] = $category_id;
				}
			}

			$results = $this->import_selected_month_posts( $month, array_values( array_unique( $ids ) ), $post_categories );
			set_transient( 'vru_sakaeo_fb_news_last_results_' . get_current_user_id(), $results, 5 * MINUTE_IN_SECONDS );

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'               => 'vru-sakaeo-fb-news-importer',
						'tab'                => 'monthly',
						'facebook_month'     => $month,
						'vru_fb_news_notice' => 'done',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$raw_urls    = isset( $_POST['facebook_urls'] ) ? sanitize_textarea_field( wp_unslash( $_POST['facebook_urls'] ) ) : '';
		$urls        = $this->split_urls( $raw_urls );
		$category_id = isset( $_POST['facebook_category_id'] ) ? absint( wp_unslash( $_POST['facebook_category_id'] ) ) : 0;
		if ( ! $this->is_valid_category_id( $category_id ) ) {
			$category_id = 0;
		}
		$results = array();

		if ( count( $urls ) > self::MAX_IMPORT_URLS ) {
			$results[] = $this->result( '', 'error', 'จำกัดการนำเข้าไม่เกิน ' . self::MAX_IMPORT_URLS . ' ลิงก์ต่อรอบ ระบบจะประมวลผลเฉพาะ ' . self::MAX_IMPORT_URLS . ' ลิงก์แรก' );
			$urls      = array_slice( $urls, 0, self::MAX_IMPORT_URLS );
		}

		if ( empty( $urls ) ) {
			$results[] = $this->result( '', 'error', 'กรุณาวางลิงก์โพสต์ Facebook อย่างน้อย 1 ลิงก์' );
		} else {
			foreach ( $urls as $url ) {
				$results[] = $this->import_from_url( $url, $category_id );
			}
		}

		$this->last_results = $results;
		set_transient( 'vru_sakaeo_fb_news_last_results_' . get_current_user_id(), $results, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'               => 'vru-sakaeo-fb-news-importer',
					'tab'                => 'links',
					'vru_fb_news_notice' => 'done',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function render_admin_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only state set by this plugin's post-import redirect.
		if ( empty( $_GET['vru_fb_news_notice'] ) || 'done' !== $_GET['vru_fb_news_notice'] ) {
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>ดำเนินการนำเข้าข่าวเรียบร้อยแล้ว กรุณาดูผลลัพธ์ในตารางด้านล่าง</p></div>';
	}

	public function render_import_page(): void {
		$settings = $this->get_settings();
		$results  = get_transient( 'vru_sakaeo_fb_news_last_results_' . get_current_user_id() );
		$logs     = $this->get_logs();
		$missing  = $this->missing_secret_labels();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation state.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'links';
		if ( ! in_array( $tab, array( 'links', 'monthly' ), true ) ) {
			$tab = 'links';
		}
		?>
		<div class="wrap">
			<h1>นำเข้าข่าวจาก Facebook</h1>
			<nav class="nav-tab-wrapper" style="margin-bottom: 16px;">
				<a class="nav-tab <?php echo 'links' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=vru-sakaeo-fb-news-importer&tab=links' ) ); ?>">นำเข้าจากลิงก์</a>
				<a class="nav-tab <?php echo 'monthly' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=vru-sakaeo-fb-news-importer&tab=monthly' ) ); ?>">เลือกจากโพสต์รายเดือน</a>
				<a class="nav-tab" href="<?php echo esc_url( admin_url( 'admin.php?page=vru-sakaeo-fb-news-settings' ) ); ?>">ตั้งค่า</a>
			</nav>

			<?php if ( ! empty( $missing ) ) : ?>
				<div class="notice notice-warning">
					<p>ยังไม่ได้ตั้งค่า secret ที่จำเป็น: <code><?php echo esc_html( implode( ', ', $missing ) ); ?></code> กรุณาไปที่หน้า <a href="<?php echo esc_url( admin_url( 'admin.php?page=vru-sakaeo-fb-news-settings' ) ); ?>">ตั้งค่า</a> เพื่อดูวิธีตั้งค่าใน <code>wp-config.php</code></p>
				</div>
			<?php endif; ?>

			<?php
			if ( 'monthly' === $tab ) {
				$this->render_monthly_import_tab();
			} else {
				$this->render_link_import_tab();
			}
			?>

			<?php if ( is_array( $results ) && ! empty( $results ) ) : ?>
				<h2>ผลลัพธ์ล่าสุด</h2>
				<?php $this->render_results_table( $results ); ?>
			<?php endif; ?>

			<h2>Import Log ล่าสุด</h2>
			<?php $this->render_results_table( $logs ); ?>
		</div>
		<?php
	}

	private function render_link_import_tab(): void {
		$settings   = $this->get_settings();
		$categories = get_categories( array( 'hide_empty' => false ) );
		?>
		<p>วางลิงก์โพสต์จากเพจ Facebook ทีละหลายบรรทัด ระบบจะสร้างข่าว WordPress และเผยแพร่ทันที</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="vru-sakaeo-fb-news-importer" />
			<?php wp_nonce_field( self::NONCE_ACTION, 'vru_sakaeo_fb_news_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="facebook_urls">ลิงก์โพสต์ Facebook</label></th>
					<td>
						<textarea id="facebook_urls" name="facebook_urls" rows="10" class="large-text code" placeholder="https://www.facebook.com/vrusakaeo/posts/..."></textarea>
						<p class="description">ใส่ 1 ลิงก์ต่อ 1 บรรทัด หากเป็นลิงก์แบบ pfbid แล้วจับคู่ไม่ได้ ให้ใช้แท็บเลือกจากโพสต์รายเดือน</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="facebook_category_id">หมวดหมู่ข่าวสำหรับรอบนี้</label></th>
					<td>
						<select id="facebook_category_id" name="facebook_category_id">
							<?php foreach ( $categories as $category ) : ?>
								<option value="<?php echo esc_attr( $category->term_id ); ?>" <?php selected( $settings['category_id'], $category->term_id ); ?>>
									<?php echo esc_html( $category->name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">ใช้เฉพาะการนำเข้ารอบนี้ ไม่เปลี่ยนหมวดหมู่ข่าวเริ่มต้นในหน้าตั้งค่า</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'นำเข้าและเผยแพร่', 'primary', 'vru_sakaeo_fb_news_import_submit' ); ?>
		</form>
		<?php
	}

	private function render_monthly_import_tab(): void {
		$settings   = $this->get_settings();
		$categories = get_categories( array( 'hide_empty' => false ) );
		$month      = isset( $_GET['facebook_month'] ) ? sanitize_text_field( wp_unslash( $_GET['facebook_month'] ) ) : current_time( 'Y-m' );
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			$month = current_time( 'Y-m' );
		}

		$posts = array();
		$error = '';
		if ( ! empty( $_GET['vru_fb_fetch_month'] ) ) {
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
				$error = 'ตรวจสอบความปลอดภัยไม่ผ่าน กรุณาลองใหม่อีกครั้ง';
			} else {
				$posts_result = $this->fetch_facebook_posts_by_month( $month );
				if ( is_wp_error( $posts_result ) ) {
					$error = $posts_result->get_error_message();
				} else {
					$posts = $posts_result;
				}
			}
		}
		?>
		<p>เลือกเดือนเพื่อดึงโพสต์จากเพจ แล้วติ๊กโพสต์ที่ต้องการนำเข้าและเผยแพร่</p>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin-bottom: 16px;">
			<input type="hidden" name="page" value="vru-sakaeo-fb-news-importer" />
			<input type="hidden" name="tab" value="monthly" />
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<label for="facebook_month"><strong>เดือน</strong></label>
			<input id="facebook_month" type="month" name="facebook_month" value="<?php echo esc_attr( $month ); ?>" />
			<?php submit_button( 'ดึงโพสต์จากเพจ', 'secondary', 'vru_fb_fetch_month', false ); ?>
		</form>

		<?php if ( '' !== $error ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
		<?php endif; ?>

		<?php if ( ! empty( $posts ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="vru-sakaeo-fb-news-importer" />
				<input type="hidden" name="facebook_month" value="<?php echo esc_attr( $month ); ?>" />
				<?php wp_nonce_field( self::NONCE_ACTION, 'vru_sakaeo_fb_news_nonce' ); ?>
				<?php $this->render_monthly_posts_table( $posts, $categories, (int) $settings['category_id'] ); ?>
				<?php submit_button( 'นำเข้าและเผยแพร่โพสต์ที่เลือก', 'primary', 'vru_sakaeo_fb_news_monthly_import_submit' ); ?>
			</form>
		<?php elseif ( ! empty( $_GET['vru_fb_fetch_month'] ) && '' === $error ) : ?>
			<p>ไม่พบโพสต์ในเดือนนี้</p>
		<?php endif; ?>
		<?php
	}

	private function render_monthly_posts_table( array $posts, array $categories, int $default_category_id ): void {
		?>
		<div style="margin: 12px 0; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
			<label for="vru-fb-bulk-category"><strong>ตั้งหมวดหมู่ทุกโพสต์</strong></label>
			<select id="vru-fb-bulk-category">
				<?php foreach ( $categories as $category ) : ?>
					<option value="<?php echo esc_attr( $category->term_id ); ?>" <?php selected( $default_category_id, $category->term_id ); ?>><?php echo esc_html( $category->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="button" onclick="var value=document.getElementById('vru-fb-bulk-category').value; document.querySelectorAll('.vru-fb-category-select').forEach(function(el){el.value=value;});">ใช้กับทุกรายการ</button>
		</div>
		<table class="widefat striped">
			<thead>
				<tr>
					<td class="manage-column column-cb check-column"><input type="checkbox" onclick="var checked=this.checked; document.querySelectorAll('.vru-fb-post-check').forEach(function(el){el.checked = checked;});" /></td>
					<th>วันที่โพสต์</th>
					<th>ตัวอย่างข้อความ</th>
					<th>หมวดหมู่ข่าว</th>
					<th>รูป</th>
					<th>สถานะ</th>
					<th>ลิงก์</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $posts as $post ) : ?>
					<?php
					$post_id       = isset( $post['id'] ) ? sanitize_text_field( (string) $post['id'] ) : '';
					$permalink_url = ! empty( $post['permalink_url'] ) ? esc_url_raw( (string) $post['permalink_url'] ) : '';
					$message       = ! empty( $post['message'] ) ? wp_strip_all_tags( (string) $post['message'] ) : '';
					$preview       = $this->trim_multibyte_text( preg_replace( '/\s+/u', ' ', $message ), 180 );
					$image_count   = count( $this->collect_image_candidates( $post ) );
					$existing_id   = $this->find_existing_post( $post_id, $permalink_url );
					?>
					<tr>
						<th scope="row" class="check-column">
							<?php if ( ! $existing_id && $post_id ) : ?>
								<input class="vru-fb-post-check" type="checkbox" name="facebook_post_ids[]" value="<?php echo esc_attr( $post_id ); ?>" />
							<?php endif; ?>
						</th>
						<td><?php echo esc_html( $this->format_facebook_created_time( $post ) ); ?></td>
						<td><?php echo esc_html( '' !== $preview ? $preview : '(ไม่มีข้อความ)' ); ?></td>
						<td>
							<?php if ( ! $existing_id && $post_id ) : ?>
								<select class="vru-fb-category-select" name="facebook_post_categories[<?php echo esc_attr( $post_id ); ?>]">
									<?php foreach ( $categories as $category ) : ?>
										<option value="<?php echo esc_attr( $category->term_id ); ?>" <?php selected( $default_category_id, $category->term_id ); ?>><?php echo esc_html( $category->name ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								-
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) $image_count ); ?></td>
						<td>
							<?php if ( $existing_id ) : ?>
								นำเข้าแล้ว: <a href="<?php echo esc_url( get_edit_post_link( $existing_id ) ); ?>">แก้ไขข่าว</a>
							<?php else : ?>
								พร้อมนำเข้า
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $permalink_url ) : ?>
								<a href="<?php echo esc_url( $permalink_url ); ?>" target="_blank" rel="noopener">เปิดโพสต์</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public function render_settings_page(): void {
		$settings   = $this->get_settings();
		$categories = get_categories( array( 'hide_empty' => false ) );
		$users      = get_users( array( 'fields' => array( 'ID', 'display_name', 'user_login' ) ) );
		$missing    = $this->missing_secret_labels();
		?>
		<div class="wrap">
			<h1>ตั้งค่าการนำเข้าข่าวจาก Facebook</h1>
			<div class="notice notice-info">
				<p>เพื่อความปลอดภัยสูงสุด ปลั๊กอินนี้ไม่เก็บ Page access token ในฐานข้อมูล WordPress และไม่แสดง token ในหน้าเว็บ ให้ตั้งค่า secret ใน <code>wp-config.php</code> หรือ environment variable ของโฮสต์เท่านั้น</p>
			</div>
			<table class="widefat striped" style="max-width: 900px; margin: 16px 0;">
				<thead>
					<tr>
						<th>Secret</th>
						<th>สถานะ</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><code>VRU_FB_PAGE_ACCESS_TOKEN</code></td>
						<td><?php echo $this->has_page_access_token() ? '<span style="color:#008a20;">configured</span>' : '<span style="color:#b32d2e;">missing</span>'; ?></td>
					</tr>
					<tr>
						<td><code>VRU_FB_PAGE_ID</code></td>
						<td><?php echo '' !== $this->get_required_page_id() ? '<span style="color:#008a20;">configured</span>' : '<span style="color:#b32d2e;">missing</span>'; ?></td>
					</tr>
					<tr>
						<td><code>VRU_FB_APP_SECRET</code></td>
						<td><?php echo '' !== $this->get_app_secret() ? '<span style="color:#008a20;">configured</span>' : '<span style="color:#b32d2e;">missing</span>'; ?></td>
					</tr>
					<tr>
						<td><code>VRU_FB_APP_ID</code> <span class="description">(optional, ไม่จำเป็นสำหรับการตรวจการเชื่อมต่อ v2.2)</span></td>
						<td><?php echo '' !== $this->get_app_id() ? '<span style="color:#008a20;">configured</span>' : '<span style="color:#666;">missing</span>'; ?></td>
					</tr>
				</tbody>
			</table>
			<?php $this->render_token_diagnostics(); ?>
			<?php if ( ! empty( $missing ) ) : ?>
				<p>เพิ่มตัวอย่างนี้ใน <code>wp-config.php</code> เหนือบรรทัด <code>/* That's all, stop editing! */</code> แล้วแทนค่าจริงบนเซิร์ฟเวอร์เท่านั้น:</p>
				<pre><code>define( 'VRU_FB_PAGE_ID', 'PAGE_ID_HERE' );
define( 'VRU_FB_PAGE_ACCESS_TOKEN', 'PAGE_OR_SYSTEM_USER_TOKEN_HERE' );
define( 'VRU_FB_APP_SECRET', 'APP_SECRET_HERE' );
define( 'VRU_FB_APP_ID', 'APP_ID_HERE' ); // optional สำหรับ token diagnostics</code></pre>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'vru_sakaeo_fb_news_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="category_id">หมวดหมู่ข่าวเริ่มต้น</label></th>
						<td>
							<select id="category_id" name="<?php echo esc_attr( self::OPTION_SETTINGS ); ?>[category_id]">
								<?php foreach ( $categories as $category ) : ?>
									<option value="<?php echo esc_attr( $category->term_id ); ?>" <?php selected( $settings['category_id'], $category->term_id ); ?>>
										<?php echo esc_html( $category->name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="author_id">ผู้เขียนข่าวเริ่มต้น</label></th>
						<td>
							<select id="author_id" name="<?php echo esc_attr( self::OPTION_SETTINGS ); ?>[author_id]">
								<?php foreach ( $users as $user ) : ?>
									<option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $settings['author_id'], $user->ID ); ?>>
										<?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="max_images">จำนวนรูปสูงสุด</label></th>
						<td>
							<input id="max_images" name="<?php echo esc_attr( self::OPTION_SETTINGS ); ?>[max_images]" type="number" min="1" max="10" value="<?php echo esc_attr( $settings['max_images'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gallery_size">ขนาดภาพในคลังรูป</label></th>
						<td>
							<select id="gallery_size" name="<?php echo esc_attr( self::OPTION_SETTINGS ); ?>[gallery_size]">
								<option value="medium_large" <?php selected( $settings['gallery_size'], 'medium_large' ); ?>>medium_large</option>
								<option value="large" <?php selected( $settings['gallery_size'], 'large' ); ?>>large (แนะนำ)</option>
								<option value="full" <?php selected( $settings['gallery_size'], 'full' ); ?>>full</option>
							</select>
							<p class="description">ค่าเริ่มต้น large ให้ภาพชัดขึ้นและยังใช้ responsive srcset ของ WordPress</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Featured Image ในคลังรูป</th>
						<td>
							<label>
								<input name="<?php echo esc_attr( self::OPTION_SETTINGS ); ?>[include_featured_in_gallery]" type="checkbox" value="1" <?php checked( $settings['include_featured_in_gallery'], 1 ); ?> />
								แสดง Featured Image ซ้ำในคลังรูป (ค่าเริ่มต้นปิด)
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">ลิงก์ต้นทางท้ายข่าว</th>
						<td>
							<label>
								<input name="<?php echo esc_attr( self::OPTION_SETTINGS ); ?>[show_source]" type="checkbox" value="1" <?php checked( $settings['show_source'], 1 ); ?> />
								แสดงลิงก์โพสต์ Facebook ต้นทางท้ายข่าว
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button( 'บันทึกการตั้งค่า' ); ?>
			</form>
		</div>
		<?php
	}

	public function render_repair_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to repair news images.', 'vru-sakaeo-fb-news' ) );
		}

		$rows  = $this->scan_existing_news_images();
		$nonce = wp_create_nonce( self::REPAIR_NONCE_ACTION );
		?>
		<div class="wrap">
			<h1>ซ่อมรูปข่าวเดิม</h1>
			<div class="notice notice-info inline"><p>ระบบจะดึงรูปจาก Facebook ใหม่ สร้าง revision และแก้เฉพาะ Featured Image กับ gallery ของปลั๊กอิน โดยไม่ลบไฟล์สื่อเก่าและไม่เปลี่ยนหัวข้อ หมวดหมู่ สถานะ วันที่ หรือข้อความข่าว</p></div>
			<p>
				<button type="button" class="button button-primary" id="vru-fb-repair-selected">ซ่อมข่าวที่เลือก</button>
				<button type="button" class="button" id="vru-fb-repair-cancel" hidden>ยกเลิกหลังรายการนี้</button>
				<span class="spinner" id="vru-fb-repair-spinner"></span>
			</p>
			<p id="vru-fb-repair-progress" aria-live="polite"></p>
			<table class="widefat striped">
				<thead><tr><td class="check-column"><input type="checkbox" id="vru-fb-repair-all" /></td><th>ข่าว</th><th>Facebook Post ID</th><th>ภาพเดิม</th><th>ภาพไม่ซ้ำที่พบ</th><th>ผลสแกน</th></tr></thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="6">ยังไม่มีข่าวที่นำเข้าจาก Facebook</td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr data-post-id="<?php echo esc_attr( $row['post_id'] ); ?>">
						<th scope="row" class="check-column"><input class="vru-fb-repair-check" type="checkbox" value="<?php echo esc_attr( $row['post_id'] ); ?>" /></th>
						<td><a href="<?php echo esc_url( get_edit_post_link( $row['post_id'] ) ); ?>"><?php echo esc_html( $row['title'] ); ?></a></td>
						<td><code><?php echo esc_html( $row['facebook_post_id'] ); ?></code></td>
						<td><?php echo esc_html( (string) $row['attachment_count'] ); ?></td>
						<td><?php echo esc_html( (string) $row['unique_count'] ); ?></td>
						<td class="vru-fb-repair-status"><?php echo esc_html( $row['status_label'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<script>
		(function () {
			'use strict';
			var running = false;
			var cancelled = false;
			var all = document.getElementById('vru-fb-repair-all');
			var button = document.getElementById('vru-fb-repair-selected');
			var cancel = document.getElementById('vru-fb-repair-cancel');
			var spinner = document.getElementById('vru-fb-repair-spinner');
			var progress = document.getElementById('vru-fb-repair-progress');
			if (!button) { return; }
			if (all) {
				all.addEventListener('change', function () {
					document.querySelectorAll('.vru-fb-repair-check').forEach(function (item) { item.checked = all.checked; });
				});
			}
			cancel.addEventListener('click', function () { cancelled = true; });
			button.addEventListener('click', async function () {
				if (running) { return; }
				var selected = Array.prototype.slice.call(document.querySelectorAll('.vru-fb-repair-check:checked'));
				if (!selected.length) { progress.textContent = 'กรุณาเลือกข่าวอย่างน้อย 1 รายการ'; return; }
				if (!window.confirm('ยืนยันซ่อม Featured Image และ gallery ของข่าวที่เลือก? ระบบจะสร้าง revision ก่อนแก้ไข')) { return; }
				running = true;
				cancelled = false;
				button.disabled = true;
				cancel.hidden = false;
				spinner.classList.add('is-active');
				for (var index = 0; index < selected.length; index++) {
					if (cancelled) { break; }
					var input = selected[index];
					var row = input.closest('tr');
					var status = row.querySelector('.vru-fb-repair-status');
					progress.textContent = 'กำลังซ่อม ' + (index + 1) + ' จาก ' + selected.length;
					status.textContent = 'กำลังซ่อม...';
					var body = new URLSearchParams({action: 'vru_fb_repair_post', nonce: <?php echo wp_json_encode( $nonce ); ?>, post_id: input.value});
					try {
						var response = await fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'}, body: body.toString()});
						var result = await response.json();
						status.textContent = result && result.data && result.data.message ? result.data.message : (result.success ? 'ซ่อมสำเร็จ' : 'ซ่อมไม่สำเร็จ');
					} catch (error) {
						status.textContent = 'เชื่อมต่อไม่สำเร็จ กรุณาลองใหม่';
					}
				}
				progress.textContent = cancelled ? 'ยกเลิกแล้วหลังจบรายการล่าสุด' : 'ดำเนินการครบแล้ว';
				running = false;
				button.disabled = false;
				cancel.hidden = true;
				spinner.classList.remove('is-active');
			});
		}());
		</script>
		<?php
	}

	public function handle_repair_ajax(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'คุณไม่มีสิทธิ์ซ่อมรูปข่าว' ), 403 );
		}
		check_ajax_referer( self::REPAIR_NONCE_ACTION, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$result  = $this->repair_existing_post_images( $post_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $this->redact_sensitive_text( $result->get_error_message() ) ), 400 );
		}

		wp_send_json_success( $result );
	}

	private function scan_existing_news_images(): array {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Bounded admin-only repair scan.
				'posts_per_page' => 300,
				'meta_key'       => '_vru_fb_post_id',
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		$rows = array();
		foreach ( $posts as $post ) {
			$analysis = $this->analyze_existing_post_images( (int) $post->ID );
			$rows[]   = array(
				'post_id'          => (int) $post->ID,
				'title'            => get_the_title( $post ),
				'facebook_post_id' => sanitize_text_field( (string) get_post_meta( $post->ID, '_vru_fb_post_id', true ) ),
				'attachment_count' => $analysis['attachment_count'],
				'unique_count'     => $analysis['unique_count'],
				'status_label'     => $analysis['status_label'],
			);
		}

		return $rows;
	}

	private function analyze_existing_post_images( int $post_id ): array {
		$attachments = get_children(
			array(
				'post_parent'    => $post_id,
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'numberposts'    => -1,
			)
		);
		$hashes      = array();
		foreach ( array_map( 'absint', array_keys( $attachments ) ) as $media_id ) {
			$hash = $this->ensure_attachment_hash( $media_id );
			if ( $hash ) {
				$hashes[] = $hash;
			}
		}
		$unique_hashes = array_values( array_unique( $hashes ) );
		$post          = get_post( $post_id );
		$content       = $post ? (string) $post->post_content : '';
		$gallery_ids   = $this->extract_gallery_media_ids( $content );
		$featured_id   = (int) get_post_thumbnail_id( $post_id );
		$labels        = array();
		if ( count( $hashes ) > count( $unique_hashes ) ) {
			$labels[] = 'ภาพซ้ำ';
		}
		if ( false !== strpos( $content, '"sizeSlug":"medium"' ) || false !== strpos( $content, 'size-medium' ) ) {
			$labels[] = 'ใช้ medium';
		}
		if ( $featured_id && in_array( $featured_id, $gallery_ids, true ) ) {
			$labels[] = 'Featured ซ้ำใน gallery';
		}

		return array(
			'attachment_count' => count( $attachments ),
			'unique_count'     => count( $unique_hashes ),
			'status_label'     => empty( $labels ) ? 'ปกติ' : implode( ', ', $labels ),
		);
	}

	private function repair_existing_post_images( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'vru_fb_repair_missing_post', 'ไม่พบข่าว WordPress ที่เลือก' );
		}

		$facebook_post_id = sanitize_text_field( (string) get_post_meta( $post_id, '_vru_fb_post_id', true ) );
		if ( '' === $facebook_post_id ) {
			return new WP_Error( 'vru_fb_repair_missing_facebook_id', 'ข่าวนี้ไม่มี Facebook Post ID' );
		}

		$facebook_post = $this->fetch_facebook_post( $facebook_post_id );
		if ( is_wp_error( $facebook_post ) ) {
			return $facebook_post;
		}
		if ( ! $this->facebook_post_matches_configured_page( $facebook_post ) ) {
			return new WP_Error( 'vru_fb_repair_wrong_page', 'โพสต์นี้ไม่ได้มาจาก Page ID ที่อนุญาตไว้' );
		}

		$settings   = $this->get_settings();
		$candidates = array_slice( $this->collect_image_candidates( $facebook_post ), 0, (int) $settings['max_images'] );
		if ( empty( $candidates ) ) {
			return new WP_Error( 'vru_fb_repair_no_images', 'ไม่พบรูปจาก Facebook จึงยังไม่แก้ข่าวเดิม' );
		}

		$this->analyze_existing_post_images( $post_id );
		$media = $this->sideload_images( $candidates, $post_id, get_the_title( $post_id ) );
		if ( ! empty( $media['failed'] ) || empty( $media['items'] ) ) {
			return new WP_Error( 'vru_fb_repair_download_failed', 'ดาวน์โหลดหรือตรวจสอบรูปไม่ครบ จึงยังไม่แก้เนื้อหาข่าวเดิม กรุณาลองใหม่' );
		}

		$featured_id = (int) $media['items'][0]['media_id'];
		$gallery     = $this->build_gallery_block( $media['items'], (string) $settings['gallery_size'], $featured_id, ! empty( $settings['include_featured_in_gallery'] ) );
		$new_content = $this->replace_plugin_gallery( (string) $post->post_content, $gallery );

		wp_save_post_revision( $post_id );
		$updated = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $new_content,
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			return new WP_Error( 'vru_fb_repair_update_failed', 'บันทึก gallery ที่ซ่อมแล้วไม่สำเร็จ' );
		}

		set_post_thumbnail( $post_id, $featured_id );
		update_post_meta( $post_id, '_vru_fb_managed_version', '2.2.1' );

		$unique_count  = count( $media['items'] );
		$gallery_count = ! empty( $settings['include_featured_in_gallery'] ) ? $unique_count : max( 0, $unique_count - 1 );
		return array( 'message' => 'ซ่อมสำเร็จ: ภาพไม่ซ้ำ ' . $unique_count . ' ภาพ, gallery ' . $gallery_count . ' ภาพ' );
	}

	private function extract_gallery_media_ids( string $content ): array {
		$ids = array();
		if ( preg_match_all( '/<!-- wp:gallery\b.*?<!-- \/wp:gallery -->/s', $content, $galleries ) ) {
			foreach ( $galleries[0] as $gallery ) {
				if ( preg_match_all( '/"id"\s*:\s*(\d+)/', $gallery, $matches ) ) {
					$ids = array_merge( $ids, array_map( 'absint', $matches[1] ) );
				}
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	private function replace_plugin_gallery( string $content, string $gallery ): string {
		$replacement = '' !== $gallery ? "\n" . $gallery : '';
		$marked      = '/\s*<!-- vru-fb-gallery:start -->.*?<!-- vru-fb-gallery:end -->\s*/s';
		if ( preg_match( $marked, $content ) ) {
			return trim( (string) preg_replace( $marked, $replacement, $content, 1 ) );
		}

		$legacy = '/\s*<!-- wp:gallery\b.*?<!-- \/wp:gallery -->\s*$/s';
		if ( preg_match( $legacy, $content ) ) {
			return trim( (string) preg_replace( $legacy, $replacement, $content, 1 ) );
		}

		return trim( $content . $replacement );
	}

	private function has_page_access_token(): bool {
		return '' !== $this->get_source_access_token();
	}

	private function get_source_access_token(): string {
		return $this->get_secret_value( 'VRU_FB_PAGE_ACCESS_TOKEN' );
	}

	private function get_runtime_page_access_token() {
		if ( '' !== $this->runtime_page_access_token ) {
			return $this->runtime_page_access_token;
		}

		$source_token = $this->get_source_access_token();
		if ( '' === $source_token ) {
			return new WP_Error( 'vru_fb_missing_source_token', 'ยังไม่ได้ตั้งค่า VRU_FB_PAGE_ACCESS_TOKEN' );
		}

		$derived_token = $this->fetch_page_access_token_from_source_token( $source_token );
		if ( ! is_wp_error( $derived_token ) && '' !== $derived_token ) {
			$this->runtime_page_access_token = $derived_token;
			return $this->runtime_page_access_token;
		}

		$this->runtime_page_access_token = $source_token;
		return $this->runtime_page_access_token;
	}

	private function fetch_page_access_token_from_source_token( string $source_token ) {
		$page_id    = $this->get_required_page_id();
		$app_secret = $this->get_app_secret();

		if ( '' === $page_id || '' === $app_secret ) {
			return new WP_Error( 'vru_fb_missing_page_token_source', 'ยังไม่ได้ตั้งค่า Page ID หรือ App Secret' );
		}

		$endpoint = sprintf( 'https://graph.facebook.com/%s/%s', self::GRAPH_VERSION, rawurlencode( $page_id ) );
		$url      = add_query_arg(
			array(
				'fields'          => 'id,name,access_token',
				'appsecret_proof' => $this->build_appsecret_proof( $source_token ),
			),
			$endpoint
		);

		$body         = $this->fetch_facebook_json_object_with_token( $url, $source_token );
		$direct_error = is_wp_error( $body ) ? $body : null;

		if ( is_array( $body ) && ! empty( $body['access_token'] ) && is_scalar( $body['access_token'] ) ) {
			return sanitize_text_field( (string) $body['access_token'] );
		}

		$accounts_endpoint = sprintf( 'https://graph.facebook.com/%s/me/accounts', self::GRAPH_VERSION );
		$accounts_url      = add_query_arg(
			array(
				'fields'          => 'id,name,access_token',
				'limit'           => 100,
				'appsecret_proof' => $this->build_appsecret_proof( $source_token ),
			),
			$accounts_endpoint
		);

		$accounts_body = $this->fetch_facebook_json_object_with_token( $accounts_url, $source_token );
		if ( ! is_wp_error( $accounts_body ) && ! empty( $accounts_body['data'] ) && is_array( $accounts_body['data'] ) ) {
			foreach ( $accounts_body['data'] as $account ) {
				if (
					is_array( $account )
					&& ! empty( $account['id'] )
					&& hash_equals( $page_id, sanitize_text_field( (string) $account['id'] ) )
					&& ! empty( $account['access_token'] )
					&& is_scalar( $account['access_token'] )
				) {
					return sanitize_text_field( (string) $account['access_token'] );
				}
			}
		}

		if ( is_wp_error( $direct_error ) && is_wp_error( $accounts_body ) ) {
			return new WP_Error(
				'vru_fb_page_token_derivation_failed',
				'ไม่สามารถดึง Page access token จาก token ต้นทางได้: ' . $direct_error->get_error_message() . ' / ' . $accounts_body->get_error_message()
			);
		}

		return new WP_Error( 'vru_fb_page_token_not_returned', 'ไม่พบ Page access token จาก token ต้นทาง หากใช้ System User token ให้เพิ่มสิทธิ์ business_management หรือใช้ Page access token โดยตรง' );
	}

	private function build_appsecret_proof( string $access_token ): string {
		return hash_hmac( 'sha256', $access_token, $this->get_app_secret() );
	}

	private function get_required_page_id(): string {
		return $this->get_secret_value( 'VRU_FB_PAGE_ID' );
	}

	private function get_app_secret(): string {
		return $this->get_secret_value( 'VRU_FB_APP_SECRET' );
	}

	private function get_app_id(): string {
		return $this->get_secret_value( 'VRU_FB_APP_ID' );
	}

	private function get_secret_value( string $name ): string {
		if ( defined( $name ) && is_scalar( constant( $name ) ) ) {
			return trim( (string) constant( $name ) );
		}

		$value = getenv( $name );
		return false === $value ? '' : trim( (string) $value );
	}

	private function render_token_diagnostics(): void {

		if ( ! $this->has_page_access_token() || '' === $this->get_required_page_id() || '' === $this->get_app_secret() ) {
			echo '<p class="description">ตั้งค่า Page ID, access token และ App Secret ให้ครบ เพื่อทดสอบการเชื่อมต่อกับเพจ</p>';
			return;
		}

		$diagnostics = $this->fetch_operational_page_diagnostics();
		if ( is_wp_error( $diagnostics ) ) {
			echo '<div class="notice notice-warning inline"><p>ตรวจการเชื่อมต่อ Facebook Page ไม่สำเร็จ: ' . esc_html( $diagnostics->get_error_message() ) . '</p></div>';
			return;
		}

		$page_id    = isset( $diagnostics['id'] ) ? sanitize_text_field( (string) $diagnostics['id'] ) : '';
		$page_name  = isset( $diagnostics['name'] ) ? sanitize_text_field( (string) $diagnostics['name'] ) : '';
		$token_mode = isset( $diagnostics['token_mode'] ) ? sanitize_text_field( (string) $diagnostics['token_mode'] ) : '';
		?>
		<table class="widefat striped" style="max-width: 900px; margin: 16px 0;">
			<thead>
				<tr><th colspan="2">สถานะการเชื่อมต่อ Facebook Page</th></tr>
			</thead>
			<tbody>
				<tr>
					<td>การเชื่อมต่อ</td>
					<td><span style="color:#008a20;">พร้อมใช้งาน</span></td>
				</tr>
				<tr>
					<td>เพจ</td>
					<td><?php echo esc_html( $page_name ? $page_name : 'ไม่ระบุ' ); ?></td>
				</tr>
				<tr>
					<td>Page ID</td>
					<td><?php echo esc_html( $page_id ); ?></td>
				</tr>
				<tr>
					<td>Page access token</td>
					<td><?php echo esc_html( $token_mode ); ?></td>
				</tr>
				<tr>
					<td>App Secret Proof</td>
					<td><span style="color:#008a20;">ผ่านการตรวจสอบ</span></td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	private function fetch_operational_page_diagnostics() {
		$access_token = $this->get_runtime_page_access_token();
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$page_id  = $this->get_required_page_id();
		$endpoint = sprintf( 'https://graph.facebook.com/%s/%s', self::GRAPH_VERSION, rawurlencode( $page_id ) );
		$url      = add_query_arg(
			array(
				'fields'          => 'id,name',
				'appsecret_proof' => $this->build_appsecret_proof( $access_token ),
			),
			$endpoint
		);

		$body = $this->fetch_facebook_json_object( $url, $access_token );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$actual_page_id = isset( $body['id'] ) ? sanitize_text_field( (string) $body['id'] ) : '';
		if ( '' === $actual_page_id || ! hash_equals( $page_id, $actual_page_id ) ) {
			return new WP_Error( 'vru_fb_diagnostics_page_mismatch', 'Page ID ที่ตอบกลับไม่ตรงกับ VRU_FB_PAGE_ID' );
		}

		$source_token = $this->get_source_access_token();
		$token_mode   = hash_equals( $source_token, $access_token ) ? 'ใช้ Page token ที่ตั้งค่าโดยตรง' : 'แปลงจาก System User token สำเร็จ';

		return array(
			'id'         => $actual_page_id,
			'name'       => isset( $body['name'] ) ? sanitize_text_field( (string) $body['name'] ) : '',
			'token_mode' => $token_mode,
		);
	}

	private function missing_secret_labels(): array {
		$missing = array();

		if ( ! $this->has_page_access_token() ) {
			$missing[] = 'VRU_FB_PAGE_ACCESS_TOKEN';
		}
		if ( '' === $this->get_required_page_id() ) {
			$missing[] = 'VRU_FB_PAGE_ID';
		}
		if ( '' === $this->get_app_secret() ) {
			$missing[] = 'VRU_FB_APP_SECRET';
		}

		return $missing;
	}

	private function facebook_post_matches_configured_page( array $facebook_post ): bool {
		$expected_page_id = $this->get_required_page_id();
		$actual_page_id   = isset( $facebook_post['from']['id'] ) ? sanitize_text_field( (string) $facebook_post['from']['id'] ) : '';

		return '' !== $expected_page_id && '' !== $actual_page_id && hash_equals( $expected_page_id, $actual_page_id );
	}

	private function import_from_url( string $url, int $category_id = 0 ): array {
		$url = esc_url_raw( trim( $url ) );

		$missing = $this->missing_secret_labels();
		if ( ! empty( $missing ) ) {
			return $this->log_result( $this->result( $url, 'error', 'ยังไม่ได้ตั้งค่า secret ที่จำเป็น: ' . implode( ', ', $missing ) ) );
		}

		if ( ! $this->is_allowed_facebook_url( $url ) ) {
			return $this->log_result( $this->result( $url, 'error', 'รับเฉพาะลิงก์โพสต์จากโดเมน facebook.com เท่านั้น' ) );
		}

		$post_id = $this->extract_facebook_post_id( $url );

		$existing_post_id = $post_id ? $this->find_existing_post( $post_id, $url ) : 0;
		if ( $existing_post_id ) {
			return $this->log_result(
				$this->result(
					$url,
					'skipped',
					'ข้ามรายการนี้ เพราะเคยนำเข้าแล้ว',
					$existing_post_id,
					get_the_title( $existing_post_id )
				)
			);
		}

		$facebook_post = $post_id ? $this->fetch_facebook_post( $post_id ) : new WP_Error( 'vru_fb_missing_post_id', 'ไม่สามารถอ่านรหัสโพสต์จากลิงก์นี้ได้' );
		if ( is_wp_error( $facebook_post ) ) {
			$facebook_post = $this->fetch_facebook_post_from_page_feed( $url, $post_id );
		}
		if ( is_wp_error( $facebook_post ) ) {
			return $this->log_result( $this->result( $url, 'error', $facebook_post->get_error_message() ) );
		}

		if ( ! $this->facebook_post_matches_configured_page( $facebook_post ) ) {
			return $this->log_result( $this->result( $url, 'error', 'โพสต์นี้ไม่ได้มาจาก Page ID ที่อนุญาตไว้ จึงไม่สร้างข่าว' ) );
		}

		$facebook_post_id = isset( $facebook_post['id'] ) ? sanitize_text_field( $facebook_post['id'] ) : $post_id;
		$permalink        = ! empty( $facebook_post['permalink_url'] ) ? esc_url_raw( $facebook_post['permalink_url'] ) : $url;

		$existing_post_id = $this->find_existing_post( $facebook_post_id, $permalink );
		if ( $existing_post_id ) {
			return $this->log_result(
				$this->result(
					$url,
					'skipped',
					'ข้ามรายการนี้ เพราะเคยนำเข้าแล้ว',
					$existing_post_id,
					get_the_title( $existing_post_id )
				)
			);
		}

		return $this->import_facebook_post( $facebook_post, $url, $category_id );
	}

	private function import_facebook_post( array $facebook_post, string $source_url = '', int $category_id = 0 ): array {
		$settings         = $this->get_settings();
		$post_category_id = $this->resolve_category_id( $category_id, (int) $settings['category_id'] );

		if ( ! $this->facebook_post_matches_configured_page( $facebook_post ) ) {
			return $this->log_result( $this->result( $source_url, 'error', 'โพสต์นี้ไม่ได้มาจาก Page ID ที่อนุญาตไว้ จึงไม่สร้างข่าว' ) );
		}

		$facebook_post_id = isset( $facebook_post['id'] ) ? sanitize_text_field( (string) $facebook_post['id'] ) : '';
		$permalink        = ! empty( $facebook_post['permalink_url'] ) ? esc_url_raw( (string) $facebook_post['permalink_url'] ) : esc_url_raw( $source_url );

		if ( '' === $facebook_post_id ) {
			return $this->log_result( $this->result( $source_url, 'error', 'โพสต์นี้ไม่มีรหัสจาก Facebook จึงไม่สามารถนำเข้าได้' ) );
		}

		$existing_post_id = $this->find_existing_post( $facebook_post_id, $permalink );
		if ( $existing_post_id ) {
			return $this->log_result(
				$this->result(
					$source_url ? $source_url : $permalink,
					'skipped',
					'ข้ามรายการนี้ เพราะเคยนำเข้าแล้ว',
					$existing_post_id,
					get_the_title( $existing_post_id )
				)
			);
		}

		$message    = ! empty( $facebook_post['message'] ) ? wp_strip_all_tags( (string) $facebook_post['message'] ) : '';
		$title      = $this->build_title( $message, $facebook_post );
		$candidates = array_slice( $this->collect_image_candidates( $facebook_post ), 0, (int) $settings['max_images'] );
		$content    = $this->build_content( $message, $permalink, (bool) $settings['show_source'] );

		$new_post_id = wp_insert_post(
			array(
				'post_title'    => $title,
				'post_content'  => $content,
				'post_status'   => 'publish',
				'post_author'   => (int) $settings['author_id'],
				'post_category' => array_filter( array( $post_category_id ) ),
				'post_date'     => $this->facebook_date_for_wordpress( $facebook_post ),
				'meta_input'    => array(
					'_vru_fb_post_id'         => $facebook_post_id,
					'_vru_fb_permalink'       => $permalink,
					'_vru_fb_imported_at'     => current_time( 'mysql' ),
					'_vru_fb_managed_version' => '2.2.1',
				),
			),
			true
		);

		if ( is_wp_error( $new_post_id ) ) {
			return $this->log_result( $this->result( $source_url, 'error', 'สร้างข่าวไม่สำเร็จ: ' . $new_post_id->get_error_message() ) );
		}

		$media = $this->sideload_images( $candidates, $new_post_id, $title );
		if ( ! empty( $media['items'] ) ) {
			$featured_id = (int) $media['items'][0]['media_id'];
			set_post_thumbnail( $new_post_id, $featured_id );
			$this->append_gallery_to_post(
				$new_post_id,
				$media['items'],
				(string) $settings['gallery_size'],
				$featured_id,
				! empty( $settings['include_featured_in_gallery'] )
			);
		}

		$message_text = 'นำเข้าสำเร็จ';
		if ( count( $candidates ) < 4 ) {
			$message_text .= ' (พบรูปประกอบน้อยกว่า 4 รูป)';
		}
		if ( ! empty( $media['failed'] ) ) {
			$message_text .= ' (บางรูปไม่ผ่านการตรวจสอบความปลอดภัยหรือดาวน์โหลดไม่สำเร็จ)';
		}
		if ( ! empty( $media['duplicates'] ) ) {
			$message_text .= ' (ตัดภาพซ้ำ ' . absint( $media['duplicates'] ) . ' ภาพ)';
		}

		return $this->log_result( $this->result( $source_url ? $source_url : $permalink, 'success', $message_text, $new_post_id, $title ) );
	}

	private function import_selected_month_posts( string $month, array $selected_ids, array $post_categories = array() ): array {
		if ( empty( $selected_ids ) ) {
			return array( $this->result( '', 'error', 'กรุณาเลือกโพสต์ที่ต้องการนำเข้าอย่างน้อย 1 รายการ' ) );
		}

		if ( count( $selected_ids ) > self::MAX_MONTHLY_IMPORT_POSTS ) {
			return array( $this->result( '', 'error', 'จำกัดการนำเข้าไม่เกิน ' . self::MAX_MONTHLY_IMPORT_POSTS . ' โพสต์ต่อรอบ' ) );
		}

		$posts = $this->fetch_facebook_posts_by_month( $month );
		if ( is_wp_error( $posts ) ) {
			return array( $this->result( '', 'error', $posts->get_error_message() ) );
		}

		$post_map = array();
		foreach ( $posts as $post ) {
			if ( is_array( $post ) && ! empty( $post['id'] ) ) {
				$post_map[ (string) $post['id'] ] = $post;
			}
		}

		$results = array();
		foreach ( $selected_ids as $selected_id ) {
			if ( empty( $post_map[ $selected_id ] ) ) {
				$results[] = $this->log_result( $this->result( '', 'error', 'ไม่พบโพสต์ที่เลือกในรายการของเดือนนี้: ' . $selected_id ) );
				continue;
			}

			$source_url  = ! empty( $post_map[ $selected_id ]['permalink_url'] ) ? esc_url_raw( (string) $post_map[ $selected_id ]['permalink_url'] ) : '';
			$category_id = isset( $post_categories[ $selected_id ] ) ? absint( $post_categories[ $selected_id ] ) : 0;
			$results[]   = $this->import_facebook_post( $post_map[ $selected_id ], $source_url, $category_id );
		}

		return $results;
	}

	private function is_valid_category_id( int $category_id ): bool {
		if ( $category_id <= 0 ) {
			return false;
		}

		$term = get_term( $category_id, 'category' );
		return $term instanceof WP_Term && ! is_wp_error( $term );
	}

	private function resolve_category_id( int $category_id, int $default_category_id ): int {
		if ( $this->is_valid_category_id( $category_id ) ) {
			return $category_id;
		}

		if ( $this->is_valid_category_id( $default_category_id ) ) {
			return $default_category_id;
		}

		$wordpress_default = absint( get_option( 'default_category', 0 ) );
		return $this->is_valid_category_id( $wordpress_default ) ? $wordpress_default : 0;
	}

	private function fetch_facebook_posts_by_month( string $month ) {
		$missing = $this->missing_secret_labels();
		if ( ! empty( $missing ) ) {
			return new WP_Error( 'vru_fb_missing_secret', 'ยังไม่ได้ตั้งค่า secret ที่จำเป็น: ' . implode( ', ', $missing ) );
		}

		$range = $this->month_range_timestamps( $month );
		if ( is_wp_error( $range ) ) {
			return $range;
		}

		$access_token = $this->get_runtime_page_access_token();
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$endpoint = sprintf( 'https://graph.facebook.com/%s/%s/posts', self::GRAPH_VERSION, rawurlencode( $this->get_required_page_id() ) );
		$url      = add_query_arg(
			array(
				'fields'          => self::FACEBOOK_POST_FIELDS,
				'since'           => $range['since'],
				'until'           => $range['until'],
				'limit'           => self::MONTHLY_POST_PAGE_SIZE,
				'appsecret_proof' => $this->build_appsecret_proof( $access_token ),
			),
			$endpoint
		);

		$posts = array();
		for ( $page = 0; $page < self::MONTHLY_POST_MAX_PAGES && $url; $page++ ) {
			$body = $this->fetch_facebook_json_object( $url, $access_token );
			if ( is_wp_error( $body ) ) {
				return $body;
			}

			if ( ! empty( $body['data'] ) && is_array( $body['data'] ) ) {
				foreach ( $body['data'] as $post ) {
					if ( is_array( $post ) && $this->facebook_post_matches_configured_page( $post ) ) {
						$posts[] = $post;
					}
				}
			}

			$url = ! empty( $body['paging']['next'] ) ? esc_url_raw( $body['paging']['next'] ) : '';
		}

		return $posts;
	}

	private function month_range_timestamps( string $month ) {
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			return new WP_Error( 'vru_fb_invalid_month', 'รูปแบบเดือนไม่ถูกต้อง' );
		}

		$timezone = wp_timezone();
		$start    = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $month . '-01 00:00:00', $timezone );
		if ( ! $start ) {
			return new WP_Error( 'vru_fb_invalid_month_date', 'ไม่สามารถอ่านเดือนที่เลือกได้' );
		}

		$end = $start->modify( 'first day of next month' );

		return array(
			'since' => $start->getTimestamp(),
			'until' => $end->getTimestamp(),
		);
	}

	private function fetch_facebook_post( string $post_id ) {
		$access_token = $this->get_runtime_page_access_token();
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$endpoint = sprintf( 'https://graph.facebook.com/%s/%s', self::GRAPH_VERSION, rawurlencode( $post_id ) );
		$url      = add_query_arg(
			array(
				'fields'          => self::FACEBOOK_POST_FIELDS,
				'appsecret_proof' => $this->build_appsecret_proof( $access_token ),
			),
			$endpoint
		);

		return $this->fetch_facebook_json_object( $url, $access_token );
	}

	private function fetch_facebook_post_from_page_feed( string $source_url, string $candidate_id = '' ) {
		$access_token = $this->get_runtime_page_access_token();
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$endpoint = sprintf( 'https://graph.facebook.com/%s/%s/posts', self::GRAPH_VERSION, rawurlencode( $this->get_required_page_id() ) );
		$url      = add_query_arg(
			array(
				'fields'          => self::FACEBOOK_POST_FIELDS,
				'limit'           => self::PAGE_POST_SEARCH_PAGE_SIZE,
				'appsecret_proof' => $this->build_appsecret_proof( $access_token ),
			),
			$endpoint
		);

		for ( $page = 0; $page < self::PAGE_POST_SEARCH_PAGES && $url; $page++ ) {
			$body = $this->fetch_facebook_json_object( $url, $access_token );
			if ( is_wp_error( $body ) ) {
				return $body;
			}

			$posts = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
			foreach ( $posts as $post ) {
				if ( is_array( $post ) && $this->facebook_post_matches_import_url( $post, $source_url, $candidate_id ) ) {
					return $post;
				}
			}

			$url = ! empty( $body['paging']['next'] ) ? esc_url_raw( $body['paging']['next'] ) : '';
		}

		return new WP_Error( 'vru_fb_post_not_found_in_feed', 'ไม่พบโพสต์นี้ในรายการโพสต์ล่าสุดของเพจ หากเป็นลิงก์แบบ pfbid ให้ใช้แท็บเลือกจากโพสต์รายเดือนแล้วเลือกเดือนของโพสต์นั้น' );
	}

	private function fetch_facebook_json_object( string $url, string $access_token = '' ) {
		if ( '' === $access_token ) {
			$access_token = $this->get_runtime_page_access_token();
			if ( is_wp_error( $access_token ) ) {
				return $access_token;
			}
		}

		return $this->fetch_facebook_json_object_with_token( $url, $access_token );
	}

	private function fetch_facebook_json_object_with_token( string $url, string $access_token ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'            => 30,
				'redirection'        => 0,
				'reject_unsafe_urls' => true,
				'headers'            => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'vru_fb_http_error', 'เชื่อมต่อ Facebook Graph API ไม่สำเร็จ: ' . $this->redact_sensitive_text( $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$error_code    = isset( $body['error']['code'] ) ? absint( $body['error']['code'] ) : 0;
			$error_subcode = isset( $body['error']['error_subcode'] ) ? absint( $body['error']['error_subcode'] ) : 0;
			$user_message  = isset( $body['error']['error_user_msg'] ) ? sanitize_text_field( $this->redact_sensitive_text( (string) $body['error']['error_user_msg'] ) ) : '';
			$message       = 'Facebook Graph API ปฏิเสธคำขอ กรุณาตรวจสอบ token, permission, Page ID และ App Secret Proof';
			if ( $error_code ) {
				$message .= ' (รหัส ' . $error_code . ')';
			}
			if ( $error_subcode ) {
				$message .= ' (subcode ' . $error_subcode . ')';
			}
			if ( 190 === $error_code && 2069032 === $error_subcode ) {
				$message .= ': Meta ต้องการ Page access token สำหรับเพจแบบ New Page Experience หากใช้ System User token ให้เพิ่มสิทธิ์ business_management และให้ System User มี asset ของเพจนี้ หรือใช้ Page access token โดยตรง';
			}
			if ( $user_message ) {
				$message .= ': ' . $user_message;
			}
			return new WP_Error( 'vru_fb_api_error', $message );
		}

		if ( ! is_array( $body ) || empty( $body['id'] ) ) {
			if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
				return $body;
			}

			return new WP_Error( 'vru_fb_empty_response', 'ไม่พบข้อมูลจาก Facebook' );
		}

		return $body;
	}

	private function split_urls( string $raw_urls ): array {
		$lines = preg_split( '/\R+/', $raw_urls );
		$urls  = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$urls[] = $line;
			}
		}

		return array_values( array_unique( $urls ) );
	}

	private function is_allowed_facebook_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( (string) $parts['scheme'] ) ) {
			return false;
		}

		if ( empty( $parts['host'] ) ) {
			return false;
		}

		return $this->host_matches_suffixes( (string) $parts['host'], self::FACEBOOK_HOST_SUFFIXES );
	}

	private function host_matches_suffixes( string $host, array $suffixes ): bool {
		$host = strtolower( trim( $host, " \t\n\r\0\x0B." ) );

		foreach ( $suffixes as $suffix ) {
			$suffix = strtolower( trim( (string) $suffix, " \t\n\r\0\x0B." ) );
			if ( $host === $suffix || substr( $host, -1 * ( strlen( $suffix ) + 1 ) ) === '.' . $suffix ) {
				return true;
			}
		}

		return false;
	}

	private function facebook_post_matches_import_url( array $post, string $source_url, string $candidate_id = '' ): bool {
		$post_id       = isset( $post['id'] ) ? sanitize_text_field( (string) $post['id'] ) : '';
		$permalink_url = ! empty( $post['permalink_url'] ) ? esc_url_raw( (string) $post['permalink_url'] ) : '';

		if ( '' !== $candidate_id && '' !== $post_id ) {
			if ( hash_equals( $post_id, $candidate_id ) || substr( $post_id, -1 * ( strlen( $candidate_id ) + 1 ) ) === '_' . $candidate_id ) {
				return true;
			}
		}

		$source_normalized    = $this->normalize_facebook_url_for_match( $source_url );
		$permalink_normalized = $this->normalize_facebook_url_for_match( $permalink_url );

		if ( '' !== $source_normalized && '' !== $permalink_normalized && hash_equals( $source_normalized, $permalink_normalized ) ) {
			return true;
		}

		if ( '' !== $candidate_id && '' !== $permalink_normalized && false !== strpos( $permalink_normalized, strtolower( $candidate_id ) ) ) {
			return true;
		}

		return '' !== $post_id && false !== strpos( $source_normalized, strtolower( $post_id ) );
	}

	private function normalize_facebook_url_for_match( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$host = strtolower( (string) $parts['host'] );
		$host = preg_replace( '/^(www|m|web)\./', '', $host );
		$path = isset( $parts['path'] ) ? rawurldecode( (string) $parts['path'] ) : '';
		$path = preg_replace( '#/+#', '/', $path );
		$path = trim( $path, '/' );

		return strtolower( $host . '/' . $path );
	}

	private function extract_facebook_post_id( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) || ! $this->host_matches_suffixes( (string) $parts['host'], self::FACEBOOK_HOST_SUFFIXES ) ) {
			return '';
		}

		$query = array();
		if ( ! empty( $parts['query'] ) ) {
			wp_parse_str( $parts['query'], $query );
		}

		foreach ( array( 'story_fbid', 'fbid', 'v' ) as $key ) {
			if ( ! empty( $query[ $key ] ) ) {
				return sanitize_text_field( (string) $query[ $key ] );
			}
		}

		$path = isset( $parts['path'] ) ? trim( $parts['path'], '/' ) : '';
		if ( '' === $path ) {
			return '';
		}

		$segments = array_values( array_filter( explode( '/', $path ) ) );
		$count    = count( $segments );

		for ( $i = 0; $i < $count; $i++ ) {
			if ( in_array( $segments[ $i ], array( 'posts', 'photos', 'videos', 'reel', 'p' ), true ) && ! empty( $segments[ $i + 1 ] ) ) {
				return sanitize_text_field( rawurldecode( $segments[ $i + 1 ] ) );
			}

			if ( 'share' === $segments[ $i ] && ! empty( $segments[ $i + 2 ] ) && in_array( $segments[ $i + 1 ], array( 'p', 'v', 'r' ), true ) ) {
				return sanitize_text_field( rawurldecode( $segments[ $i + 2 ] ) );
			}
		}

		if ( $count >= 2 && 'permalink.php' === end( $segments ) && ! empty( $query['id'] ) ) {
			return sanitize_text_field( (string) $query['id'] );
		}

		return '';
	}

	private function find_existing_post( string $facebook_post_id, string $permalink ): int {
		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'   => '_vru_fb_post_id',
						'value' => $facebook_post_id,
					),
					array(
						'key'   => '_vru_fb_permalink',
						'value' => $permalink,
					),
				),
			)
		);

		return ! empty( $query->posts ) ? (int) $query->posts[0] : 0;
	}

	private function build_title( string $message, array $facebook_post ): string {
		$title_parts = array();
		foreach ( preg_split( '/\R+/', $message ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$title_parts[] = $line;
			$current_title = preg_replace( '/\s+/u', ' ', implode( ' ', $title_parts ) );
			if ( $this->meaningful_title_length( $current_title ) >= 35 || count( $title_parts ) >= 2 ) {
				break;
			}
		}

		if ( ! empty( $title_parts ) ) {
			$title = preg_replace( '/\s+/u', ' ', implode( ' ', $title_parts ) );
			return $this->trim_multibyte_text( $title, self::TITLE_MAX_CHARS );
		}

		if ( ! empty( $facebook_post['attachments']['data'][0]['title'] ) ) {
			return $this->trim_multibyte_text( wp_strip_all_tags( (string) $facebook_post['attachments']['data'][0]['title'] ), self::TITLE_MAX_CHARS );
		}

		$date = ! empty( $facebook_post['created_time'] ) ? date_i18n( 'j F Y', strtotime( $facebook_post['created_time'] ) ) : date_i18n( 'j F Y' );
		return 'ข่าวประชาสัมพันธ์ มรภ.วไลยอลงกรณ์ฯ สระแก้ว วันที่ ' . $date;
	}

	private function meaningful_title_length( string $text ): int {
		$meaningful = preg_replace( '/[^\p{L}\p{N}]+/u', '', $text );
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $meaningful, 'UTF-8' );
		}

		return strlen( $meaningful );
	}

	private function trim_multibyte_text( string $text, int $max_chars ): string {
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		if ( '' === $text ) {
			return '';
		}

		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $text, 'UTF-8' ) <= $max_chars ) {
				return $text;
			}

			return rtrim( mb_substr( $text, 0, $max_chars, 'UTF-8' ) ) . '...';
		}

		if ( strlen( $text ) <= $max_chars ) {
			return $text;
		}

		return rtrim( substr( $text, 0, $max_chars ) ) . '...';
	}

	private function build_content( string $message, string $permalink, bool $show_source ): string {
		$paragraphs = array_filter( array_map( 'trim', preg_split( '/\R{2,}|\R/', $message ) ) );
		$content    = '';

		foreach ( $paragraphs as $paragraph ) {
			$content .= '<p>' . esc_html( $paragraph ) . '</p>' . "\n";
		}

		if ( '' === $content ) {
			$content = '<p>ข่าวประชาสัมพันธ์จากมหาวิทยาลัยราชภัฏวไลยอลงกรณ์ ในพระบรมราชูปถัมภ์ สระแก้ว</p>' . "\n";
		}

		if ( $show_source && ! empty( $permalink ) ) {
			$content .= '<p><strong>ที่มา:</strong> <a href="' . esc_url( $permalink ) . '" target="_blank" rel="noopener">Facebook VRU Sakaeo</a></p>' . "\n";
		}

		return $content;
	}

	private function collect_image_urls( array $facebook_post ): array {
		return array_values(
			array_map(
				static function ( array $candidate ): string {
					return (string) $candidate['url'];
				},
				$this->collect_image_candidates( $facebook_post )
			)
		);
	}

	private function collect_image_candidates( array $facebook_post ): array {
		$candidates = array();
		$order      = 0;

		if ( ! empty( $facebook_post['attachments']['data'] ) && is_array( $facebook_post['attachments']['data'] ) ) {
			$this->collect_attachment_candidates( $facebook_post['attachments']['data'], $candidates, $order );
		}

		if ( empty( $candidates ) && ! empty( $facebook_post['full_picture'] ) ) {
			$url          = esc_url_raw( (string) $facebook_post['full_picture'] );
			$post_id      = sanitize_text_field( (string) ( $facebook_post['id'] ?? '' ) );
			$url_hash     = substr( hash( 'sha256', $this->normalize_candidate_url( $url ) ), 0, 16 );
			$candidates[] = array(
				'source_id' => 'full_picture:' . ( $post_id ? $post_id . ':' : '' ) . $url_hash,
				'url'       => $url,
				'order'     => $order,
				'kind'      => 'full_picture',
			);
		}

		$unique    = array();
		$seen_ids  = array();
		$seen_urls = array();
		foreach ( $candidates as $candidate ) {
			$url       = esc_url_raw( (string) ( $candidate['url'] ?? '' ) );
			$source_id = sanitize_text_field( (string) ( $candidate['source_id'] ?? '' ) );
			$url_key   = $this->normalize_candidate_url( $url );
			if ( ! $this->is_allowed_image_url( $url ) ) {
				continue;
			}
			if ( ( $source_id && isset( $seen_ids[ $source_id ] ) ) || ( $url_key && isset( $seen_urls[ $url_key ] ) ) ) {
				continue;
			}
			if ( $source_id ) {
				$seen_ids[ $source_id ] = true;
			}
			if ( $url_key ) {
				$seen_urls[ $url_key ] = true;
			}
			$unique[] = array(
				'source_id' => $source_id ? $source_id : 'url:' . hash( 'sha256', $url_key ),
				'url'       => $url,
				'order'     => absint( $candidate['order'] ?? count( $unique ) ),
				'kind'      => sanitize_key( (string) ( $candidate['kind'] ?? 'attachment' ) ),
			);
		}

		usort(
			$unique,
			static function ( array $left, array $right ): int {
				return $left['order'] <=> $right['order'];
			}
		);

		return $unique;
	}

	private function collect_attachment_candidates( array $attachments, array &$candidates, int &$order ): void {
		foreach ( $attachments as $attachment ) {
			if ( ! empty( $attachment['subattachments']['data'] ) && is_array( $attachment['subattachments']['data'] ) ) {
				$this->collect_attachment_candidates( $attachment['subattachments']['data'], $candidates, $order );
				continue;
			}

			$url = ! empty( $attachment['media']['image']['src'] ) ? esc_url_raw( (string) $attachment['media']['image']['src'] ) : '';
			if ( '' === $url ) {
				continue;
			}

			$target_id    = isset( $attachment['target']['id'] ) ? sanitize_text_field( (string) $attachment['target']['id'] ) : '';
			$candidates[] = array(
				'source_id' => $target_id ? 'attachment:' . $target_id : 'url:' . hash( 'sha256', $this->normalize_candidate_url( $url ) ),
				'url'       => $url,
				'order'     => $order++,
				'kind'      => 'attachment',
			);
		}
	}

	private function normalize_candidate_url( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		$host = strtolower( trim( (string) $parts['host'], '.' ) );
		$path = isset( $parts['path'] ) ? preg_replace( '#/+#', '/', rawurldecode( (string) $parts['path'] ) ) : '';
		return $host . $path;
	}

	private function sideload_images( array $candidates, int $post_id, string $title ): array {
		$result = array(
			'items'      => array(),
			'failed'     => array(),
			'duplicates' => 0,
		);
		if ( empty( $candidates ) ) {
			return $result;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$seen_hashes = array();
		$seen_media  = array();
		foreach ( $candidates as $index => $candidate ) {
			$image_url = esc_url_raw( trim( (string) ( $candidate['url'] ?? '' ) ) );
			$source_id = sanitize_text_field( (string) ( $candidate['source_id'] ?? '' ) );
			if ( ! $this->is_allowed_image_url( $image_url ) ) {
				$result['failed'][] = $source_id;
				continue;
			}

			$existing_id = $this->find_existing_media_by_source_id( $source_id );
			if ( $existing_id ) {
				$hash = $this->ensure_attachment_hash( $existing_id );
				if ( isset( $seen_media[ $existing_id ] ) || ( $hash && isset( $seen_hashes[ $hash ] ) ) ) {
					++$result['duplicates'];
					continue;
				}
				$seen_media[ $existing_id ] = true;
				if ( $hash ) {
					$seen_hashes[ $hash ] = true;
				}
				$result['items'][] = array(
					'media_id'  => $existing_id,
					'source_id' => $source_id,
					'sha256'    => $hash,
					'url'       => $image_url,
				);
				continue;
			}

			$head_check = $this->validate_remote_image_headers( $image_url );
			if ( is_wp_error( $head_check ) ) {
				$result['failed'][] = $source_id;
				continue;
			}

			$filename  = $this->image_filename( $image_url, $post_id, $index );
			$temp_file = $this->download_checked_image( $image_url, $filename );
			if ( is_wp_error( $temp_file ) ) {
				$result['failed'][] = $source_id;
				continue;
			}

			$sha256 = hash_file( 'sha256', $temp_file );
			if ( ! is_string( $sha256 ) || '' === $sha256 ) {
				wp_delete_file( $temp_file );
				$result['failed'][] = $source_id;
				continue;
			}
			if ( isset( $seen_hashes[ $sha256 ] ) ) {
				wp_delete_file( $temp_file );
				++$result['duplicates'];
				continue;
			}

			$existing_hash_id = $this->find_existing_media_by_hash( $sha256 );
			if ( $existing_hash_id ) {
				wp_delete_file( $temp_file );
				$this->add_media_source_id( $existing_hash_id, $source_id );
				$seen_hashes[ $sha256 ]          = true;
				$seen_media[ $existing_hash_id ] = true;
				$result['items'][]               = array(
					'media_id'  => $existing_hash_id,
					'source_id' => $source_id,
					'sha256'    => $sha256,
					'url'       => $image_url,
				);
				++$result['duplicates'];
				continue;
			}

			$checked_file = wp_check_filetype_and_ext( $temp_file, $filename );
			if ( empty( $checked_file['type'] ) || 0 !== strpos( $checked_file['type'], 'image/' ) ) {
				wp_delete_file( $temp_file );
				$result['failed'][] = $source_id;
				continue;
			}
			if ( ! empty( $checked_file['proper_filename'] ) ) {
				$filename = sanitize_file_name( (string) $checked_file['proper_filename'] );
			}

			$file = array(
				'name'     => $filename,
				'type'     => $checked_file['type'],
				'tmp_name' => $temp_file,
				'error'    => 0,
				'size'     => filesize( $temp_file ),
			);

			$media_id = media_handle_sideload( $file, $post_id, $title );
			if ( is_wp_error( $media_id ) ) {
				wp_delete_file( $temp_file );
				$result['failed'][] = $source_id;
				continue;
			}

			$media_id = (int) $media_id;
			$this->add_media_source_id( $media_id, $source_id );
			update_post_meta( $media_id, '_vru_fb_media_sha256', $sha256 );
			update_post_meta( $media_id, '_vru_fb_media_source_url', $image_url );
			update_post_meta( $media_id, '_wp_attachment_image_alt', sanitize_text_field( $title ) );
			$seen_hashes[ $sha256 ]  = true;
			$seen_media[ $media_id ] = true;
			$result['items'][]       = array(
				'media_id'  => $media_id,
				'source_id' => $source_id,
				'sha256'    => $sha256,
				'url'       => $image_url,
			);
		}

		return $result;
	}

	private function find_existing_media_by_source_id( string $source_id ): int {
		if ( '' === $source_id ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_vru_fb_media_source_id',
				'meta_value'     => $source_id,
				'no_found_rows'  => true,
			)
		);
		return ! empty( $ids[0] ) ? absint( $ids[0] ) : 0;
	}

	private function find_existing_media_by_hash( string $sha256 ): int {
		if ( '' === $sha256 ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_vru_fb_media_sha256',
				'meta_value'     => $sha256,
				'no_found_rows'  => true,
			)
		);
		return ! empty( $ids[0] ) ? absint( $ids[0] ) : 0;
	}

	private function ensure_attachment_hash( int $media_id ): string {
		$stored = sanitize_text_field( (string) get_post_meta( $media_id, '_vru_fb_media_sha256', true ) );
		if ( preg_match( '/^[a-f0-9]{64}$/', $stored ) ) {
			return $stored;
		}
		$file = get_attached_file( $media_id );
		if ( ! is_string( $file ) || ! is_file( $file ) ) {
			return '';
		}
		$hash = hash_file( 'sha256', $file );
		if ( ! is_string( $hash ) || '' === $hash ) {
			return '';
		}
		update_post_meta( $media_id, '_vru_fb_media_sha256', $hash );
		return $hash;
	}

	private function add_media_source_id( int $media_id, string $source_id ): void {
		if ( '' === $source_id ) {
			return;
		}
		$existing = array_map( 'strval', get_post_meta( $media_id, '_vru_fb_media_source_id', false ) );
		if ( ! in_array( $source_id, $existing, true ) ) {
			add_post_meta( $media_id, '_vru_fb_media_source_id', $source_id, false );
		}
	}

	private function is_allowed_image_url( string $image_url ): bool {
		$parts = wp_parse_url( $image_url );
		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( (string) $parts['scheme'] ) ) {
			return false;
		}

		if ( empty( $parts['host'] ) ) {
			return false;
		}

		return $this->host_matches_suffixes( (string) $parts['host'], self::FACEBOOK_IMAGE_HOST_SUFFIXES );
	}

	private function validate_remote_image_headers( string $image_url ) {
		$response = wp_safe_remote_head(
			$image_url,
			array(
				'timeout'            => 15,
				'redirection'        => 0,
				'reject_unsafe_urls' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'vru_fb_image_head_status', 'รูปประกอบตอบกลับด้วยสถานะที่ไม่อนุญาต' );
		}

		$content_type = $this->normalize_header_value( wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( '' === $content_type || 0 !== strpos( $content_type, 'image/' ) ) {
			return new WP_Error( 'vru_fb_image_type', 'ไฟล์ประกอบไม่ใช่รูปภาพ' );
		}

		$content_length = $this->normalize_header_value( wp_remote_retrieve_header( $response, 'content-length' ) );
		if ( '' !== $content_length && (int) $content_length > self::MAX_IMAGE_BYTES ) {
			return new WP_Error( 'vru_fb_image_too_large', 'รูปประกอบมีขนาดใหญ่เกินกำหนด' );
		}

		return true;
	}

	private function download_checked_image( string $image_url, string $filename ) {
		$temp_file = wp_tempnam( $filename );
		if ( ! $temp_file ) {
			return new WP_Error( 'vru_fb_image_temp_file', 'ไม่สามารถสร้างไฟล์ชั่วคราวสำหรับรูปภาพ' );
		}

		$response = wp_safe_remote_get(
			$image_url,
			array(
				'timeout'             => 30,
				'redirection'         => 0,
				'reject_unsafe_urls'  => true,
				'stream'              => true,
				'filename'            => $temp_file,
				'limit_response_size' => self::MAX_IMAGE_BYTES + 1,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $temp_file );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			wp_delete_file( $temp_file );
			return new WP_Error( 'vru_fb_image_get_status', 'ดาวน์โหลดรูปประกอบไม่สำเร็จ' );
		}

		$content_type = $this->normalize_header_value( wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( '' === $content_type || 0 !== strpos( $content_type, 'image/' ) ) {
			wp_delete_file( $temp_file );
			return new WP_Error( 'vru_fb_image_get_type', 'ไฟล์ที่ดาวน์โหลดไม่ใช่รูปภาพ' );
		}

		$file_size = filesize( $temp_file );
		if ( false === $file_size || $file_size <= 0 || $file_size > self::MAX_IMAGE_BYTES ) {
			wp_delete_file( $temp_file );
			return new WP_Error( 'vru_fb_image_get_size', 'รูปประกอบมีขนาดไม่ถูกต้องหรือใหญ่เกินกำหนด' );
		}

		return $temp_file;
	}

	private function normalize_header_value( $value ): string {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		$value = strtolower( trim( (string) $value ) );
		if ( false !== strpos( $value, ';' ) ) {
			$parts = explode( ';', $value );
			$value = trim( $parts[0] );
		}

		return $value;
	}

	private function image_filename( string $image_url, int $post_id, int $index ): string {
		$path      = wp_parse_url( $image_url, PHP_URL_PATH );
		$extension = $path ? pathinfo( $path, PATHINFO_EXTENSION ) : '';
		$extension = strtolower( preg_replace( '/[^a-z0-9]/', '', $extension ) );

		if ( ! in_array( $extension, array( 'jpg', 'jpeg', 'png', 'webp', 'gif' ), true ) ) {
			$extension = 'jpg';
		}

		return sprintf( 'vru-sakaeo-facebook-news-%d-%02d.%s', $post_id, $index + 1, $extension );
	}

	private function append_gallery_to_post( int $post_id, array $items, string $gallery_size, int $featured_id, bool $include_featured ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$gallery = $this->build_gallery_block( $items, $gallery_size, $featured_id, $include_featured );
		if ( '' === $gallery ) {
			return;
		}

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => trim( $post->post_content . "\n" . $gallery ),
			)
		);
	}

	private function build_gallery_block( array $items, string $gallery_size, int $featured_id, bool $include_featured ): string {
		if ( ! in_array( $gallery_size, self::ALLOWED_GALLERY_SIZES, true ) ) {
			$gallery_size = 'large';
		}
		$gallery_items = array_values(
			array_filter(
				$items,
				static function ( array $item ) use ( $featured_id, $include_featured ): bool {
					return $include_featured || absint( $item['media_id'] ?? 0 ) !== $featured_id;
				}
			)
		);
		if ( empty( $gallery_items ) ) {
			return '';
		}

		$attributes = wp_json_encode(
			array(
				'columns'   => 3,
				'linkTo'    => 'media',
				'sizeSlug'  => $gallery_size,
				'imageCrop' => false,
			),
			JSON_UNESCAPED_SLASHES
		);
		$gallery    = '<!-- vru-fb-gallery:start -->' . "\n";
		$gallery   .= '<!-- wp:gallery ' . $attributes . ' -->' . "\n";
		$gallery   .= '<figure class="wp-block-gallery has-nested-images columns-3">' . "\n";
		foreach ( $gallery_items as $item ) {
			$media_id  = absint( $item['media_id'] ?? 0 );
			$image     = wp_get_attachment_image( $media_id, $gallery_size, false, array( 'loading' => 'lazy' ) );
			$media_url = wp_get_attachment_url( $media_id );
			if ( ! $media_id || ! $image || ! $media_url ) {
				continue;
			}
			$image_attributes = wp_json_encode(
				array(
					'id'              => $media_id,
					'sizeSlug'        => $gallery_size,
					'linkDestination' => 'media',
				),
				JSON_UNESCAPED_SLASHES
			);
			$gallery         .= '<!-- wp:image ' . $image_attributes . ' -->' . "\n";
			$gallery         .= '<figure class="wp-block-image size-' . esc_attr( $gallery_size ) . '"><a href="' . esc_url( $media_url ) . '">' . $image . '</a></figure>' . "\n";
			$gallery         .= '<!-- /wp:image -->' . "\n";
		}
		$gallery .= '</figure>' . "\n";
		$gallery .= '<!-- /wp:gallery -->' . "\n";
		$gallery .= '<!-- vru-fb-gallery:end -->';

		return $gallery;
	}

	private function facebook_date_for_wordpress( array $facebook_post ): string {
		if ( empty( $facebook_post['created_time'] ) ) {
			return current_time( 'mysql' );
		}

		$timestamp = strtotime( $facebook_post['created_time'] );
		if ( ! $timestamp ) {
			return current_time( 'mysql' );
		}

		return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ), 'Y-m-d H:i:s' );
	}

	private function format_facebook_created_time( array $facebook_post ): string {
		if ( empty( $facebook_post['created_time'] ) ) {
			return '';
		}

		$timestamp = strtotime( (string) $facebook_post['created_time'] );
		if ( ! $timestamp ) {
			return '';
		}

		return date_i18n( 'Y-m-d H:i', $timestamp );
	}

	private function render_results_table( array $rows ): void {
		if ( empty( $rows ) ) {
			echo '<p>ยังไม่มีข้อมูล</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>เวลา</th>
					<th>ผู้ใช้</th>
					<th>ลิงก์ต้นทาง</th>
					<th>สถานะ</th>
					<th>รายละเอียด</th>
					<th>ข่าว</th>
					<th>ลิงก์</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['time'] ?? '' ); ?></td>
						<td><?php echo esc_html( isset( $row['user_id'] ) ? (string) absint( $row['user_id'] ) : '' ); ?></td>
						<td>
							<?php if ( ! empty( $row['url'] ) ) : ?>
								<a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener">เปิดโพสต์</a>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $this->status_label( $row['status'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( $row['message'] ?? '' ); ?></td>
						<td><?php echo esc_html( $row['title'] ?? '' ); ?></td>
						<td>
							<?php if ( ! empty( $row['post_id'] ) ) : ?>
								<a href="<?php echo esc_url( get_permalink( (int) $row['post_id'] ) ); ?>" target="_blank" rel="noopener">ดูข่าว</a>
								|
								<a href="<?php echo esc_url( get_edit_post_link( (int) $row['post_id'] ) ); ?>">แก้ไขข่าว</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function status_label( string $status ): string {
		switch ( $status ) {
			case 'success':
				return 'สำเร็จ';
			case 'skipped':
				return 'ข้ามเพราะซ้ำ';
			case 'error':
				return 'ผิดพลาด';
			default:
				return $status;
		}
	}

	private function result( string $url, string $status, string $message, int $post_id = 0, string $title = '' ): array {
		return array(
			'time'    => current_time( 'mysql' ),
			'url'     => $url,
			'status'  => $status,
			'message' => $message,
			'post_id' => $post_id,
			'title'   => $title,
			'user_id' => get_current_user_id(),
		);
	}

	private function log_result( array $result ): array {
		$result = $this->sanitize_log_result( $result );
		$logs   = $this->get_logs();
		array_unshift( $logs, $result );
		$logs = array_slice( $logs, 0, self::MAX_LOGS );
		update_option( self::OPTION_LOGS, $logs, false );

		return $result;
	}

	private function sanitize_log_result( array $result ): array {
		$sanitized = array(
			'time'    => isset( $result['time'] ) ? sanitize_text_field( (string) $result['time'] ) : current_time( 'mysql' ),
			'url'     => isset( $result['url'] ) ? esc_url_raw( $this->redact_sensitive_text( (string) $result['url'] ) ) : '',
			'status'  => isset( $result['status'] ) ? sanitize_key( (string) $result['status'] ) : 'error',
			'message' => isset( $result['message'] ) ? sanitize_text_field( $this->redact_sensitive_text( (string) $result['message'] ) ) : '',
			'post_id' => isset( $result['post_id'] ) ? absint( $result['post_id'] ) : 0,
			'title'   => isset( $result['title'] ) ? sanitize_text_field( $this->redact_sensitive_text( (string) $result['title'] ) ) : '',
			'user_id' => isset( $result['user_id'] ) ? absint( $result['user_id'] ) : 0,
		);

		if ( ! in_array( $sanitized['status'], array( 'success', 'skipped', 'error' ), true ) ) {
			$sanitized['status'] = 'error';
		}

		return $sanitized;
	}

	private function redact_sensitive_text( string $text ): string {
		$text = preg_replace( '/(access_token=)[^&\s]+/i', '$1[redacted]', $text );
		$text = preg_replace( '/(Authorization:\s*Bearer\s+)[A-Za-z0-9_\-\.]+/i', '$1[redacted]', $text );

		foreach ( array( $this->get_source_access_token(), $this->runtime_page_access_token, $this->get_app_secret() ) as $secret ) {
			if ( is_string( $secret ) && strlen( $secret ) >= 8 ) {
				$text = str_replace( $secret, '[redacted]', $text );
			}
		}

		return $text;
	}

	private function get_logs(): array {
		$logs = get_option( self::OPTION_LOGS, array() );
		if ( ! is_array( $logs ) ) {
			return array();
		}

		$sanitized_logs = array();
		foreach ( $logs as $row ) {
			if ( is_array( $row ) ) {
				$sanitized_logs[] = $this->sanitize_log_result( $row );
			}
		}

		if ( $sanitized_logs !== $logs ) {
			update_option( self::OPTION_LOGS, $sanitized_logs, false );
		}

		return $sanitized_logs;
	}

	private function get_settings(): array {
		$settings = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		unset( $settings['access_token'] );

		return wp_parse_args( $settings, $this->default_settings() );
	}

	private function default_settings(): array {
		return array(
			'category_id'                 => (int) get_option( 'default_category', 1 ),
			'author_id'                   => get_current_user_id() ? get_current_user_id() : 1,
			'max_images'                  => 5,
			'gallery_size'                => 'large',
			'include_featured_in_gallery' => 0,
			'show_source'                 => 1,
		);
	}
}

register_activation_hook( __FILE__, array( 'VRU_Sakaeo_Facebook_News_Importer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'VRU_Sakaeo_Facebook_News_Importer', 'deactivate' ) );

new VRU_Sakaeo_Facebook_News_Importer();
