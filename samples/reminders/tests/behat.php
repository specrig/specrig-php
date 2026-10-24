<?php

use App\Tests\Internal\Harness;
use Behat\Config\Config;
use Behat\Config\Extension;
use Behat\Config\Filter\TagFilter;
use Behat\Config\Profile;
use Behat\Config\Suite;
use Behat\Config\TesterOptions;

return (new Config())->withProfile((new Profile('default'))
    ->withTesterOptions((new TesterOptions())->withErrorReporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED))
    ->withExtension(new Extension(Specrig\Behat\Extension::class, ['harness' => Harness::class]))
    ->withSuite((new Suite('cases'))->withPaths('%paths.base%/features')->withFilter(new TagFilter('~@ignore')))
    ->withSuite((new Suite('platform'))->withPaths('%paths.base%/internal')));
