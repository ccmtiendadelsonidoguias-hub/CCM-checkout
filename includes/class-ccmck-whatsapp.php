<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pop-up de WhatsApp en la página de gracias: a los 5 s de confirmar la compra
 * se abre un <dialog> que ofrece continuar la conversación por WhatsApp con un
 * mensaje pre-escrito (nombre del cliente + número de pedido). Una sola vez por
 * pedido (localStorage). 100 % front: sin AJAX ni nonce (page cache friendly).
 */
final class CCMCK_Whatsapp {

    /** Milisegundos de espera antes de abrir el pop-up. */
    const DELAY_MS = 5000;

    public static function init(): void {
        add_action( 'woocommerce_thankyou', array( __CLASS__, 'render_popup' ), 5 );
    }

    /**
     * Mensaje pre-escrito del chat. PURO.
     *
     * @param string $name         Nombre del cliente (puede venir vacío).
     * @param string $order_number Número visible del pedido.
     */
    public static function build_message( string $name, string $order_number ): string {
        $name = trim( $name );
        $who  = '' !== $name ? sprintf( 'Hola, soy %s.', $name ) : 'Hola.';
        return sprintf(
            '%s Acabo de realizar el pedido #%s en ccmtiendadelsonido.com y quiero confirmar mi compra.',
            $who,
            $order_number
        );
    }

    /**
     * URL wa.me con el mensaje pre-escrito. PURO. Devuelve '' si no hay número.
     *
     * @param string $number       Solo dígitos (ej. 573178119077).
     * @param string $name         Nombre del cliente.
     * @param string $order_number Número visible del pedido.
     */
    public static function build_wa_url( string $number, string $name, string $order_number ): string {
        $number = preg_replace( '/[^0-9]/', '', $number );
        if ( '' === $number ) {
            return '';
        }
        return 'https://wa.me/' . $number . '?text=' . rawurlencode( self::build_message( $name, $order_number ) );
    }

    /** Hook woocommerce_thankyou: imprime el <dialog> + JS inline. */
    public static function render_popup( $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order || $order->has_status( 'failed' ) ) {
            return;
        }

        $s = CCMCK_Settings::all();
        if ( empty( $s['whatsapp_enabled'] ) ) {
            return;
        }

        $name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $url  = self::build_wa_url( (string) $s['whatsapp_number'], $name, (string) $order->get_order_number() );
        if ( '' === $url ) {
            return;
        }

        $title    = ! empty( $s['whatsapp_title'] ) ? $s['whatsapp_title'] : __( '¿Necesitas ayuda con tu pedido?', 'ccm-checkout' );
        $subtitle = ! empty( $s['whatsapp_subtitle'] ) ? $s['whatsapp_subtitle'] : __( 'Escríbenos por WhatsApp', 'ccm-checkout' );
        ?>
        <dialog class="ccmck-wa-dialog" id="ccmck-wa-dialog">
            <button type="button" class="ccmck-wa-close" aria-label="<?php esc_attr_e( 'Cerrar', 'ccm-checkout' ); ?>">&times;</button>
            <div class="ccmck-wa-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="34" height="34" fill="currentColor"><path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.87 9.87 0 004.74 1.21h.01c5.46 0 9.9-4.45 9.9-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0012.04 2zm5.83 14.12c-.25.7-1.45 1.34-2 1.4-.51.05-1.16.08-1.87-.12-.43-.13-.99-.31-1.7-.62-2.99-1.29-4.94-4.3-5.09-4.5-.15-.2-1.22-1.62-1.22-3.09 0-1.47.77-2.19 1.05-2.49.27-.3.6-.37.8-.37h.57c.19.01.44-.07.68.53.25.6.85 2.07.92 2.22.08.15.13.33.03.53-.1.2-.15.32-.3.5-.15.17-.31.39-.45.52-.15.15-.3.31-.13.6.17.3.76 1.26 1.64 2.04 1.13 1.01 2.08 1.32 2.38 1.47.3.15.47.13.65-.08.17-.2.74-.87.94-1.17.2-.3.4-.25.68-.15.27.1 1.74.82 2.04.97.3.15.5.22.57.35.08.12.08.72-.17 1.42z"/></svg>
            </div>
            <h3 class="ccmck-wa-title"><?php echo esc_html( $title ); ?></h3>
            <p class="ccmck-wa-subtitle"><?php echo esc_html( $subtitle ); ?></p>
            <a class="ccmck-wa-btn" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">
                <?php esc_html_e( 'Abrir WhatsApp', 'ccm-checkout' ); ?>
            </a>
        </dialog>
        <script>
        (function () {
            var d = document.getElementById('ccmck-wa-dialog');
            if (!d || typeof d.showModal !== 'function') { return; }
            var key = 'ccmck_wa_<?php echo (int) $order_id; ?>';
            try { if (window.localStorage.getItem(key)) { return; } } catch (e) {}
            setTimeout(function () {
                try { window.localStorage.setItem(key, '1'); } catch (e) {}
                try { d.showModal(); } catch (e) { return; }
                requestAnimationFrame(function () { d.classList.add('is-open'); });
            }, <?php echo (int) self::DELAY_MS; ?>);
            d.querySelector('.ccmck-wa-close').addEventListener('click', function () { d.close(); });
            d.addEventListener('click', function (e) { if (e.target === d) { d.close(); } });
            d.addEventListener('close', function () { d.classList.remove('is-open'); });
        })();
        </script>
        <?php
    }
}
