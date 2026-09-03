<?php
/**
 * cart.php — CCM Checkout.
 *
 * Basado en woocommerce/templates/cart/cart.php @version 10.1.0: la tabla
 * (shop_table) se sustituye por una lista (.ccmck-cart__items) con foto,
 * nombre, cantidad e importe por artículo. Los ganchos y filtros de la
 * plantilla original se conservan tal cual — otros plugins cuelgan de ellos.
 *
 * Revisar tras cada actualización de WooCommerce.
 *
 * @package CCM_Checkout
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_cart' ); ?>

<div class="ccmck-cart">

	<form class="woocommerce-cart-form ccmck-cart__form" action="<?php echo esc_url( wc_get_cart_url() ); ?>" method="post">
		<?php do_action( 'woocommerce_before_cart_table' ); ?>

		<?php
		/*
		 * La clase `woocommerce-cart-form__contents` es OBLIGATORIA, aunque esto sea
		 * un <ul> y no el <tbody> del núcleo. `cart.js` de WooCommerce 10.7.0 la usa
		 * en dos sitios y sin ella los dos se caen en silencio:
		 *
		 *   cart_submit()   línea 551: `if ( 0 === $form.find(...).length ) return;`
		 *                   -> ningún cambio de cantidad llega nunca al servidor
		 *   update_wc_div() línea 122: busca este nodo para saber qué reemplazar
		 *                   cuando el carrito se queda vacío
		 *
		 * Medido: con la clase, un cambio de cantidad son 1 petición y 0 recargas;
		 * sin ella, el núcleo no interviene.
		 */
		?>
		<ul class="ccmck-cart__items woocommerce-cart-form__contents">

			<?php /* Cabecera de columnas. Va como <li> porque esta dentro de un <ul>;
			         se oculta en movil, donde las columnas se apilan. */ ?>
			<li class="ccmck-cart__encabezado" aria-hidden="true">
				<span></span>
				<span><?php esc_html_e( 'Producto', 'ccm-checkout' ); ?></span>
				<span><?php esc_html_e( 'Cantidad', 'ccm-checkout' ); ?></span>
				<span><?php esc_html_e( 'Total', 'ccm-checkout' ); ?></span>
				<span></span>
			</li>
			<?php do_action( 'woocommerce_before_cart_contents' ); ?>

			<?php
			foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
				$_product   = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
				$product_id = apply_filters( 'woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key );

				if ( ! $_product || ! $_product->exists() || $cart_item['quantity'] <= 0 || ! apply_filters( 'woocommerce_cart_item_visible', true, $cart_item, $cart_item_key ) ) {
					continue;
				}

				$permalink = apply_filters( 'woocommerce_cart_item_permalink', $_product->is_visible() ? $_product->get_permalink( $cart_item ) : '', $cart_item, $cart_item_key );
				$thumbnail = apply_filters( 'woocommerce_cart_item_thumbnail', $_product->get_image( 'woocommerce_thumbnail', array( 'alt' => '' ) ), $cart_item, $cart_item_key );
				?>
				<li class="ccmck-item <?php echo esc_attr( apply_filters( 'woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key ) ); ?>" data-key="<?php echo esc_attr( $cart_item_key ); ?>">

					<span class="ccmck-item__photo" aria-hidden="true">
						<?php
						// Decorativa: el nombre va al lado y lo dice mejor. Un alt
						// con el nombre repetido lo hace leer dos veces.
						echo $permalink
							? '<a href="' . esc_url( $permalink ) . '">' . wp_kses_post( $thumbnail ) . '</a>'
							: wp_kses_post( $thumbnail );
						?>
					</span>

					<div class="ccmck-item__body">
						<span class="ccmck-item__name">
							<?php
							echo wp_kses_post( apply_filters( 'woocommerce_cart_item_name', $permalink ? sprintf( '<a href="%s">%s</a>', esc_url( $permalink ), $_product->get_name() ) : $_product->get_name(), $cart_item, $cart_item_key ) );
							?>
						</span>

						<span class="ccmck-item__meta">
							<?php echo wc_get_formatted_cart_item_data( $cart_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</span>

						<?php if ( $_product->backorders_require_notification() && $_product->is_on_backorder( $cart_item['quantity'] ) ) : ?>
							<span class="ccmck-item__aviso"><?php esc_html_e( 'Disponible bajo pedido', 'ccm-checkout' ); ?></span>
						<?php endif; ?>
					</div>

					<?php
					// La venta individual no admite más ni menos: la cantidad es
					// fija, así que ahí los botones no se pintan. Un botón que no
					// hace nada es un defecto visible.
					$sold_individually = $_product->is_sold_individually();

					// Mismo principio, un paso más allá: cuando la línea ya tiene
					// todas las unidades que hay, el más TAMPOCO hace nada — el
					// JavaScript lo rechaza contra el `max` del campo. Antes se
					// veía igual de pulsable que siempre y el cliente se quedaba
					// dándole sin entender por qué el número no sube. Se pinta
					// apagado, que es lo que ya es.
					//
					// El tope se lee con la ayuda pura, no a pelo: WooCommerce
					// devuelve -1 cuando no hay tope y comparar eso directamente
					// apagaría el botón en toda la tienda.
					$maximo  = (int) $_product->get_max_purchase_quantity();
					$en_tope = CCMCK_Cart_Ajax::en_tope( (int) $cart_item['quantity'], $maximo );
					?>
					<div class="ccmck-qty">
						<?php if ( ! $sold_individually ) : ?>
							<button type="button" class="ccmck-qty__menos" aria-label="<?php esc_attr_e( 'Quitar uno', 'ccm-checkout' ); ?>">&minus;</button>
						<?php endif; ?>

						<?php
						// El campo de cantidad de WooCommerce, con sus límites de
						// stock: el atributo `max` que escribe aquí es el tope que
						// respetan los botones. En venta individual va un `1`
						// visible delante del campo oculto; sin él la cantidad no
						// se lee en ninguna parte.
						echo apply_filters(
							'woocommerce_cart_item_quantity',
							$sold_individually
								? '1 <input type="hidden" name="cart[' . $cart_item_key . '][qty]" value="1" />'
								: woocommerce_quantity_input(
									array(
										'input_name'  => "cart[{$cart_item_key}][qty]",
										'input_value' => $cart_item['quantity'],
										'max_value'   => $_product->get_max_purchase_quantity(),
										'min_value'   => '0',
										'product_name' => $_product->get_name(),
									),
									$_product,
									false
								),
							$cart_item_key,
							$cart_item
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>

						<?php if ( ! $sold_individually ) : ?>
							<?php
							// `disabled` de verdad, no solo un color: así el
							// teclado lo salta en vez de ofrecer un control muerto.
							// La razón va en la etiqueta accesible, porque un
							// botón deshabilitado no muestra `title` en el
							// navegador y quien no ve el gris se quedaría sin
							// saber por qué.
							$etiqueta_mas = $en_tope
								? sprintf(
									/* translators: %d: unidades disponibles */
									_n( 'No hay más: solo queda %d unidad', 'No hay más: solo quedan %d unidades', $maximo, 'ccm-checkout' ),
									$maximo
								)
								: __( 'Añadir uno', 'ccm-checkout' );
							?>
							<button type="button" class="ccmck-qty__mas" aria-label="<?php echo esc_attr( $etiqueta_mas ); ?>" <?php disabled( $en_tope ); ?>>+</button>
						<?php endif; ?>
					</div>

					<span class="ccmck-item__total">
						<?php echo apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $_product, $cart_item['quantity'] ), $cart_item, $cart_item_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</span>

					<?php
					// `product-remove` es el gancho del núcleo: escucha en
					// `.woocommerce-cart-form .product-remove > a`. El <a> tiene que
					// seguir siendo HIJO DIRECTO de este span o el selector no entra.
					?>
					<span class="ccmck-item__remove product-remove">
						<?php
						echo apply_filters( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							'woocommerce_cart_item_remove_link',
							sprintf(
								'<a href="%s" class="remove" aria-label="%s" data-product_id="%s" data-product_sku="%s">&times;</a>',
								esc_url( wc_get_cart_remove_url( $cart_item_key ) ),
								/* translators: %s: nombre del producto */
								esc_attr( sprintf( __( 'Quitar %s del carrito', 'ccm-checkout' ), $_product->get_name() ) ),
								esc_attr( $product_id ),
								esc_attr( $_product->get_sku() )
							),
							$cart_item_key
						);
						?>
					</span>

				</li>
				<?php
			}
			?>

			<?php do_action( 'woocommerce_cart_contents' ); ?>
			<?php do_action( 'woocommerce_after_cart_contents' ); ?>
		</ul>

		<div class="ccmck-cart__acciones">
			<?php do_action( 'woocommerce_cart_actions' ); ?>

			<?php
			// Reposicion: el nucleo traia este boton escrito a mano dentro del
			// <td class="actions">, y al sustituir la tabla se fue con ella. Sin
			// el, escribir una cantidad a mano no cambia nada: el nucleo solo
			// procesa el cambio si le llega $_POST['update_cart']
			// (WC_Form_Handler::update_cart_action).
			//
			// El name="update_cart" y la clase woocommerce-cart-form del
			// formulario son obligatorios: el JavaScript del nucleo busca ese
			// nombre exacto para habilitar el boton en cuanto se toca el campo.
			//
			// La caja de cupon NO va aqui: el diseno la pone en la tarjeta de
			// resumen, que se pinta FUERA de este formulario. Vive en
			// cart-totals.php, con su propio <form>.
			?>
			<button type="submit" class="ccmck-cart__actualizar button" name="update_cart" value="<?php esc_attr_e( 'Actualizar carrito', 'ccm-checkout' ); ?>"><?php esc_html_e( 'Actualizar carrito', 'ccm-checkout' ); ?></button>

			<?php wp_nonce_field( 'woocommerce-cart', 'woocommerce-cart-nonce' ); ?>
		</div>

		<?php do_action( 'woocommerce_after_cart_table' ); ?>
	</form>

	<?php do_action( 'woocommerce_before_cart_collaterals' ); ?>

	<div class="ccmck-cart__resumen">
		<?php do_action( 'woocommerce_cart_collaterals' ); ?>
	</div>

</div>

<?php do_action( 'woocommerce_after_cart' ); ?>
