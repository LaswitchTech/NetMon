<?php

namespace App\NetMon\Controllers;

use App\Core\Controller;

class HomeController extends Controller
{
    public function index(array $params = []): void
    {
        $config = $this->container->get('config');

        $this->json([
            'app'    => $config['name'],
            'status' => 'ok',
        ]);
    }
}
