<?php

test('public pages link to the license and source information', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('AGPLv3 license')
        ->assertSee(route('license'))
        ->assertSee('Source');
});

test('license information identifies the exact license and the restricted source repository', function () {
    $this->get(route('license'))
        ->assertOk()
        ->assertSee('GNU Affero General Public License version 3.0 only')
        ->assertSee('AGPL-3.0-only')
        ->assertSee(route('license.text'))
        ->assertSee('source repository is private')
        ->assertSee('https://github.com/mahriman/fictional-internet');
});

test('complete license text is served as plain text', function () {
    $this->get(route('license.text'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee('GNU AFFERO GENERAL PUBLIC LICENSE')
        ->assertSee('Version 3, 19 November 2007');
});
