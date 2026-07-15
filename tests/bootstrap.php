<?php
define( 'ABSPATH', __DIR__ );

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) {
        return trim( preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags_stub( (string) $str ) ) );
    }
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
    function sanitize_textarea_field( $str ) {
        // Como WP: quita etiquetas pero conserva los saltos de línea.
        return trim( wp_strip_all_tags_stub( (string) $str ) );
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
if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( $url ) { return esc_url_raw( $url ); }
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
if ( ! function_exists( '_n' ) ) {
    function _n( $single, $plural, $number, $domain = 'default' ) {
        return 1 === (int) $number ? $single : $plural;
    }
}
if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $s, $d = 'default' ) { return $s; }
}
if ( ! function_exists( 'esc_attr__' ) ) {
    function esc_attr__( $s, $d = 'default' ) { return $s; }
}
if ( ! function_exists( 'esc_html_e' ) ) {
    function esc_html_e( $s, $d = 'default' ) { echo esc_html( $s ); }
}
if ( ! function_exists( 'esc_attr_e' ) ) {
    function esc_attr_e( $s, $d = 'default' ) { echo esc_attr( $s ); }
}
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $hook, $value ) { return $value; }
}
if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( $value ) {
        return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $name, $default = false ) { return $default; }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public $errors = array();
        public $error_data = array();
        public function __construct( $code = '', $message = '', $data = '' ) {
            if ( '' !== $code ) { $this->add( $code, $message, $data ); }
        }
        public function add( $code, $message = '', $data = '' ) {
            $this->errors[ $code ][] = $message;
            if ( '' !== $data ) { $this->error_data[ $code ] = $data; }
        }
        public function remove( $code ) {
            unset( $this->errors[ $code ], $this->error_data[ $code ] );
        }
        public function get_error_codes() { return array_keys( $this->errors ); }
        public function get_error_messages( $code = '' ) {
            if ( '' === $code ) {
                $all = array();
                foreach ( $this->errors as $msgs ) { $all = array_merge( $all, $msgs ); }
                return $all;
            }
            return $this->errors[ $code ] ?? array();
        }
    }
}

if ( ! class_exists( 'WC_Shipping_Rate' ) ) {
    class WC_Shipping_Rate {
        public $id; public $label; public $cost; public $taxes; public $method_id;
        public function __construct( $id = '', $label = '', $cost = 0, $taxes = array(), $method_id = '' ) {
            $this->id = $id; $this->label = $label; $this->cost = $cost;
            $this->taxes = $taxes; $this->method_id = $method_id;
        }
        public function get_id() { return $this->id; }
        public function get_label() { return $this->label; }
        public function get_cost() { return $this->cost; }
        public $meta_data = array();
        public function add_meta_data( $key, $value ) { $this->meta_data[ $key ] = $value; }
        public function get_meta_data() { return $this->meta_data; }
    }
}

require_once dirname( __DIR__ ) . '/includes/class-ccmck-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-info-cards.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-faq.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-document.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-payments.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-thankyou.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-shipping.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-pickup.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-dequeue.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-surcharge.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-coordinadora.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-guias.php';
