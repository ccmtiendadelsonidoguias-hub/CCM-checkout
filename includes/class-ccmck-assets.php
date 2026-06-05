<?php
defined( 'ABSPATH' ) || exit;

final class CCMCK_Assets {
    public static function init(): void {
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
    }

    public static function enqueue(): void {
        if ( ! is_checkout() ) {
            return;
        }
        wp_enqueue_style( 'ccmck-checkout', CCMCK_URL . 'assets/ccmck-checkout.css', array(), self::asset_version( 'assets/ccmck-checkout.css' ) );
        wp_enqueue_script( 'ccmck-checkout', CCMCK_URL . 'assets/ccmck-checkout.js', array( 'jquery' ), self::asset_version( 'assets/ccmck-checkout.js' ), true );
        wp_localize_script( 'ccmck-checkout', 'CCMCK', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'ccmck_cart' ),
        ) );
    }

    /**
     * Versión del asset para cache-busting.
     *
     * Usa filemtime() del archivo en disco (cambia en cada edición → rompe
     * caché del navegador automáticamente). Si el archivo no existe, cae a
     * CCMCK_VERSION como fallback.
     *
     * @param string $relative_path Ruta del asset relativa a la raíz del plugin (CCMCK_DIR).
     * @return string
     */
    private static function asset_version( string $relative_path ): string {
        $file = CCMCK_DIR . ltrim( $relative_path, '/' );

        if ( file_exists( $file ) ) {
            return (string) filemtime( $file );
        }

        return CCMCK_VERSION;
    }
}
