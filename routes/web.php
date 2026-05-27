<?php

use Illuminate\Support\Facades\Route;

class A
{
    public static string $name = 'A';

    public static function name()
    {
        return 'A';
    }

    public static function testSelf()
    {
        return self::name();
    }

    public static function testStatic()
    {
        return static::name();
    }
}

class B extends A
{
    public static string $name = 'B';

    public static function name()
    {
        return self::$name;
    }
}

Route::get('/', function () {
    echo ' self: '.B::testSelf().'; static: '.B::testStatic(); // B

    return view('welcome');
});
