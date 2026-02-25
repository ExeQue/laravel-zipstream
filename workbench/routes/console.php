<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

Artisan::command('testing', function () {
    dd(Storage::disk('local'));
});
