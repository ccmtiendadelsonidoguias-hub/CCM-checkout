<?php
define( 'ABSPATH', __DIR__ );

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) {
        return trim( preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags_stub( (string) $str ) ) );
    }
}
function wp_strip_all_tags_stub( string $s ): string {
    return preg_replace( '/<[^>]*>/', '', $s );
}
if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( $url ) {
        $url = trim( (string) $url );
        return preg_match( '#^(https?:|/|tel:|mailto:)#i', $url ) ? $url : '';
    }
}
if ( ! function_exists( 'wp_kses_post' ) ) {
    function wp_kses_post( $s ) { return (string) $s; }
}
if ( ! function_exists( 'sanitize_hex_color' ) ) {
    function sanitize_hex_color( $color ) {
        $color = (string) $color;
        return preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color ) ? $color : null;
    }
}
if ( ! function_exists( 'absint' ) ) {
    function absint( $n ) { return abs( (int) $n ); }
}
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) { return $text; }
}

require_once dirname( __DIR__ ) . '/includes/class-ccmck-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-document.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-payments.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-thankyou.php';
