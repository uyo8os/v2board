<?php

namespace Tests\Unit;

use App\Utils\ExternalUrlValidator;
use PHPUnit\Framework\TestCase;

class ExternalUrlValidatorTest extends TestCase
{
    public function testImageHostRequiresHttpsAndPublicDnsName()
    {
        $this->assertTrue(ExternalUrlValidator::inspectImageUploadUrl('https://img.example.com/api/index.php', false)['valid']);
        $this->assertFalse(ExternalUrlValidator::inspectImageUploadUrl('http://img.example.com/api/index.php', false)['valid']);
        $this->assertFalse(ExternalUrlValidator::inspectImageUploadUrl('https://127.0.0.1/api/index.php', false)['valid']);
        $this->assertFalse(ExternalUrlValidator::inspectImageUploadUrl('https://localhost/api/index.php', false)['valid']);
    }

    public function testImageHostRejectsCredentialsAndNonDefaultPort()
    {
        $this->assertFalse(ExternalUrlValidator::inspectImageUploadUrl('https://user:pass@img.example.com/api/index.php', false)['valid']);
        $this->assertFalse(ExternalUrlValidator::inspectImageUploadUrl('https://img.example.com:8443/api/index.php', false)['valid']);
    }
}
