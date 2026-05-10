---
name: generate-controller-test
description: Laravelコントローラーに対してFeatureテストを生成する。命令網羅・DBアサーション付き。対象コントローラーのパスを引数に取る。
user-invocable: true
---

# コントローラー Featureテスト生成

対象: $ARGUMENTS（`PostController#update` のように `クラス名#メソッド名` で指定する）

メソッドを省略した場合は全 public メソッドを列挙し、1メソッド = 1テストクラスとして順に生成する。

規約の詳細は `.claude/rules/controller-test-conventions.md` を参照すること。
プロジェクト固有の注意点は `.claude/rules/project-specifics.md` を参照すること。
入力と出力のペアとして以下を参照すること。
- 入力（コントローラー）: `.claude/skills/generate-controller-test/examples/PostController.php`
- 出力（テスト）: `.claude/skills/generate-controller-test/examples/PostController_PublishTest.php`

---

## Step 1: 関連ファイルを全て読む

1ファイルでも読み漏らすと分岐の洗い出しが不完全になる。以下を全て読む。

| ファイル | 目的 |
|---|---|
| `app/Http/Controllers/XxxController.php` | `__invoke()` の処理とDI（サービス等）を把握 |
| `app/Http/Requests/XxxRequest.php` | バリデーションルール（`rules()` と `authorize()`）を把握 |
| `app/Http/Resources/XxxResource.php` | `toArray()` のフィールド一覧を把握 |
| `app/Services/XxxService.php` | switch / if の全分岐とDB変化（save・update・delete）を把握 |
| `routes/api.php` | HTTPメソッド・パス・ミドルウェアを確認 |
| `tests/Feature/` の既存テスト | プロジェクトのアサーションスタイルを把握 |

コントローラーが複数のサービスをDIしている場合は全て読む。

---

## Step 2: 分岐を4軸で全て洗い出す

以下の表を頭の中で（または明示的に）埋めてからコードを書く。

### 2-1. バリデーション（FormRequest の rules() を読む）

各ルールに対して「失敗させる値」を列挙する。

| ルール | テストで使う NG 値 |
|---|---|
| `required` | フィールドごとキー自体を省略 |
| `email` | `'not-an-email'` |
| `max:255` | `str_repeat('a', 250) . '@b.com'`（256文字） |
| `exists:table,col` | DB に存在しない ID |
| `unique:table,col` | 既に登録されている値 |
| `boolean` | `'string'` |
| `integer` | `'abc'` |

### 2-2. ルートモデルバインディング

パスパラメータにモデルが使われている場合、存在しない ID（例: `999`）で 404 になることを確認する。

### 2-3. ビジネスロジック（サービスの switch / if を全て追う）

各分岐について以下を記録する:

| ブランチ条件（セットアップに必要な状態） | dispatch されるJob | DB変化（テーブル・カラム・値） |
|---|---|---|
| ○○が null の場合 | SendXxxJob | なし |
| ○○が10以上の場合 | SendYyyJob | users.warned_at = now() |
| else | SendZzzJob | なし |

### 2-4. レスポンス構造（Resource の toArray() を読む）

- 返すべきフィールド一覧 → `assertJsonStructure`
- 返してはいけないフィールド（password等） → `assertJsonMissing`
- 特定フィールドの値（enumのvalue等） → `assertJson`

---

## Step 3: DB変化を分岐ごとに確定する

サービスのコードを追い、各分岐で起きる DB 操作を洗い出す。

**チェックポイント:**
- `$model->save()` `$model->update()` → UPDATE
- `Model::create()` `Model::insert()` → INSERT
- `$model->delete()` `$model->forceDelete()` → DELETE
- `dispatch(new Job())` でキューに積まれるJob → `Queue::fake()` により実行されないのでJob内のDB変化は**発生しない**

**アサーション選択フローチャート:**

```
値が確定できる?
  YES → assertDatabaseHas('table', ['col' => $value])
  NO（NULLでないことだけ確認）→ assertNotNull(Model::find($id)->col)

レコードが存在しないことを確認?
  → assertDatabaseMissing('table', ['col' => $value])

件数を確認?（usersテーブルは使わない ※後述）
  → assertDatabaseCount('table', $n)
```

---

## Step 4: テストクラスを書く

