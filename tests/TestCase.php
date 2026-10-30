<?php

namespace Tests;

use App\Answers\QuestionGate;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The off-topic gate never reaches a real model: every question is religious unless a test says otherwise.
        QuestionGate::fake(fn (): array => ['religious' => true]);
    }
}
