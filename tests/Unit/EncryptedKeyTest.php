<?php

declare( strict_types=1 );

namespace LicenseKit\Tests\Unit;

use LicenseKit\Services\EncryptedKey;
use PHPUnit\Framework\TestCase;

final class EncryptedKeyTest extends TestCase {

	protected function setUp(): void {
		lk_test_reset_state();
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->markTestSkipped( 'libsodium not available' );
		}
	}

	public function test_round_trip(): void {
		$plaintext = 'LK1-A7F2-XXXX-YYYY-ZZZZ-WWWW';
		$encoded   = EncryptedKey::encrypt( $plaintext );
		$this->assertNotNull( $encoded );
		$this->assertNotSame( $plaintext, $encoded );
		$this->assertSame( $plaintext, EncryptedKey::decrypt( $encoded ) );
	}

	public function test_two_encryptions_use_different_nonces(): void {
		$plain = 'LK1-AAAA-BBBB-CCCC-DDDD-EEEE';
		$a     = EncryptedKey::encrypt( $plain );
		$b     = EncryptedKey::encrypt( $plain );
		$this->assertNotSame( $a, $b, 'each encryption should have a fresh nonce' );
		// But both decrypt to the same plaintext.
		$this->assertSame( $plain, EncryptedKey::decrypt( $a ) );
		$this->assertSame( $plain, EncryptedKey::decrypt( $b ) );
	}

	public function test_decrypt_with_different_pepper_fails(): void {
		$plain   = 'LK1-XXXX-YYYY-ZZZZ-AAAA-BBBB';
		$encoded = EncryptedKey::encrypt( $plain );

		// Rotate the pepper.
		$GLOBALS['__lk_options']['licensekit_secrets']['hash_pepper'] = 'different-pepper';

		$this->assertNull( EncryptedKey::decrypt( $encoded ), 'pepper rotation should invalidate previously-encrypted keys' );
	}

	public function test_decrypt_handles_garbage(): void {
		$this->assertNull( EncryptedKey::decrypt( null ) );
		$this->assertNull( EncryptedKey::decrypt( '' ) );
		$this->assertNull( EncryptedKey::decrypt( 'not-base64!!!' ) );
		$this->assertNull( EncryptedKey::decrypt( 'YWJj' ) ); // valid base64 but too short for a nonce.
	}

	public function test_tampered_ciphertext_fails(): void {
		$encoded = EncryptedKey::encrypt( 'LK1-A7F2-B3C4-D5E6-F7G8-H9JK' );
		$this->assertNotNull( $encoded );
		// Flip a byte in the middle.
		$tampered = substr( $encoded, 0, -3 ) . 'XXX';
		$this->assertNull( EncryptedKey::decrypt( $tampered ) );
	}

	public function test_empty_string_encrypts_round_trip(): void {
		$encoded = EncryptedKey::encrypt( '' );
		$this->assertNotNull( $encoded );
		$this->assertSame( '', EncryptedKey::decrypt( $encoded ) );
	}
}
