<?php
/**
 * Plugin Name: WooCommerce Cross-Site Rewards
 * Description: Connects two WooCommerce sites. Site A (Sender) triggers rewards on Site B (Receiver) via REST API.
 * Version: 1.1.0
 * Author: Tomas Hoyos
 * Text Domain: wc-xsr
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Cross_Site_Rewards {

	private $option_name = 'wc_xsr_settings';
	private $settings;

	public function __construct() {
		$this->settings = get_option( $this->option_name, array() );

		// Admin
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'settings_init' ) );
		add_action( 'admin_footer', array( $this, 'admin_scripts' ) ); // For Test Email JS

		// Mode: Receiver (Site B)
		if ( $this->get_mode() === 'receiver' ) {
			add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		}

		// Mode: Sender (Site A)
		if ( $this->get_mode() === 'sender' ) {
			// Product Meta
			add_action( 'woocommerce_product_options_general_product_data', array( $this, 'add_product_meta_field' ) );
			add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_meta_field' ) );
			
			// AJAX Actions
			add_action( 'wp_ajax_wc_xsr_refresh_products', array( $this, 'ajax_refresh_remote_products' ) );
			add_action( 'wp_ajax_wc_xsr_send_test_email', array( $this, 'ajax_send_test_email' ) );

			// Order Hook
			add_action( 'woocommerce_order_status_completed', array( $this, 'process_order_rewards' ), 10, 1 );

			// Display
			add_action( 'woocommerce_thankyou', array( $this, 'display_reward_on_thankyou' ) );
			add_action( 'woocommerce_email_order_meta', array( $this, 'display_reward_on_email' ), 10, 3 );
		}
	}

	/* -------------------------------------------------------------------------- */
	/*                                   HELPERS                                  */
	/* -------------------------------------------------------------------------- */

	public function get_mode() {
		return isset( $this->settings['mode'] ) ? $this->settings['mode'] : 'disabled';
	}

	public function get_secret() {
		return isset( $this->settings['secret_key'] ) ? $this->settings['secret_key'] : '';
	}

	public function get_remote_url() {
		return isset( $this->settings['remote_url'] ) ? untrailingslashit( $this->settings['remote_url'] ) : 'http://localhost';
	}

	public function get_default_template_content() {
		return "<p>¡Gracias por tu compra!</p>
<p>Aquí tienes tu regalo: <strong>{product_name}</strong></p>
<p>{qr_code}</p>
<p>
    <strong>Automático:</strong> Escanea el QR o pulsa el botón para reclamarlo.<br>
    <strong>Manual:</strong> Usa este código en el carrito: <span style='background:#eee; padding:5px;'>{code}</span>
</p>
<p><a href='{url}' style='background:#4caf50; color:#fff; padding:10px 15px; text-decoration:none;'>Reclamar Ahora</a></p>";
	}

	public function get_email_template() {
		return ! empty( $this->settings['email_template'] ) ? $this->settings['email_template'] : $this->get_default_template_content();
	}

	/* -------------------------------------------------------------------------- */
	/*                                ADMIN SETTINGS                              */
	/* -------------------------------------------------------------------------- */

	public function add_admin_menu() {
		add_options_page( 'Cross-Site Rewards', 'WC Rewards', 'manage_options', 'wc-xsr', array( $this, 'options_page_html' ) );
	}

	public function settings_init() {
		register_setting( 'wc_xsr_group', $this->option_name );
		add_settings_section( 'wc_xsr_main', 'Configuración General', null, 'wc-xsr-section' );
		add_settings_field( 'mode', 'Modo del Sitio', array( $this, 'render_mode_field' ), 'wc-xsr-section', 'wc_xsr_main' );
		add_settings_field( 'secret_key', 'Clave Secreta Compartida', array( $this, 'render_input_field' ), 'wc-xsr-section', 'wc_xsr_main', ['field' => 'secret_key'] );

		if ( $this->get_mode() === 'sender' ) {
			add_settings_field( 'remote_url', 'URL del Sitio Remoto (Receptor)', array( $this, 'render_input_field' ), 'wc-xsr-section', 'wc_xsr_main', ['field' => 'remote_url'] );
			add_settings_field( 'email_template', 'Plantilla de Correo', array( $this, 'render_editor_field' ), 'wc-xsr-section', 'wc_xsr_main' );
		}
	}

	public function options_page_html() {
		?>
		<div class="wrap">
			<h1>WooCommerce Cross-Site Rewards v1.1</h1>
			
			<form action="options.php" method="post">
				<?php
				settings_fields( 'wc_xsr_group' );
				do_settings_sections( 'wc-xsr-section' );
				submit_button();
				?>
			</form>

			<hr>

			<?php if ( $this->get_mode() === 'receiver' ): ?>
				<h2>🎟️ Últimos Cupones Generados (XSR)</h2>
				<?php $this->render_coupon_list(); ?>
			<?php endif; ?>

			<?php if ( $this->get_mode() === 'sender' ): ?>
				<div class="card" style="max-width: 600px;">
					<h3>📧 Prueba de Correo</h3>
					<p>Envía un correo de prueba con la plantilla actual.</p>
					<p>
						<input type="email" id="xsr_test_email_input" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" style="width: 100%; max-width: 300px;" placeholder="tu@email.com">
					</p>
					<button type="button" class="button button-secondary" id="xsr_send_test_email">Enviar Prueba</button>
					<span id="xsr_test_msg" style="margin-left:10px;"></span>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	public function render_mode_field() {
		$value = $this->get_mode();
		?>
		<select name="<?php echo $this->option_name; ?>[mode]">
			<option value="disabled" <?php selected( $value, 'disabled' ); ?>>Desactivado</option>
			<option value="sender" <?php selected( $value, 'sender' ); ?>>EMISOR (Web A - Vende)</option>
			<option value="receiver" <?php selected( $value, 'receiver' ); ?>>RECEPTOR (Web B - Regala)</option>
		</select>
		<?php
	}

	public function render_input_field( $args ) {
		$field = $args['field'];
		$value = isset( $this->settings[ $field ] ) ? $this->settings[ $field ] : '';
		echo '<input type="text" name="' . $this->option_name . '[' . $field . ']" value="' . esc_attr( $value ) . '" class="regular-text">';
	}

	public function render_editor_field() {
		$content = $this->get_email_template();
		wp_editor( $content, 'wc_xsr_email_template', array(
			'textarea_name' => $this->option_name . '[email_template]',
			'textarea_rows' => 10,
			'media_buttons' => false,
			'wpautop'       => false,
		) );
		echo '<div style="margin-top: 5px;">';
		echo '<p class="description" style="display:inline-block; margin-right:15px;">Variables: <code>{product_name}</code>, <code>{code}</code>, <code>{url}</code>, <code>{qr_code}</code>, <code>{qr_url}</code></p>';
		echo '<button type="button" class="button" id="xsr_reset_template" style="color: #a00;">Restaurar Plantilla Original</button>';
		echo '</div>';
	}

	/* -------------------------------------------------------------------------- */
	/*                          RECEIVER: COUPON MANAGER                          */
	/* -------------------------------------------------------------------------- */

	public function render_coupon_list() {
		$args = array(
			'post_type'      => 'shop_coupon',
			'posts_per_page' => 20,
			's'              => 'XSR-',
			'post_status'    => array( 'publish', 'draft', 'trash' ),
		);
		$coupons = get_posts( $args );

		if ( empty( $coupons ) ) {
			echo '<p>No se han generado cupones de recompensa todavía.</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr><th>Código</th><th>Estado</th><th>Usos</th><th>Creado</th><th>Acciones</th></tr></thead>';
		echo '<tbody>';
		foreach ( $coupons as $post ) {
			$coupon = new WC_Coupon( $post->ID );
			$usage = $coupon->get_usage_count() . ' / ' . $coupon->get_usage_limit();
			$delete_url = get_delete_post_link( $post->ID );
			$edit_url = get_edit_post_link( $post->ID );

			$status_label = $post->post_status == 'publish' ? '<span style="color:green">Activo</span>' : $post->post_status;
			if( $coupon->get_usage_count() >= $coupon->get_usage_limit() ) $status_label = '<span style="color:gray">Usado</span>';

			echo '<tr>';
			echo '<td><strong>' . esc_html( $coupon->get_code() ) . '</strong></td>';
			echo '<td>' . $status_label . '</td>';
			echo '<td>' . esc_html( $usage ) . '</td>';
			echo '<td>' . get_the_date( 'Y-m-d H:i', $post->ID ) . '</td>';
			echo '<td>';
			echo '<a href="' . esc_url( $edit_url ) . '">Editar</a> | ';
			echo '<a href="' . esc_url( $delete_url ) . '" style="color:#a00;" onclick="return confirm(\'¿Borrar?\')">Borrar</a>';
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/* -------------------------------------------------------------------------- */
	/*                        RECEIVER LOGIC (API)                                */
	/* -------------------------------------------------------------------------- */

	public function register_rest_routes() {
		register_rest_route( 'wc-xsr/v1', '/products', array(
			'methods' => 'GET',
			'callback' => array( $this, 'api_get_products' ),
			'permission_callback' => array( $this, 'api_permission_check' ),
		) );

		register_rest_route( 'wc-xsr/v1', '/generate', array(
			'methods' => 'POST',
			'callback' => array( $this, 'api_generate_coupon' ),
			'permission_callback' => array( $this, 'api_permission_check' ),
		) );
	}

	public function api_permission_check( $request ) {
		return $request->get_header( 'X-XSR-Secret' ) === $this->get_secret();
	}

	public function api_get_products() {
		$products = wc_get_products( array( 'status' => 'publish', 'limit' => -1 ) );
		$data = array();
		foreach ( $products as $product ) {
			$data[] = array( 'id' => $product->get_id(), 'name' => $product->get_name() . ' (ID: ' . $product->get_id() . ')' );
		}
		return rest_ensure_response( $data );
	}

	public function api_generate_coupon( $request ) {
		$params = $request->get_json_params();
		$reward_product_id = isset( $params['reward_product_id'] ) ? intval( $params['reward_product_id'] ) : 0;

		if ( ! $reward_product_id ) return new WP_Error( 'no_product', 'No Product ID provided', array( 'status' => 400 ) );

		$code = 'XSR-' . strtoupper( wp_generate_password( 8, false ) );
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 100 );
		$coupon->set_individual_use( true );
		$coupon->set_product_ids( array( $reward_product_id ) );
		$coupon->set_usage_limit( 1 );
		$coupon->set_description( 'Cross-site reward for product ID ' . $reward_product_id );
		$coupon->save();

		$cart_url = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : site_url( '/cart/' );
		$claim_url = add_query_arg( array( 'add-to-cart' => $reward_product_id, 'coupon_code' => $code ), $cart_url );

		return rest_ensure_response( array( 'code' => $code, 'claim_url' => $claim_url ) );
	}

	/* -------------------------------------------------------------------------- */
	/*                            SENDER LOGIC                                    */
	/* -------------------------------------------------------------------------- */

	public function add_product_meta_field() {
		global $post;
		$selected_id = get_post_meta( $post->ID, '_xsr_remote_reward_id', true );
		echo '<div class="options_group"><p class="form-field"><label for="_xsr_remote_reward_id">Producto Remoto de Regalo</label>';
		echo '<select id="_xsr_remote_reward_id" name="_xsr_remote_reward_id" style="min-width: 200px;"><option value="">-- Ninguno --</option>';
		
		$cached = get_transient( 'xsr_remote_products_list' );
		if ( $cached && is_array( $cached ) ) {
			foreach ( $cached as $p ) echo "<option value='" . esc_attr( $p['id'] ) . "' " . selected( $selected_id, $p['id'], false ) . ">" . esc_html( $p['name'] ) . "</option>";
		} else {
			echo '<option value="" disabled>Lista no cargada</option>';
		}
		
		echo '</select><button type="button" class="button" id="xsr_refresh_products">🔄 Actualizar Lista</button>';
		echo '</p></div>';
		?>
		<script>
		jQuery(document).ready(function($) {
			$('#xsr_refresh_products').click(function(e) {
				e.preventDefault();
				var btn = $(this); btn.text('Cargando...');
				$.post(ajaxurl, { action: 'wc_xsr_refresh_products' }, function(res) {
					if(res.success) {
						var opts = '<option value="">-- Ninguno --</option>';
						$.each(res.data, function(i, p) { opts += '<option value="'+p.id+'">'+p.name+'</option>'; });
						$('#_xsr_remote_reward_id').html(opts); btn.text('¡Lista Actualizada!');
					} else { btn.text('Error'); }
				});
			});
		});
		</script>
		<?php
	}

	public function save_product_meta_field( $post_id ) {
		if ( isset( $_POST['_xsr_remote_reward_id'] ) ) update_post_meta( $post_id, '_xsr_remote_reward_id', sanitize_text_field( $_POST['_xsr_remote_reward_id'] ) );
	}

	public function ajax_refresh_remote_products() {
		$url = $this->get_remote_url(); $secret = $this->get_secret();
		if ( ! $url || ! $secret ) wp_send_json_error( 'Configurar URL/Clave' );

		$response = wp_remote_get( $url . '/wp-json/wc-xsr/v1/products', array( 'headers' => array( 'X-XSR-Secret' => $secret ), 'timeout' => 10 ) );
		if ( is_wp_error( $response ) ) wp_send_json_error( $response->get_error_message() );
		
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data ) ) wp_send_json_error( 'Error remoto' );
		
		set_transient( 'xsr_remote_products_list', $data, HOUR_IN_SECONDS );
		wp_send_json_success( $data );
	}

	public function process_order_rewards( $order_id ) {
		$order = wc_get_order( $order_id );
		foreach ( $order->get_items() as $item_id => $item ) {
			$remote_reward_id = get_post_meta( $item->get_product_id(), '_xsr_remote_reward_id', true );
			if ( $remote_reward_id && ! wc_get_order_item_meta( $item_id, '_xsr_reward_code', true ) ) {
				$this->request_remote_coupon( $order, $item_id, $remote_reward_id );
			}
		}
	}

	private function request_remote_coupon( $order, $item_id, $remote_id ) {
		$response = wp_remote_post( $this->get_remote_url() . '/wp-json/wc-xsr/v1/generate', array(
			'headers' => array( 'X-XSR-Secret' => $this->get_secret(), 'Content-Type' => 'application/json' ),
			'body' => json_encode( array( 'reward_product_id' => $remote_id ) ),
			'timeout' => 15
		) );
		if ( ! is_wp_error( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( isset( $data['code'] ) ) {
				wc_add_order_item_meta( $item_id, '_xsr_reward_code', $data['code'] );
				wc_add_order_item_meta( $item_id, '_xsr_claim_url', $data['claim_url'] );
			}
		}
	}

	public function display_reward_on_thankyou( $order_id ) { $this->render_reward_html( $order_id ); }
	public function display_reward_on_email( $order, $sent_to_admin, $plain_text = false ) {
		if ( ! $sent_to_admin && ! $plain_text ) $this->render_reward_html( $order->get_id() );
	}

	private function render_reward_html( $order_id ) {
		$order = wc_get_order( $order_id );
		$template = $this->get_email_template();
		$found = false;

		foreach ( $order->get_items() as $item ) {
			$code = $item->get_meta( '_xsr_reward_code' );
			$url = $item->get_meta( '_xsr_claim_url' );
			if ( $code && $url ) {
				$qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode( $url );
				$qr_img = '<img src="' . $qr_url . '" alt="QR" style="border:1px solid #ccc; padding:5px;">';

				$html = str_replace( 
					array( '{product_name}', '{code}', '{url}', '{qr_code}', '{qr_url}' ),
					array( $item->get_name(), $code, $url, $qr_img, $qr_url ),
					$template
				);

				echo '<div style="margin: 20px 0; padding: 20px; border: 2px dashed #4caf50; background-color: #f9f9f9; text-align: center;">';
				echo wp_kses_post( $html );
				echo '</div>';
				$found = true;
			}
		}
	}

	/* -------------------------------------------------------------------------- */
	/*                            TESTING                                         */
	/* -------------------------------------------------------------------------- */

	public function ajax_send_test_email() {
		$email = isset( $_POST['test_email'] ) && is_email( $_POST['test_email'] ) ? sanitize_email( $_POST['test_email'] ) : wp_get_current_user()->user_email;
		
		// Use live content from editor if available, otherwise fallback to DB
		if ( isset( $_POST['template_content'] ) && ! empty( $_POST['template_content'] ) ) {
			$template = stripslashes( $_POST['template_content'] );
		} else {
			$template = stripslashes( $this->settings['email_template'] );
		}
		
		if( empty( $template ) ) $template = $this->get_email_template();

		// Mock Data
		$qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=TEST';
		$qr_img = '<img src="' . $qr_url . '" alt="QR" style="border:1px solid #ccc; padding:5px;">';
		$html = str_replace( 
			array( '{product_name}', '{code}', '{url}', '{qr_code}', '{qr_url}' ),
			array( 'Producto de Prueba', 'TEST-CODE-123', '#', $qr_img, $qr_url ),
			$template
		);

		$message = '<div style="margin: 20px 0; padding: 20px; border: 2px dashed #4caf50; background-color: #f9f9f9; text-align: center;">' . $html . '</div>';
		
		$headers = array('Content-Type: text/html; charset=UTF-8');
		$sent = wp_mail( $email, 'Prueba de Recompensa Cross-Site', $message, $headers );

		if( $sent ) wp_send_json_success( 'Enviado a ' . $email );
		else wp_send_json_error( 'Fallo al enviar mail' );
	}

	public function admin_scripts() {
		if ( isset( $_GET['page'] ) && $_GET['page'] === 'wc-xsr' ) {
			?>
			<script>
			var xsrDefaultTemplate = <?php echo json_encode( $this->get_default_template_content() ); ?>;

			jQuery(document).ready(function($) {
				// Test Email
				$('#xsr_send_test_email').click(function(e) {
					e.preventDefault();
					var btn = $(this); 
					var email = $('#xsr_test_email_input').val();
					
					// Get content from WP Editor (Visual or Text)
					var content = '';
					if ( typeof tinyMCE !== 'undefined' && tinyMCE.activeEditor && ! tinyMCE.activeEditor.isHidden() ) {
						content = tinyMCE.activeEditor.getContent();
					} else {
						content = $('#wc_xsr_email_template').val();
					}

					btn.text('Enviando...');
					$.post(ajaxurl, { 
						action: 'wc_xsr_send_test_email',
						test_email: email,
						template_content: content
					}, function(res) {
						if(res.success) {
							btn.text('¡Enviado!'); $('#xsr_test_msg').css('color','green').text(res.data);
						} else {
							btn.text('Error'); $('#xsr_test_msg').css('color','red').text(res.data);
						}
					});
				});

				// Reset Template
				$('#xsr_reset_template').click(function(e) {
					e.preventDefault();
					if ( confirm( '¿Estás seguro de que quieres borrar tu plantilla actual y volver a la original?' ) ) {
						if ( typeof tinyMCE !== 'undefined' && tinyMCE.activeEditor && ! tinyMCE.activeEditor.isHidden() ) {
							tinyMCE.activeEditor.setContent( xsrDefaultTemplate );
						} else {
							$('#wc_xsr_email_template').val( xsrDefaultTemplate );
						}
					}
				});
			});
			</script>
			<?php
		}
	}
}

new WC_Cross_Site_Rewards();
