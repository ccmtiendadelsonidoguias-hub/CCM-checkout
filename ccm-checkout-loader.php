<?php
/**
 * Plugin Name: CCM Checkout
 * Description: Checkout personalizado para WooCommerce (reemplazo de CheckoutWC). Reestiliza el checkout clásico y página de gracias, configurable desde WooCommerce › Checkout CCM.
 * Version:     1.0.1
 * Author:      CCM
 *
 * ----------------------------------------------------------------------------
 * IMPORTANTE — UBICACIÓN DE ESTE ARCHIVO
 * ----------------------------------------------------------------------------
 * WordPress NO auto-carga MU-plugins que viven en una subcarpeta. Este archivo
 * es el "stub loader" que debe colocarse en la RAÍZ de mu-plugins:
 *
 *     wp-content/mu-plugins/ccm-checkout-loader.php   ← copia de ESTE archivo
 *     wp-content/mu-plugins/ccm-checkout/             ← carpeta del plugin (este repo)
 *
 * Esta copia dentro del repo es la fuente de verdad versionada. Al desplegar,
 * cópiala a la raíz de mu-plugins (ver docs/DEPLOY.md). Mantén su `Version:`
 * sincronizada con CCMCK_VERSION en ccm-checkout.php.
 */

defined( 'ABSPATH' ) || exit;

$ccmck_bootstrap = __DIR__ . '/ccm-checkout/ccm-checkout.php';

if ( file_exists( $ccmck_bootstrap ) ) {
    require_once $ccmck_bootstrap;
    return;
}

if ( is_admin() ) {
    add_action( 'admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html( 'CCM Checkout no puede iniciar: falta /wp-content/mu-plugins/ccm-checkout/ccm-checkout.php.' )
            . '</p></div>';
    } );
}
