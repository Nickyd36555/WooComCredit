<?php
/**
 * Plugin Name: Simple Store Credit for WooCommerce
 * Description: Gift store credit to customers. Customers see their balance under My Account → Store Credit and can apply it at checkout whenever they like.
 * Version: 1.0.0
 * Author: WooComCredit
 * Text Domain: wc-simple-store-credit
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * License: GPL-2.0+
 */

defined( 'ABSPATH' ) || exit;

class WC_Simple_Store_Credit {

	const META_BALANCE = '_wcsc_credit_balance';
	const META_LOG     = '_wcsc_credit_log';
	const ENDPOINT     = 'store-credit';
	const SESSION_KEY  = 'wcsc_apply_credit';

	/** @var WC_Simple_Store_Credit */
	private static $instance;

	/** @var string Last wp_mail failure message, captured during our sends. */
	private $mail_error = '';

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// My Account tab.
		add_filter( 'woocommerce_get_query_vars', array( $this, 'register_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'account_menu_item' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( $this, 'endpoint_title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'endpoint_content' ) );

		// Applying credit on cart/checkout.
		add_action( 'woocommerce_before_cart', array( $this, 'cart_notice' ) );
		add_action( 'wp', array( $this, 'handle_apply_link' ) );
		add_action( 'woocommerce_review_order_before_payment', array( $this, 'checkout_apply_field' ) );
		add_action( 'woocommerce_review_order_before_order_total', array( $this, 'review_order_credit_row' ) );
		add_filter( 'woocommerce_form_field_checkbox', array( $this, 'strip_optional_suffix' ), 10, 2 );
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'checkout_update_session' ) );
		// Late priority so other plugins' fees (e.g. package protection) are
		// already in the cart and get covered by the credit too.
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_credit_fee' ), 999 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_credit_at_checkout' ), 10, 2 );

		// Deduct credit when the order is placed; restore it if the order dies.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'deduct_credit_for_order' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'deduct_credit_for_order' ), 10, 1 );
		add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'keep_flagged_orders_on_hold' ), 10, 3 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'restore_credit_for_order' ) );
		add_action( 'woocommerce_order_status_failed', array( $this, 'restore_credit_for_order' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'restore_credit_for_order' ) );

		// Admin.
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_filter( 'woocommerce_screen_ids', array( $this, 'admin_screen_ids' ) );
	}

	/* -------------------------------------------------------------------------
	 * Balance API
	 * ---------------------------------------------------------------------- */

	public function get_balance( $user_id ) {
		return max( 0, (float) get_user_meta( $user_id, self::META_BALANCE, true ) );
	}

	/**
	 * Adjust a customer's balance by $amount (negative to deduct) and log it.
	 * The balance never drops below zero. Returns the new balance.
	 */
	public function adjust_balance( $user_id, $amount, $note = '' ) {
		$decimals = wc_get_price_decimals();
		$balance  = $this->get_balance( $user_id );
		$new      = max( 0, round( $balance + (float) $amount, $decimals ) );

		update_user_meta( $user_id, self::META_BALANCE, $new );

		$log = get_user_meta( $user_id, self::META_LOG, true );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = array(
			'time'    => time(),
			'amount'  => round( $new - $balance, $decimals ),
			'balance' => $new,
			'note'    => $note,
			'by'      => get_current_user_id(), // Audit trail: who triggered the change (0 = system).
		);
		update_user_meta( $user_id, self::META_LOG, array_slice( $log, -100 ) );

		return $new;
	}

	public function get_log( $user_id ) {
		$log = get_user_meta( $user_id, self::META_LOG, true );
		return is_array( $log ) ? array_reverse( $log ) : array();
	}

	private function fee_name() {
		return __( 'Store credit', 'wc-simple-store-credit' );
	}

	private function is_credit_applied() {
		return WC()->session && 'yes' === WC()->session->get( self::SESSION_KEY );
	}

	private function set_credit_applied( $applied ) {
		if ( WC()->session ) {
			WC()->session->set( self::SESSION_KEY, $applied ? 'yes' : 'no' );
		}
	}

	/* -------------------------------------------------------------------------
	 * My Account → Store Credit
	 * ---------------------------------------------------------------------- */

	public function register_query_var( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	public function endpoint_title() {
		return __( 'Store Credit', 'wc-simple-store-credit' );
	}

	public function account_menu_item( $items ) {
		$new = array();
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'orders' === $key ) {
				$new[ self::ENDPOINT ] = __( 'Store Credit', 'wc-simple-store-credit' );
			}
		}
		if ( ! isset( $new[ self::ENDPOINT ] ) ) {
			$new[ self::ENDPOINT ] = __( 'Store Credit', 'wc-simple-store-credit' );
		}
		return $new;
	}

	public function endpoint_content() {
		$user_id = get_current_user_id();
		$balance = $this->get_balance( $user_id );
		$log     = $this->get_log( $user_id );
		?>
		<div class="wcsc-balance" style="border:1px solid #e0e0e0;border-radius:4px;padding:1.25em 1.5em;margin-bottom:1.5em;">
			<p style="margin:0 0 .25em;"><?php esc_html_e( 'Your store credit balance', 'wc-simple-store-credit' ); ?></p>
			<p style="margin:0;font-size:2em;font-weight:700;"><?php echo wp_kses_post( wc_price( $balance ) ); ?></p>
			<?php if ( $balance > 0 ) : ?>
				<p style="margin:.75em 0 0;">
					<?php esc_html_e( 'You can apply your credit to any order at checkout — use it now or save it for later.', 'wc-simple-store-credit' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
		if ( empty( $log ) ) {
			echo '<p>' . esc_html__( 'No store credit activity yet.', 'wc-simple-store-credit' ) . '</p>';
			return;
		}
		?>
		<h3><?php esc_html_e( 'Credit history', 'wc-simple-store-credit' ); ?></h3>
		<table class="woocommerce-table shop_table shop_table_responsive">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'wc-simple-store-credit' ); ?></th>
					<th><?php esc_html_e( 'Details', 'wc-simple-store-credit' ); ?></th>
					<th><?php esc_html_e( 'Amount', 'wc-simple-store-credit' ); ?></th>
					<th><?php esc_html_e( 'Balance', 'wc-simple-store-credit' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $log as $entry ) : ?>
					<tr>
						<td data-title="<?php esc_attr_e( 'Date', 'wc-simple-store-credit' ); ?>">
							<?php echo esc_html( wp_date( get_option( 'date_format' ), $entry['time'] ) ); ?>
						</td>
						<td data-title="<?php esc_attr_e( 'Details', 'wc-simple-store-credit' ); ?>">
							<?php echo esc_html( $entry['note'] ); ?>
						</td>
						<td data-title="<?php esc_attr_e( 'Amount', 'wc-simple-store-credit' ); ?>" style="color:<?php echo $entry['amount'] >= 0 ? '#1a7f37' : '#c0392b'; ?>;">
							<?php echo wp_kses_post( ( $entry['amount'] >= 0 ? '+' : '−' ) . wc_price( abs( $entry['amount'] ) ) ); ?>
						</td>
						<td data-title="<?php esc_attr_e( 'Balance', 'wc-simple-store-credit' ); ?>">
							<?php echo wp_kses_post( wc_price( $entry['balance'] ) ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/* -------------------------------------------------------------------------
	 * Cart & checkout
	 * ---------------------------------------------------------------------- */

	public function cart_notice() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$balance = $this->get_balance( get_current_user_id() );
		if ( $balance <= 0 ) {
			return;
		}

		if ( $this->is_credit_applied() ) {
			$url     = wp_nonce_url( add_query_arg( 'wcsc_credit', '0', wc_get_cart_url() ), 'wcsc_credit' );
			$message = sprintf(
				/* translators: 1: credit amount, 2: remove link */
				__( 'Your store credit of %1$s is applied to this order. %2$s', 'wc-simple-store-credit' ),
				wc_price( $balance ),
				'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Remove it', 'wc-simple-store-credit' ) . '</a>'
			);
		} else {
			$url     = wp_nonce_url( add_query_arg( 'wcsc_credit', '1', wc_get_cart_url() ), 'wcsc_credit' );
			$message = sprintf(
				/* translators: 1: credit amount, 2: apply link */
				__( 'You have %1$s in store credit. %2$s', 'wc-simple-store-credit' ),
				wc_price( $balance ),
				'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Apply it to this order', 'wc-simple-store-credit' ) . '</a>'
			);
		}

		wc_print_notice( $message, 'notice' );
	}

	public function handle_apply_link() {
		if ( ! isset( $_GET['wcsc_credit'], $_GET['_wpnonce'] ) || ! is_user_logged_in() ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'wcsc_credit' ) ) {
			return;
		}
		$this->set_credit_applied( '1' === $_GET['wcsc_credit'] );
		wp_safe_redirect( remove_query_arg( array( 'wcsc_credit', '_wpnonce' ) ) );
		exit;
	}

	public function checkout_apply_field() {
		$this->render_apply_checkbox( 'wcsc_apply_credit' );
	}

	/**
	 * Second copy of the checkbox inside the order summary, just above the
	 * Total row (below where themes place the coupon field).
	 */
	public function review_order_credit_row() {
		if ( ! is_user_logged_in() || $this->get_balance( get_current_user_id() ) <= 0 ) {
			return;
		}
		echo '<tr class="wcsc-credit-row"><td colspan="2" style="text-align:left;">';
		$this->render_apply_checkbox( 'wcsc_apply_credit_totals' );
		echo '</td></tr>';
	}

	private function render_apply_checkbox( $field_id ) {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$balance = $this->get_balance( get_current_user_id() );
		if ( $balance <= 0 ) {
			return;
		}

		// Standard WooCommerce field markup so checkout themes/skins style it
		// like every other checkout field.
		woocommerce_form_field(
			'wcsc_apply_credit',
			array(
				'type'   => 'checkbox',
				'id'     => $field_id,
				'class'  => array( 'form-row-wide', 'wcsc-apply-credit', 'update_totals_on_change' ),
				'label'  => '<strong>' . sprintf(
					/* translators: %s: available credit amount */
					esc_html__( 'Use my store credit (%s available)', 'wc-simple-store-credit' ),
					'&#9733; ' . wp_kses_post( wc_price( $balance ) ) . ' &#9733;'
				) . '</strong>',
				'return' => false,
			),
			$this->is_credit_applied() ? 1 : ''
		);

		// Both copies share one name; keep them visually in sync and refresh
		// the totals whenever either one changes. Enqueue once.
		static $js_added = false;
		if ( ! $js_added ) {
			$js_added = true;
			wc_enqueue_js(
				"$( document.body ).on( 'change', 'input[name=\"wcsc_apply_credit\"]', function() {
					$( 'input[name=\"wcsc_apply_credit\"]' ).prop( 'checked', $( this ).prop( 'checked' ) );
					$( document.body ).trigger( 'update_checkout' );
				} );"
			);
		}
	}

	/**
	 * Hide the "(optional)" suffix WooCommerce appends to non-required fields
	 * — it reads oddly on the store credit checkbox.
	 */
	public function strip_optional_suffix( $field, $key ) {
		if ( 'wcsc_apply_credit' === $key ) {
			$field = preg_replace( '/&nbsp;<span class="optional">.*?<\/span>/', '', $field );
		}
		return $field;
	}

	public function checkout_update_session( $post_data ) {
		if ( ! is_user_logged_in() ) {
			return;
		}
		parse_str( (string) $post_data, $data );
		$this->set_credit_applied( ! empty( $data['wcsc_apply_credit'] ) );
	}

	public function apply_credit_fee( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( ! is_user_logged_in() || ! $this->is_credit_applied() ) {
			return;
		}
		$balance = $this->get_balance( get_current_user_id() );
		if ( $balance <= 0 ) {
			return;
		}

		// Cap the credit at the full order cost — items, shipping, and any
		// other charges (all incl. tax) — so it can never push the total
		// negative but can cover the whole order.
		$cap = (float) $cart->get_cart_contents_total() + (float) $cart->get_cart_contents_tax();
		$cap += (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax();
		foreach ( $cart->get_fees() as $fee ) {
			if ( $fee->name !== $this->fee_name() && (float) $fee->amount > 0 ) {
				$cap += (float) $fee->amount + (float) $fee->tax;
			}
		}
		$credit = min( $balance, max( 0, $cap ) );

		if ( $credit > 0 ) {
			$cart->add_fee( $this->fee_name(), -$credit, false );
		}
	}

	/**
	 * The applied fee amount is trusted nowhere: recheck it against the live
	 * balance when the checkout form is submitted, so a stale cart (or a
	 * second browser tab) can't spend credit that no longer exists.
	 */
	public function validate_credit_at_checkout( $data, $errors ) {
		$applied = $this->get_cart_credit_total();
		if ( $applied <= 0 ) {
			return;
		}
		$balance = is_user_logged_in() ? $this->get_balance( get_current_user_id() ) : 0;
		if ( $applied > $balance + 0.01 ) {
			$this->set_credit_applied( false );
			$errors->add(
				'wcsc_credit',
				__( 'Your store credit balance has changed, so it was removed from this order. Please review your total and place the order again.', 'wc-simple-store-credit' )
			);
		}
	}

	private function get_cart_credit_total() {
		if ( ! WC()->cart ) {
			return 0;
		}
		$total = 0;
		foreach ( WC()->cart->get_fees() as $fee ) {
			if ( $fee->name === $this->fee_name() && $fee->amount < 0 ) {
				$total += abs( (float) $fee->amount + (float) $fee->tax );
			}
		}
		return $total;
	}

	/* -------------------------------------------------------------------------
	 * Order lifecycle
	 * ---------------------------------------------------------------------- */

	public function deduct_credit_for_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order || $order->get_meta( '_wcsc_credit_used' ) ) {
			return;
		}
		$user_id = $order->get_user_id();
		if ( ! $user_id ) {
			return;
		}

		$used = 0;
		foreach ( $order->get_fees() as $fee ) {
			if ( $fee->get_name() === $this->fee_name() && (float) $fee->get_total() < 0 ) {
				$used += abs( (float) $fee->get_total() + (float) $fee->get_total_tax() );
			}
		}
		if ( $used <= 0 ) {
			return;
		}

		// Backstop against double-spends: never deduct more than the live
		// balance, and hold any order whose discount exceeds it.
		$balance   = $this->get_balance( $user_id );
		$overspend = $used > $balance + 0.01;
		$deduct    = min( $used, $balance );

		if ( $deduct > 0 ) {
			$this->adjust_balance(
				$user_id,
				-$deduct,
				sprintf(
					/* translators: %s: order number */
					__( 'Used on order #%s', 'wc-simple-store-credit' ),
					$order->get_order_number()
				)
			);
		}

		$order->update_meta_data( '_wcsc_credit_used', wc_format_decimal( $deduct ) );

		if ( $overspend ) {
			$order->update_meta_data( '_wcsc_credit_hold', 'yes' );
			$order->update_status(
				'on-hold',
				sprintf(
					/* translators: 1: discount taken, 2: balance available */
					__( 'Store credit review needed: this order took a %1$s credit discount but the customer only had %2$s available. Placed on hold.', 'wc-simple-store-credit' ),
					wc_price( $used, array( 'currency' => $order->get_currency() ) ),
					wc_price( $balance, array( 'currency' => $order->get_currency() ) )
				)
			);
		}

		$order->save();

		$this->set_credit_applied( false );
	}

	/**
	 * Zero-total orders are auto-completed by WooCommerce via
	 * payment_complete(), which would lift the on-hold status we set on
	 * overspent orders. Keep those flagged orders on hold.
	 */
	public function keep_flagged_orders_on_hold( $status, $order_id, $order ) {
		if ( $order instanceof WC_Order && $order->get_meta( '_wcsc_credit_hold' ) ) {
			return 'on-hold';
		}
		return $status;
	}

	public function restore_credit_for_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$used = (float) $order->get_meta( '_wcsc_credit_used' );
		if ( $used <= 0 || $order->get_meta( '_wcsc_credit_restored' ) ) {
			return;
		}
		$user_id = $order->get_user_id();
		if ( ! $user_id ) {
			return;
		}

		$this->adjust_balance(
			$user_id,
			$used,
			sprintf(
				/* translators: %s: order number */
				__( 'Credit returned from order #%s', 'wc-simple-store-credit' ),
				$order->get_order_number()
			)
		);

		$order->update_meta_data( '_wcsc_credit_restored', 'yes' );
		$order->save();
	}

	/* -------------------------------------------------------------------------
	 * Admin: WooCommerce → Store Credit
	 * ---------------------------------------------------------------------- */

	public function admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Store Credit', 'wc-simple-store-credit' ),
			__( 'Store Credit', 'wc-simple-store-credit' ),
			'manage_woocommerce',
			'wcsc-store-credit',
			array( $this, 'admin_page' )
		);
	}

	/**
	 * Register our admin page as a WooCommerce screen so WC loads its
	 * enhanced-select (customer search) scripts on it.
	 */
	public function admin_screen_ids( $ids ) {
		$ids[] = 'woocommerce_page_wcsc-store-credit';
		return $ids;
	}

	public function admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$notice = $this->maybe_handle_admin_post();
		if ( ! $notice ) {
			$notice = $this->maybe_handle_template_post();
		}
		if ( ! $notice ) {
			$notice = $this->maybe_handle_guest_account_post();
		}
		if ( ! $notice ) {
			$notice = $this->maybe_handle_test_email_post();
		}
		if ( ! $notice ) {
			$notice = $this->maybe_handle_promo_pick();
		}
		if ( ! $notice ) {
			$notice = $this->maybe_handle_promo_send();
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Store Credit', 'wc-simple-store-credit' ); ?></h1>
			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo wp_kses_post( $notice['message'] ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Gift or adjust credit', 'wc-simple-store-credit' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'wcsc_adjust_credit' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="wcsc_user"><?php esc_html_e( 'Customer', 'wc-simple-store-credit' ); ?></label></th>
						<td>
							<select id="wcsc_user" name="wcsc_user" class="wc-customer-search" style="min-width:300px;"
								data-placeholder="<?php esc_attr_e( 'Search for a customer…', 'wc-simple-store-credit' ); ?>" data-allow_clear="true"></select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcsc_action"><?php esc_html_e( 'Action', 'wc-simple-store-credit' ); ?></label></th>
						<td>
							<select id="wcsc_action" name="wcsc_action">
								<option value="add"><?php esc_html_e( 'Add credit', 'wc-simple-store-credit' ); ?></option>
								<option value="deduct"><?php esc_html_e( 'Deduct credit', 'wc-simple-store-credit' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcsc_amount"><?php esc_html_e( 'Amount', 'wc-simple-store-credit' ); ?> (<?php echo esc_html( get_woocommerce_currency_symbol() ); ?>)</label></th>
						<td><input type="number" step="0.01" min="0.01" id="wcsc_amount" name="wcsc_amount" style="width:120px;" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="wcsc_note"><?php esc_html_e( 'Note (shown to customer)', 'wc-simple-store-credit' ); ?></label></th>
						<td><input type="text" id="wcsc_note" name="wcsc_note" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Thanks for your loyalty!', 'wc-simple-store-credit' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Notify customer', 'wc-simple-store-credit' ); ?></th>
						<td>
							<label for="wcsc_notify">
								<input type="checkbox" id="wcsc_notify" name="wcsc_notify" value="1" checked />
								<?php esc_html_e( 'Email the customer about this credit (only sent when adding credit)', 'wc-simple-store-credit' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Update credit', 'wc-simple-store-credit' ) ); ?>
			</form>

			<?php $this->render_promo_section(); ?>

			<hr style="margin:2em 0;" />
			<h2><?php esc_html_e( 'Create account from a guest order', 'wc-simple-store-credit' ); ?></h2>
			<p>
				<?php esc_html_e( 'Guests can\'t hold store credit. Enter a guest order number to create a customer account from its billing details — the customer is emailed a link to set their password, and all their past guest orders are linked to the new account. Then you can gift them credit above.', 'wc-simple-store-credit' ); ?>
			</p>
			<form method="post">
				<?php wp_nonce_field( 'wcsc_guest_account' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="wcsc_order_id"><?php esc_html_e( 'Order number', 'wc-simple-store-credit' ); ?></label></th>
						<td><input type="number" min="1" id="wcsc_order_id" name="wcsc_order_id" style="width:120px;" required /></td>
					</tr>
				</table>
				<?php submit_button( __( 'Create account', 'wc-simple-store-credit' ), 'secondary', 'wcsc_create_account' ); ?>
			</form>

			<h2><?php esc_html_e( 'Customers with credit', 'wc-simple-store-credit' ); ?></h2>
			<?php $this->admin_balances_table(); ?>

			<hr style="margin:2em 0;" />
			<h2><?php esc_html_e( 'Gift email template', 'wc-simple-store-credit' ); ?></h2>
			<p>
				<?php esc_html_e( 'Customize the email customers receive when you gift them credit. Available placeholders:', 'wc-simple-store-credit' ); ?>
				<code>{first_name}</code> <code>{amount}</code> <code>{balance}</code> <code>{note}</code> <code>{store_name}</code> <code>{account_link}</code>
			</p>
			<?php $template = $this->get_email_template(); ?>
			<form method="post">
				<?php wp_nonce_field( 'wcsc_email_template' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="wcsc_email_subject"><?php esc_html_e( 'Subject', 'wc-simple-store-credit' ); ?></label></th>
						<td><input type="text" id="wcsc_email_subject" name="wcsc_email_subject" class="large-text" value="<?php echo esc_attr( $template['subject'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="wcsc_email_heading"><?php esc_html_e( 'Heading', 'wc-simple-store-credit' ); ?></label></th>
						<td><input type="text" id="wcsc_email_heading" name="wcsc_email_heading" class="large-text" value="<?php echo esc_attr( $template['heading'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="wcsc_email_body"><?php esc_html_e( 'Message', 'wc-simple-store-credit' ); ?></label></th>
						<td>
							<textarea id="wcsc_email_body" name="wcsc_email_body" class="large-text" rows="10"><?php echo esc_textarea( $template['body'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Blank lines become paragraphs. Basic HTML (links, bold, italics) is allowed. Leave a field empty and save to restore its default text.', 'wc-simple-store-credit' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save email template', 'wc-simple-store-credit' ), 'secondary', 'wcsc_save_template' ); ?>
			</form>

			<form method="post" style="margin-top:.5em;">
				<?php wp_nonce_field( 'wcsc_test_email' ); ?>
				<?php submit_button( __( 'Send me a test email', 'wc-simple-store-credit' ), 'secondary', 'wcsc_send_test', false ); ?>
				<p class="description"><?php esc_html_e( 'Sends the saved template to your own email address with sample values, and reports any mail-system error. Save the template first to test recent edits.', 'wc-simple-store-credit' ); ?></p>
			</form>
		</div>
		<?php
	}

	private function maybe_handle_template_post() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['wcsc_save_template'] ) ) {
			return null;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return null;
		}
		check_admin_referer( 'wcsc_email_template' );

		$template = array();
		foreach ( array( 'subject', 'heading', 'body' ) as $field ) {
			$raw   = isset( $_POST[ 'wcsc_email_' . $field ] ) ? wp_unslash( $_POST[ 'wcsc_email_' . $field ] ) : '';
			$clean = 'body' === $field ? trim( wp_kses_post( $raw ) ) : sanitize_text_field( $raw );
			if ( '' !== $clean ) {
				$template[ $field ] = $clean;
			}
			// Empty fields are omitted so the defaults kick back in.
		}
		update_option( 'wcsc_email_template', $template );

		return array(
			'type'    => 'success',
			'message' => __( 'Email template saved.', 'wc-simple-store-credit' ),
		);
	}

	private function maybe_handle_admin_post() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['wcsc_amount'] ) ) {
			return null;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return null;
		}
		check_admin_referer( 'wcsc_adjust_credit' );

		$user_id = isset( $_POST['wcsc_user'] ) ? absint( $_POST['wcsc_user'] ) : 0;
		$user    = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Please choose a customer.', 'wc-simple-store-credit' ),
			);
		}

		$amount = isset( $_POST['wcsc_amount'] ) ? (float) wc_format_decimal( wp_unslash( $_POST['wcsc_amount'] ) ) : 0;
		if ( $amount <= 0 ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Please enter an amount greater than zero.', 'wc-simple-store-credit' ),
			);
		}

		$deduct = isset( $_POST['wcsc_action'] ) && 'deduct' === $_POST['wcsc_action'];
		$note   = isset( $_POST['wcsc_note'] ) ? sanitize_text_field( wp_unslash( $_POST['wcsc_note'] ) ) : '';
		if ( '' === $note ) {
			$note = $deduct
				? __( 'Credit adjusted by the store', 'wc-simple-store-credit' )
				: __( 'Credit gifted by the store', 'wc-simple-store-credit' );
		}

		$new = $this->adjust_balance( $user_id, $deduct ? -$amount : $amount, $note );

		$type       = 'success';
		$email_note = '';
		if ( ! $deduct && ! empty( $_POST['wcsc_notify'] ) ) {
			if ( $this->send_gift_email( $user, $amount, $note, $new ) ) {
				$email_note = sprintf(
					/* translators: %s: customer email address */
					__( 'A notification email was sent to %s.', 'wc-simple-store-credit' ),
					esc_html( $user->user_email )
				);
			} else {
				$type       = 'warning';
				$email_note = sprintf(
					/* translators: %s: mail error detail */
					__( 'However, the notification email could NOT be sent%s. Use the "Send me a test email" button below to diagnose.', 'wc-simple-store-credit' ),
					$this->mail_error ? ' — ' . esc_html( $this->mail_error ) : ''
				);
			}
		}

		return array(
			'type'    => $type,
			'message' => sprintf(
				/* translators: 1: customer name, 2: new balance */
				__( 'Done! %1$s now has a store credit balance of %2$s.', 'wc-simple-store-credit' ),
				esc_html( $user->display_name ),
				wc_price( $new )
			) . ' ' . $email_note,
		);
	}

	private function maybe_handle_test_email_post() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['wcsc_send_test'] ) ) {
			return null;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return null;
		}
		check_admin_referer( 'wcsc_test_email' );

		$me   = wp_get_current_user();
		$sent = $this->send_gift_email( $me, 12.34, __( 'This is a test note.', 'wc-simple-store-credit' ), 56.78 );

		if ( $sent ) {
			return array(
				'type'    => 'success',
				'message' => sprintf(
					/* translators: %s: admin email address */
					__( 'Test email handed off to the mail system for %s. If it doesn\'t arrive within a few minutes (check spam too), your site\'s outgoing mail isn\'t delivering — an SMTP plugin such as WP Mail SMTP usually fixes that.', 'wc-simple-store-credit' ),
					esc_html( $me->user_email )
				),
			);
		}

		return array(
			'type'    => 'error',
			'message' => sprintf(
				/* translators: %s: mail error detail */
				__( 'Test email failed to send%s. Your site\'s outgoing mail is not working — this affects all WooCommerce emails, not just store credit. Installing an SMTP plugin such as WP Mail SMTP (connected to your email provider) is the usual fix.', 'wc-simple-store-credit' ),
				$this->mail_error ? ' — ' . esc_html( $this->mail_error ) : ''
			),
		);
	}

	/**
	 * Editable email template, stored in one option with sane defaults.
	 */
	public function get_email_template() {
		$defaults = array(
			'subject' => __( 'You\'ve received {amount} in store credit at {store_name}', 'wc-simple-store-credit' ),
			'heading' => __( 'You\'ve got store credit!', 'wc-simple-store-credit' ),
			'body'    => __(
				"Hi {first_name},\n\nWe've added {amount} in store credit to your account.\n\n{note}\n\nYour balance is now {balance}.\n\nUse it on any order at checkout — now or whenever you like. You can view your balance any time on your {account_link} page.",
				'wc-simple-store-credit'
			),
		);
		$saved = get_option( 'wcsc_email_template', array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	/**
	 * Notify the customer they've been gifted credit, wrapped in the store's
	 * standard WooCommerce email template.
	 */
	private function send_gift_email( $user, $amount, $note, $new_balance ) {
		$mailer     = WC()->mailer();
		$template   = $this->get_email_template();
		$store_name = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$first_name = $user->first_name ? $user->first_name : $user->display_name;

		// Plain-text values for the subject line.
		$subject = strtr(
			$template['subject'],
			array(
				'{first_name}' => $first_name,
				'{amount}'     => html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' ),
				'{balance}'    => html_entity_decode( wp_strip_all_tags( wc_price( $new_balance ) ), ENT_QUOTES, 'UTF-8' ),
				'{note}'       => $note,
				'{store_name}' => $store_name,
			)
		);

		// HTML values for the body.
		$body = strtr(
			wp_kses_post( $template['body'] ),
			array(
				'{first_name}'   => esc_html( $first_name ),
				'{amount}'       => '<strong>' . wp_kses_post( wc_price( $amount ) ) . '</strong>',
				'{balance}'      => '<strong>' . wp_kses_post( wc_price( $new_balance ) ) . '</strong>',
				'{note}'         => $note ? '<em>' . esc_html( $note ) . '</em>' : '',
				'{store_name}'   => esc_html( $store_name ),
				'{account_link}' => '<a href="' . esc_url( wc_get_account_endpoint_url( self::ENDPOINT ) ) . '">' . esc_html__( 'Store Credit', 'wc-simple-store-credit' ) . '</a>',
			)
		);
		$body = wpautop( trim( $body ) );

		// Capture the reason if the mail system rejects the send, so the
		// admin notice can say more than "it didn't work".
		$this->mail_error = '';
		add_action( 'wp_mail_failed', array( $this, 'capture_mail_error' ) );
		$sent = (bool) $mailer->send( $user->user_email, $subject, $mailer->wrap_message( $template['heading'], $body ) );
		remove_action( 'wp_mail_failed', array( $this, 'capture_mail_error' ) );

		return $sent;
	}

	public function capture_mail_error( $wp_error ) {
		$this->mail_error = $wp_error->get_error_message();
	}

	private function maybe_handle_guest_account_post() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['wcsc_create_account'] ) ) {
			return null;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return null;
		}
		check_admin_referer( 'wcsc_guest_account' );

		$order_id = isset( $_POST['wcsc_order_id'] ) ? absint( $_POST['wcsc_order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order ) {
			return array(
				'type'    => 'error',
				/* translators: %d: order id */
				'message' => sprintf( __( 'Order #%d was not found.', 'wc-simple-store-credit' ), $order_id ),
			);
		}

		if ( $order->get_user_id() ) {
			$user = get_userdata( $order->get_user_id() );
			return array(
				'type'    => 'error',
				'message' => sprintf(
					/* translators: 1: order number, 2: customer name */
					__( 'Order #%1$s already belongs to the account of %2$s — you can gift them credit above.', 'wc-simple-store-credit' ),
					$order->get_order_number(),
					esc_html( $user ? $user->display_name : '' )
				),
			);
		}

		$user_id = $this->get_or_create_customer_for_order( $order, $created, $linked );
		if ( is_wp_error( $user_id ) ) {
			return array(
				'type'    => 'error',
				'message' => $user_id->get_error_message(),
			);
		}

		$user = get_userdata( $user_id );
		if ( ! $created ) {
			return array(
				'type'    => 'success',
				'message' => sprintf(
					/* translators: 1: email, 2: customer name, 3: number of orders linked */
					__( 'An account for %1$s already exists (%2$s) — %3$d guest order(s) were linked to it. You can gift them credit above.', 'wc-simple-store-credit' ),
					esc_html( $order->get_billing_email() ),
					esc_html( $user ? $user->display_name : '' ),
					(int) $linked
				),
			);
		}

		return array(
			'type'    => 'success',
			'message' => sprintf(
				/* translators: 1: customer name, 2: email, 3: number of orders linked */
				__( 'Account created for %1$s (%2$s) and %3$d order(s) linked to it. They\'ve been emailed a link to set their password. You can now gift them credit above.', 'wc-simple-store-credit' ),
				esc_html( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ),
				esc_html( $order->get_billing_email() ),
				(int) $linked
			),
		);
	}

	/**
	 * Find (by billing email) or create a customer account for an order.
	 * Creation deliberately bypasses the public registration pipeline
	 * (captcha plugins would block it), so only call this from
	 * capability-checked admin actions. Past guest orders with the same
	 * email are linked to the account either way.
	 *
	 * @return int|WP_Error User ID.
	 */
	private function get_or_create_customer_for_order( $order, &$created = false, &$linked = 0 ) {
		$created = false;
		$linked  = 0;

		$email = sanitize_email( $order->get_billing_email() );
		if ( ! $email ) {
			return new WP_Error( 'wcsc_no_email', __( 'That order has no billing email address, so no account can be created.', 'wc-simple-store-credit' ) );
		}

		$existing = email_exists( $email );
		if ( $existing ) {
			$linked = (int) wc_update_new_customer_past_orders( $existing );
			return (int) $existing;
		}

		// Create the user directly rather than through WooCommerce's
		// registration pipeline: this is an admin-initiated action (already
		// capability- and nonce-protected), so captcha/anti-spam plugins
		// guarding the public registration form must not block it.
		if ( function_exists( 'wc_create_new_customer_username' ) ) {
			$username = wc_create_new_customer_username(
				$email,
				array(
					'first_name' => $order->get_billing_first_name(),
					'last_name'  => $order->get_billing_last_name(),
				)
			);
		} else {
			$username = sanitize_user( current( explode( '@', $email ) ), true );
			$suffix   = 1;
			while ( username_exists( $username ) ) {
				$username = sanitize_user( current( explode( '@', $email ) ), true ) . $suffix++;
			}
		}

		$password = wp_generate_password( 24 );
		$user_id  = wp_insert_user(
			array(
				'user_login' => $username,
				'user_pass'  => $password,
				'user_email' => $email,
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
				'role'       => 'customer',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// Fire WooCommerce's created-customer hook (password_generated = true)
		// so the standard New Account email goes out with a set-password link.
		do_action(
			'woocommerce_created_customer',
			$user_id,
			array(
				'user_login' => $username,
				'user_pass'  => $password,
				'user_email' => $email,
				'role'       => 'customer',
			),
			true
		);

		// Copy the order's billing/shipping details onto the new account.
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			foreach ( $order->get_address( $type ) as $key => $value ) {
				if ( $value ) {
					update_user_meta( $user_id, $type . '_' . $key, $value );
				}
			}
		}

		$linked  = (int) wc_update_new_customer_past_orders( $user_id );
		$created = true;

		return (int) $user_id;
	}

	/* -------------------------------------------------------------------------
	 * Daily giveaway
	 * ---------------------------------------------------------------------- */

	private function get_promo_settings() {
		$defaults = array(
			'min'  => 5,
			'max'  => 50,
			'note' => __( 'Congratulations — you\'ve been randomly selected for our daily store credit giveaway!', 'wc-simple-store-credit' ),
		);
		$saved = get_option( 'wcsc_promo_settings', array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	private function maybe_handle_promo_pick() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['wcsc_promo_pick'] ) ) {
			return null;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return null;
		}
		check_admin_referer( 'wcsc_promo' );

		$min = isset( $_POST['wcsc_promo_min'] ) ? (float) wc_format_decimal( wp_unslash( $_POST['wcsc_promo_min'] ) ) : 5;
		$max = isset( $_POST['wcsc_promo_max'] ) ? (float) wc_format_decimal( wp_unslash( $_POST['wcsc_promo_max'] ) ) : 50;
		$min = max( 0.01, $min );
		$max = max( $min, $max );

		$settings = array(
			'min'  => $min,
			'max'  => $max,
			'note' => isset( $_POST['wcsc_promo_note'] ) ? sanitize_text_field( wp_unslash( $_POST['wcsc_promo_note'] ) ) : '',
		);
		if ( '' === $settings['note'] ) {
			$settings['note'] = $this->get_promo_settings()['note'];
		}
		update_option( 'wcsc_promo_settings', $settings );

		// Yesterday, in the store's timezone.
		$tz    = wp_timezone();
		$start = ( new DateTimeImmutable( 'yesterday', $tz ) )->setTime( 0, 0 )->getTimestamp();
		$end   = ( new DateTimeImmutable( 'today', $tz ) )->setTime( 0, 0 )->getTimestamp() - 1;

		$orders = wc_get_orders(
			array(
				'limit'        => 300,
				'status'       => array( 'processing', 'completed' ),
				'date_created' => $start . '...' . $end,
			)
		);

		// One entry per customer, keyed by billing email.
		$pool = array();
		foreach ( $orders as $order ) {
			$email = strtolower( sanitize_email( $order->get_billing_email() ) );
			if ( ! $email || isset( $pool[ $email ] ) ) {
				continue;
			}
			$pool[ $email ] = $order;
		}

		if ( empty( $pool ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'No paid orders from yesterday were found, so there\'s no one to pick from.', 'wc-simple-store-credit' ),
			);
		}

		$pool = array_values( $pool );
		shuffle( $pool );
		$winners = array();
		foreach ( array_slice( $pool, 0, 5 ) as $order ) {
			$winners[] = array(
				'order_id' => $order->get_id(),
				'number'   => $order->get_order_number(),
				'name'     => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'email'    => $order->get_billing_email(),
				'user_id'  => (int) $order->get_user_id(),
				'total'    => (float) $order->get_total(),
				'amount'   => round( mt_rand( (int) round( $min * 100 ), (int) round( $max * 100 ) ) / 100, 2 ),
				'sent'     => false,
			);
		}

		update_option(
			'wcsc_promo',
			array(
				'date'    => current_time( 'Y-m-d' ),
				'winners' => $winners,
			)
		);

		return array(
			'type'    => 'success',
			'message' => sprintf(
				/* translators: 1: winners picked, 2: customers in the pool */
				__( 'Picked %1$d winner(s) at random from %2$d customer(s) who ordered yesterday. Review the list below and send the credit.', 'wc-simple-store-credit' ),
				count( $winners ),
				count( $pool )
			),
		);
	}

	private function maybe_handle_promo_send() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['wcsc_promo_send'] ) ) {
			return null;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return null;
		}
		check_admin_referer( 'wcsc_promo' );

		$promo = get_option( 'wcsc_promo' );
		$index = isset( $_POST['wcsc_promo_index'] ) ? absint( $_POST['wcsc_promo_index'] ) : 0;
		if ( ! is_array( $promo ) || ! isset( $promo['winners'][ $index ] ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'That giveaway entry could not be found — try picking winners again.', 'wc-simple-store-credit' ),
			);
		}

		$row = $promo['winners'][ $index ];
		if ( ! empty( $row['sent'] ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'That winner has already received their credit.', 'wc-simple-store-credit' ),
			);
		}

		$amount = isset( $_POST['wcsc_promo_amount'] ) ? (float) wc_format_decimal( wp_unslash( $_POST['wcsc_promo_amount'] ) ) : 0;
		if ( $amount <= 0 ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Please enter a credit amount greater than zero.', 'wc-simple-store-credit' ),
			);
		}

		$order = wc_get_order( $row['order_id'] );
		if ( ! $order ) {
			return array(
				'type'    => 'error',
				'message' => __( 'The winner\'s order no longer exists.', 'wc-simple-store-credit' ),
			);
		}

		$user_id = $order->get_user_id();
		if ( ! $user_id ) {
			$user_id = $this->get_or_create_customer_for_order( $order );
			if ( is_wp_error( $user_id ) ) {
				return array(
					'type'    => 'error',
					'message' => $user_id->get_error_message(),
				);
			}
		}

		$note = $this->get_promo_settings()['note'];
		$new  = $this->adjust_balance( $user_id, $amount, $note );

		$user    = get_userdata( $user_id );
		$emailed = $user ? $this->send_gift_email( $user, $amount, $note, $new ) : false;

		$promo['winners'][ $index ]['sent']    = true;
		$promo['winners'][ $index ]['amount']  = $amount;
		$promo['winners'][ $index ]['user_id'] = (int) $user_id;
		update_option( 'wcsc_promo', $promo );

		$message = sprintf(
			/* translators: 1: credit amount, 2: winner name, 3: winner email */
			__( 'Sent %1$s store credit to %2$s (%3$s).', 'wc-simple-store-credit' ),
			wc_price( $amount ),
			esc_html( $row['name'] ),
			esc_html( $row['email'] )
		);
		if ( $emailed ) {
			$message .= ' ' . __( 'They\'ve been notified by email.', 'wc-simple-store-credit' );
			return array(
				'type'    => 'success',
				'message' => $message,
			);
		}

		$message .= ' ' . sprintf(
			/* translators: %s: mail error detail */
			__( 'However, the notification email could NOT be sent%s.', 'wc-simple-store-credit' ),
			$this->mail_error ? ' — ' . esc_html( $this->mail_error ) : ''
		);
		return array(
			'type'    => 'warning',
			'message' => $message,
		);
	}

	private function render_promo_section() {
		$settings = $this->get_promo_settings();
		$promo    = get_option( 'wcsc_promo' );
		$symbol   = get_woocommerce_currency_symbol();
		?>
		<hr style="margin:2em 0;" />
		<h2><?php esc_html_e( 'Daily giveaway', 'wc-simple-store-credit' ); ?></h2>
		<p><?php esc_html_e( 'Pick 5 customers at random from yesterday\'s paid orders and gift each a random amount of store credit. Winners are emailed automatically when you click Send. Guest winners get an account created for them on the spot.', 'wc-simple-store-credit' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'wcsc_promo' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="wcsc_promo_min"><?php esc_html_e( 'Random amount between', 'wc-simple-store-credit' ); ?> (<?php echo esc_html( $symbol ); ?>)</label></th>
					<td>
						<input type="number" step="0.01" min="0.01" id="wcsc_promo_min" name="wcsc_promo_min" style="width:100px;" value="<?php echo esc_attr( $settings['min'] ); ?>" />
						&nbsp;<?php esc_html_e( 'and', 'wc-simple-store-credit' ); ?>&nbsp;
						<input type="number" step="0.01" min="0.01" name="wcsc_promo_max" style="width:100px;" value="<?php echo esc_attr( $settings['max'] ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wcsc_promo_note"><?php esc_html_e( 'Message to winners', 'wc-simple-store-credit' ); ?></label></th>
					<td><input type="text" id="wcsc_promo_note" name="wcsc_promo_note" class="large-text" value="<?php echo esc_attr( $settings['note'] ); ?>" /></td>
				</tr>
			</table>
			<?php
			$has_today = is_array( $promo ) && ! empty( $promo['winners'] ) && ( $promo['date'] ?? '' ) === current_time( 'Y-m-d' );
			submit_button(
				$has_today
					? __( 'Re-pick winners (replaces today\'s list)', 'wc-simple-store-credit' )
					: __( 'Pick 5 winners from yesterday', 'wc-simple-store-credit' ),
				'primary',
				'wcsc_promo_pick'
			);
			?>
		</form>
		<?php
		if ( ! is_array( $promo ) || empty( $promo['winners'] ) ) {
			return;
		}
		?>
		<h3>
			<?php
			printf(
				/* translators: %s: date the winners were picked */
				esc_html__( 'Winners picked on %s', 'wc-simple-store-credit' ),
				esc_html( $promo['date'] ?? '' )
			);
			?>
		</h3>
		<table class="widefat striped" style="max-width:1000px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Order', 'wc-simple-store-credit' ); ?></th>
					<th><?php esc_html_e( 'Customer', 'wc-simple-store-credit' ); ?></th>
					<th><?php esc_html_e( 'Email', 'wc-simple-store-credit' ); ?></th>
					<th><?php esc_html_e( 'Order total', 'wc-simple-store-credit' ); ?></th>
					<th><?php esc_html_e( 'Store credit', 'wc-simple-store-credit' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $promo['winners'] as $i => $row ) : ?>
					<tr>
						<td>#<?php echo esc_html( $row['number'] ); ?></td>
						<td>
							<?php echo esc_html( $row['name'] ? $row['name'] : '—' ); ?>
							<?php if ( empty( $row['user_id'] ) && empty( $row['sent'] ) ) : ?>
								<br /><small><em><?php esc_html_e( 'guest — an account will be created', 'wc-simple-store-credit' ); ?></em></small>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $row['email'] ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $row['total'] ) ); ?></td>
						<td>
							<?php if ( ! empty( $row['sent'] ) ) : ?>
								<strong style="color:#1a7f37;">✓ <?php printf( /* translators: %s: amount sent */ esc_html__( 'Sent %s', 'wc-simple-store-credit' ), wp_kses_post( wc_price( $row['amount'] ) ) ); ?></strong>
							<?php else : ?>
								<form method="post" style="margin:0;display:flex;gap:.5em;align-items:center;">
									<?php wp_nonce_field( 'wcsc_promo' ); ?>
									<input type="hidden" name="wcsc_promo_index" value="<?php echo esc_attr( $i ); ?>" />
									<input type="number" step="0.01" min="0.01" name="wcsc_promo_amount" style="width:90px;" value="<?php echo esc_attr( $row['amount'] ); ?>" />
									<button type="submit" name="wcsc_promo_send" value="1" class="button button-primary"><?php esc_html_e( 'Send credit', 'wc-simple-store-credit' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function admin_balances_table() {
		$users = get_users(
			array(
				'meta_query' => array(
					array(
						'key'     => self::META_BALANCE,
						'value'   => 0,
						'compare' => '>',
						'type'    => 'DECIMAL(20,4)',
					),
				),
				'number'     => 200,
				'orderby'    => 'display_name',
			)
		);

		if ( empty( $users ) ) {
			echo '<p>' . esc_html__( 'No customers have store credit yet.', 'wc-simple-store-credit' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped" style="max-width:700px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Customer', 'wc-simple-store-credit' ); ?></th>
					<th><?php esc_html_e( 'Email', 'wc-simple-store-credit' ); ?></th>
					<th><?php esc_html_e( 'Balance', 'wc-simple-store-credit' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $users as $user ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"><?php echo esc_html( $user->display_name ); ?></a></td>
						<td><?php echo esc_html( $user->user_email ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $this->get_balance( $user->ID ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}

/* ---------------------------------------------------------------------------
 * Bootstrap
 * ------------------------------------------------------------------------ */

add_action(
	'plugins_loaded',
	function () {
		if ( class_exists( 'WooCommerce' ) ) {
			WC_Simple_Store_Credit::instance();
		} else {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' .
						esc_html__( 'Simple Store Credit for WooCommerce requires WooCommerce to be installed and active.', 'wc-simple-store-credit' ) .
						'</p></div>';
				}
			);
		}
	}
);

// Declare HPOS (custom order tables) compatibility. The checkout checkbox is
// built for the classic [woocommerce_checkout] shortcode, so flag block-based
// checkout as unsupported.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, false );
		}
	}
);

register_activation_hook(
	__FILE__,
	function () {
		add_rewrite_endpoint( WC_Simple_Store_Credit::ENDPOINT, EP_ROOT | EP_PAGES );
		flush_rewrite_rules();
	}
);

register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
