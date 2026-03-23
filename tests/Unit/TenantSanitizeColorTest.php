<?php

namespace Tests\Unit;

use App\Models\Tenant;
use PHPUnit\Framework\TestCase;

class TenantSanitizeColorTest extends TestCase
{
    public function test_valid_hex_3_digit_returned_as_is(): void
    {
        $this->assertSame('#fff', Tenant::sanitizeColor('#fff'));
    }

    public function test_valid_hex_6_digit_returned_as_is(): void
    {
        $this->assertSame('#FF9900', Tenant::sanitizeColor('#FF9900'));
    }

    public function test_valid_hex_8_digit_returned_as_is(): void
    {
        $this->assertSame('#FF990080', Tenant::sanitizeColor('#FF990080'));
    }

    public function test_valid_rgb_returned_as_is(): void
    {
        $this->assertSame('rgb(255, 128, 0)', Tenant::sanitizeColor('rgb(255, 128, 0)'));
    }

    public function test_valid_rgb_no_spaces_returned_as_is(): void
    {
        $this->assertSame('rgb(255,128,0)', Tenant::sanitizeColor('rgb(255,128,0)'));
    }

    public function test_valid_hsl_returned_as_is(): void
    {
        $this->assertSame('hsl(210, 50%, 80%)', Tenant::sanitizeColor('hsl(210, 50%, 80%)'));
    }

    public function test_css_injection_returns_default(): void
    {
        $this->assertSame('#6366f1', Tenant::sanitizeColor('red; } body { display:none } .x {'));
    }

    public function test_script_injection_returns_default(): void
    {
        $this->assertSame('#6366f1', Tenant::sanitizeColor('</style><script>alert(1)</script>'));
    }

    public function test_expression_injection_returns_default(): void
    {
        $this->assertSame('#6366f1', Tenant::sanitizeColor('expression(alert(1))'));
    }

    public function test_url_injection_returns_default(): void
    {
        $this->assertSame('#6366f1', Tenant::sanitizeColor('url(javascript:alert(1))'));
    }

    public function test_null_returns_default(): void
    {
        $this->assertSame('#6366f1', Tenant::sanitizeColor(null));
    }

    public function test_empty_string_returns_default(): void
    {
        $this->assertSame('#6366f1', Tenant::sanitizeColor(''));
    }

    public function test_custom_default_is_used(): void
    {
        $this->assertSame('#FF9900', Tenant::sanitizeColor(null, '#FF9900'));
    }

    public function test_named_color_returns_default(): void
    {
        $this->assertSame('#6366f1', Tenant::sanitizeColor('red'));
    }

    public function test_hex_with_extra_chars_returns_default(): void
    {
        $this->assertSame('#6366f1', Tenant::sanitizeColor('#fff; background: red'));
    }
}
