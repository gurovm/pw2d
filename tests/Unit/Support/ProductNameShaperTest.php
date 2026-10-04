<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ProductNameShaper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec 041 Part 2 — inputs are real prod names measured on 2026-10-04.
 */
class ProductNameShaperTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_shape(string $name, string $brand, string $expected): void
    {
        $this->assertSame($expected, ProductNameShaper::shape($name, $brand));
    }

    /** @return array<string, array{string, string, string}> */
    public static function cases(): array
    {
        return [
            'brandless model-only name gets the brand'     => ['UWP-D', 'Sony', 'Sony UWP-D'],
            'trailing connector removed, brand first'      => ['MVL Lavalier Microphone for iPhone & Tablet -', 'Shure', 'Shure MVL Lavalier Microphone for iPhone & Tablet'],
            'leading bracket junk and brand case fixed'    => ['【Iron Gray】KINGrinder K7 Manual Coffee Grinder', 'Kingrinder', 'Kingrinder K7 Manual Coffee Grinder'],
            'brand already first is left in place'         => ['RK ROYAL KLUDGE S70 Wireless Mechanical Keyboard', 'RK ROYAL KLUDGE', 'RK ROYAL KLUDGE S70 Wireless Mechanical Keyboard'],
            'brand spelling canonicalised'                 => ['SE ELECTRONICS V7', 'sE Electronics', 'sE Electronics V7'],
            'brand later in the name is not duplicated'    => ['Advantage2 Ergonomic Keyboard by Kinesis', 'Kinesis', 'Advantage2 Ergonomic Keyboard by Kinesis'],
            'brandless name with no brand anywhere'        => ['Advantage2 Ergonomic Keyboard', 'Kinesis', 'Kinesis Advantage2 Ergonomic Keyboard'],
            'cut at comma'                                 => ['Hollyland Lark M2 Wireless Microphone, 48kHz', 'Hollyland', 'Hollyland Lark M2 Wireless Microphone'],
            'cut at parenthesis'                           => ['Rode Wireless GO II (Black)', 'Rode', 'Rode Wireless GO II'],
            'cut at pipe'                                  => ['Elgato Wave:3 | Premium Mic', 'Elgato', 'Elgato Wave:3'],
            'trailing pipe attached to a word'             => ['Corsair K70 PRO TKL RGB|', 'Corsair', 'Corsair K70 PRO TKL RGB'],
            'trailing stopword'                            => ['Keychron K6 with', 'Keychron', 'Keychron K6'],
            'plus attached to a model is kept'             => ['Shure MV7+', 'Shure', 'Shure MV7+'],
            'standalone trailing plus is dropped'          => ['Shure MV7 +', 'Shure', 'Shure MV7'],
            'capped at eight words'                        => ['Keychron K6 Bluetooth 5.1 Wireless Mechanical Keyboard Compact Layout Mac', 'Keychron', 'Keychron K6 Bluetooth 5.1 Wireless Mechanical Keyboard Compact'],
            'cap leaves no dangling stopword'              => ['Keychron K6 Bluetooth 5.1 Wireless Mechanical Keyboard for Mac', 'Keychron', 'Keychron K6 Bluetooth 5.1 Wireless Mechanical Keyboard'],
            'brand must end on a word boundary'            => ['Rodecaster Pro II', 'Rode', 'Rode Rodecaster Pro II'],
            'short AI name is kept as is'                  => ['Sony WH-1000XM5', 'Sony', 'Sony WH-1000XM5'],
            'brand-only name stays brand-only'             => ['Breville', 'Breville', 'Breville'],
        ];
    }
}
