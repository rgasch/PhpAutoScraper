<?php

use Rgasch\AutoScraper\Helpers\UserAgentHelper;

it('retrieves a random user agent', function () {
    $userAgent = UserAgentHelper::getUserAgent();
    expect($userAgent)->toBeString();
    expect($userAgent)->not->toBeEmpty();
});

it('retrieves all user agents', function () {
    $userAgents = UserAgentHelper::getUserAgents();
    expect($userAgents)->toBeArray();
    expect($userAgents)->not->toBeEmpty();
    expect($userAgents[0])->toBeString();
});

it('throws an exception if user agent list is empty', function () {
    UserAgentHelper::setUserAgents([]);
    expect(fn() => UserAgentHelper::getUserAgent())->toThrow(\Exception::class);
});

it('sets and gets user agents correctly', function () {
    $userAgents = ['Mozilla/5.0', 'Chrome/91.0'];
    UserAgentHelper::setUserAgents($userAgents);
    expect(UserAgentHelper::getUserAgents())->toBe($userAgents);
});