# コントローラー Featureテスト規約

Featureテストを書くときに常に適用する規約。テスト生成の手順は `.claude/skills/generate-controller-test/SKILL.md` を参照。

---

## テストクラスの粒度

**1 コントローラーメソッド = 1 テストクラス。**

コントローラーに複数の public メソッドがある場合、メソッドごとにテストクラスを分ける。
1 つのテストクラスには対象メソッドに関する分岐・バリデーション・レスポンスを全て書く。

### クラス名・ファイル名

```
PostController#index  → PostController_IndexTest.php  / class PostController_IndexTest
PostController#store  → PostController_StoreTest.php  / class PostController_StoreTest
PostController#update → PostController_UpdateTest.php / class PostController_UpdateTest
PostController#destroy→ PostController_DestroyTest.php/ class PostController_DestroyTest
```

---

## 必須構造

```php
class PostController_UpdateTest extends TestCase
{
    use RefreshDatabase;

    private const AUTH_HEADER = ['Authorization' => 'Bearer test-token'];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // コントローラー・サービスが dispatch するなら必須
    }
}
```

`Queue::fake()` はコントローラーまたはサービスが `dispatch()` を呼ぶ可能性があれば **必ず** `setUp()` に書く。テストメソッドごとに書かない。

---

## DBアサーション規約

### 全テストメソッドに書く（必須）

HTTPアサーション（assertStatus 等）だけで終わらせない。
**毎テストメソッドに必ず DB の状態変化アサーションを含める。**

```php
// 成功系: 変化したカラムを確認
$this->assertDatabaseHas('users', ['id' => $user->id, 'email' => self::NEW_EMAIL]);

// 失敗系（422等）: 変化していないことを確認
$this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
```

### アサーションメソッドの使い分け

| 確認したいこと | 使うメソッド |
|---|---|
| 特定レコードの値 | `assertDatabaseHas('table', ['col' => $val])` |
| レコードが存在しないこと | `assertDatabaseMissing('table', ['col' => $val])` |
| テーブルの件数 | `assertDatabaseCount('table', $n)` ※認証ミドルウェアの副作用に注意（下記参照） |
| NULL でないこと | `assertNotNull(Model::find($id)->col)` |

### assertDatabaseCount を使う際の注意

認証ミドルウェアが副作用としてレコードを INSERT するプロジェクトでは、
`assertDatabaseCount` が誤検知することがある。
プロジェクト固有の副作用は `.claude/rules/project-specifics.md` を確認すること。

### timestamp カラムの NULL チェック

```php
// NG: テスト実行タイミングと now() がずれて失敗する
$this->assertDatabaseHas('users', ['warned_at' => now()]);

// OK
$this->assertNotNull(User::find($user->id)->warned_at);
```

---

## Job アサーション規約

```php
// dispatch されたことを確認（クロージャでプロパティも検証する）
Queue::assertPushed(SomeJob::class, function (SomeJob $job) use ($model): bool {
    return $job->model->id === $model->id
        && str_contains($job->subject, '期待文字列');
});

// dispatch されていないことを確認
Queue::assertNotPushed(OtherJob::class);

// バリデーションエラー等でサービスまで到達しない場合
Queue::assertNothingPushed();
```

---

## 命名規約

- メソッド名は **日本語** で書く
- `test_` プレフィックスを付ける
- 「条件 + 結果」を含める

```php
// OK
public function test_emailが未指定の場合に422を返す(): void
public function test_notifyありプレミアム著者の場合にNotifyFollowersJobがdispatchされる(): void

// NG
public function testUpdatePost(): void
public function test_validationError(): void
```

---

## テストメソッドの並べ順

1. **バリデーション**（422 / 401）― FormRequest の rules() ルール順
2. **ルートモデルバインディング**（404）
3. **ビジネスロジック分岐**（サービスの switch / if の case 順）
4. **レスポンス構造**（assertJsonStructure / assertJsonMissing / assertJson）

---

## テストDB

`.env.testing` でテスト専用DBを分離する。`phpunit.xml` のDB設定より `.env.testing` を優先させること。
実行コマンドはプロジェクトの実行環境に応じて異なる。`.claude/rules/project-specifics.md` を参照。
