<?php
defined( 'ABSPATH' ) || exit;

final class CCMCK_Thankyou {
    public static function init(): void {
        // La plantilla thankyou.php se resuelve vía CCMCK_Templates; no hay hooks aquí.
    }

    public static function status_to_step( string $status ): int {
        $map = array(
            'pending'    => 1,
            'failed'     => 1,
            'cancelled'  => 1,
            'processing' => 2,
            'on-hold'    => 3,
            'completed'  => 4,
        );
        return $map[ $status ] ?? 1;
    }

    public static function build_view_model( $order ): array {
        return array(
            'number'        => $order->get_order_number(),
            'email'         => $order->get_billing_email(),
            'active_step'   => self::status_to_step( $order->get_status() ),
            'payment_title' => $order->get_payment_method_title(),
            'shipping_addr' => $order->get_formatted_shipping_address(),
            'billing_addr'  => $order->get_formatted_billing_address(),
            'total'         => $order->get_formatted_order_total(),
        );
    }
}
