<?php

test('public pages link to the license and source information', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('AGPLv3 license')
        ->assertSee(route('license'))
        ->assertSee('Source');
});

test('license information identifies the exact license, copyright holder, and source availability timing', function () {
    $this->get(route('license'))
        ->assertOk()
        ->assertSee('GNU Affero General Public License version 3.0 only')
        ->assertSee('AGPL-3.0-only')
        ->assertSee('Copyright © 2026 github.com/mahriman.')
        ->assertSee(route('license.text'))
        ->assertSee('private during release-candidate testing')
        ->assertSee('planned to become public for the 1.0 release')
        ->assertSee('Before deploying the network-accessible 1.0 release')
        ->assertSee('https://github.com/mahriman/fictional-internet');
});

test('complete license text is served as plain text', function () {
    $this->get(route('license.text'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee('GNU AFFERO GENERAL PUBLIC LICENSE')
        ->assertSee('Version 3, 19 November 2007');
});
