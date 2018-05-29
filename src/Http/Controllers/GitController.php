<?php
namespace Mylgeorge\Deploy\Http\Controllers;

use Illuminate\Routing\Controller;
use Mylgeorge\Deploy\Contracts\Deploy;

class GitController extends Controller
{

    public function __invoke(Deploy $deploy)
    {
        return $deploy->handle();
    }


}