<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Testes HTTP validam o backend e as views sem depender do build de assets.
        $this->withoutVite();
    }
}