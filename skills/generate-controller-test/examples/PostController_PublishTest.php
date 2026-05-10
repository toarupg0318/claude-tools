<?php

/**
 * 汎用サンプル — PostController#publish のテスト
 *
 * 想定コントローラー:
 *   PUT /api/posts/{post}  (auth ミドルウェア)
 *   PostController::publish(Request $request, Post $post)
 *   → PostService::publish(Post $post, string $title, bool $notify) を呼ぶ
 *
 * 想定サービス分岐（PostService::publish）:
 *   if ($notify && $post->author->isPremium())
 *     → posts.published_at を now() にセット + NotifyFollowersJob を dispatch
 *   else
 *     → posts.published_at を now() にセット のみ
 *
 * このファイルは実在しない。スキルが参照する「パターン見本」として使う。
 * 実プロジェクトのモデル名・フィールド名に置き換えて読むこと。
 */

namespace Tests\Feature;

use App\Jobs\NotifyFollowersJob;
use App\Models\Author;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PostController_PublishTest extends TestCase
{
    use RefreshDatabase;

    private const AUTH_HEADER = ['Authorization' => 'Bearer test-token'];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    // =========================================================================
    // バリデーション（422）
    // =========================================================================

    public function test_titleが未指定の場合に422を返す(): void
    {
        $post = Post::factory()->create();

        $this->putJson("/api/posts/{$post->id}", ['notify' => true], self::AUTH_HEADER)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title']);

        // DB: published_at は変化しない
        $this->assertDatabaseHas('posts', ['id' => $post->id, 'published_at' => null]);
        Queue::assertNothingPushed();
    }

    public function test_titleが256文字を超える場合に422を返す(): void
    {
        $post = Post::factory()->create();

        $this->putJson(
            "/api/posts/{$post->id}",
            ['title' => str_repeat('a', 256), 'notify' => true],
            self::AUTH_HEADER,
        )->assertStatus(422)
            ->assertJsonValidationErrors(['title']);

        $this->assertDatabaseHas('posts', ['id' => $post->id, 'published_at' => null]);
    }

    public function test_notifyがboolean以外の場合に422を返す(): void
    {
        $post = Post::factory()->create();

        $this->putJson(
            "/api/posts/{$post->id}",
            ['title' => 'Valid Title', 'notify' => 'yes'],
            self::AUTH_HEADER,
        )->assertStatus(422)
            ->assertJsonValidationErrors(['notify']);

        $this->assertDatabaseHas('posts', ['id' => $post->id, 'published_at' => null]);
    }

    // =========================================================================
    // ルートモデルバインディング（404）
    // =========================================================================

    public function test_存在しない投稿の場合に404を返す(): void
    {
        $this->putJson(
            '/api/posts/999',
            ['title' => 'Valid Title', 'notify' => false],
            self::AUTH_HEADER,
        )->assertStatus(404);

        $this->assertDatabaseCount('posts', 0);
    }

    // =========================================================================
    // サービス分岐 / notify=true + プレミアム著者
    // → posts.published_at UPDATE + NotifyFollowersJob dispatch
    // =========================================================================

    public function test_notifyありプレミアム著者の場合にJobがdispatchされpublished_atが更新される(): void
    {
        $author = Author::factory()->premium()->create();
        $post   = Post::factory()->create(['author_id' => $author->id, 'published_at' => null]);

        $this->putJson(
            "/api/posts/{$post->id}",
            ['title' => 'New Title', 'notify' => true],
            self::AUTH_HEADER,
        )->assertStatus(200);

        // Job: dispatch されたこと＋プロパティを確認
        Queue::assertPushed(NotifyFollowersJob::class, function (NotifyFollowersJob $job) use ($post): bool {
            return $job->post->id === $post->id;
        });

        // DB: published_at が NULL でなくなること + title が更新されること
        $this->assertNotNull(Post::find($post->id)->published_at);
        $this->assertDatabaseHas('posts', ['id' => $post->id, 'title' => 'New Title']);
        // authors テーブルは変化しない
        $this->assertDatabaseHas('authors', ['id' => $author->id]);
    }

    // =========================================================================
    // サービス分岐 / notify=false（else ブランチ）
    // → posts.published_at UPDATE のみ、Job は dispatch されない
    // =========================================================================

    public function test_notifyなしの場合にJobがdispatchされずpublished_atが更新される(): void
    {
        $author = Author::factory()->premium()->create();
        $post   = Post::factory()->create(['author_id' => $author->id, 'published_at' => null]);

        $this->putJson(
            "/api/posts/{$post->id}",
            ['title' => 'New Title', 'notify' => false],
            self::AUTH_HEADER,
        )->assertStatus(200);

        Queue::assertNotPushed(NotifyFollowersJob::class);
        $this->assertNotNull(Post::find($post->id)->published_at);
        $this->assertDatabaseHas('posts', ['id' => $post->id, 'title' => 'New Title']);
    }

    // =========================================================================
    // サービス分岐 / notify=true + 非プレミアム著者（else ブランチ）
    // → posts.published_at UPDATE のみ、Job は dispatch されない
    // =========================================================================

    public function test_notifyありでも非プレミアム著者の場合はJobがdispatchされない(): void
    {
        $author = Author::factory()->create(); // premium でない
        $post   = Post::factory()->create(['author_id' => $author->id, 'published_at' => null]);

        $this->putJson(
            "/api/posts/{$post->id}",
            ['title' => 'New Title', 'notify' => true],
            self::AUTH_HEADER,
        )->assertStatus(200);

        Queue::assertNotPushed(NotifyFollowersJob::class);
        $this->assertNotNull(Post::find($post->id)->published_at);
    }

    // =========================================================================
    // レスポンス構造
    // =========================================================================

    public function test_レスポンスのプロパティが定義通りであること(): void
    {
        $post = Post::factory()->create(['published_at' => null]);

        $this->putJson(
            "/api/posts/{$post->id}",
            ['title' => 'New Title', 'notify' => false],
            self::AUTH_HEADER,
        )->assertStatus(200)
            ->assertJsonStructure(['data' => ['id', 'title', 'published_at', 'updated_at']])
            ->assertJsonMissing(['body']) // body は Resource に含めない想定
            ->assertJson(['data' => ['id' => $post->id, 'title' => 'New Title']]);

        $this->assertDatabaseHas('posts', ['id' => $post->id, 'title' => 'New Title']);
    }
}
