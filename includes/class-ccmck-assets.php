<?php
defined( 'ABSPATH' ) || exit;

final class CCMCK_Assets {
    public static function init(): void {
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        // Preload de la imagen LCP (logo) lo antes posible en el <head>.
        add_action( 'wp_head', array( __CLASS__, 'preload_lcp' ), 1 );
    }

    /**
     * Precarga el logo del checkout (candidato a LCP) para que el preload
     * scanner lo baje primero. Solo en el checkout y si hay logo configurado.
     */
    public static function preload_lcp(): void {
        if ( ! is_checkout() ) {
            return;
        }
        $logo = CCMCK_Settings::get( 'logo_image', '' );
        if ( empty( $logo ) ) {
            return;
        }
        printf(
            '<link rel="preload" as="image" href="%s" fetchpriority="high" />' . "\n",
            esc_url( $logo )
        );
    }

    /**
     * ¿Toca cargar los assets del carrito? PURO.
     *
     * Se separa del `enqueue()` para poder probarlo: `is_cart()` necesita
     * WordPress entero y el banco de pruebas no lo carga.
     */
    public static function loads_cart( bool $is_cart ): bool {
        return $is_cart;
    }

    public static function enqueue(): void {
        if ( self::loads_cart( is_cart() ) ) {
            wp_enqueue_style( 'ccmck-cart', CCMCK_URL . 'assets/ccmck-cart.css', array(), self::asset_version( 'assets/ccmck-cart.css' ) );
            wp_enqueue_script( 'ccmck-cart', CCMCK_URL . 'assets/ccmck-cart.js', array(), self::asset_version( 'assets/ccmck-cart.js' ), true );

            // El endpoint y el nonce que ya usa CCMCK_Cart_Ajax.
            wp_localize_script( 'ccmck-cart', 'ccmckCart', array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'ccmck_cart' ),
            ) );

            // Repoblado del desplegable de ciudad al cambiar de departamento.
            // Va aqui, ANTES del return de is_checkout(), o no se cargaria nunca
            // en el carrito.
            wp_enqueue_script(
                'ccmck-cart-city',
                CCMCK_URL . 'assets/ccmck-cart-city.js',
                array(),
                self::asset_version( 'assets/ccmck-cart-city.js' ),
                true
            );
            wp_localize_script( 'ccmck-cart-city', 'ccmckCartCity', array(
                'rest'     => esc_url_raw( rest_url( 'ccmck/v1/ciudades' ) ),
                'elige'    => __( 'Elige tu ciudad', 'ccm-checkout' ),
                'cargando' => __( 'Cargando ciudades…', 'ccm-checkout' ),
            ) );
        }

        if ( ! is_checkout() ) {
            return;
        }
        wp_enqueue_style( 'ccmck-checkout', CCMCK_URL . 'assets/ccmck-checkout.css', array(), self::asset_version( 'assets/ccmck-checkout.css' ) );
        wp_enqueue_script( 'ccmck-checkout', CCMCK_URL . 'assets/ccmck-checkout.js', array( 'jquery' ), self::asset_version( 'assets/ccmck-checkout.js' ), true );
        wp_localize_script( 'ccmck-checkout', 'CCMCK', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'ccmck_cart' ),
        ) );

        // Este sitio tiene un optimizador (LiteSpeed descartado; probable snippet/tema que
        // engancha style_loader_src/script_loader_src) que ELIMINA el ?ver= de TODOS los
        // assets. Con max-age de 1 año del hosting, eso deja la caché del navegador clavada.
        // Re-aplicamos la versión SOLO a los assets de ccmck con prioridad máxima y registro
        // tardío (estamos en wp_enqueue_scripts) para ejecutarnos DESPUÉS del stripper.
        add_filter( 'style_loader_src',  array( __CLASS__, 'force_version' ), PHP_INT_MAX, 2 );
        add_filter( 'script_loader_src', array( __CLASS__, 'force_version' ), PHP_INT_MAX, 2 );
    }

    /**
     * Reañade ?ver=<filemtime> a los assets de ccmck si un filtro global lo quitó.
     *
     * Ambos assets comparten el handle 'ccmck-checkout' (uno como style, otro como
     * script), así que distinguimos el archivo por la extensión presente en $src.
     *
     * @param string $src    URL del asset (posiblemente ya sin query string).
     * @param string $handle Handle registrado del asset.
     * @return string
     */
    public static function force_version( string $src, string $handle ): string {
        if ( 'ccmck-checkout' !== $handle ) {
            return $src;
        }

        $relative = ( false !== strpos( $src, '.js' ) )
            ? 'assets/ccmck-checkout.js'
            : 'assets/ccmck-checkout.css';

        $src = remove_query_arg( 'ver', $src );

        return add_query_arg( 'ver', self::asset_version( $relative ), $src );
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