### クラス名・ファイル名の決め方

コントローラーに複数の public メソッドがある場合、**メソッドごとにテストクラスを分ける**。

```
PostController#index  → tests/Feature/PostController_IndexTest.php
PostController#store  → tests/Feature/PostController_StoreTest.php
PostController#update → tests/Feature/PostController_UpdateTest.php
```

シングルアクションコントローラー（`__invoke` のみ）の場合は従来通り `PostControllerTest` とする。

### クラス骨格

```php
<?php

namespace Tests\Feature;

use App\Jobs\SendXxxJob;
use App\Models\RelatedModel;
use App\Models\TargetModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PostController_UpdateTest extends TestCase
{
    use RefreshDatabase;

    // リクエストで繰り返す値は定数化する
    private const AUTH_HEADER   = ['Authorization' => 'Bearer test-token'];
    private const VALID_PAYLOAD = ['field' => 'value'];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // コントローラーまたはサービスが dispatch する場合は必須
    }
```

### テストメソッドの並べ順

1. バリデーション（422）
2. ルートモデルバインディング（404）
3. ビジネスロジック分岐（成功系）― switch の case 順に並べる
4. レスポンス構造

### 各テストメソッドのテンプレート

```php
public function test_○○の場合に△△する(): void
{
    // 1. DB セットアップ（分岐に必要な状態を Factory で作る）
    $company = Company::factory()->create();
    $user    = User::factory()->create([
        'roll'       => UserRoll::Admin,
        'company_id' => $company->id,
    ]);
    Task::factory()->count(3)->create(['user_id' => $user->id, 'is_done' => true]);

    // 2. HTTP リクエスト
    $response = $this->putJson(
        "/api/target/{$user->id}",
        self::VALID_PAYLOAD,
        self::AUTH_HEADER,
    );

    // 3. HTTP レスポンスのアサーション
    $response->assertStatus(200);
    $response->assertJson(['data' => ['id' => $user->id]]);
    $response->assertJsonStructure(['data' => ['id', 'email', 'updated_at']]);
    $response->assertJsonMissing(['password']);

    // 4. Job のアサーション（Queue::fake() している場合）
    Queue::assertPushed(SendXxxJob::class, function (SendXxxJob $job) use ($user): bool {
        return $job->user->id === $user->id;
    });
    Queue::assertNotPushed(SendYyyJob::class);

    // 5. DB 状態のアサーション（必ず書く）
    $this->assertDatabaseHas('users', [
        'id'    => $user->id,
        'email' => 'new@example.com',
    ]);
    $this->assertNotNull(User::find($user->id)->warned_at); // null でないことだけ確認
    $this->assertDatabaseCount('tasks', 3);                 // tasks は変化しない
}
```

---

## Step 5: 実行して確認

```bash
php artisan test --filter=XxxControllerTest
# 実行コマンドはプロジェクトの環境に合わせる（Docker 等）。project-specifics.md を参照。
```

失敗した場合の対処:

| エラー | 原因 | 対処 |
|---|---|---|
| `assertDatabaseHas` 失敗 | 期待値とDB実値がずれている | `dd(Model::find($id)->toArray())` で実値を確認 |
| `Queue::assertPushed` 失敗 | Job が dispatch されていない、または別の分岐に入っている | Factory のセットアップ条件を見直す |
| 422 が返らない | バリデーションルールと NG 値がズレている | `rules()` を再確認 |

---

## 注意点

### 認証ミドルウェアの副作用

認証ミドルウェアがリクエスト処理の副作用でレコードを INSERT するプロジェクトでは
`assertDatabaseCount` が誤検知する。`project-specifics.md` を確認し、影響があるテーブルでは
`assertDatabaseHas` で特定レコードの値を検証する方式に切り替える。

### Queue::fake() はJob内のDB変化を止める

`dispatch(new SomeJob())` でキューに積まれたJobは実行されない。
Job内のDB操作・メール送信は発生しないため、それらはテスト対象外となる。

### timestamp カラムの「NULL でない」アサーション

```php
// NG: now() の値はアサーション実行タイミングとズレる
$this->assertDatabaseHas('posts', ['published_at' => now()]);

// OK
$this->assertNotNull(Post::find($post->id)->published_at);
```