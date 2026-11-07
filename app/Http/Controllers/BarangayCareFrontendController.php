<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class BarangayCareFrontendController extends Controller
{
    public function __invoke(): Response
    {
        return response()->view('app');
    }

    public function stylesheet(): Response
    {
        $stylesheet = resource_path('css/app.css');
        abort_unless(is_file($stylesheet), 500, 'BarangayCare stylesheet is missing.');
        $contents = file_get_contents($stylesheet);
        abort_unless($contents !== false, 500, 'BarangayCare stylesheet could not be read.');

        return response($contents, 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }

    public function script(): Response
    {
        $script = base_path('resources/js/app.js');
        abort_unless(is_file($script), 500, 'BarangayCare frontend script is missing.');
        $contents = file_get_contents($script);
        abort_unless($contents !== false, 500, 'BarangayCare frontend script could not be read.');

        return response($contents, 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }
}
