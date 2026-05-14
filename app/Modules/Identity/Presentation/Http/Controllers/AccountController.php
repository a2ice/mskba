<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use Illuminate\View\View;
use App\Presentation\Theming\ThemeResolver;

class AccountController
{
    public function __construct(private ThemeResolver $theme) {}

    public function index(): View
    {
        return view($this->theme->view('pages.account'));
    }
    
    public function login(): View
    {
        return view($this->theme->view('pages.account.login'));
    }
    
    public function register(): View
    {
        return view($this->theme->view('pages.account.register'));
    }
}