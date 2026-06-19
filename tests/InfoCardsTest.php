<?php
use PHPUnit\Framework\TestCase;

final class InfoCardsTest extends TestCase {

	public function test_markup_empty_returns_empty_string(): void {
		$this->assertSame( '', CCMCK_Info_Cards::markup( array() ) );
	}

	public function test_markup_skips_rows_without_title(): void {
		$out = CCMCK_Info_Cards::markup( array(
			array( 'icon' => '🚚', 'title' => '', 'text' => 'sin titulo' ),
			array( 'title' => '   ' ),
		) );
		$this->assertSame( '', $out );
	}

	public function test_markup_emits_card_with_icon_title_and_text(): void {
		$out = CCMCK_Info_Cards::markup( array(
			array( 'icon' => '🚚', 'title' => '1 - 3 Días hábiles', 'text' => 'En ciudades principales' ),
		) );
		$this->assertStringContainsString( '<div class="delivery-info">', $out );
		$this->assertStringContainsString( '<div class="delivery-card">', $out );
		$this->assertStringContainsString( '🚚', $out );
		$this->assertStringContainsString( '<strong>1 - 3 Días hábiles</strong>', $out );
		$this->assertStringContainsString( '<span>En ciudades principales</span>', $out );
	}

	public function test_markup_omits_icon_and_text_when_empty(): void {
		$out = CCMCK_Info_Cards::markup( array(
			array( 'icon' => '', 'title' => 'Solo título', 'text' => '' ),
		) );
		$this->assertStringNotContainsString( 'dc-icon', $out );
		$this->assertStringNotContainsString( '<span>', $out );
		$this->assertStringContainsString( '<strong>Solo título</strong>', $out );
	}

	public function test_markup_renders_multiple_cards_in_order(): void {
		$out = CCMCK_Info_Cards::markup( array(
			array( 'icon' => '🚚', 'title' => 'Primera', 'text' => '' ),
			array( 'icon' => '📦', 'title' => 'Segunda', 'text' => '' ),
		) );
		$this->assertSame( 2, substr_count( $out, 'delivery-card' ) );
		$this->assertLessThan( strpos( $out, 'Segunda' ), strpos( $out, 'Primera' ) );
	}

	public function test_markup_escapes_html_in_values(): void {
		$out = CCMCK_Info_Cards::markup( array(
			array( 'icon' => '', 'title' => '<script>x</script>', 'text' => 'a & b' ),
		) );
		$this->assertStringNotContainsString( '<script>', $out );
		$this->assertStringContainsString( 'a &amp; b', $out );
	}

	public function test_markup_renders_image_when_set(): void {
		$out = CCMCK_Info_Cards::markup( array(
			array( 'image' => 'https://cdn.test/x.png', 'title' => 'Envío', 'text' => '' ),
		) );
		$this->assertStringContainsString( '<div class="dc-icon" aria-hidden="true"><img src="https://cdn.test/x.png" alt="">', $out );
	}

	public function test_markup_image_takes_priority_over_emoji(): void {
		$out = CCMCK_Info_Cards::markup( array(
			array( 'icon' => '🚚', 'image' => 'https://cdn.test/x.png', 'title' => 'Envío', 'text' => '' ),
		) );
		$this->assertStringContainsString( '<img src="https://cdn.test/x.png"', $out );
		$this->assertStringNotContainsString( '🚚', $out );
	}

	public function test_markup_falls_back_to_emoji_when_image_empty(): void {
		$out = CCMCK_Info_Cards::markup( array(
			array( 'icon' => '🚚', 'image' => '', 'title' => 'Envío', 'text' => '' ),
		) );
		$this->assertStringNotContainsString( '<img', $out );
		$this->assertStringContainsString( '🚚', $out );
	}
}
