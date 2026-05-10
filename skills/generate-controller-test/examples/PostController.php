<?php

/**
 * 汎用サンプル — PostController（publish メソッドのみ抜粋）
 *
 * PostController_PublishTest.php の入力となるコントローラー。
 * このファイルと対応するテストをセットで読むことで
 * 「コントローラーを読んだらどんなテストになるか」のマッピングを把握する。
 */

namespace App\Http\Controllers;

use App\Http\Requests\PublishPostRequest;
use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Services\PostService;

class PostController extends Controller
{
    public function __construct(private readonly PostService $postService) {}

    public function publish(PublishPostRequest $request, Post $post): PostResource
    {
        $this->postService->publish(
            $post,
            $request->validated('title'),
            $request->validated('notify'),
        );

        return new PostResource($post);
    }
}
