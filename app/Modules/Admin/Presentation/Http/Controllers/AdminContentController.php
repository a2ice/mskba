<?php

namespace App\Modules\Admin\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Application\UseCases\ListAdminContentPagesHandler;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Response;

final class AdminContentController extends Controller
{
    public function index(ListAdminContentPagesHandler $content): Response
    {
        return ThemeResolver::page('admin.content', [
            'pages' => $content->handle(),
        ]);
    }
}
