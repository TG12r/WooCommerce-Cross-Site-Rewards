<?php
/**
 * Plugin Name: WooCommerce Cross-Site Rewards
 * Description: Connects two WooCommerce sites. Site A (Sender) triggers rewards on Site B (Receiver) via REST API.
 * Version: 1.0.0
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
		// Loaad Settings
		$this->settings = get_option( $this->option_name, array() );

		// Admin Menu
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'settings_init' ) );

		// Mode: Receiver (Site B)
		if ( $this->get_mode() === 'receiver' ) {
			add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		}

		// Mode: Sender (Site A)
		if ( $this->get_mode() === 'sender' ) {
			// Product Meta Field
			add_action( 'woocommerce_product_options_general_product_data', array( $this, 'add_product_meta_field' ) );
			add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_meta_field' ) );
			
			// AJAX for refreshing remote products
			add_action( 'wp_ajax_wc_xsr_refresh_products', array( $this, 'ajax_refresh_remote_products' ) );

			// Order Hook
			add_action( 'woocommerce_order_status_completed', array( $this, 'process_order_rewards' ), 10, 1 );

			// Display Code/QR
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
		return isset( $this->settings['remote_url'] ) ? untrailingslashit( $this->settings['remote_url'] ) : '';
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
		add_settings_field( 'remote_url', 'URL del Sitio Remoto (Solo Emisor)', array( $this, 'render_input_field' ), 'wc-xsr-section', 'wc_xsr_main', ['field' => 'remote_url'] );
	}

	public function options_page_html() {
		?>
		<div class="wrap">
			<h1>WooCommerce Cross-Site Rewards</h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'wc_xsr_group' );
				do_settings_sections( 'wc-xsr-section' );
				submit_button();
				?>
			</form>
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
		<p class="description">Si este es la web donde se compra, elige EMISOR. Si es donde se canjea, RECEPTOR.</p>
		<?php
	}

	public function render_input_field( $args ) {
		$field = $args['field'];
		$value = isset( $this->settings[ $field ] ) ? $this->settings[ $field ] : '';
		?>
		<input type="text" name="<?php echo $this->option_name; ?>[<?php echo $field; ?>]" value="<?php echo esc_attr( $value ); ?>" class="regular-text">
		<?php
	}

	/* -------------------------------------------------------------------------- */
	/*                            RECEIVER LOGIC (SITE B)                         */
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
		$secret = $request->get_header( 'X-XSR-Secret' );
		return $secret === $this->get_secret();
	}

	public function api_get_products() {
		$args = array(
			'status' => 'publish',
			'limit' => -1,
		);
		$products = wc_get_products( $args );
		$data = array();

		foreach ( $products as $product ) {
			$data[] = array(
				'id' => $product->get_id(),
				'name' => $product->get_name() . ' (ID: ' . $product->get_id() . ')',
			);
		}

		return rest_ensure_response( $data );
	}

	public function api_generate_coupon( $request ) {
		$params = $request->get_json_params();
		$reward_product_id = isset( $params['reward_product_id'] ) ? intval( $params['reward_product_id'] ) : 0;

		if ( ! $reward_product_id ) {
			return new WP_Error( 'no_product', 'No Product ID provided', array( 'status' => 400 ) );
		}

		// Generate Unique Code
		$code = 'XSR-' . strtoupper( wp_generate_password( 8, false ) );

		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'percent' ); // Or fixed_cart
		$coupon->set_amount( 100 ); // 100% off
		$coupon->set_individual_use( true );
		$coupon->set_product_ids( array( $reward_product_id ) );
		$coupon->set_usage_limit( 1 );
		$coupon->set_description( 'Cross-site reward for product ID ' . $reward_product_id );
		$coupon->save();

		// Generate Claim URL
		// Example: https://siteb.com/cart/?add-to-cart=123&coupon_code=CODE
		$cart_url = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : site_url( '/cart/' );
		
		$claim_url = add_query_arg( array(
			'add-to-cart' => $reward_product_id,
			'coupon_code' => $code
		), $cart_url );

		return rest_ensure_response( array(
			'code' => $code,
			'claim_url' => $claim_url,
		) );
	}


	/* -------------------------------------------------------------------------- */
	/*                            SENDER LOGIC (SITE A)                           */
	/* -------------------------------------------------------------------------- */

	// 1. PRODUCT SETTINGS FIELD
	public function add_product_meta_field() {
		global $post;
		$selected_id = get_post_meta( $post->ID, '_xsr_remote_reward_id', true );
		
		echo '<div class="options_group">';
		
		// Render Select
		echo '<p class="form-field"><label for="_xsr_remote_reward_id">Producto Remoto de Regalo</label>';
		echo '<select id="_xsr_remote_reward_id" name="_xsr_remote_reward_id" style="min-width: 200px;">';
		echo '<option value="">-- Ninguno --</option>';

		// Check cache
		$cached_products = get_transient( 'xsr_remote_products_list' );
		if ( $cached_products && is_array( $cached_products ) ) {
			foreach ( $cached_products as $p ) {
				$selected = selected( $selected_id, $p['id'], false );
				echo "<option value='" . esc_attr( $p['id'] ) . "' $selected>" . esc_html( $p['name'] ) . "</option>";
			}
		} else {
			echo '<option value="" disabled>Lista no cargada o vacía</option>';
		}
		
		echo '</select>';
		echo '<button type="button" class="button" id="xsr_refresh_products">🔄 Actualizar Lista Remota</button>';
		echo '<span class="description"> Selecciona qué producto de la Web B se regala al comprar este.</span>';
		echo '</p>';

		// Simple JS for refresh
		?>
		<script>
		jQuery(document).ready(function($) {
			$('#xsr_refresh_products').click(function(e) {
				e.preventDefault();
				var btn = $(this);
				btn.text('Cargando...');
				$.post(ajaxurl, { action: 'wc_xsr_refresh_products' }, function(res) {
					if(res.success) {
						var opts = '<option value="">-- Ninguno --</option>';
						$.each(res.data, function(i, p) {
							opts += '<option value="'+p.id+'">'+p.name+'</option>';
						});
						$('#_xsr_remote_reward_id').html(opts);
						btn.text('¡Lista Actualizada!');
					} else {
						btn.text('Error: ' + res.data);
					}
				});
			});
		});
		</script>
		<?php
		echo '</div>';
	}

	public function save_product_meta_field( $post_id ) {
		if ( isset( $_POST['_xsr_remote_reward_id'] ) ) {
			update_post_meta( $post_id, '_xsr_remote_reward_id', sanitize_text_field( $_POST['_xsr_remote_reward_id'] ) );
		}
	}

	public function ajax_refresh_remote_products() {
		// fetch_remote_products
		$url = $this->get_remote_url();
		$secret = $this->get_secret();

		if ( ! $url || ! $secret ) {
			wp_send_json_error( 'Falta configurar URL o Clave en Ajustes' );
		}

		$response = wp_remote_get( $url . '/wp-json/wc-xsr/v1/products', array(
			'headers' => array( 'X-XSR-Secret' => $secret ),
			'timeout' => 10
		) );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message() );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( empty( $data ) || ! is_array( $data ) ) {
			wp_send_json_error( 'Respuesta inválida del servidor remoto' );
		}

		// Cache for 1 hour
		set_transient( 'xsr_remote_products_list', $data, HOUR_IN_SECONDS );

		wp_send_json_success( $data );
	}


	// 2. ORDER PROCESSING
	public function process_order_rewards( $order_id ) {
		$order = wc_get_order( $order_id );
		
		foreach ( $order->get_items() as $item_id => $item ) {
			$product_id = $item->get_product_id();
			$remote_reward_id = get_post_meta( $product_id, '_xsr_remote_reward_id', true );

			if ( $remote_reward_id ) {
				// Check if we already have a code for this item to avoid duplicates
				if ( wc_get_order_item_meta( $item_id, '_xsr_reward_code', true ) ) {
					continue;
				}

				// Call Remote API
				$this->request_remote_coupon( $order, $item_id, $remote_reward_id );
			}
		}
	}

	private function request_remote_coupon( $order, $item_id, $remote_id ) {
		$url = $this->get_remote_url();
		$secret = $this->get_secret();

		$response = wp_remote_post( $url . '/wp-json/wc-xsr/v1/generate', array(
			'headers' => array(
				'X-XSR-Secret' => $secret,
				'Content-Type' => 'application/json'
			),
			'body' => json_encode( array(
				'reward_product_id' => $remote_id,
				'order_id' => $order->get_id() // Reference
			) ),
			'timeout' => 15
		) );

		if ( ! is_wp_error( $response ) ) {
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( isset( $data['code'] ) && isset( $data['claim_url'] ) ) {
				// Save to Item Meta
				wc_add_order_item_meta( $item_id, '_xsr_reward_code', $data['code'] );
				wc_add_order_item_meta( $item_id, '_xsr_claim_url', $data['claim_url'] );
			}
		}
	}

	// 3. DISPLAY LOGIC (Thank You & Email)
	public function display_reward_on_thankyou( $order_id ) {
		$this->render_reward_box( $order_id );
	}

	public function display_reward_on_email( $order, $sent_to_admin, $plain_text = false ) {
		if ( ! $sent_to_admin && ! $plain_text ) {
			$this->render_reward_box( $order->get_id() );
		}
	}

	private function render_reward_box( $order_id ) {
		$order = wc_get_order( $order_id );
		$has_reward = false;
		$rewards = array();

		foreach ( $order->get_items() as $item ) {
			$code = $item->get_meta( '_xsr_reward_code' );
			$url = $item->get_meta( '_xsr_claim_url' );
			if ( $code && $url ) {
				$rewards[] = array(
					'product' => $item->get_name(),
					'code' => $code,
					'url' => $url
				);
				$has_reward = true;
			}
		}

		if ( ! $has_reward ) return;

		?>
		<div style="background: #fdfdfd; border: 2px dashed #4caf50; padding: 20px; margin: 20px 0; text-align: center;">
			<h2 style="color: #4caf50; margin-top:0;">¡Tienes un regalo!</h2>
			<?php foreach ( $rewards as $reward ): ?>
				<p>Por comprar <strong><?php echo esc_html( $reward['product'] ); ?></strong>, aquí tienes tu acceso al simulador:</p>
                <p>
                    <strong>Automático:</strong> Escanea el QR o pulsa el botón para reclamarlo automáticamente.<br>
                </p>
				<p>
					<!-- Generate QR using a public API for simplicity -->
					<?php 
					$qr_api = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode( $reward['url'] ); 
					?>
					<img src="<?php echo esc_url( $qr_api ); ?>" alt="QR Code" style="border:1px solid #ccc; padding:5px;">
				</p>
				<p>
					<strong>Manual:</strong> Ve a la tienda, añade el producto al carrito y usa este código de cupón:
				</p>
				<p style="font-size: 1.2em; border: 1px solid #ddd; display: inline-block; padding: 5px 10px; background: #eee;">
					<?php echo esc_html( $reward['code'] ); ?>
				</p>
				<p><a href="<?php echo esc_url( $reward['url'] ); ?>" style="background:#4caf50; color:#fff; padding:10px 15px; text-decoration:none; border-radius:5px;">Reclamar Ahora</a></p>
				<hr style="border: 0; border-top: 1px dashed #ccc; margin: 15px 0;">
			<?php endforeach; ?>
		</div>
		<?php
	}

}

new WC_Cross_Site_Rewards();
