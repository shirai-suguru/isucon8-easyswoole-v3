<?php

namespace App\HttpController;

use EasySwoole\Http\Request;
use EasySwoole\Http\Response;
use FastRoute\RouteCollector;

class Router extends \EasySwoole\Http\AbstractInterface\AbstractRouter
{
    public function initialize(RouteCollector $routeCollector)
    {
        $routeCollector->get('/initialize', function (Request $request, Response $response) {
            exec(EASYSWOOLE_ROOT . '/db/init.sh');

            $response->withStatus(204);
            $response->end();
        });

        $routeCollector->addRoute('GET', '/', '/index');
        $routeCollector->addRoute('GET', '/admin', '/admin');
        $routeCollector->addRoute('POST', '/api/actions/login', '/login');
        $routeCollector->addRoute('POST', '/api/actions/logout', '/logout');
        $routeCollector->addRoute('POST', '/api/users', '/signup');
        $routeCollector->addRoute('GET', '/api/users/{id}', '/apiUsersById');
        $routeCollector->addRoute('GET', '/api/events', '/apiEvents');
        $routeCollector->addRoute('GET', '/api/events/{id}', '/apiEventsById');
        $routeCollector->addRoute('POST', '/api/events/{id}/actions/reserve', '/apiEventsActionsReserveById');
        $routeCollector->addRoute('DELETE', '/api/events/{id}/sheets/{ranks}/{num}/reservation', '/apiEventsSheetsReservationById');
        $routeCollector->addRoute('POST', '/admin/api/actions/login', '/adminLogin');
        $routeCollector->addRoute('POST', '/admin/api/actions/logout', '/adminLogout');
        $routeCollector->addRoute('GET', '/admin/api/events', '/adminApiEvents');
        $routeCollector->addRoute('POST', '/admin/api/events', '/adminApiEventsCreate');
        $routeCollector->addRoute('GET', '/admin/api/events/{id}', '/adminApiEventsById');
        $routeCollector->addRoute('POST', '/admin/api/events/{id}/actions/edit', '/adminApiEventsActionsEditById');
        $routeCollector->addRoute('GET', '/admin/api/reports/events/{id}/sales', '/adminApiReportsEventsSalesById');
        $routeCollector->addRoute('GET', '/admin/api/reports/sales', '/adminApiReportsSales');
    }
}
