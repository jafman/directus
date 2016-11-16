<?php

namespace Directus\API\Routes\A1;

use Directus\Database\TableGateway\DirectusUsersTableGateway;
use Directus\Application\Route;
use Directus\Database\TableGateway\RelationalTableGateway as TableGateway;
use Directus\View\JsonView;

class Entries extends Route
{
    public function rows($table)
    {
        $app = $this->app;
        $ZendDb = $app->container->get('zenddb');
        $acl = $app->container->get('acl');
        $requestPayload = $app->request()->post();
        $params = $app->request()->get();

        $id = null;
        $params['table_name'] = $table;
        $TableGateway = new TableGateway($table, $ZendDb, $acl);

        // any CREATE requests should md5 the email
        if ('directus_users' === $table &&
            in_array($app->request()->getMethod(), ['POST']) &&
            array_key_exists('email', $requestPayload)
        ) {
            $avatar = DirectusUsersTableGateway::get_avatar($requestPayload['email']);
            $requestPayload['avatar'] = $avatar;
        }

        switch ($app->request()->getMethod()) {
            // POST one new table entry
            case 'POST':
                $activityLoggingEnabled = !(isset($_GET['skip_activity_log']) && (1 == $_GET['skip_activity_log']));
                $activityMode = $activityLoggingEnabled ? TableGateway::ACTIVITY_ENTRY_MODE_PARENT : TableGateway::ACTIVITY_ENTRY_MODE_DISABLED;
                $newRecord = $TableGateway->manageRecordUpdate($table, $requestPayload, $activityMode);
                $params[$TableGateway->primaryKeyFieldName] = $newRecord[$TableGateway->primaryKeyFieldName];
                break;
            // PUT a change set of table entries
            case 'PUT':
                if (!is_numeric_array($requestPayload)) {
                    $params[$TableGateway->primaryKeyFieldName] = $requestPayload[$TableGateway->primaryKeyFieldName];
                    $requestPayload = [$requestPayload];
                }
                $TableGateway->updateCollection($requestPayload);
                break;
        }

        // GET all table entries
        $Table = new TableGateway($table, $ZendDb, $acl);
        $response = $Table->getEntries($params);

        JsonView::render($response);
    }

    public function rowsBulk($table)
    {
        $ZendDb = $this->app->container->get('zenddb');
        $acl = $this->app->container->get('acl');
        $requestPayload = $this->app->request()->post();
        $params = $this->app->request()->get();
        $rows = array_key_exists('rows', $requestPayload) ? $requestPayload['rows'] : false;
        if (!is_array($rows) || count($rows) <= 0) {
            throw new \Exception(__t('rows_no_specified'));
        }

        $TableGateway = new TableGateway($table, $ZendDb, $acl);
        $primaryKeyFieldName = $TableGateway->primaryKeyFieldName;

        $rowIds = [];
        foreach ($rows as $row) {
            if (!array_key_exists($primaryKeyFieldName, $row)) {
                throw new \Exception(__t('row_without_primary_key_field'));
            }
            array_push($rowIds, $row[$primaryKeyFieldName]);
        }

        $where = new \Zend\Db\Sql\Where;

        if ($this->app->request()->isDelete()) {
            $TableGateway->delete($where->in($primaryKeyFieldName, $rowIds));
        } else {
            foreach ($rows as $row) {
                $TableGateway->updateCollection($row);
            }
        }

        $entries = $TableGateway->getEntries($params);
        JsonView::render($entries);
    }

    public function typeAhead($table, $query = null)
    {
        $ZendDb = $this->app->container->get('zenddb');
        $acl = $this->app->container->get('acl');

        $params = $this->app->request()->get();

        $Table = new TableGateway($table, $ZendDb, $acl);

        if (!isset($params['columns'])) {
            $params['columns'] = '';
        }

        $columns = ($params['columns']) ? explode(',', $params['columns']) : [];
        if (count($columns) > 0) {
            $params['group_by'] = $columns[0];

            if (isset($params['q'])) {
                $params['adv_where'] = "`{$columns[0]}` like '%{$params['q']}%'";
                $params['perPage'] = 50;
            }
        }

        if (!$query) {
            $entries = $Table->getEntries($params);
        }

        $entries = $entries['rows'];
        $response = [];
        foreach ($entries as $entry) {
            $val = '';
            $tokens = [];
            foreach ($columns as $col) {
                array_push($tokens, $entry[$col]);
            }
            $val = implode(' ', $tokens);
            array_push($response, ['value' => $val, 'tokens' => $tokens, 'id' => $entry['id']]);
        }
        JsonView::render($response);
    }

    public function row($table, $id)
    {
        $app = $this->app;
        $ZendDb = $app->container->get('zenddb');
        $auth = $app->container->get('auth');
        $acl = $app->container->get('acl');
        $requestPayload = $app->request()->post();
        $params = $app->request()->get();

        $currentUser = $auth->getUserInfo();
        $params['table_name'] = $table;

        // any UPDATE requests should md5 the email
        if ('directus_users' === $table &&
            in_array($app->request()->getMethod(), ['PUT', 'PATCH']) &&
            array_key_exists('email', $requestPayload)
        ) {
            $avatar = DirectusUsersTableGateway::get_avatar($requestPayload['email']);
            $requestPayload['avatar'] = $avatar;
        }

        $TableGateway = new TableGateway($table, $ZendDb, $acl);
        switch ($app->request()->getMethod()) {
            // PUT an updated table entry
            case 'PATCH':
            case 'PUT':
                $requestPayload[$TableGateway->primaryKeyFieldName] = $id;
                $activityLoggingEnabled = !(isset($_GET['skip_activity_log']) && (1 == $_GET['skip_activity_log']));
                $activityMode = $activityLoggingEnabled ? TableGateway::ACTIVITY_ENTRY_MODE_PARENT : TableGateway::ACTIVITY_ENTRY_MODE_DISABLED;
                $TableGateway->manageRecordUpdate($table, $requestPayload, $activityMode);
                break;
            // DELETE a given table entry
            case 'DELETE':
                echo $TableGateway->delete([$TableGateway->primaryKeyFieldName => $id]);
                return;
        }

        $params[$TableGateway->primaryKeyFieldName] = $id;
        // GET a table entry
        $Table = new TableGateway($table, $ZendDb, $acl);
        $response = $Table->getEntries($params);
        if (!$response) {
            $response = [
                'message' => __t('unable_to_find_record_in_x_with_id_x', ['table' => $table, 'id' => $id]),
                'success' => false
            ];
        }
        JsonView::render($response);
    }
}