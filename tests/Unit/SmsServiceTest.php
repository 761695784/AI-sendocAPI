<?php

namespace Tests\Unit;

use App\Services\SmsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SmsServiceTest extends TestCase
{
    public static function phones(): array
    {
        return [
            ['77 123 45 67', '+221771234567'],
            ['771234567', '+221771234567'],
            ['+221771234567', '+221771234567'],
            ['+221 77 123 45 67', '+221771234567'],
            ['00221771234567', '+221771234567'],
            ['221771234567', '+221771234567'],
            ['338201234', '+221338201234'],
            ['12345', null],
            ['791234567', null],
            ['+33612345678', null],
            ['', null],
            [null, null],
        ];
    }

    #[DataProvider('phones')]
    public function test_normalizes_senegalese_numbers(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, SmsService::normalizeSenegalPhone($input));
    }

    public function test_masks_phone_numbers(): void
    {
        $this->assertSame('*********4567', SmsService::maskPhone('+221771234567'));
    }
}
