<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ModelIdentity;
use PHPUnit\Framework\TestCase;

class ModelIdentityTest extends TestCase
{
    public function test_plus_is_part_of_the_identity(): void
    {
        $this->assertNotSame(ModelIdentity::normalize('MV7'), ModelIdentity::normalize('MV7+'));
        $this->assertSame(ModelIdentity::normalize('MV7+'), ModelIdentity::normalize('mv7 +'));
    }

    public function test_case_and_punctuation_are_ignored(): void
    {
        $this->assertSame(ModelIdentity::normalize('Wave:3'), ModelIdentity::normalize('Wave 3'));
        $this->assertSame('', ModelIdentity::normalize(null));
    }
}
