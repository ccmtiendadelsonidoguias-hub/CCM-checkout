<?php
/**
 * Aviso de compra grande en el checkout.
 *
 * Por qué existe (medido sobre pedidos reales, may–jul 2026): la tasa de pagos
 * fallidos crece con el monto — hasta $1M falla el 19%, entre $3M y $5M el 92%,
 * y por encima de $5M **el 100%** (15 intentos, 15 fallos). El pago más grande
 * que ha entrado por cualquier medio son $5.375.473. En ese tramo se cayeron 15
 * intentos por $150.286.822 y ninguno de esos clientes volvió a comprar; el 30%
 * de los fallidos son de gente reintentando el mismo carrito varias veces.
 *
 * Así que no es que se arrepientan: el checkout no los deja pagar. Este aviso les
 * ofrece coordinar el pago con un asesor ANTES de que la pasarela los rechace.
 *
 * NO bloquea el pago: quien quiera intentarlo por la pasarela puede hacerlo — un
 * bloqueo dejaría fuera a los que sí habrían pasado.
 *
 * @package CCM_Checkout
 */

defined( 'ABSPATH' ) || exit;

final class CCMCK_Highvalue {

    /** Umbral por defecto (COP). Por debajo del techo real ($4.87M) para avisar antes del rechazo. */
    const UMBRAL_DEFAULT = 4500000;

    /**
     * ¿Toca avisar? PURO.
     *
     * @param float $total  Total del carrito.
     * @param int   $umbral Monto a partir del cual se avisa (0 = desactivado).
     */
    public static function should_warn( float $total, int $umbral ): bool {
        if ( $umbral <= 0 ) {
            return false;
        }
        return $total >= (float) $umbral;
    }

    /**
     * Mensaje pre-escrito para el chat. PURO. Lleva el monto para que el asesor
     * ubique el caso sin preguntar.
     *
     * @param float $total Total del carrito.
     */
    public static function build_message( float $total ): string {
        return sprintf(
            'Hola, quiero hacer una compra de %s en ccmtiendadelsonido.com y me gustaría coordinar el pago con un asesor.',
            '$' . number_format( round( $total ), 0, ',', '.' )
        );
    }

    /**
     * URL de wa.me con el mensaje. PURO. '' si no hay número configurado.
     *
     * @param string $number Solo dígitos (ej. 573178119077).
     * @param float  $total  Total del carrito.
     */
    public static function build_wa_url( string $number, float $total ): string {
        $number = preg_replace( '/[^0-9]/', '', $number );
        if ( '' === $number ) {
            return '';
        }
        return 'https://wa.me/' . $number . '?text=' . rawurlencode( self::build_message( $total ) );
    }

    /** Total del carrito actual, 0.0 si no hay carrito. */
    private static function cart_total(): float {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return 0.0;
        }
        return (float) WC()->cart->get_total( 'edit' );
    }

    /** Hook woocommerce_review_order_before_payment: el aviso va justo antes de los medios de pago. */
    public static function render(): void {
        if ( ! CCMCK_Settings::get( 'highvalue_enabled', true ) ) {
            return;
        }
        $umbral = (int) CCMCK_Settings::get( 'highvalue_threshold', self::UMBRAL_DEFAULT );
        $total  = self::cart_total();
        if ( ! self::should_warn( $total, $umbral ) ) {
            return;
        }
        $url = self::build_wa_url( (string) CCMCK_Settings::get( 'whatsapp_number', '' ), $total );
        if ( '' === $url ) {
            return;
        }
        ?>
        <div class="ccmck-hv" style="margin:14px 0;padding:14px 16px;border:1px solid #f0c36d;background:#fff8e6;border-radius:8px;line-height:1.45">
            <strong style="display:block;margin-bottom:6px;font-size:15px">💬 ¿Prefieres que te ayudemos con el pago?</strong>
            <span style="display:block;margin-bottom:10px;font-size:14px;color:#5b4a1f">
                En compras de este valor el banco suele rechazar el pago en línea por los límites de la tarjeta.
                Escríbenos y coordinamos transferencia o consignación en minutos — sin recargo.
            </span>
            <a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener nofollow"
               style="display:inline-block;background:#25D366;color:#fff;font-weight:600;text-decoration:none;padding:9px 16px;border-radius:6px;font-size:14px">
                Coordinar mi pago por WhatsApp
            </a>
            <span style="display:block;margin-top:8px;font-size:12px;color:#7a6a3f">
                También puedes intentar el pago en línea normalmente.
            </span>
        </div>
        <?php
    }

    public static function init(): void {
        add_action( 'woocommerce_review_order_before_payment', array( __CLASS__, 'render' ), 10 );
    }
}
