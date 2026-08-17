<?php

namespace Tests\Feature;

use App\Filament\Pages\ProductSearchDashboard;
use App\Models\User;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelSmokeTest extends TestCase
{
    public function test_guests_are_redirected_to_the_panel_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_the_panel_login_page_renders(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_the_seeded_admin_lands_on_the_product_search_board(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin')
            ->assertOk()
            ->assertSee('Product Search')
            ->assertSee('Query console')
            ->assertSee('Result board');
    }

    public function test_the_management_resources_are_reachable(): void
    {
        $admin = $this->admin();

        foreach (['/admin/providers', '/admin/products', '/admin/categories', '/admin/sync-logs'] as $path) {
            $this->actingAs($admin)->get($path)->assertOk();
        }
    }

    public function test_the_board_is_populated_on_mount(): void
    {
        $this->actingAs($this->admin());

        $component = Livewire::test(ProductSearchDashboard::class)
            ->assertSet('page', 1)
            ->assertSet('sort', 'relevance')
            ->assertSet('perPage', 20);

        $this->assertNotEmpty($component->get('results'));
        $this->assertGreaterThan(0, $component->get('total'));
        $this->assertNotNull($component->get('lastSearchMs'));
    }

    public function test_a_text_query_only_returns_matching_offers(): void
    {
        $this->actingAs($this->admin());

        $component = Livewire::test(ProductSearchDashboard::class)
            ->set('query', 'ноутбук')
            ->call('search');

        $titles = array_column($component->get('results'), 'title');

        $this->assertNotEmpty($titles);

        foreach ($titles as $title) {
            $this->assertStringContainsString('ноутбук', mb_strtolower($title));
        }
    }

    public function test_the_price_filter_is_converted_from_roubles_to_kopecks(): void
    {
        $this->actingAs($this->admin());

        // Only three catalogue rows cost 100 000 ₽ (10 000 000 kopecks) or more.
        $component = Livewire::test(ProductSearchDashboard::class)
            ->set('minPrice', 100000)
            ->call('search');

        $this->assertSame(3, $component->get('total'));
    }

    public function test_pagination_serves_a_second_window_of_offers(): void
    {
        $this->actingAs($this->admin());

        $component = Livewire::test(ProductSearchDashboard::class)
            ->set('perPage', 10)
            ->call('search');

        $this->assertCount(10, $component->get('results'));
        $this->assertGreaterThan(10, $component->get('total'));

        $firstPageTitles = array_column($component->get('results'), 'title');

        $component->call('gotoPage', 2)->assertSet('page', 2);

        $secondPageTitles = array_column($component->get('results'), 'title');

        $this->assertNotEmpty($secondPageTitles);
        $this->assertSame([], array_intersect($firstPageTitles, $secondPageTitles));
    }

    private function admin(): User
    {
        return User::where('email', 'admin@example.com')->firstOrFail();
    }
}
