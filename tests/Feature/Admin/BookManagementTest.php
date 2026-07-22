<?php

namespace Tests\Feature\Admin;

use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_admin_books(): void
    {
        $this->getJson('/api/v1/admin/books')->assertUnauthorized();
    }

    public function test_regular_user_cannot_access_admin_books(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/books')->assertStatus(403);
    }

    public function test_admin_can_create_book(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/books', [
            'title' => 'رياض الصالحين',
            'description' => 'كتاب في الأحاديث',
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'رياض الصالحين');

        $this->assertDatabaseHas('books', ['title' => 'رياض الصالحين']);
    }

    public function test_admin_can_update_and_delete_book(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $book = Book::factory()->create();

        $this->putJson("/api/v1/admin/books/{$book->id}", ['title' => 'Updated'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated');

        $this->deleteJson("/api/v1/admin/books/{$book->id}")->assertOk();
        $this->assertDatabaseMissing('books', ['id' => $book->id]);
    }
}
