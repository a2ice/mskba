<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Presentation\Theming\ThemeResolver;

class SiteController extends Controller
{
    public function __construct(private ThemeResolver $theme) {}

    public function index()
    {
        return view($this->theme->view('pages.home'));
    }
}
