<?php
use PHPUnit\Framework\TestCase;

final class DequeueTest extends TestCase {
    public function test_handles_returns_scripts_and_styles_lists(): void {
        $handles = CCMCK_Dequeue::handles();
        $this->assertArrayHasKey( 'scripts', $handles );
        $this->assertArrayHasKey( 'styles', $handles );
        $this->assertNotEmpty( $handles['scripts'] );
        $this->assertNotEmpty( $handles['styles'] );
    }

    public function test_drops_elementor_and_addon_cruft(): void {
        $scripts = CCMCK_Dequeue::handles()['scripts'];
        $styles  = CCMCK_Dequeue::handles()['styles'];
        // Elementor (free + pro) + addons que el checkout clásico no usa.
        $this->assertContains( 'elementor-frontend', $scripts );
        $this->assertContains( 'elementor-pro-frontend', $scripts );
        $this->assertContains( 'eael-general', $scripts );
        $this->assertContains( 'lightslider', $scripts );
        $this->assertContains( 'elementor-frontend', $styles );
    }

    public function test_never_drops_payment_or_theme_critical_handles(): void {
        $all = array_merge(
            CCMCK_Dequeue::handles()['scripts'],
            CCMCK_Dequeue::handles()['styles']
        );
        // El tema Hello renderiza el shell de la página: NO tocar.
        $this->assertNotContains( 'hello-theme-frontend', $all );
        $this->assertNotContains( 'hello-elementor-theme-style', $all );
        // Addi es pasarela de pago y su integración depende de wc-blocks: NO tocar.
        $this->assertNotContains( 'widget-addi', $all );
        $this->assertNotContains( 'wc-addi-blocks-integration', $all );
        $this->assertNotContains( 'wc-blocks-checkout', $all );
        // Iconos y popup de WhatsApp: NO tocar.
        $this->assertNotContains( 'font-awesomeWpf', $all );
        $this->assertNotContains( 'nta-js-popup', $all );
    }
}
