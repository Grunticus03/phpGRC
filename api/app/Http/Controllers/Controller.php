<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Laravel\AuthorizesRequests;
use App\Support\Laravel\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

abstract class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;
}
