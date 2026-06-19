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
        $button  = CCMCK_Settings::get( 'button_color', '' );

        $vars = '--ccmck-accent:' . esc_html( $accent ) . ';--ccmck-sidebar:' . esc_html( $sidebar ) . ';';
        // Solo si el usuario fijó un color de botón; si no, el CSS cae al acento.
        if ( is_string( $button ) && '' !== $button ) {
            $vars .= '--ccmck-button:' . esc_html( $button ) . ';';
        }

        echo '<style id="ccmck-vars">:root{' . $vars . '}</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}
