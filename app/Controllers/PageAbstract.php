<?php namespace GLPI\Telemetry\Controllers;

use Slim\Views\Twig;
use Slim\Http\Request;
use Slim\Http\Response;
use Interop\Container\ContainerInterface;
use GLPI\Telemetry\Controllers\ControllerAbstract;
use GLPI\Telemetry\Models\Reference as ReferenceModel;
use GLPI\Telemetry\Models\User as UserModel;

abstract class PageAbstract extends ControllerAbstract
{

    /**
     * This function will set filters (orderby, sort and status)
     * It is used in several pages
     * @param array $args[] Contain the route's parameters like status
     * @param array $get[] Contain Query Params
     * @param boolean $status_filter To set or not status filter (use for References page)
     * @return array [args, session_ref] Who can be changed by the function
    **/
    public function setDifferentsFilters(array $get, array $args, $status_filter = true)
    {
        $session_ref = $_SESSION['reference'];
        $page = get_class($this);

        // default session param for this controller
        if (!isset($session_ref)) {
            $session_ref['orderby'] = 'created_at';
            $session_ref['sort'] = 'desc';
        }
        
        /**
         * if status is not specified in parameter $args :
         * default -> pending
         * and if the status is specified in the $session_ref, we'll take it.
         * That allow the pagination doesn't specified any status parameter.
        **/

        if ($status_filter) {
            if (!isset($args['status'])) {
                $args['status'] = 1;
                if ($session_ref[$page] !== null) {
                    $args['status'] = $session_ref[$page];
                }
            }
            $session_ref[$page] = $args['status'];
        }

        // manage sorting
        if (isset($get['orderby'])) {
            if ($session_ref['orderby'] == $get['orderby']) {
               // toggle sort if orderby requested on the same column
                $session_ref['sort'] = ($session_ref['sort'] == "desc"
                                                ? "asc"
                                                : "desc");
            }
            $session_ref['orderby'] = $get['orderby'];
        }
        $session_ref['pagination'] = 15;

        return $session_ref;
    }


    /**
     * This function load references and dynamics references
     * @param $user_id to specify if the function will have to load references for a specific user
     * @param $status to specify if the function will have to load references for a specific status
     * @return array ['references', 'dyn_refs'] to return references and dynamics references
     **/
    public function load_refs($user_id = null, $status = null)
    {
        $status = ($status === null) ? $_SESSION['reference'][get_class($this)] : $status;

        //check for refences presence
        $dyn_refs = $this->container->project->getDynamicReferences();
        if (false === $dyn_refs) {
             // retrieve data from model
            $references = ReferenceModel::active()->orderBy(
                $_SESSION['reference']['orderby'],
                $_SESSION['reference']['sort']
            )->paginate($_SESSION['reference']['pagination']);
        } else {
            try {
                $join_table = $this->container->project->getSlug() . '_reference';
                $order_field = $_SESSION['reference']['orderby'];
                $order_table = (isset($dyn_refs[$order_field]) ? $join_table : 'reference');
                // retrieve data from model
                $ref = new ReferenceModel();
                $model = $ref->newInstance();
                $model = call_user_func_array(
                    [
                        $model,
                        'select'
                    ],
                    array_merge(
                        ['reference.*'],
                        array_map(
                            function ($key) use ($join_table) {
                                return $join_table . '.' . $key;
                            },
                            array_keys($dyn_refs)
                        )
                    )
                );
                $model->where('status', '=', $status);
                if ($user_id != null) {
                    $model->where('user_id', '=', $user_id);
                }
                $model->orderBy(
                    $order_table . '.' . $order_field,
                    $_SESSION['reference']['sort']
                )
                    ->leftJoin($join_table, 'reference.id', '=', $join_table . '.reference_id')
                ;
                $references = $model->paginate($_SESSION['reference']['pagination']);
            } catch (\Illuminate\Database\QueryException $e) {
                if ($e->getCode() == '42P01') {
                    //rlation does not exists
                    throw new \RuntimeException(
                        'You have configured dynamic references for your project; but table ' .
                        $join_table . ' is missing!',
                        0,
                        $e
                    );
                }
                throw $e;
            }
        }
        return ['references' => $references, 'dyn_refs' => $dyn_refs];
    }

    public function loadUsers($is_admin = null, $order_field = 'username')
    {
        $ref = new UserModel();
        $model = $ref->newInstance();
        if ($is_admin != null) {
            $model->where('is_admin', '=', $is_admin);
        }
        $model->orderBy(
            $order_field,
            $_SESSION['users']['sort']
        );
        $users = $model->paginate($_SESSION['users']['pagination']);

        foreach ($users as $key => $user) {
            $references = $this->load_refs($user['id']);
            foreach ($references as $key2 => $reference) {
                echo "<pre>";var_dump($user['id']);   
            }
            //$user['references'] = $reference['references'];
            $users[$key] = $user['attributes'];
        }
/*
$reference[0]['attributes']
*/
        return $users;
    }

    public function loadUserRefsCount($user_id = null)
    {
        if ($user_id == null) {
            $user_id = $_SESSION['user']['id'];
        }

        //Reload SESSION variables for user's references
        $ref = new ReferenceModel();
        $ref_model = $ref->newInstance();
        $_SESSION['user']['references_count'] = $ref_model->where('user_id', $user_id)->get()->count();
    }
}
