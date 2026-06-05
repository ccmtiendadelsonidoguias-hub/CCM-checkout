<?php
defined( 'ABSPATH' ) || exit;

final class CCMCK_Branding {
    public static function init(): void {
        add_action( 'wp_head', array( __CLASS__, 'print_vars' ), 99 );
    }

    public static function print_vars(): void {
        if ( ! is_checkout() ) {
            return;
        }
        $accent  = CCMCK_Settings::get( 'accent_color', '#e63946' ) ?: '#e63946';
        $sidebar = CCMCK_Settings::get( 'sidebar_color', '#1a1a1a' ) ?: '#1a1a1a';
        echo '<style id="ccmck-vars">:root{--ccmck-accent:' . esc_html( $accent ) . ';--ccmck-sidebar:' . esc_html( $sidebar ) . ';}</style>';
    }
}
