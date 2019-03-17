<?php

namespace App\HttpController;

use EasySwoole\Http\AbstractInterface\Controller;
use EasySwoole\EasySwoole\Logger;

class Index extends BaseController
{
    public function index()
    {
        $this->fillinUser();

        $events = array_map(function (array $event) {
            return $this->sanitize_event($event);
        }, $this->get_events());

        $this->render('index.twig', [
            'events' => $events
        ]);
    }

    public function admin()
    {
        $this->fillinAdministrator();

        $events = $this->get_events(function ($event) { return $event; });

        $this->render('admin.twig', [
            'events' => $events
        ]);
    }

    public function login()
    {
        $content = $this->request()->getBody()->__toString();
        $raw_array = json_decode($content, true);
        
        $login_name = $raw_array['login_name'];
        $password = $raw_array['password'];

        // Logger::getInstance()->console(var_export($login_name, true));

    
        $user = $this->select_row('SELECT * FROM users WHERE login_name = ?', [$login_name]);
        $pass_hash = $this->select_one('SELECT SHA2(?, 256) AS pass_hash', [$password]);

        if (!$user || $pass_hash['pass_hash'] != $user['pass_hash']) {
            return $this->res_error('authentication_failed', 401);
        }

        $this->session()->set('user_id', $user['id']);
        $user = $this->get_login_user();
    
        $this->response()->write(json_encode($user, JSON_NUMERIC_CHECK));
        $this->response()->end();
    }

    public function logout()
    {
        if (!$this->loginRequired()) {
            return;
        }

        $this->session()->destroy();

        $this->response()->withStatus(204);
        $this->response()->end();
    }

    public function signup()
    {
        $content = $this->request()->getBody()->__toString();
        $raw_array = json_decode($content, true);

        $nickname   = $raw_array['nickname'];
        $login_name = $raw_array['login_name'];
        $password   = $raw_array['password'];
    
        $user_id = null;

        $this->db->startTransaction();

        try {
            $duplicated = $this->select_one('SELECT * FROM users WHERE login_name = ?', [$login_name]);
            if ($duplicated) {
                $this->db->rollback();

                return $this->res_error('duplicated', 409);
            }
            $ret = $this->db->rawQuery('INSERT INTO users (login_name, pass_hash, nickname) VALUES (?, SHA2(?, 256), ?)', [$login_name, $password, $nickname]);

            $user_id = $this->lastInsertId();
            $this->db->commit();
        } catch (\Throwable $throwable) {
            $this->db->rollback();

            return $this->res_error();
        }

        $this->response()->write(json_encode([
            'id' => $user_id,
            'nickname' => $nickname
        ], JSON_NUMERIC_CHECK));
        $this->response()->withStatus(201);
        $this->response()->end();
    }
    public function apiUsersById()
    {
        if (!$this->loginRequired()) {
            return;
        }
        $id = $this->request()->getQueryParam('id');


        $user = $this->select_row('SELECT id, nickname FROM users WHERE id = ?', [$id]);
        $user['id'] = (int) $user['id'];
        if (!$user || $user['id'] !== $this->get_login_user()['id']) {
            return $this->res_error('forbidden', 403);
        }

        $recent_reservations = [];

        $rows = $this->select_all('SELECT r.*, s.rank AS sheet_rank, s.num AS sheet_num FROM reservations r INNER JOIN sheets s ON s.id = r.sheet_id WHERE r.user_id = ? ORDER BY IFNULL(r.canceled_at, r.reserved_at) DESC LIMIT 5', [$user['id']]);
        foreach ($rows as $row) {
            $event = $this->get_event($row['event_id']);
            $price = $event['sheets'][$row['sheet_rank']]['price'];
            unset($event['sheets']);
            unset($event['total']);
            unset($event['remains']);

            $reservation = [
                'id' => $row['id'],
                'event' => $event,
                'sheet_rank' => $row['sheet_rank'],
                'sheet_num' => $row['sheet_num'],
                'price' => $price,
                'reserved_at' => (new \DateTime("{$row['reserved_at']}", new \DateTimeZone('UTC')))->getTimestamp(),
            ];

            if ($row['canceled_at']) {
                $reservation['canceled_at'] = (new \DateTime("{$row['canceled_at']}", new \DateTimeZone('UTC')))->getTimestamp();
            }

            array_push($recent_reservations, $reservation);
        }
    
        $user['recent_reservations'] = $recent_reservations;
        $total_price = $this->select_one('SELECT IFNULL(SUM(e.price + s.price), 0) AS total_price FROM reservations r INNER JOIN sheets s ON s.id = r.sheet_id INNER JOIN events e ON e.id = r.event_id WHERE r.user_id = ? AND r.canceled_at IS NULL', [$user['id']]);
        $user['total_price'] = $total_price['total_price'];

        $recent_events = [];

        $rows = $this->select_all('SELECT event_id FROM reservations WHERE user_id = ? GROUP BY event_id ORDER BY MAX(IFNULL(canceled_at, reserved_at)) DESC LIMIT 5', [$user['id']]);
        foreach ($rows as $row) {
            $event = $this->get_event($row['event_id']);
            foreach (array_keys($event['sheets']) as $rank) {
                unset($event['sheets'][$rank]['detail']);
            }
            array_push($recent_events, $event);
        }
        $user['recent_events'] = $recent_events;

        $this->response()->write(json_encode($user, JSON_NUMERIC_CHECK));
        $this->response()->end();
    }

