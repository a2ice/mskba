<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use app\Presentation\Theming\ThemeResolver;

class AdminController extends Controller
{
    public function __construct(private ThemeResolver $themeResolver) {}

    public function index()
    {
        return 'check';
        return view($this->themeResolver->view('pages.admin.index'));
    }
}
