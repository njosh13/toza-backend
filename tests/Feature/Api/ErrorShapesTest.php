<?php

declare(strict_types=1);

use App\Http\Api\InvalidStateException;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('api')->prefix('api/v1/_test')->group(function () {
        Route::get('conflict', fn () => throw new InvalidStateException('The bill is not in a state that allows this.', 'issued', ['agree', 'dispute']));
        Route::get('forbidden', fn () => abort(403, 'Your role cannot do this.'));
        Route::get('missing', fn () => abort(404, 'Not found.'));
    });
});

it('answers 409 with the current status and the allowed actions', function () {
    $this->getJson('/api/v1/_test/conflict')
        ->assertStatus(409)
        ->assertExactJson([
            'message' => 'The bill is not in a state that allows this.',
            'current_status' => 'issued',
            'allowed_actions' => ['agree', 'dispute'],
        ]);
});

it('answers 403 and 404 as json with a message', function () {
    $this->getJson('/api/v1/_test/forbidden')->assertForbidden()->assertJsonPath('message', 'Your role cannot do this.');
    $this->getJson('/api/v1/_test/missing')->assertNotFound()->assertJsonStructure(['message']);
});

it('answers an unknown api route with a json 404', function () {
    $this->getJson('/api/v1/no-such-endpoint')->assertNotFound()->assertJsonStructure(['message']);
});