    public function apiEvents()
    {
        $events = array_map(function (array $event) {
            return $this->sanitize_event($event);
        }, $this->get_events());
   
        $this->response->write(json_encode($events, JSON_NUMERIC_CHECK));
        $this->response->end();
    }

    public function apiEventsById()
    {
        $event_id = $this->request()->getQueryParam('id');

        $user = $this->get_login_user();
        $event = $this->get_event($event_id, $user['id']);
    
        if (empty($event) || !$event['public']) {
            return $this->res_error('not_found', 404);
        }
        $event = $this->sanitize_event($event);
    
        $this->response()->write(json_encode($event, JSON_NUMERIC_CHECK));
        $this->response()->end();
    }

    public function apiEventsActionsReserveById()
    {
        if (!$this->loginRequired()) {
            return;
        }

        $content = $this->request()->getBody()->__toString();
        $raw_array = json_decode($content, true);

        $event_id = $this->request()->getQueryParam('id');
        $rank = $raw_array['sheet_rank'];
    
        $user = $this->get_login_user();
        $event = $this->get_event($event_id, $user['id']);
    
        if (empty($event) || !$event['public']) {
            return $this->res_error('invalid_event', 404);
        }
    
        if (!$this->validate_rank($rank)) {
            return $this->res_error('invalid_rank', 400);
        }

        $sheet = null;
        $reservation_id = null;
        while (true) {
            $sheet = $this->select_row('SELECT * FROM sheets WHERE id NOT IN (SELECT sheet_id FROM reservations WHERE event_id = ? AND canceled_at IS NULL FOR UPDATE) AND `rank` = ? ORDER BY RAND() LIMIT 1', [$event['id'], $rank]);
            if (!$sheet) {
                return $this->res_error('sold_out', 409);
            }
    
            $this->db->startTransaction();
            try {
                $this->db->rawQuery('INSERT INTO reservations (event_id, sheet_id, user_id, reserved_at) VALUES (?, ?, ?, ?)', [$event['id'], $sheet['id'], $user['id'], (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')]);
                $reservation_id = (int) $this->lastInsertId();
    
                $this->db->commit();
            } catch (\Exception $e) {
                $this->db->rollback();
                continue;
            }
            break;
        }
        $this->response()->withStatus(202);
        $this->response()->write(json_encode([
            'id' => $reservation_id,
            'sheet_rank' => $rank,
            'sheet_num' => $sheet['num'],
        ], JSON_NUMERIC_CHECK));
        $this->response()->end();
    }

    public function apiEventsSheetsReservationById()
    {
        if (!$this->loginRequired()) {
            return;
        }

        $event_id = $this->request()->getQueryParam('id');
        $rank = $this->request()->getQueryParam('ranks');
        $num = $this->request()->getQueryParam('num');
    
        $user = $this->get_login_user();
        $event = $this->get_event($event_id, $user['id']);

        if (empty($event) || !$event['public']) {
            return $this->res_error('invalid_event', 404);
        }

        if (!$this->validate_rank($rank)) {
            return $this->res_error('invalid_rank', 404);
        }

        $sheet = $this->select_row('SELECT * FROM sheets WHERE `rank` = ? AND num = ?', [$rank, $num]);
        if (!$sheet) {
            return $this->res_error('invalid_sheet', 404);
        }

        $this->db->startTransaction();
        try {
            $reservation = $this->select_row('SELECT * FROM reservations WHERE event_id = ? AND sheet_id = ? AND canceled_at IS NULL GROUP BY event_id HAVING reserved_at = MIN(reserved_at) FOR UPDATE', [$event['id'], $sheet['id']]);
            if (!$reservation) {
                $this->db->rollback();
                return $this->res_error('not_reserved', 400);
            }

            if ($reservation['user_id'] != $user['id']) {
                $this->db->rollback();

                return $this->res_error('not_permitted', 403);
            }

            $this->db->rawQuery('UPDATE reservations SET canceled_at = ? WHERE id = ?', [(new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'), $reservation['id']]);
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollback();

            return $this->res_error();
        }

        $this->response()->withStatus(204);
        $this->response()->end();
    }

    public function adminLogin()
    {
        $content = $this->request()->getBody()->__toString();
        $raw_array = json_decode($content, true);

        $login_name = $raw_array['login_name'];
        $password = $raw_array['password'];
    

        $administrator = $this->select_row('SELECT * FROM administrators WHERE login_name = ?', [$login_name]);
        $pass_hash = $this->select_one('SELECT SHA2(?, 256) AS pass_hash', [$password]);

        if (!$administrator || $pass_hash['pass_hash'] != $administrator['pass_hash']) {
            return $this->res_error('authentication_failed', 401);
        }
    
        $this->session()->set('administrator_id', $administrator['id']);
    
        $this->response()->write(json_encode($administrator, JSON_NUMERIC_CHECK));
        $this->response()->end();
    }

    public function adminLogout()
    {
        if (!$this->adminLoginRequired()) {
            return;
        }
        $this->session()->destroy();

        $this->response()->withStatus(204);
        $this->response()->end();
    }

    public function adminApiEvents()
    {
        if (!$this->adminLoginRequired()) {
            return;
        }

        $events = $this->get_events(function ($event) { return $event; });
        
        $this->response()->write(json_encode($events, JSON_NUMERIC_CHECK));
        $this->response()->end();
    }

    public function adminApiEventsCreate()
    {
        if (!$this->adminLoginRequired()) {
            return;
        }

        $content = $this->request()->getBody()->__toString();
        $raw_array = json_decode($content, true);

        $title  = $raw_array['title'];
        $public = $raw_array['public'] ? 1 : 0;
        $price  = $raw_array['price'];
    
        $event_id = null;
    
        $this->db->startTransaction();
        try {
            $this->db->rawQuery('INSERT INTO events (title, public_fg, closed_fg, price) VALUES (?, ?, 0, ?)', [$title, $public, $price]);
            $event_id = $this->lastInsertId();
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollback();
        }
    
        $event = $this->get_event($event_id);
    
        $this->response()->write(json_encode($event, JSON_NUMERIC_CHECK));
        $this->response()->end();
    }

    public function adminApiEventsById()
    {
        if (!$this->adminLoginRequired()) {
            return;
        }
        $event_id = $this->request()->getQueryParam('id');

        $event = $this->get_event($event_id);
        if (empty($event)) {
            return $this->res_error('not_found', 404);
        }
    
        $this->response()->write(json_encode($event, JSON_NUMERIC_CHECK));
        $this->response()->end();
    }

    public function adminApiEventsActionsEditById()
    {
        if (!$this->adminLoginRequired()) {
            return;
        }
        $content = $this->request()->getBody()->__toString();
        $raw_array = json_decode($content, true);

        $event_id = $this->request()->getQueryParam('id');
        $public = $raw_array['public'] ? 1 : 0;
        $closed = $raw_array['closed'] ? 1 : 0;
    
        if ($closed) {
            $public = 0;
        }
    
        $event = $this->get_event($event_id);
        if (empty($event)) {
            return $this->res_error('not_found', 404);
        }
    
        if ($event['closed']) {
            return $this->res_error('cannot_edit_closed_event', 400);
        } elseif ($event['public'] && $closed) {
            return $this->res_error('cannot_close_public_event', 400);
        }
    
        $this->db->startTransaction();
        try {
            $this->db->rawQuery('UPDATE events SET public_fg = ?, closed_fg = ? WHERE id = ?', [$public, $closed, $event['id']]);
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollback();
        }
            $event = $this->get_event($event_id);
    
        $this->response()->write(json_encode($event, JSON_NUMERIC_CHECK));
        $this->response()->end();
    }

    public function adminApiReportsEventsSalesById()
    {
        $event_id = $this->request()->getQueryParam('id');

        $event = $this->get_event($event_id);
    
        $reports = [];
    
        $reservations = $this->select_all('SELECT r.*, s.rank AS sheet_rank, s.num AS sheet_num, s.price AS sheet_price, e.price AS event_price FROM reservations r INNER JOIN sheets s ON s.id = r.sheet_id INNER JOIN events e ON e.id = r.event_id WHERE r.event_id = ? ORDER BY reserved_at ASC FOR UPDATE', [$event['id']]);
        foreach ($reservations as $reservation) {
            $report = [
                'reservation_id' => $reservation['id'],
                'event_id' => $reservation['event_id'],
                'rank' => $reservation['sheet_rank'],
                'num' => $reservation['sheet_num'],
                'user_id' => $reservation['user_id'],
                'sold_at' => (new \DateTime("{$reservation['reserved_at']}", new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u').'Z',
                'canceled_at' => $reservation['canceled_at'] ? (new \DateTime("{$reservation['canceled_at']}", new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u').'Z' : '',
                'price' => $reservation['event_price'] + $reservation['sheet_price'],
            ];
    
            array_push($reports, $report);
        }
    
        return $this->render_report_csv($reports);
    }

    public function adminApiReportsSales()
    {
        if (!$this->adminLoginRequired()) {
            return;
        }

        $reports = [];
        $reservations = $this->select_all('SELECT r.*, s.rank AS sheet_rank, s.num AS sheet_num, s.price AS sheet_price, e.id AS event_id, e.price AS event_price FROM reservations r INNER JOIN sheets s ON s.id = r.sheet_id INNER JOIN events e ON e.id = r.event_id ORDER BY reserved_at ASC FOR UPDATE');

        foreach ($reservations as $reservation) {
            $report = [
                'reservation_id' => $reservation['id'],
                'event_id' => $reservation['event_id'],
                'rank' => $reservation['sheet_rank'],
                'num' => $reservation['sheet_num'],
                'user_id' => $reservation['user_id'],
                'sold_at' => (new \DateTime("{$reservation['reserved_at']}", new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u').'Z',
                'canceled_at' => $reservation['canceled_at'] ? (new \DateTime("{$reservation['canceled_at']}", new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u').'Z' : '',
                'price' => $reservation['event_price'] + $reservation['sheet_price'],
            ];
    
            array_push($reports, $report);
        }
    
        return $this->render_report_csv($reports);
    }
}
