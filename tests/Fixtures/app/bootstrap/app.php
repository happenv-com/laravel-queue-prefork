<?php

declare(strict_types=1);

use Happenv\QueuePrefork\Tests\Fixtures\FakeApplication;

return new FakeApplication((string) getenv('FIXTURE_LOG'), getenv('FIXTURE_MODE') ?: 'work');
