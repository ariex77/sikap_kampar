<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_halaman_utama_mengarah_ke_dashboard(): void
    {
        $this->get('/')->assertRedirect('/dashboard');
    }

    public function test_dashboard_mewajibkan_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }
}