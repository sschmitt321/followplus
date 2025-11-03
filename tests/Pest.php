<?php

use Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

// Set JWT secret for testing if not set
uses()->beforeEach(function () {
    if (empty(env('JWT_SECRET'))) {
        putenv('JWT_SECRET=AwwFl7ChVIjOs3TzWnoHKRndsKXxrzxI2WEBbtQElDSn5CGUndmyZT3WuFbDIwgt');
        config(['jwt.secret' => getenv('JWT_SECRET')]);
    }
})->in('Feature');

