<?php

namespace Tests\Feature\Backstage;

use App\User;
use Tests\TestCase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PromoterLoginTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    function logging_in_with_valid_credentials()
    {
        $user = factory(User::class)->create([
            'email' => 'johnny@gmail.com',
            'password' => bcrypt('uncrackable-password'),
        ]);

        $response = $this->post('/login', [
            'email' => 'johnny@gmail.com',
            'password' => 'uncrackable-password',
        ]);

        $response->assertRedirect('/backstage/concerts');
        $this->assertTrue(Auth::check());
        $this->assertTrue(Auth::user()->is($user));
    }

    /** @test */
    function logging_in_with_invalid_credentials()
    {
        $user = factory(User::class)->create([
            'email' => 'johnny@gmail.com',
            'password' => bcrypt('uncrackable-password'),
        ]);

        $response = $this->post('/login', [
            'email' => 'johnny@gmail.com',
            'password' => 'attempt-at-hacking-password',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors(['email']);
        $this->assertFalse(Auth::check());
    }

    /** @test */
    function logging_in_with_nonexistent_account()
    {
        $response = $this->post('/login', [
            'email' => 'anon@gmail.com',
            'password' => 'fluffy123456',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors(['email']);
        $this->assertFalse(Auth::check());
    }

    /** @test */
    function logging_out_the_current_user()
    {
        Auth::login(factory(User::class)->create());

        $response = $this->post('/logout');

        $response->assertRedirect('/login');
        $this->assertFalse(Auth::check());
    }
}
