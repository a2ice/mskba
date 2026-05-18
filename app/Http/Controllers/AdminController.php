<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use app\Presentation\Theming\ThemeResolver;

class AdminController extends Controller
{
    public function __construct(private ThemeResolver $themeResolver) {}

    public function index()
    {
        return view($this->themeResolver->view('pages.admin.index'));
    }

    public function users()
    {
        return view($this->themeResolver->view('pages.admin.users'));
    }
}
