<?php
use PHPUnit\Framework\TestCase;

final class ThankyouTest extends TestCase {
    public function test_status_to_step_maps_known_states(): void {
        $this->assertSame( 1, CCMCK_Thankyou::status_to_step( 'pending' ) );
        $this->assertSame( 2, CCMCK_Thankyou::status_to_step( 'processing' ) );
        $this->assertSame( 3, CCMCK_Thankyou::status_to_step( 'on-hold' ) );
        $this->assertSame( 4, CCMCK_Thankyou::status_to_step( 'completed' ) );
    }

    public function test_status_to_step_defaults_to_first_step(): void {
        $this->assertSame( 1, CCMCK_Thankyou::status_to_step( 'cualquier-otro' ) );
    }
}
