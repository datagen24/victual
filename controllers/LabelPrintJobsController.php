<?php

namespace Victual\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Victual\Controllers\Users\User;

class LabelPrintJobsController extends BaseController
{
    public function Index(Request $request, Response $response, array $args)
    {
        User::CheckPermission($request, User::PERMISSION_ADMIN);
        return $this->RenderPage($response, 'labelprintjobs');
    }

    public function Printers(Request $request, Response $response, array $args)
    {
        User::CheckPermission($request, User::PERMISSION_ADMIN);
        return $this->RenderPage($response, 'labelprinters');
    }
}
